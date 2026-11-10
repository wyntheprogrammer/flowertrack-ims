<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Delivery;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentRefund;
use App\Models\SalesRecord;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ReportService;
use App\Services\SalesPaymentService;
use App\Support\AccountSession;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReportBackendTest extends TestCase
{
    private ReportService $reports;
    private InventoryService $inventory;
    private OrderService $orders;
    private SalesPaymentService $sales;
    private User $admin;
    private User $supplier;
    private bool $testConnectionConfigured = false;
    private string $previousTimezone;

    /** All schemas are created only in a named ephemeral database, never the owner's DB. */
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Isolated report tests require pdo_sqlite.');
        }
        config([
            'database.connections.report_backend_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'report_backend_test',
            'orders.delivery_fee' => null,
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        $this->testConnectionConfigured = true;
        DB::purge('report_backend_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email')->unique();
            $table->string('password'); $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('email_verified_at')->nullable(); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('flowers', function (Blueprint $table) {
            $table->id(); $table->string('code', 30)->unique(); $table->string('name', 150);
            $table->enum('product_type', ['Stem', 'Bouquet']); $table->string('category', 100);
            $table->unsignedInteger('minimum_stock'); $table->text('description');
            $table->string('image_path')->nullable(); $table->enum('status', ['Active', 'Inactive']); $table->timestamps();
        });
        foreach (['create_clients_table', 'create_inventory_tables', 'create_order_tables', 'create_delivery_tables', 'create_finance_tables'] as $name) {
            $files = glob(database_path('migrations/*_'.$name.'.php')) ?: [];
            $this->assertCount(1, $files, 'Expected one '.$name.' migration.');
            (require $files[0])->up();
        }
        $this->admin = $this->user('admin', User::ROLE_ADMIN);
        $this->supplier = $this->user('supplier', User::ROLE_SUPPLIER);
        $this->reports = app(ReportService::class);
        $this->inventory = app(InventoryService::class);
        $this->orders = app(OrderService::class);
        $this->sales = app(SalesPaymentService::class);
    }

    /** Disconnect only the ephemeral named connection. */
    protected function tearDown(): void
    {
        if ($this->testConnectionConfigured) DB::disconnect('report_backend_test');
        if (isset($this->previousTimezone)) date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    /** Empty databases still return every report source without invented rows or schema writes. */
    public function test_empty_dataset_contains_all_seven_real_report_sources(): void
    {
        $before = $this->databaseSnapshot();
        $result = $this->reports->data($this->admin);
        $keys = array_keys($result['data']);
        sort($keys);
        $this->assertSame(['clients', 'delivery', 'inventory', 'orders', 'payments', 'sales', 'spoilage'], $keys);
        foreach ($result['data'] as $rows) $this->assertSame([], $rows);
        $this->assertSame('Asia/Manila', $result['timezone']);
        $this->assertStringEndsWith('+08:00', $result['generatedAt']);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    /** Every report reads the same actual module records and never mutates them. */
    public function test_all_seven_sources_reconcile_with_real_module_records_without_writes(): void
    {
        [$client, $stem, $bouquet] = $this->fixture();
        $completed = $this->complete($this->newOrder($client, $stem, 2));
        $payment = $this->pay($completed);
        $this->refund($payment, '5.00');
        $deliveryOrder = $this->newOrder($client, $bouquet, 1, 'Delivery');
        foreach (['Confirmed', 'Processing', 'Ready for Delivery'] as $status) $this->advance($deliveryOrder, $status);
        $delivery = Delivery::where('order_id', $deliveryOrder->id)->firstOrFail();
        $this->inventory->stockOut([
            'request_key' => (string) Str::uuid(), 'flower_id' => $stem->id,
            'quantity' => 3, 'reason' => 'Spoilage', 'remarks' => 'Three inspected damaged stems.',
        ], $this->supplier);
        $before = $this->databaseSnapshot();
        $data = $this->reports->data($this->admin)['data'];
        foreach (['sales' => 1, 'payments' => 1, 'inventory' => 2, 'orders' => 2,
            'delivery' => 1, 'clients' => 1, 'spoilage' => 1] as $key => $count) {
            $this->assertCount($count, $data[$key], $key);
        }
        $sale = $data['sales'][0];
        $this->assertSame(2500, $sale['gross']);
        $this->assertSame(500, $sale['refund']);
        $this->assertSame(2000, $sale['net']);
        $receipt = $data['payments'][0];
        $this->assertSame(2500, $receipt['amount']);
        $this->assertSame(2500, $receipt['verified']);
        $this->assertSame(500, $receipt['refund']);
        $this->assertSame(2000, $receipt['net']);
        $stock = $this->find($data['inventory'], 'code', $stem->code);
        $this->assertSame(15, $stock['onHand']);
        $this->assertSame('Stem', $stock['unit']);
        $arrangements = $this->find($data['inventory'], 'code', $bouquet->code);
        $this->assertSame(5, $arrangements['onHand']);
        $this->assertSame(1, $arrangements['reserved']);
        $this->assertSame(4, $arrangements['available']);
        $this->assertSame('Bouquet', $arrangements['unit']);
        $this->assertSame($delivery->code, $data['delivery'][0]['id']);
        $this->assertSame($deliveryOrder->code, $data['delivery'][0]['order']);
        $this->assertSame(2000, $data['clients'][0]['purchases']);
        $this->assertSame(3, $data['spoilage'][0]['quantity']);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    /** Equal display names cannot merge distinct client identities or historical order snapshots. */
    public function test_clients_are_joined_by_identity_and_use_current_profile_fields(): void
    {
        [, $stem] = $this->fixture();
        $first = $this->newClient('same-a', 'Same Name');
        $second = $this->newClient('same-b', 'Same Name');
        $this->complete($this->newOrder($first, $stem, 2));
        $this->newOrder($second, $stem, 1);
        $first->user->name = 'Current Renamed Client';
        $first->user->save();
        $data = $this->reports->data($this->admin)['data'];
        $a = $this->find($data['clients'], 'id', $first->code);
        $b = $this->find($data['clients'], 'id', $second->code);
        $this->assertSame('Current Renamed Client', $a['name']);
        $this->assertSame('Same Name', $b['name']);
        $this->assertSame(1, $a['orders']);
        $this->assertSame(1, $b['orders']);
        $this->assertSame(2500, $a['purchases']);
        $this->assertSame(0, $b['purchases']);
        $this->assertSame('Same Name', $data['sales'][0]['client']);
    }

    /** Historical completed revenue is based on immutable commercial snapshots, not today's price. */
    public function test_current_selling_price_changes_do_not_reprice_reports(): void
    {
        [$client, $stem] = $this->fixture();
        $order = $this->complete($this->newOrder($client, $stem, 2));
        $flower = $this->find($this->sales->data($this->supplier)['flowers'], 'dbId', $stem->id);
        $this->sales->price($stem, ['request_key' => (string) Str::uuid(), 'version' => $flower['version'],
            'amount' => '99.99'], $this->supplier);
        $data = $this->reports->data($this->admin)['data'];
        $this->assertSame(9999, FlowerPrice::where('flower_id', $stem->id)->firstOrFail()->price_cents);
        $this->assertSame(1250, $order->items()->firstOrFail()->unit_price_cents);
        $this->assertSame(2500, $data['sales'][0]['gross']);
        $this->assertSame(2500, $this->find($data['orders'], 'id', $order->code)['total']);
    }

    /** Pending, verified and refunded collection amounts remain independent of order fulfillment. */
    public function test_payment_states_and_refunds_do_not_change_reported_physical_stock(): void
    {
        [$client, $stem] = $this->fixture();
        $order = $this->newOrder($client, $stem, 2);
        $payment = $this->pay($order, false);
        $pending = $this->reports->data($this->admin)['data'];
        $this->assertSame(2500, $pending['payments'][0]['amount']);
        $this->assertSame(0, $pending['payments'][0]['verified']);
        $this->assertSame(0, $pending['payments'][0]['net']);
        $this->assertSame([], $pending['sales']);
        $this->confirmPayment($payment);
        $verified = $this->reports->data($this->admin)['data'];
        $this->assertSame(2500, $verified['payments'][0]['verified']);
        $this->assertSame(2500, $verified['payments'][0]['net']);
        $this->refund($payment, '5.00');
        $partial = $this->reports->data($this->admin)['data'];
        $this->assertSame('Refunded', $partial['payments'][0]['status']);
        $this->assertSame(500, $partial['payments'][0]['refund']);
        $this->assertSame(2000, $partial['payments'][0]['net']);
        $this->refund($payment, '20.00');
        $full = $this->reports->data($this->admin)['data'];
        $this->assertSame(2500, $full['payments'][0]['verified']);
        $this->assertSame(2500, $full['payments'][0]['refund']);
        $this->assertSame(0, $full['payments'][0]['net']);
        foreach ([$pending, $verified, $partial, $full] as $data) {
            $this->assertSame(20, $this->find($data['inventory'], 'code', $stem->code)['onHand']);
            $this->assertSame([], $data['sales']);
        }
        $this->assertSame('Pending', $order->fresh()->status);
    }

    /** Only actual negative spoilage issues count, never expiry flags or other stock uses. */
    public function test_spoilage_reports_only_qualifying_movement_events(): void
    {
        [$client, $stem, $bouquet] = $this->fixture();
        InventoryBatch::where('flower_id', $stem->id)->update(['expiry_date' => '2020-01-01']);
        $this->inventory->stockOut([
            'request_key' => (string) Str::uuid(), 'flower_id' => $stem->id,
            'quantity' => 2, 'reason' => 'Other use', 'remarks' => 'Display arrangement.',
        ], $this->supplier);
        $this->complete($this->newOrder($client, $stem, 1));
        $this->assertSame([], $this->reports->data($this->admin)['data']['spoilage']);
        foreach ([[$stem, 3], [$bouquet, 5]] as [$item, $quantity]) {
            $this->inventory->stockOut([
                'request_key' => (string) Str::uuid(), 'flower_id' => $item->id,
                'quantity' => $quantity, 'reason' => 'Spoilage', 'remarks' => 'Inspected actual spoilage.',
            ], $this->supplier);
        }
        $data = $this->reports->data($this->admin)['data'];
        $this->assertCount(2, $data['spoilage']);
        $stemEvent = $this->find($data['spoilage'], 'unit', 'Stem');
        $bouquetEvent = $this->find($data['spoilage'], 'unit', 'Bouquet');
        $this->assertSame(3, $stemEvent['quantity']);
        $this->assertSame(5, $bouquetEvent['quantity']);
        $this->assertSame(14, $this->find($data['inventory'], 'code', $stem->code)['onHand']);
        $this->assertSame(0, $this->find($data['inventory'], 'code', $bouquet->code)['onHand']);
        $this->assertTrue(InventoryBatch::where('flower_id', $bouquet->id)->firstOrFail()->spoiled);
    }

    /** Oversubscribed stock remains visible instead of being silently clamped to zero. */
    public function test_inventory_preserves_negative_availability_and_ignores_released_holds(): void
    {
        [, $stem, $bouquet] = $this->fixture();
        InventoryReservation::create(['flower_id' => $stem->id, 'reference' => 'TEST-OVER', 'quantity' => 21, 'status' => 'Active']);
        InventoryReservation::create(['flower_id' => $stem->id, 'reference' => 'TEST-RELEASED', 'quantity' => 10, 'status' => 'Released']);
        $rows = $this->reports->data($this->admin)['data']['inventory'];
        $stock = $this->find($rows, 'code', $stem->code);
        $this->assertSame(20, $stock['onHand']);
        $this->assertSame(21, $stock['reserved']);
        $this->assertSame(-1, $stock['available']);
        $this->assertSame('Out of Stock', $stock['status']);
        $this->assertSame(5, $this->find($rows, 'code', $bouquet->code)['available']);
    }

    /** Refunds restate the original completion day and do not substitute the refund day. */
    public function test_manila_dates_and_original_sale_refund_attribution(): void
    {
        [$client, $stem] = $this->fixture();
        $order = $this->complete($this->newOrder($client, $stem, 2));
        $payment = $this->pay($order);
        $this->refund($payment, '5.00');
        $sale = SalesRecord::where('order_id', $order->id)->firstOrFail();
        $sale->completed_at = CarbonImmutable::parse('2026-11-06T16:00:00+00:00');
        $sale->save();
        OrderPayment::where('id', $payment->id)->update(['created_at' => '2026-11-06 15:59:59']);
        PaymentRefund::query()->update(['created_at' => '2026-11-09 12:00:00']);
        $data = $this->reports->data($this->admin)['data'];
        $this->assertSame('2026-11-07', $data['sales'][0]['date']);
        $this->assertSame(500, $data['sales'][0]['refund']);
        $this->assertSame(2000, $data['sales'][0]['net']);
        $this->assertSame('2026-11-06', $data['payments'][0]['date']);
    }

    /** The report data route and service both enforce Admin-only operational access. */
    public function test_report_access_is_admin_only_and_responses_exclude_credentials(): void
    {
        foreach ([User::ROLE_SUPPLIER, User::ROLE_DELIVERY, User::ROLE_CLIENT] as $role) {
            $user = $this->user('blocked-'.$role, $role);
            try {
                $this->reports->data($user);
                $this->fail('Non-Admin report access must be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->actingAs($user)->withSession([AccountSession::KEY => AccountSession::signature($user)])
                ->getJson('/supplier/reports/data')->assertForbidden();
        }
        [$client] = $this->fixture();
        $this->actingAs($this->admin)->withSession([AccountSession::KEY => AccountSession::signature($this->admin)])
            ->getJson('/supplier/reports/data')->assertOk()
            ->assertJsonPath('timezone', 'Asia/Manila')
            ->assertJsonMissingPath('data.clients.0.password')
            ->assertJsonMissingPath('data.clients.0.remember_token');
        $inactive = $this->user('inactive-admin', User::ROLE_ADMIN);
        $inactive->is_active = false;
        $this->assertBlocked($inactive);
        $temporary = $this->user('temporary-admin', User::ROLE_ADMIN);
        $temporary->must_change_password = true;
        $this->assertBlocked($temporary);
    }

    private function assertBlocked(User $user): void
    {
        try { $this->reports->data($user); $this->fail('Access must be rejected.'); }
        catch (HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
    }

    private function fixture(): array
    {
        $client = $this->newClient('base-client', 'Report Client');
        $items = [];
        foreach ([['FLW-001', 'Stem', 1250, 20], ['FLW-002', 'Bouquet', 125000, 5]] as [$code, $type, $price, $quantity]) {
            $flower = Flower::create(['code' => $code, 'name' => 'Report '.$type,
                'product_type' => $type, 'category' => 'Roses', 'minimum_stock' => 3,
                'description' => 'Report fixture.', 'status' => 'Active']);
            FlowerPrice::create(['flower_id' => $flower->id, 'price_cents' => $price]);
            $this->inventory->stockIn([
                'request_key' => (string) Str::uuid(), 'flower_id' => $flower->id,
                'quantity' => $quantity, 'unit' => $type, 'received' => '2026-01-02',
                'harvest' => '2026-01-01', 'expiry' => null, 'remarks' => 'Report fixture receipt.',
            ], $this->supplier);
            $items[] = $flower;
        }
        return [$client, ...$items];
    }

    private function newClient(string $label, string $name): Client
    {
        $user = $this->user($label, User::ROLE_CLIENT);
        $user->name = $name; $user->save();
        return Client::create(['code' => 'CLI-'.$user->id, 'user_id' => $user->id,
            'phone' => '09170000000', 'address' => 'Report test address']);
    }

    private function user(string $label, string $role): User
    {
        return User::forceCreate(['name' => 'Report '.$label, 'email' => $label.'@example.test',
            'password' => 'Temporary-report-password!', 'role' => $role,
            'is_active' => true, 'must_change_password' => false]);
    }

    private function newOrder(Client $client, Flower $item, int $quantity, string $method = 'Customer Pickup'): Order
    {
        config(['orders.delivery_fee' => '150.00']);
        return $this->orders->store(['request_key' => (string) Str::uuid(),
            'client_id' => $client->id, 'method' => $method,
            'address' => $method === 'Delivery' ? 'Report delivery address' : null,
            'notes' => null, 'items' => [['flower_id' => $item->id, 'quantity' => $quantity]],
        ], $this->supplier);
    }

    private function advance(Order $order, string $status): Order
    {
        return $this->orders->action($order, ['status' => $status,
            'version' => $this->orders->row($order->fresh(), $this->admin)['version'],
            'collected' => $status === 'Completed'], $this->admin);
    }

    private function complete(Order $order): Order
    {
        foreach (['Confirmed', 'Processing', 'Ready for Pickup', 'Completed'] as $status) {
            $this->advance($order, $status);
        }
        return $order->fresh();
    }

    private function pay(Order $order, bool $confirm = true): OrderPayment
    {
        $row = $this->find($this->sales->data($this->supplier)['orders'], 'dbId', $order->id);
        $this->sales->recordPayment(['request_key' => (string) Str::uuid(),
            'order_id' => $order->id, 'version' => $row['version'], 'method' => 'Cash',
            'amount' => Money::format((int) $order->total_cents)], $this->supplier);
        $payment = OrderPayment::where('order_id', $order->id)->firstOrFail();
        if ($confirm) $this->confirmPayment($payment);
        return $payment->fresh();
    }

    private function confirmPayment(OrderPayment $payment): void
    {
        $row = $this->find($this->sales->data($this->supplier)['payments'], 'dbId', $payment->id);
        $this->sales->confirm($payment, ['request_key' => (string) Str::uuid(),
            'version' => $row['version'], 'reason' => 'Actual collection verified.'], $this->supplier);
    }

    private function refund(OrderPayment $payment, string $amount): void
    {
        $row = $this->find($this->sales->data($this->admin)['payments'], 'dbId', $payment->id);
        $this->sales->refund($payment, ['request_key' => (string) Str::uuid(),
            'version' => $row['version'], 'amount' => $amount,
            'reason' => 'Admin verified the actual refund.'], $this->admin);
    }

    private function find(array $rows, string $key, mixed $value): array
    {
        $row = collect($rows)->first(fn ($row) => $row[$key] === $value);
        $this->assertNotNull($row, 'Report row not found: '.$key);
        return $row;
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'clients', 'flowers', 'flower_prices', 'inventory_batches',
            'inventory_movements', 'inventory_operations', 'inventory_unit_settings', 'inventory_reservations',
            'orders', 'order_items', 'order_histories', 'order_payments', 'sales_records',
            'deliveries', 'delivery_histories', 'finance_operations', 'payment_refunds'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }
}