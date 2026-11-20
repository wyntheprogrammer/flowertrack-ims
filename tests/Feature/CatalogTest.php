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

    public function test_home_shows_a_limited_catalog_preview_with_purchasable_flowers_first(): void
    {
        $bouquet = $this->flower('Zinnia Bouquet', 'Bouquet', 'Mixed Flowers');
        DB::table('flower_prices')->insert(['flower_id' => $bouquet->id, 'price_cents' => 12500]);
        DB::table('inventory_batches')->insert([
            'flower_id' => $bouquet->id, 'code' => 'BAT-ZINNIA', 'received_date' => '2026-11-01',
            'harvest_date' => '2026-11-01', 'received_quantity' => 3, 'receiving_unit' => 'Bouquet',
            'conversion_factor' => 1, 'initial_quantity' => 3, 'remaining_quantity' => 3,
        ]);
        foreach (['Aster', 'Dahlia', 'Iris', 'Tulip'] as $name) {
            $this->flower($name, 'Stem', 'Cut Flowers');
        }
        $this->flower('Archived Anemone', 'Stem', 'Cut Flowers', 'Inactive');

        $response = $this->get(route('client.home'));

        $response->assertOk()
            ->assertSeeTextInOrder(['Zinnia Bouquet', 'Aster', 'Dahlia', 'Iris'])
            ->assertSeeText('₱125.00 / bouquet')
            ->assertSeeText('3 bouquets available')
            ->assertSee('href="'.route('client.product', $bouquet).'"', false)
            ->assertDontSeeText('Tulip')
            ->assertDontSeeText('Archived Anemone');
    }

    public function test_home_shows_an_empty_preview_when_no_flowers_are_listed(): void
    {
        $this->get(route('client.home'))->assertOk()
            ->assertSeeText('No flowers are listed in the catalog yet.')
            ->assertSeeText('Shop the catalog');
    }

    public function test_collections_links_to_the_two_catalog_filters(): void
    {
        $this->get(route('client.collections'))->assertOk()
            ->assertSeeText('Ready-made bouquets')
            ->assertSee('href="'.route('client.catalog', ['type' => Flower::TYPE_STEM]).'"', false)
            ->assertSee('href="'.route('client.catalog', ['type' => Flower::TYPE_BOUQUET]).'"', false);
    }

    public function test_catalog_search_combines_with_filters_and_persists_in_pagination(): void
    {
        for ($number = 1; $number <= 13; $number++) {
            $this->flower(sprintf('Rose Bouquet %02d', $number), 'Bouquet', 'Mixed Flowers');
        }
        $this->flower('Dahlia Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->flower('Rose Stem', 'Stem', 'Mixed Flowers');
        $this->flower('Rose Bunch', 'Bouquet', 'Roses');

        $filters = ['type' => 'Bouquet', 'category' => 'Mixed Flowers', 'q' => 'rOsE'];
        $response = $this->get(route('client.catalog', [...$filters, 'q' => '  rOsE  ']));

        $response->assertOk()
            ->assertSeeText('Rose Bouquet 01')
            ->assertDontSeeText('Rose Bouquet 13')
            ->assertDontSeeText('Dahlia Bouquet')
            ->assertDontSeeText('Rose Stem')
            ->assertDontSeeText('Rose Bunch')
            ->assertSee(route('client.catalog', [...$filters, 'page' => 2]));
    }

    public function test_catalog_search_matches_wildcards_literally_and_escapes_the_search_field(): void
    {
        $search = 'Rose_%! & Lily';
        $this->flower($search, 'Stem', 'Cut Flowers');
        $this->flower('RoseAB! & Lily', 'Stem', 'Cut Flowers');

        $response = $this->get(route('client.catalog', ['q' => $search]));

        $response->assertOk()
            ->assertSeeText($search)
            ->assertDontSeeText('RoseAB! & Lily')
            ->assertSee('value="Rose_%! &amp; Lily"', false)
            ->assertDontSee('value="Rose_%! & Lily"', false);
    }

    public function test_catalog_rejects_an_array_as_a_search_term(): void
    {
        $this->get(route('client.catalog', ['q' => ['Rose']]))->assertNotFound();
    }

    private function flower(string $name, string $type, string $category, string $status = 'Active'): Flower
    {
        return Flower::query()->create([
            'code' => str_replace(' ', '-', $name), 'name' => $name, 'product_type' => $type,
            'category' => $category, 'status' => $status, 'description' => $name,
        ]);
    }
}
