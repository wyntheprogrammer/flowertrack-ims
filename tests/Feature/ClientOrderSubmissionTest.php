<?php

namespace Tests\Feature;

use App\Models\Flower;
use App\Models\Order;
use App\Models\User;
use App\Services\ClientService;
use App\Services\OrderService;
use App\Support\AccountSession;
use App\Support\CheckoutSession;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientOrderSubmissionTest extends TestCase
{
    private User $customer;

    private Flower $rose;

    private Flower $bouquet;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'client_order_test',
            'database.connections.client_order_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'session.driver' => 'array',
            'cache.default' => 'array',
            'hashing.bcrypt.rounds' => 4,
            'orders.delivery_fee' => null,
        ]);

        $this->artisan('migrate', ['--database' => 'client_order_test'])->assertSuccessful();
        $this->withoutVite();
        $this->customer = $this->customer();
        $this->rose = $this->stockProduct('Red Rose', 'Stem', 5000);
        $this->bouquet = $this->stockProduct('Garden Bouquet', 'Bouquet', 80000);
        $this->withSession([
            CheckoutSession::CART => [$this->rose->id => 3, $this->bouquet->id => 1],
            CheckoutSession::SELECTION => [$this->rose->id],
        ]);
    }

    public function test_customer_submits_only_checked_items_and_replays_the_same_order_after_cart_clear(): void
    {
        $this->signIn($this->customer);
        $key = $this->review();
        $this->assertSame($key, $this->review());

        $this->post(route('client.checkout.store'), [
            'request_key' => $key, 'client_id' => 999, 'total_cents' => 1,
            'items' => [['flower_id' => $this->bouquet->id, 'quantity' => 20]],
        ])->assertRedirect()->assertSessionHas(CheckoutSession::CART, [$this->bouquet->id => 1])
            ->assertSessionHas(CheckoutSession::SELECTION, []);

        $order = Order::sole();
        $confirmation = route('client.orders.confirmation', $order);
        $this->assertSame($this->customer->clientProfile->id, $order->client_id);
        $this->assertSame('Pending', $order->status);
        $this->assertSame('Customer Pickup', $order->method);
        $this->assertSame(15000, $order->total_cents);
        $this->assertSame(3, $order->items()->sole()->quantity);
        $this->assertSame($this->rose->id, (int) $order->items()->sole()->flower_id);

        $this->post(route('client.cart.add', $this->rose), ['quantity' => 1])->assertRedirect();
        $this->post(route('client.checkout.store'), ['request_key' => $key])->assertRedirect($confirmation)
            ->assertSessionHas(CheckoutSession::CART, [$this->bouquet->id => 1, $this->rose->id => 1]);

        for ($visit = 0; $visit < 2; $visit++) {
            $this->get($confirmation)->assertOk()
                ->assertSeeTextInOrder([$order->code, 'Pending confirmation', 'Red Rose', 'Quantity 3', 'Customer Pickup', 'Order total', '₱150.00'])
                ->assertSee('shop-footer-featured', false)
                ->assertDontSeeText('Garden Bouquet');
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_histories', 1);
        $this->assertDatabaseHas('order_histories', ['order_id' => $order->id, 'actor_id' => $this->customer->id]);
        $this->assertDatabaseCount('order_payments', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('inventory_batches', ['flower_id' => $this->rose->id, 'remaining_quantity' => 5]);

        $this->signIn($this->customer());
        $this->get($confirmation)->assertNotFound();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true, 'must_change_password' => false]);
        $this->signIn($admin);
        $this->getJson(route('supplier.orders.data'))->assertOk()
            ->assertJsonPath('data.0.id', $order->code)
            ->assertJsonPath('data.0.status', 'Pending');
    }

    public function test_retry_recovers_when_the_order_was_committed_before_the_session_was_saved(): void
    {
        $this->signIn($this->customer);
        $key = $this->review();
        $order = app(OrderService::class)->store([
            'request_key' => $key, 'client_id' => $this->customer->clientProfile->id,
            'method' => 'Customer Pickup', 'items' => [['flower_id' => $this->rose->id, 'quantity' => 3]],
        ], $this->customer, reviewedTotal: 15000);

        $this->assertSame(3, session(CheckoutSession::CART)[$this->rose->id]);

        $confirmation = route('client.orders.confirmation', $order);
        $this->post(route('client.checkout.store'), ['request_key' => $key])->assertRedirect($confirmation)
            ->assertSessionHas(CheckoutSession::CART, [$this->bouquet->id => 1]);
        $this->post(route('client.checkout.store'), ['request_key' => $key])->assertRedirect($confirmation)
            ->assertSessionHas(CheckoutSession::SELECTION, []);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_price_change_requires_new_confirmation_and_preserves_the_cart(): void
    {
        $this->signIn($this->customer);
        $key = $this->review();
        DB::table('flower_prices')->where('flower_id', $this->rose->id)->update(['price_cents' => 6500]);

        $this->post(route('client.checkout.store'), ['request_key' => $key])
            ->assertRedirect(route('client.checkout'))
            ->assertSessionHasErrors(['review_total' => 'Your order total changed to ₱195.00. Review it and confirm again.'])
            ->assertSessionHas(CheckoutSession::CART, [$this->rose->id => 3, $this->bouquet->id => 1])
            ->assertSessionHas(CheckoutSession::SELECTION, [$this->rose->id]);
        $fresh = $this->get(route('client.checkout'))->assertOk()
            ->assertSeeTextInOrder(['Pickup total', '₱195.00', 'Place order']);
        $newKey = $fresh->viewData('reviewKey');
        $this->assertNotSame($key, $newKey);

        $this->post(route('client.checkout.store'), ['request_key' => $key])
            ->assertRedirect(route('client.checkout'))->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);

        $this->post(route('client.checkout.store'), ['request_key' => $newKey])->assertRedirect();
        $this->assertSame(19500, Order::sole()->total_cents);
    }

    public function test_stale_stock_identifies_the_flower_and_stale_selection_does_not_place_an_order(): void
    {
        $this->signIn($this->customer);
        $key = $this->review();
        DB::table('inventory_batches')->where('flower_id', $this->rose->id)->update(['remaining_quantity' => 2]);

        $this->post(route('client.checkout.store'), ['request_key' => $key])
            ->assertRedirect(route('client.checkout'))
            ->assertSessionHasErrors(['items' => 'Red Rose has 2 stems available. Update your cart and try again.'])
            ->assertSessionHas(CheckoutSession::CART, [$this->rose->id => 3, $this->bouquet->id => 1])
            ->assertSessionHas(CheckoutSession::SELECTION, [$this->rose->id]);
        $this->get(route('client.checkout'))->assertOk()->assertSeeText('Red Rose')->assertDontSeeText('Place order');
        $this->assertDatabaseCount('orders', 0);

        $this->postJson(route('client.cart.selection'), ['selected' => [$this->bouquet->id]])->assertNoContent();
        $bouquetKey = $this->review();
        $this->putJson(route('client.cart.update', $this->bouquet), ['quantity' => 2])->assertOk();
        $this->post(route('client.checkout.store'), ['request_key' => $bouquetKey])
            ->assertRedirect(route('client.checkout'))->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders', 0);

        $this->post(route('client.checkout.store'), ['request_key' => $this->review()])->assertRedirect()
            ->assertSessionHas(CheckoutSession::CART, [$this->rose->id => 3]);
        $this->assertSame(160000, Order::sole()->total_cents);
    }

    public function test_delivery_requires_address_and_reconfirmation_when_the_fee_changes(): void
    {
        $this->signIn($this->customer);
        config(['orders.delivery_fee' => '30.00']);
        $checkout = route('client.checkout', ['method' => 'delivery']);
        $key = $this->review('delivery');
        $this->post(route('client.checkout.store'), ['request_key' => $key, 'address' => ' '])
            ->assertRedirect($checkout)->assertSessionHasErrors('address');

        config(['orders.delivery_fee' => null]);
        $this->post(route('client.checkout.store'), ['request_key' => $key, 'address' => 'Sampaloc, Manila'])
            ->assertRedirect($checkout)->assertSessionHasErrors('method');

        config(['orders.delivery_fee' => '45.50']);
        $this->post(route('client.checkout.store'), ['request_key' => $key, 'address' => 'Sampaloc, Manila'])
            ->assertRedirect($checkout)
            ->assertSessionHasErrors(['review_total' => 'Your order total changed to ₱195.50. Review it and confirm again.'])
            ->assertSessionHas(CheckoutSession::CART.'.'.$this->rose->id, 3);
        $this->assertDatabaseCount('orders', 0);
        $this->get($checkout)->assertOk()->assertSeeTextInOrder(['Delivery fee', '₱45.50', 'Delivery total', '₱195.50', 'Delivery address'])
            ->assertSeeText('Sampaloc, Manila');

        $this->post(route('client.checkout.store'), ['request_key' => $this->review('delivery'), 'address' => 'New address, Manila'])
            ->assertRedirect()
            ->assertSessionHas(CheckoutSession::CART, [$this->bouquet->id => 1]);
        $order = Order::sole();
        $this->assertSame('New address, Manila', $order->address);
        $this->assertSame(4550, $order->fee_cents);
        $this->get(route('client.orders.confirmation', $order))->assertOk()
            ->assertSeeTextInOrder(['Delivery', 'New address, Manila', 'Delivery fee', '₱45.50', 'Order total', '₱195.50']);
    }

    public function test_only_an_eligible_customer_can_submit_checkout(): void
    {
        $this->get(route('client.checkout'))->assertOk()->assertSeeText('Sign in to continue')->assertDontSeeText('Place order');
        $this->post(route('client.checkout.store'))->assertRedirect(route('client.login'));

        $staff = User::factory()->create(['role' => User::ROLE_SUPPLIER, 'is_active' => true, 'must_change_password' => false]);
        $this->signIn($staff);
        $this->post(route('client.checkout.store'))->assertForbidden();

        $unlinked = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true, 'must_change_password' => false]);
        $this->signIn($unlinked);
        $this->post(route('client.checkout.store'))->assertRedirect(route('client.login'));

        $this->customer->forceFill(['must_change_password' => true])->save();
        $this->signIn($this->customer);
        $this->post(route('client.checkout.store'))->assertRedirect(route('password.change'));

        $this->customer->forceFill(['is_active' => false])->save();
        $this->signIn($this->customer);
        $this->post(route('client.checkout.store'))->assertRedirect(route('client.login'));
        $this->assertDatabaseCount('orders', 0);
    }

    private function review(?string $method = null): string
    {
        $url = route('client.checkout', $method === null ? [] : ['method' => $method]);
        $response = $this->get($url)->assertOk()->assertSeeText('Place order');
        $key = $response->viewData('reviewKey');
        $response->assertSee('name="request_key" value="'.$key.'"', false);

        return $key;
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession([AccountSession::KEY => AccountSession::signature($user)]);
    }

    private function customer(): User
    {
        $identity = User::factory()->make();
        $client = app(ClientService::class)->store([
            'name' => $identity->name, 'email' => $identity->email, 'password' => 'Temporary-flower-password!',
            'phone' => '09171234567', 'address' => 'Sampaloc, Manila', 'status' => 'Active',
        ]);
        $client->user->forceFill(['must_change_password' => false])->save();

        return $client->user;
    }

    private function stockProduct(string $name, string $type, int $price): Flower
    {
        $flower = Flower::create([
            'code' => str_replace(' ', '-', $name), 'name' => $name, 'product_type' => $type,
            'category' => 'Roses', 'status' => 'Active', 'description' => $name,
        ]);
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => $price]);
        DB::table('inventory_batches')->insert([
            'flower_id' => $flower->id, 'code' => 'BAT-'.$flower->id,
            'received_date' => '2026-11-01', 'harvest_date' => '2026-11-01', 'received_quantity' => 5,
            'receiving_unit' => $type, 'conversion_factor' => 1, 'initial_quantity' => 5, 'remaining_quantity' => 5,
        ]);

        return $flower;
    }
}
