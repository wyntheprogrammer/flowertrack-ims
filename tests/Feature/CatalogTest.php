<?php

namespace Tests\Feature;

use App\Models\Flower;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ->assertOk()->assertSeeTextInOrder(['No flowers match these filters.', 'Clear filters']);
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
        $this->get(route('client.checkout'))->assertSeeText('No items selected for checkout')->assertDontSeeText('Pickup total');
        $flower->update(['status' => 'Inactive']);
        $this->get(route('client.product', $flower))->assertNotFound();
        $this->get(route('client.cart'))->assertSeeText('Item unavailable')->assertDontSeeText('Spring Rose');
        $this->delete(route('client.cart.remove', $flower))->assertRedirect(route('client.cart'));
        $this->get(route('client.cart'))->assertSeeText('Your cart is empty');
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_mixed_cart_renders_line_calculations_and_recalculates_after_update(): void
    {
        $stem = $this->flower('Red Rose', 'Stem', 'Roses');
        $bouquet = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->stockProduct($stem, 5);
        $this->stockProduct($bouquet, 3);
        DB::table('flower_prices')->insert([
            ['flower_id' => $stem->id, 'price_cents' => 5000],
            ['flower_id' => $bouquet->id, 'price_cents' => 27525],
        ]);
        $this->withSession(['shop.cart' => [$stem->id => 3, $bouquet->id => 1]]);

        $cart = $this->get(route('client.cart'));
        $cart->assertOk()->assertSeeTextInOrder(['Red Rose', '₱50.00 per stem', '× 3 = ₱150.00', 'Garden Bouquet', '₱275.25 per bouquet', '× 1 = ₱275.25', 'Selected subtotal', '₱425.25', 'Review checkout (2 items)'])
            ->assertSeeText('Photo unavailable')
            ->assertDontSeeText('Pickup total');

        $this->put(route('client.cart.update', $stem), ['quantity' => 2])
            ->assertRedirect(route('client.cart'))
            ->assertSessionHas('cart_status', 'Red Rose updated to 2 stems.')
            ->assertSessionHas('shop.cart.'.$bouquet->id, 1);

        $this->get(route('client.cart'))->assertOk()
            ->assertSeeTextInOrder(['Red Rose updated to 2 stems.', '₱100.00', 'Selected subtotal', '₱375.25']);
        $this->get(route('client.checkout'))->assertOk()
            ->assertSeeTextInOrder(['Checkout review', 'Item subtotal', '₱375.25', 'Pickup total', '₱375.25']);
        $this->assertDatabaseHas('inventory_batches', ['flower_id' => $stem->id, 'remaining_quantity' => 5]);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cart_pinpoints_invalid_lines_and_preserves_other_items(): void
    {
        $short = $this->flower('Red Rose', 'Stem', 'Roses');
        $soldOut = $this->flower('White Tulip', 'Stem', 'Tulips');
        $unpriced = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->stockProduct($short, 2);
        $this->stockProduct($unpriced, 1);
        DB::table('flower_prices')->insert([
            ['flower_id' => $short->id, 'price_cents' => 5000],
            ['flower_id' => $soldOut->id, 'price_cents' => 2500],
        ]);
        $this->withSession(['shop.cart' => [$short->id => 3, $soldOut->id => 1, $unpriced->id => 1]]);

        $this->get(route('client.cart'))->assertOk()
            ->assertSeeTextInOrder([
                'Red Rose', 'Only 2 stems are available. Change your quantity to 2 or fewer.',
                'White Tulip', 'This flower is sold out.',
                'Garden Bouquet', 'The price for this flower is unavailable.',
            ])
            ->assertViewHas('selectedCount', 0);

        $this->put(route('client.cart.update', $short), ['quantity' => 4])
            ->assertRedirect(route('client.cart'))
            ->assertSessionHas('shop.cart.'.$short->id, 3)
            ->assertSessionHas('shop.cart.'.$soldOut->id, 1)
            ->assertSessionHasErrors(['items.'.$short->id => 'Only 2 stems are available. Change your quantity to 2 or fewer.'], null, 'cart');
    }

    public function test_cart_selection_preserves_unchecked_lines_and_reviews_only_saved_choices(): void
    {
        $stem = $this->flower('Red Rose', 'Stem', 'Roses');
        $bouquet = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->stockProduct($stem, 5);
        $this->stockProduct($bouquet, 3);
        DB::table('flower_prices')->insert([
            ['flower_id' => $stem->id, 'price_cents' => 5000],
            ['flower_id' => $bouquet->id, 'price_cents' => 80000],
        ]);
        $this->withSession(['shop.cart' => [$stem->id => 3, $bouquet->id => 1]]);

        $this->get(route('client.cart'))->assertOk()
            ->assertSeeTextInOrder(['Selected subtotal', '₱950.00', 'Review checkout (2 items)']);

        $this->postJson(route('client.cart.selection'), ['selected' => [$stem->id, 999], 'subtotal' => 1])
            ->assertNoContent()
            ->assertSessionHas('shop.cart_selection', [$stem->id]);

        $this->get(route('client.cart'))->assertOk()
            ->assertSeeText('Garden Bouquet')
            ->assertSeeTextInOrder(['Selected subtotal', '₱150.00', 'Review checkout (1 item)']);

        $this->put(route('client.cart.update', $bouquet), ['quantity' => 2])
            ->assertRedirect(route('client.cart'))
            ->assertSessionHas('shop.cart_selection', [$stem->id])
            ->assertSessionHas('shop.cart.'.$bouquet->id, 2);

        $this->get(route('client.checkout'))->assertOk()
            ->assertSeeTextInOrder(['Red Rose', 'Quantity 3', 'Item subtotal', '₱150.00'])
            ->assertDontSeeText('Garden Bouquet');

        $this->post(route('client.cart.selection'), ['selected' => [$stem->id, $bouquet->id], 'review' => 1])
            ->assertRedirect(route('client.checkout'));

        $this->get(route('client.checkout'))->assertOk()
            ->assertSeeText('Garden Bouquet')
            ->assertSeeText('₱1750.00');

        $this->postJson(route('client.cart.selection'), [])->assertNoContent()
            ->assertSessionHas('shop.cart_selection', []);
        $this->get(route('client.cart'))->assertOk()
            ->assertViewHas('selectedCount', 0)
            ->assertSeeTextInOrder(['Selected subtotal', '₱0', 'Review checkout (0 items)']);
        $this->get(route('client.checkout'))->assertOk()
            ->assertSeeText('No items selected for checkout')
            ->assertDontSeeText('Item subtotal');
    }

    public function test_cart_quantity_autosave_returns_current_totals_and_keeps_selection(): void
    {
        $stem = $this->flower('Red Rose', 'Stem', 'Roses');
        $bouquet = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->stockProduct($stem, 12);
        $this->stockProduct($bouquet, 3);
        DB::table('flower_prices')->insert([
            ['flower_id' => $stem->id, 'price_cents' => 5000],
            ['flower_id' => $bouquet->id, 'price_cents' => 80000],
        ]);
        $this->withSession([
            'shop.cart' => [$stem->id => 3, $bouquet->id => 1],
            'shop.cart_selection' => [$stem->id],
        ]);

        $this->putJson(route('client.cart.update', $stem), ['quantity' => 12, 'price' => 1])
            ->assertOk()
            ->assertExactJson(['quantity' => 12, 'price' => 5000, 'amount' => 60000, 'maxQuantity' => 12, 'selected' => true]);
        $this->putJson(route('client.cart.update', $bouquet), ['quantity' => 2])
            ->assertOk()
            ->assertJson(['quantity' => 2, 'amount' => 160000, 'selected' => false])
            ->assertSessionHas('shop.cart_selection', [$stem->id]);
        $this->putJson(route('client.cart.update', $stem), ['quantity' => 13])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only 12 stems are available. Change your quantity to 12 or fewer.')
            ->assertSessionHas('shop.cart.'.$stem->id, 12)
            ->assertSessionHas('shop.cart.'.$bouquet->id, 2);

        $this->get(route('client.checkout'))->assertOk()
            ->assertSeeTextInOrder(['Red Rose', 'Quantity 12', 'Item subtotal', '₱600.00'])
            ->assertDontSeeText('Garden Bouquet');
        $this->assertDatabaseHas('inventory_batches', ['flower_id' => $stem->id, 'remaining_quantity' => 12]);
    }

    public function test_checkout_skips_selected_items_that_become_unavailable_and_reprices_the_rest(): void
    {
        $stem = $this->flower('Red Rose', 'Stem', 'Roses');
        $bouquet = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->stockProduct($stem, 5);
        $this->stockProduct($bouquet, 3);
        DB::table('flower_prices')->insert([
            ['flower_id' => $stem->id, 'price_cents' => 5000],
            ['flower_id' => $bouquet->id, 'price_cents' => 80000],
        ]);
        $this->withSession([
            'shop.cart' => [$stem->id => 3, $bouquet->id => 1],
            'shop.cart_selection' => [$stem->id, $bouquet->id],
        ]);

        DB::table('inventory_batches')->where('flower_id', $stem->id)->update(['remaining_quantity' => 2]);
        DB::table('flower_prices')->where('flower_id', $bouquet->id)->update(['price_cents' => 82550]);

        $this->get(route('client.checkout'))->assertOk()
            ->assertSeeText('Some selected items are unavailable.')
            ->assertSeeTextInOrder(['Garden Bouquet', 'Item subtotal', '₱825.50', 'Pickup total'])
            ->assertDontSeeText('Red Rose');

        $this->get(route('client.cart'))->assertOk()
            ->assertSeeText('Only 2 stems are available. Change your quantity to 2 or fewer.')
            ->assertSeeTextInOrder(['Selected subtotal', '₱825.50', 'Review checkout (1 item)'])
            ->assertSessionHas('shop.cart.'.$stem->id, 3);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_removing_the_last_item_leaves_the_browse_flow_and_shared_footer(): void
    {
        $flower = $this->flower('Red Rose', 'Stem', 'Roses');
        $this->withSession(['shop.cart' => [$flower->id => 1]])
            ->delete(route('client.cart.remove', $flower))
            ->assertRedirect(route('client.cart'));

        $this->get(route('client.cart'))->assertOk()
            ->assertSeeText('Your cart is empty')
            ->assertSee('href="'.route('client.catalog').'">Browse flowers', false)
            ->assertSee('shop-footer-featured', false)
            ->assertDontSeeText('Review checkout');
    }

    public function test_bouquet_details_show_the_per_bouquet_price_and_starting_total(): void
    {
        $flower = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => 12575]);
        $this->stockProduct($flower, 5);

        $this->get(route('client.product', $flower))
            ->assertOk()
            ->assertSeeText('Photo unavailable')
            ->assertSeeText('₱125.75 / bouquet')
            ->assertSeeText('5 bouquets available')
            ->assertSeeText('Number of bouquets')
            ->assertSee('name="quantity" min="1" max="5" step="1" value="1"', false)
            ->assertSeeTextInOrder(['Item total', '₱125.75']);
    }

    public function test_product_details_display_the_uploaded_photo_when_available(): void
    {
        $flower = $this->flower('Garden Bouquet', 'Bouquet', 'Mixed Flowers');
        $photo = 'flowers/garden-bouquet.webp';
        Storage::fake('public')->put($photo, file_get_contents(public_path('images/shop-bouquets.webp')));
        $flower->update(['image_path' => $photo]);

        $this->get(route('client.product', $flower))
            ->assertOk()
            ->assertSee('src="'.e(Storage::disk('public')->url($photo)).'"', false)
            ->assertSee('alt="Garden Bouquet"', false)
            ->assertDontSeeText('Photo unavailable');
    }

    public function test_addition_uses_current_stock_price_and_the_quantity_already_in_cart(): void
    {
        $flower = $this->flower('Garden Rose', 'Stem', 'Roses');
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => 5000]);
        $this->stockProduct($flower, 7);
        $product = route('client.product', $flower);
        $add = route('client.cart.add', $flower);
        $cartKey = 'shop.cart.'.$flower->id;

        $this->post($add, ['quantity' => 3])
            ->assertRedirect(route('client.cart'))
            ->assertSessionHas('cart_status', '3 stems of Garden Rose added to your cart.');
        $this->get($product)->assertOk()
            ->assertSeeText('Number of stems')
            ->assertSeeText('3 stems in your cart')
            ->assertSee('name="quantity" min="1" max="4" step="1" value="1"', false);

        DB::table('inventory_batches')->where('flower_id', $flower->id)->update(['remaining_quantity' => 5]);
        DB::table('flower_prices')->where('flower_id', $flower->id)->update(['price_cents' => 5125]);

        $this->post($add, ['quantity' => 3])
            ->assertRedirect($product)
            ->assertSessionHasErrors(['quantity' => 'You can add 2 more stems.'])
            ->assertSessionHas($cartKey, 3);
        $this->post($add, ['quantity' => 2, 'price_cents' => 5000])
            ->assertRedirect(route('client.cart'))
            ->assertSessionHas('cart_status', '2 stems of Garden Rose added to your cart.')
            ->assertSessionHas($cartKey, 5);

        $this->get(route('client.cart'))->assertOk()
            ->assertSee('role="status">2 stems of Garden Rose added to your cart.', false)
            ->assertSeeText('View cart')
            ->assertSeeText('Continue shopping')
            ->assertSeeText('₱256.25');
        $this->assertDatabaseHas('inventory_batches', ['flower_id' => $flower->id, 'remaining_quantity' => 5]);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_product_with_stock_and_no_price_explains_why_it_cannot_be_added(): void
    {
        $flower = $this->flower('Unpriced Bouquet', 'Bouquet', 'Mixed Flowers');
        $this->stockProduct($flower, 2);
        $product = route('client.product', $flower);

        $this->get($product)->assertOk()
            ->assertSeeText('Price unavailable')
            ->assertSeeText('Back to catalog')
            ->assertDontSeeText('Add to cart');

        $this->post(route('client.cart.add', $flower), ['quantity' => 1])
            ->assertRedirect($product)
            ->assertSessionHasErrors(['quantity' => 'The price for this flower is unavailable.'])
            ->assertSessionMissing('shop.cart.'.$flower->id);
    }

    public function test_product_with_zero_stock_explains_why_it_cannot_be_added(): void
    {
        $flower = $this->flower('Garden Tulip', 'Stem', 'Tulips');
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => 5000]);
        $product = route('client.product', $flower);

        $this->get($product)->assertOk()
            ->assertSeeText('Out of stock')
            ->assertSeeText('Back to catalog')
            ->assertDontSeeText('Add to cart');

        $this->post(route('client.cart.add', $flower), ['quantity' => 1])
            ->assertRedirect($product)
            ->assertSessionHasErrors(['quantity' => 'This flower is out of stock.'])
            ->assertSessionMissing('shop.cart.'.$flower->id);
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

    public function test_old_collections_url_redirects_permanently_to_catalog(): void
    {
        $this->get('/shop/collections')
            ->assertMovedPermanently()
            ->assertRedirect(route('client.catalog'));
    }

    public function test_home_shopping_links_point_to_the_filtered_catalog(): void
    {
        $this->get(route('client.home'))
            ->assertOk()
            ->assertSee('href="'.route('client.catalog', ['type' => Flower::TYPE_STEM]).'">Shop by Stem</a>', false)
            ->assertSee('href="'.route('client.catalog', ['type' => Flower::TYPE_BOUQUET]).'">Shop Bouquets</a>', false)
            ->assertDontSee('/shop/collections')
            ->assertDontSeeText('Shop by Collection');
    }

    public function test_about_shows_the_shop_story_and_visit_links_in_order(): void
    {
        $this->get(route('client.about'))
            ->assertOk()
            ->assertSeeTextInOrder([
                'About Gemma’s Flower Shop',
                'How the shop got its name',
                'Roses and Friends in Quiapo',
                'Stems and bouquets',
                'Browse flowers',
                'Visit the shop',
                'Laon Laan – Juaning Consolidation Area',
                'Barangay 471, Sampaloc, Manila 1008',
                'Get directions',
                'Visit Gemma’s on Facebook',
            ])
            ->assertSee('href="'.route('client.catalog').'">Browse flowers', false)
            ->assertSee('href="https://www.google.com/maps/dir/?api=1&amp;destination=14.6114375%2C120.9863871"', false)
            ->assertSee('href="https://www.facebook.com/profile.php?id=100063693678426"', false)
            ->assertSee('src="https://maps.google.com/maps?q=14.6114375,120.9863871&amp;z=18&amp;output=embed"', false)
            ->assertDontSee('/shop/collections');
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

    public function test_type_choices_preserve_the_applied_filters_and_reset_the_page(): void
    {
        for ($number = 1; $number <= 13; $number++) {
            $this->flower(sprintf('Rose Stem %02d', $number), 'Stem', 'Cut Flowers');
        }

        $filters = ['category' => 'Cut Flowers', 'q' => 'Rose'];
        $response = $this->get(route('client.catalog', ['type' => 'Stem', ...$filters, 'page' => 2]));

        $response->assertOk()
            ->assertSeeText('Flower catalog')
            ->assertSeeText('Rose Stem 13')
            ->assertSeeText('Photo unavailable')
            ->assertSee('name="type" value="Stem"', false)
            ->assertSee('href="'.e(route('client.catalog', ['type' => 'Stem', ...$filters])).'" aria-current="page">', false)
            ->assertSee('href="'.e(route('client.catalog', ['type' => 'Bouquet', ...$filters])).'"', false)
            ->assertSee('href="'.e(route('client.catalog', $filters)).'"', false);
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

    private function stockProduct(Flower $flower, int $quantity): void
    {
        DB::table('inventory_batches')->insert([
            'flower_id' => $flower->id, 'code' => 'BAT-'.$flower->id, 'received_date' => '2026-11-01',
            'harvest_date' => '2026-11-01', 'received_quantity' => $quantity, 'receiving_unit' => $flower->product_type,
            'conversion_factor' => 1, 'initial_quantity' => $quantity, 'remaining_quantity' => $quantity,
        ]);
    }

    private function flower(string $name, string $type, string $category, string $status = 'Active'): Flower
    {
        return Flower::query()->create([
            'code' => str_replace(' ', '-', $name), 'name' => $name, 'product_type' => $type,
            'category' => $category, 'status' => $status, 'description' => $name,
        ]);
    }
}
