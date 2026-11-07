<?php

namespace Tests\Feature;

use App\Http\Controllers\Supplier\ClientController;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Client;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\SalesRecord;
use App\Models\User;
use App\Services\ClientService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderBackendTest extends TestCase
{
    private OrderService $orders;
    private InventoryService $inventory;
    private User $admin;
    private User $supplier;
    private Client $client;
    private Flower $stem;
    private Flower $bouquet;
    private bool $testConnectionConfigured = false;

    /** Build only a named in-memory database; never refresh or migrate the owner's DB. */
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Isolated order tests require pdo_sqlite.');
        }
        config([
            'database.connections.order_backend_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'order_backend_test',
            'orders.delivery_fee' => null,
        ]);
        $this->testConnectionConfigured = true;
        DB::purge('order_backend_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('flowers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->enum('product_type', ['Stem', 'Bouquet']);
            $table->string('category', 100);
            $table->unsignedInteger('minimum_stock');
            $table->text('description');
            $table->string('image_path')->nullable();
            $table->enum('status', ['Active', 'Inactive']);
            $table->timestamps();
        });
        foreach ([
            'create_clients_table',
            'create_inventory_tables',
            'create_order_tables',
            'create_delivery_tables',
        ] as $name) {
            $files = glob(database_path('migrations/*_' . $name . '.php')) ?: [];
            $this->assertCount(1, $files, 'Expected one ' . $name . ' migration.');
            (require $files[0])->up();
        }
        $this->admin = $this->user('admin', User::ROLE_ADMIN);
        $this->supplier = $this->user('supplier', User::ROLE_SUPPLIER);
        $account = $this->user('client', User::ROLE_CLIENT);
        $this->client = Client::create([
            'code' => 'CLI-001',
            'user_id' => $account->id,
            'phone' => '09123456789',
            'address' => 'Test delivery address',
        ]);
        $this->stem = $this->item('FLW-001', 'Stem', 1250);
        $this->bouquet = $this->item('FLW-002', 'Bouquet', 125000);
        $this->inventory = app(InventoryService::class);
        $this->orders = app(OrderService::class);
        $this->receive($this->stem, 20);
        $this->receive($this->bouquet, 5);
    }

    /** Disconnect only the ephemeral named connection. */
    protected function tearDown(): void
    {
        if ($this->testConnectionConfigured)
            DB::disconnect('order_backend_test');
        parent::tearDown();
    }

    /** Duplicate requested lines aggregate before snapshots and creation UUID replay is safe. */
    public function test_creation_aggregates_lines_without_reserving_and_replays_matching_uuid(): void
    {
        $input = $this->input([
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 2], ['flower_id' => $this->stem->id, 'quantity' => 3]],
        ]);
        $first = $this->orders->store($input, $this->supplier);
        $retry = $this->orders->store($input, $this->supplier);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, Order::count());
        $this->assertSame('Pending', $first->status);
        $this->assertSame(1, $first->items()->count());
        $this->assertSame(5, $first->items()->first()->quantity);
        $this->assertSame(6250, $first->total_cents);
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(20, $this->remaining($this->stem));
        $input['items'][0]['quantity'] = 4;
        $this->rejected(fn() => $this->orders->store($input, $this->supplier), 'request_key');
        $this->assertSame(1, Order::count());
        // Enough stock exists: this rejection must enforce the aggregated input limit.
        $this->receive($this->stem, 120000);
        $this->rejected(fn() => $this->orders->store($this->input([
            'items' => [
                ['flower_id' => $this->stem->id, 'quantity' => 60000],
                ['flower_id' => $this->stem->id, 'quantity' => 60000],
            ]
        ]), $this->supplier), 'items');
        $this->assertSame(1, Order::count());
    }

    /** Admin review alone can confirm and place a hold; cancelling releases it without stock-out. */
    public function test_admin_confirmation_and_cancellation_manage_holds_without_deduction(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        try {
            $this->action($order, 'Confirmed', $this->supplier);
            $this->fail('Supplier must not approve an order.');
        } catch (AuthorizationException | HttpException $exception) {
            if ($exception instanceof HttpException)
                $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('Pending', $order->fresh()->status);
        $this->action($order, 'Confirmed', $this->admin);
        $this->assertSame(4, (int) InventoryReservation::where('status', 'Active')->sum('quantity'));
        $this->assertSame(20, $this->remaining($this->stem));
        $this->action($order, 'Cancelled', $this->supplier, ['reason' => 'Client requested cancellation.']);
        $this->assertSame(0, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame('Released', InventoryReservation::first()->status);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(0, InventoryMovement::where('type', 'Stock-Out')->count());
    }

    /** Confirmation rechecks availability and rolls back a mixed order's partial holds. */
    public function test_insufficient_confirmed_stock_rolls_back_all_new_reservations(): void
    {
        $first = $this->orders->store($this->input(['items' => [['flower_id' => $this->stem->id, 'quantity' => 18]]]), $this->supplier);
        $second = $this->orders->store($this->input([
            'items' => [
                ['flower_id' => $this->bouquet->id, 'quantity' => 1],
                ['flower_id' => $this->stem->id, 'quantity' => 5],
            ]
        ]), $this->supplier);
        $this->action($first, 'Confirmed', $this->admin);
        $this->rejected(fn() => $this->action($second, 'Confirmed', $this->admin));
        $this->assertSame('Pending', $second->fresh()->status);
        $this->assertSame(18, (int) InventoryReservation::where('status', 'Active')->sum('quantity'));
        $this->assertSame(0, InventoryReservation::where('reference', $second->code)->count());
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(5, $this->remaining($this->bouquet));
    }

    /** Existing item prices remain immutable and any payment row blocks profile edits. */
    public function test_edits_preserve_line_price_snapshots_and_block_recorded_payments(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        FlowerPrice::where('flower_id', $this->stem->id)->update(['price_cents' => 9900]);
        $updated = $this->orders->update($order, $this->edit($order, [
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 3]],
        ]), $this->supplier);
        $this->assertSame(1250, $updated->items()->first()->unit_price_cents);
        $this->assertSame(3750, $updated->total_cents);
        OrderPayment::create([
            'order_id' => $order->id,
            'code' => 'PAY-001',
            'method' => 'Cash',
            'amount_cents' => 3750,
            'status' => 'Pending',
        ]);
        $this->rejected(fn() => $this->orders->update($order, $this->edit($order), $this->supplier));
        $this->assertSame(3, $order->items()->first()->quantity);
    }

    /** Pickup collection consumes both item units once and creates one immutable gross sale. */
    public function test_pickup_completion_is_once_only_without_inventing_a_payment_gate(): void
    {
        $order = $this->orders->store($this->input([
            'items' => [
                ['flower_id' => $this->stem->id, 'quantity' => 4],
                ['flower_id' => $this->bouquet->id, 'quantity' => 2],
            ]
        ]), $this->supplier);
        $this->action($order, 'Confirmed', $this->admin);
        $this->action($order, 'Processing', $this->supplier);
        $this->action($order, 'Ready for Pickup', $this->supplier);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(5, $this->remaining($this->bouquet));
        $payload = ['status' => 'Completed', 'collected' => true, 'version' => $this->orders->row($order->fresh(), $this->supplier)['version']];
        $this->orders->action($order, $payload, $this->supplier);
        $this->orders->action($order, $payload, $this->supplier);
        $this->assertSame('Completed', $order->fresh()->status);
        $this->assertTrue($order->fresh()->stocked_out);
        $this->assertSame(16, $this->remaining($this->stem));
        $this->assertSame(3, $this->remaining($this->bouquet));
        $this->assertSame(0, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(2, InventoryReservation::where('status', 'Consumed')->count());
        $this->assertSame(1, SalesRecord::count());
        $this->assertSame(255000, SalesRecord::first()->total_cents);
        $this->assertSame(0, OrderPayment::count());
        $this->assertSame('Unpaid', $this->orders->row($order->fresh(), $this->supplier)['payment']);
        $this->assertSame(2, InventoryMovement::where('type', 'Stock-Out')->count());
        $profile = app(ClientController::class)->show($this->client, app(ClientService::class))->getData(true);
        $this->assertTrue($profile['order_history_available']);
        $this->assertSame(1, $profile['summary']['total_orders']);
        $this->assertSame(1, $profile['summary']['completed_orders']);
        $this->assertSame(0, $profile['summary']['ongoing_orders']);
        $this->assertSame(255000, $profile['summary']['completed_purchases_cents']);
        $this->assertCount(1, $profile['orders']);
        $this->assertSame('Completed', $profile['orders'][0]['status']);
        $this->assertSame('Unpaid', $profile['orders'][0]['payment']);
    }

    /** A later-item shortage rolls back earlier fulfillment and every outer completion write. */
    public function test_mixed_pickup_shortage_rolls_back_all_completion_side_effects(): void
    {
        $order = $this->orders->store($this->input([
            'items' => [
                ['flower_id' => $this->stem->id, 'quantity' => 4],
                ['flower_id' => $this->bouquet->id, 'quantity' => 2],
            ]
        ]), $this->supplier);
        $this->action($order, 'Confirmed', $this->admin);
        $this->action($order, 'Processing', $this->supplier);
        $this->action($order, 'Ready for Pickup', $this->supplier);
        // Deliberately simulate corrupt/out-of-band stock to exercise outer rollback.
        InventoryBatch::where('flower_id', $this->bouquet->id)->update(['remaining_quantity' => 1]);
        $movements = InventoryMovement::count();
        $operations = InventoryOperation::count();
        $this->rejected(fn() => $this->action($order, 'Completed', $this->supplier, ['collected' => true]));
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(1, $this->remaining($this->bouquet));
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(0, InventoryReservation::where('status', 'Consumed')->count());
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame($operations, InventoryOperation::count());
        $this->assertSame('Ready for Pickup', $order->fresh()->status);
        $this->assertFalse($order->fresh()->stocked_out);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame(0, SalesRecord::count());
    }

    /** Delivery needs an approved configured fee and cannot be completed in Order Management. */
    public function test_delivery_fee_is_explicit_and_order_actions_stop_at_ready_for_delivery(): void
    {
        $input = $this->input(['method' => 'Delivery', 'address' => 'Test delivery address']);
        $this->rejected(fn() => $this->orders->store($input, $this->supplier));
        $this->assertSame(0, Order::count());
        config(['orders.delivery_fee' => '75.25']);
        $order = $this->orders->store($input, $this->supplier);
        $this->assertSame(7525, $order->fee_cents);
        config(['orders.delivery_fee' => null]);
        $edited = $this->orders->update($order, $this->edit($order, ['method' => 'Delivery', 'address' => 'Updated address']), $this->supplier);
        $this->assertSame(7525, $edited->fee_cents);
        $this->action($order, 'Confirmed', $this->admin);
        $this->action($order, 'Processing', $this->supplier);
        $this->action($order, 'Ready for Delivery', $this->supplier);
        $this->rejected(fn() => $this->action($order, 'Completed', $this->supplier, ['collected' => true]));
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(0, SalesRecord::count());
    }

    /** Stale edits and increasing inactive existing catalog lines cannot overwrite valid state. */
    public function test_stale_version_and_inactive_line_increase_are_rejected(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $stale = $this->edit($order);
        $this->orders->update($order, $this->edit($order, ['notes' => 'Changed once.']), $this->supplier);
        $this->rejected(fn() => $this->orders->update($order, $stale, $this->supplier), 'version');
        $this->stem->status = 'Inactive';
        $this->stem->save();
        $this->rejected(fn() => $this->orders->update($order, $this->edit($order, [
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 5]],
        ]), $this->supplier));
        $updated = $this->orders->update($order, $this->edit($order, [
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 2]],
        ]), $this->supplier);
        $this->assertSame(2, $updated->items()->first()->quantity);
    }

    /** Valid inputs pass while nested price tampering and top-level state assignments fail. */
    public function test_request_validation_protects_prices_finances_and_workflow_state(): void
    {
        $rules = (new StoreOrderRequest())->rules();
        $payload = $this->input();
        $this->assertTrue(Validator::make($payload, $rules)->passes());
        foreach (['price_cents', 'unit_price_cents', 'unitPriceCents', 'subtotal_cents'] as $field) {
            $forged = $payload;
            $forged['items'][0][$field] = 1;
            $validator = Validator::make($forged, $rules);
            $this->assertTrue($validator->fails(), 'Nested ' . $field);
            $this->assertArrayHasKey('items.0', $validator->errors()->toArray());
        }
        foreach ([
            'price_cents' => 1,
            'total_cents' => 1,
            'deliveryFeeCents' => 1,
            'status' => 'Completed',
            'payment' => 'Paid',
            'paidCents' => 1,
            'stocked_out' => true,
            'remaining_quantity' => 999,
            'reserved' => 999
        ] as $field => $value) {
            $validator = Validator::make([...$payload, $field => $value], $rules);
            $this->assertTrue($validator->fails(), $field);
            $this->assertArrayHasKey($field, $validator->errors()->toArray());
        }
    }

    /** Monetary arithmetic uses exact bounded cents, never floating-point rounding. */
    public function test_money_parses_and_formats_exact_decimal_cents(): void
    {
        $this->assertSame(1001, Money::parse('10.01'));
        $this->assertSame(1050, Money::parse('10.5'));
        $this->assertSame(0, Money::parse('0'));
        $this->assertSame('10.01', Money::format(1001));
        foreach (['1.001', '-1', '1e2', '10000000000.00'] as $invalid) {
            try {
                Money::parse($invalid);
                $this->fail('Invalid monetary input must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    /** Create one isolated account with the existing User hashing/casting behavior. */
    private function user(string $label, string $role): User
    {
        return User::forceCreate([
            'name' => 'Test ' . $label,
            'email' => $label . '@example.test',
            'password' => 'Temporary-test-password!',
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /** Define a catalog item and its current Sales-owned price. */
    private function item(string $code, string $type, int $price): Flower
    {
        $flower = Flower::forceCreate([
            'code' => $code,
            'name' => 'Test ' . $type,
            'product_type' => $type,
            'category' => 'Roses',
            'minimum_stock' => 1,
            'description' => 'Test item.',
            'status' => 'Active',
        ]);
        FlowerPrice::create(['flower_id' => $flower->id, 'price_cents' => $price]);

        return $flower;
    }

    /** Receive real test stock through the shared Inventory service. */
    private function receive(Flower $flower, int $quantity): void
    {
        $this->inventory->stockIn([
            'request_key' => (string) Str::uuid(),
            'flower_id' => $flower->id,
            'quantity' => $quantity,
            'unit' => $flower->product_type,
            'received' => '2026-01-02',
            'harvest' => '2026-01-01',
            'expiry' => null,
            'remarks' => 'Test receipt.',
        ], $this->admin);
    }

    /** Read physical stock directly from batches, independently of reservation logic. */
    private function remaining(Flower $flower): int
    {
        return (int) InventoryBatch::where('flower_id', $flower->id)
            ->sum('remaining_quantity');
    }

    /** Supply a valid creation payload with a stable unique request identity. */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'method' => 'Customer Pickup',
            'address' => null,
            'notes' => null,
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 4]],
        ], $overrides);
    }

    /** Build an optimistic editable payload using the current version. */
    private function edit(Order $order, array $overrides = []): array
    {
        $current = $order->fresh()->load('items');

        return array_replace([
            'client_id' => $current->client_id,
            'method' => $current->method,
            'address' => $current->address,
            'notes' => $current->notes,
            'items' => $current->items->map(fn($item) => ['flower_id' => $item->flower_id, 'quantity' => $item->quantity])->all(),
            'version' => $this->orders->row($current, $this->supplier)['version'],
        ], $overrides);
    }

    /** Apply an explicit desired state through the same API used by the controller. */
    private function action(Order $order, string $status, User $actor, array $overrides = []): Order
    {
        return $this->orders->action($order, array_replace([
            'status' => $status,
            'version' => $this->orders->row($order->fresh(), $actor)['version'],
        ], $overrides), $actor);
    }

    /** Assert a domain rejection while permitting subsequent atomicity assertions. */
    private function rejected(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Expected order validation to reject this operation.');
        } catch (ValidationException $exception) {
            if ($field !== null)
                $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
