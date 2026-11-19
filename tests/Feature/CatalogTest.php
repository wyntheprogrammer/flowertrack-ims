<?php

namespace Tests\Feature;

use App\Models\Flower;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'catalog_test',
            'database.connections.catalog_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        $this->artisan('migrate', ['--database' => 'catalog_test'])->assertSuccessful();
        $this->withoutVite();
    }

    public function test_public_catalog_shows_the_current_price_and_only_unreserved_usable_stock(): void
    {
        $flower = $this->flower('Garden Rose', 'Stem', 'Roses');
        $this->flower('Archived Rose', 'Stem', 'Roses', 'Inactive');
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => 2345]);
        foreach ([false, true] as $spoiled) {
            DB::table('inventory_batches')->insert([
                'flower_id' => $flower->id, 'code' => 'BAT-'.(int) $spoiled,
                'received_date' => '2026-11-01', 'harvest_date' => '2026-11-01',
                'received_quantity' => 10, 'receiving_unit' => 'Stem', 'conversion_factor' => 1,
                'initial_quantity' => 10, 'remaining_quantity' => 10, 'spoiled' => $spoiled,
            ]);
        }
        foreach (['Active', 'Released'] as $status) {
            DB::table('inventory_reservations')->insert([
                'flower_id' => $flower->id, 'reference' => 'ORD-'.$status, 'quantity' => 3, 'status' => $status,
            ]);
        }
        $this->get(route('client.catalog'))->assertOk()->assertSeeText('Garden Rose')
            ->assertSeeText('₱23.45 / stem')->assertSeeText('7 stems available')->assertDontSeeText('Archived Rose');
    }

    public function test_filters_combine_and_unpriced_bouquets_remain_identifiable(): void
    {
        $this->flower('Spring bunch', 'Bouquet', 'Mixed Flowers');
        $this->flower('Spring stem', 'Stem', 'Mixed Flowers');
        $this->flower('Rose bunch', 'Bouquet', 'Roses');
        $this->get(route('client.catalog', ['type' => 'Bouquet', 'category' => 'Mixed Flowers']))
            ->assertOk()->assertSeeText('Spring bunch')->assertDontSeeText('Spring stem')
            ->assertDontSeeText('Rose bunch')->assertSeeText('Price unavailable')->assertSeeText('Out of stock');
        $this->get(route('client.catalog', ['type' => 'Stem', 'category' => 'Roses']))
            ->assertOk()->assertSeeText('No flowers match these filters.');
    }

    public function test_cart_reviews_current_stock_and_price_without_reserving_or_submitting(): void
    {
        $flower = $this->flower('Spring Rose', 'Stem', 'Roses');
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => 3500]);
        DB::table('inventory_batches')->insert([
            'flower_id' => $flower->id, 'code' => 'BAT-1', 'received_date' => '2026-11-01',
            'harvest_date' => '2026-11-01', 'received_quantity' => 4, 'receiving_unit' => 'Stem',
            'conversion_factor' => 1, 'initial_quantity' => 4, 'remaining_quantity' => 4,
        ]);
        $this->get(route('client.product', $flower))->assertOk()->assertSeeText('Add to cart');
        $this->post(route('client.cart.add', $flower), ['quantity' => 3])->assertRedirect(route('client.cart'));
        $this->post(route('client.cart.add', $flower), ['quantity' => 2])->assertSessionHasErrors('quantity');
        $this->get(route('client.cart'))->assertSeeText('₱105.00');
        $this->put(route('client.cart.update', $flower), ['quantity' => 2])->assertRedirect(route('client.cart'));
        DB::table('flower_prices')->where('flower_id', $flower->id)->update(['price_cents' => 4000]);
        $this->get(route('client.checkout'))->assertOk()->assertSeeText('₱80.00');
        config(['orders.delivery_fee' => '15.50']);
        $this->get(route('client.checkout', ['method' => 'delivery']))->assertOk()
            ->assertSeeText('Delivery total')->assertSeeText('₱95.50');
        DB::table('inventory_batches')->where('flower_id', $flower->id)->update(['remaining_quantity' => 1]);
        $this->get(route('client.checkout'))->assertSeeText('Review item')->assertDontSeeText('Pickup total');
        $flower->update(['status' => 'Inactive']);
        $this->get(route('client.product', $flower))->assertNotFound();
        $this->get(route('client.cart'))->assertSeeText('Item unavailable')->assertDontSeeText('Spring Rose');
        $this->delete(route('client.cart.remove', $flower))->assertRedirect(route('client.cart'));
        $this->get(route('client.cart'))->assertSeeText('Your cart is empty');
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    private function flower(string $name, string $type, string $category, string $status = 'Active'): Flower
    {
        return Flower::query()->create([
            'code' => str_replace(' ', '-', $name), 'name' => $name, 'product_type' => $type,
            'category' => $category, 'status' => $status, 'description' => $name,
        ]);
    }
}
