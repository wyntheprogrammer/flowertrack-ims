<?php

namespace Tests\Feature;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Client;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\FinanceOperation;
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
use App\Services\SalesPaymentService;
use App\Support\Money;
use App\Support\AccountSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesBackendTest extends TestCase
{
    private OrderService $orders;
    private InventoryService $inventory;
    private SalesPaymentService $sales;
    private User $admin;
    private User $supplier;
    private Client $client;
    private Flower $stem;
    private Flower $bouquet;
    private bool $testConnectionConfigured = false;

    /** Isolate every schema operation in a named memory-only SQLite connection. */
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Isolated sales tests require pdo_sqlite.');
        }
        config([
            'database.connections.sales_backend_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'sales_backend_test',
            'orders.delivery_fee' => null,
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        $this->testConnectionConfigured = true;
        DB::purge('sales_backend_test');
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
        $account = $this->user('client', User::ROLE_CLIENT);
        $this->client = Client::create([
            'code' => 'CLI-001', 'user_id' => $account->id,
            'phone' => '09123456789', 'address' => 'Test delivery address',
        ]);
        $this->stem = $this->item('FLW-001', 'Stem', 1250);
        $this->bouquet = $this->item('FLW-002', 'Bouquet', 125000);
        $this->inventory = app(InventoryService::class);
        $this->orders = app(OrderService::class);
        $this->sales = app(SalesPaymentService::class);
        $this->receive($this->stem, 20);
        $this->receive($this->bouquet, 5);
    }

    /** Disconnect only the ephemeral named connection. */
    protected function tearDown(): void
    {
        if ($this->testConnectionConfigured) DB::disconnect('sales_backend_test');
        parent::tearDown();
    }

    /** Collection is exactly one full payment; under/over amounts cannot mutate stock or orders. */
    public function test_recording_requires_exact_full_payment_without_inventory_changes(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $movements = InventoryMovement::count();
        $this->rejected(fn () => $this->sales->recordPayment($this->paymentInput($order, ['amount' => '24.99']), $this->supplier));
        $this->rejected(fn () => $this->sales->recordPayment($this->paymentInput($order, ['amount' => '25.01']), $this->supplier));
        $this->assertSame(0, OrderPayment::count());
        $this->sales->recordPayment($this->paymentInput($order), $this->supplier);
        $payment = OrderPayment::firstOrFail();
        $this->assertSame(2500, $payment->amount_cents);
        $this->assertSame('Pending', $payment->status);
        $this->assertNull($payment->confirmed_at);
        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(0, SalesRecord::count());
        $mixed = $this->orders->store($this->input(['items' => [
            ['flower_id' => $this->stem->id, 'quantity' => 2],
            ['flower_id' => $this->bouquet->id, 'quantity' => 1],
        ]]), $this->supplier);
        $this->assertSame(127500, $this->record($mixed)->amount_cents);
        $this->assertSame(5, $this->remaining($this->bouquet));
        $this->assertSame($movements, InventoryMovement::count());
    }

    /** A replay is safe even when its version changed; another UUID cannot create a second payment. */
    public function test_payment_uuid_replay_conflict_and_order_uniqueness(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $input = $this->paymentInput($order, ['request_key' => 'a0000000-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        $this->sales->recordPayment($input, $this->supplier);
        $input['request_key'] = strtoupper($input['request_key']);
        $input['version'] = $this->orderFinanceRow($order)['version'];
        $this->sales->recordPayment($input, $this->supplier);
        $this->assertSame(1, OrderPayment::count());
        $this->assertSame(1, FinanceOperation::count());
        $this->rejected(fn () => $this->sales->recordPayment($input, $this->admin), 'request_key');
        $changed = [...$input, 'remarks' => 'Different semantic request.'];
        $this->rejected(fn () => $this->sales->recordPayment($changed, $this->supplier), 'request_key');
        $this->rejected(fn () => $this->sales->recordPayment($this->paymentInput($order), $this->supplier));
        $this->assertSame(1, OrderPayment::count());
        $this->assertSame(1, FinanceOperation::count());
    }

    /** Actual receipt confirmation is independent of fulfillment and does not generate a sale. */
    public function test_confirmation_is_replay_safe_and_never_issues_inventory(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $payment = $this->record($order);
        $input = $this->confirmInput($payment);
        $movements = InventoryMovement::count();
        $this->sales->confirm($payment, $input, $this->supplier);
        $input['version'] = $this->paymentFinanceRow($payment)['version'];
        $this->sales->confirm($payment->fresh(), $input, $this->supplier);
        $this->assertSame('Confirmed', $payment->fresh()->status);
        $this->assertSame($this->supplier->id, $payment->fresh()->confirmed_by);
        $this->assertNotNull($payment->fresh()->confirmed_at);
        $this->assertSame('Paid', $this->orders->row($order->fresh(), $this->supplier)['payment']);
        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertFalse($order->fresh()->stocked_out);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(0, SalesRecord::count());
    }

    /** Refunds are Admin-only, exact, bounded cumulatively, and append-only with safe retries. */
    public function test_partial_and_full_refunds_preserve_history_and_prevent_over_refund(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $payment = $this->record($order);
        $this->sales->confirm($payment, $this->confirmInput($payment), $this->supplier);
        $confirmedAt = $payment->fresh()->confirmed_at->toIso8601String();
        $input = $this->refundInput($payment, '10.00');
        try {
            $this->sales->refund($payment, $input, $this->supplier);
            $this->fail('Supplier must not refund payments.');
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->sales->refund($payment, $input, $this->admin);
        $input['version'] = $this->paymentFinanceRow($payment)['version'];
        $this->sales->refund($payment->fresh(), $input, $this->admin);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(1000, (int) PaymentRefund::sum('amount_cents'));
        $this->assertSame('Refunded', $payment->fresh()->status);
        $this->assertSame('Refunded', $this->orders->row($order->fresh(), $this->supplier)['payment']);
        $this->assertSame(2500, $this->orders->row($order->fresh(), $this->supplier)['paidCents']);
        $this->rejected(fn () => $this->sales->refund($payment, $this->refundInput($payment, '15.01'), $this->admin));
        $this->assertSame(1, PaymentRefund::count());
        $this->sales->refund($payment, $this->refundInput($payment, '15.00'), $this->admin);
        $this->assertSame('Refunded', $payment->fresh()->status);
        $this->assertSame(2500, (int) PaymentRefund::sum('amount_cents'));
        $this->assertSame(2500, $payment->fresh()->amount_cents);
        $this->assertSame(2, PaymentRefund::count());
        $this->assertSame($confirmedAt, $payment->fresh()->confirmed_at->toIso8601String());
        $this->assertSame('Cash', $payment->fresh()->method);
        $this->rejected(fn () => $this->sales->refund($payment, $this->refundInput($payment, '0.01'), $this->admin));
        $this->assertSame(2, PaymentRefund::count());
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(1, OrderPayment::count());
    }

    /** Cancellation disallows new collections while preserving legitimate refund eligibility. */
    public function test_cancelled_orders_reject_collection_but_existing_payment_can_be_refunded(): void
    {
        $cancelled = $this->orders->store($this->input(), $this->supplier);
        $this->action($cancelled, 'Cancelled', $this->supplier, ['reason' => 'Client cancelled before paying.']);
        $this->rejected(fn () => $this->sales->recordPayment($this->paymentInput($cancelled), $this->supplier));
        $order = $this->orders->store($this->input(), $this->supplier);
        $payment = $this->record($order);
        $this->sales->confirm($payment, $this->confirmInput($payment), $this->supplier);
        $this->action($order, 'Cancelled', $this->supplier, ['reason' => 'Client cancelled after paying.']);
        $this->sales->refund($payment, $this->refundInput($payment, '25.00'), $this->admin);
        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame('Refunded', $payment->fresh()->status);
        $this->assertSame(20, $this->remaining($this->stem));
    }

    /** Price changes leave historical orders intact and require current optimistic versions. */
    public function test_selling_prices_are_exact_and_do_not_reprice_existing_orders(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $priceInput = $this->priceInput($this->stem, '20.01');
        $this->sales->price($this->stem, $priceInput, $this->supplier);
        $this->assertSame(2001, FlowerPrice::where('flower_id', $this->stem->id)->firstOrFail()->price_cents);
        $this->assertSame(1250, $order->items()->firstOrFail()->unit_price_cents);
        $this->assertSame(2500, $order->fresh()->total_cents);
        $stale = [...$priceInput, 'request_key' => (string) Str::uuid(), 'amount' => '30.00'];
        $this->rejected(fn () => $this->sales->price($this->stem, $stale, $this->supplier), 'version');
        foreach (['0', '10.001', '-1', '1e2'] as $amount) {
            $this->rejected(fn () => $this->sales->price($this->stem, $this->priceInput($this->stem, $amount), $this->supplier));
        }
        $this->assertSame(2001, FlowerPrice::where('flower_id', $this->stem->id)->firstOrFail()->price_cents);
        $this->stem->status = 'Inactive';
        $this->stem->save();
        $this->rejected(fn () => $this->sales->price($this->stem, $this->priceInput($this->stem, '40.00'), $this->supplier));
        $this->assertSame(2001, FlowerPrice::where('flower_id', $this->stem->id)->firstOrFail()->price_cents);
    }

    /** Stale order/payment snapshots cannot be used to record or confirm changed financial state. */
    public function test_stale_order_and_payment_finance_versions_are_rejected(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $input = $this->paymentInput($order);
        $this->orders->update($order, $this->edit($order, ['items' => [['flower_id' => $this->stem->id, 'quantity' => 3]]]), $this->supplier);
        $input['amount'] = '37.50';
        $this->rejected(fn () => $this->sales->recordPayment($input, $this->supplier), 'version');
        $payment = $this->record($order->fresh());
        $confirm = $this->confirmInput($payment);
        $this->sales->confirm($payment, $confirm, $this->supplier);
        $staleConfirm = [...$confirm, 'request_key' => (string) Str::uuid()];
        $this->rejected(fn () => $this->sales->confirm($payment, $staleConfirm, $this->supplier), 'version');
        $this->assertSame('Confirmed', $payment->fresh()->status);
    }

    /** Noncash references are normalized and unique within their payment method, not globally. */
    public function test_noncash_reference_normalization_and_method_scope(): void
    {
        $first = $this->orders->store($this->input(), $this->supplier);
        $this->record($first, ['method' => 'GCash', 'reference' => 'TXN-001']);
        $second = $this->orders->store($this->input(), $this->supplier);
        $this->rejected(fn () => $this->sales->recordPayment($this->paymentInput($second, [
            'method' => 'GCash', 'reference' => ' txn-001 ',
        ]), $this->supplier));
        $this->record($second, ['method' => 'Bank Transfer', 'reference' => 'TXN-001']);
        $third = $this->orders->store($this->input(), $this->supplier);
        $this->rejected(fn () => $this->sales->recordPayment($this->paymentInput($third, [
            'method' => 'GCash', 'reference' => null,
        ]), $this->supplier));
        $this->assertSame(2, OrderPayment::count());
    }

    /** Payment requests reject direct status, identity, and money-state tampering. */
    public function test_payment_request_rejects_client_assigned_confirmation_and_stock_state(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $payload = $this->paymentInput($order);
        $rules = (new StorePaymentRequest())->rules();
        $this->assertTrue(Validator::make($payload, $rules)->passes());
        foreach (['status' => 'Confirmed', 'confirmed_by' => $this->admin->id,
            'confirmed_at' => '2026-01-01', 'stocked_out' => true, 'amount_cents' => 1,
            'code' => 'PAY-FORGED'] as $field => $value) {
            $validator = Validator::make([...$payload, $field => $value], $rules);
            $this->assertTrue($validator->fails(), $field);
            $this->assertArrayHasKey($field, $validator->errors()->toArray());
        }
        $this->assertTrue(Validator::make([...$payload, 'amount' => 25.00], $rules)->fails());
        $this->assertTrue(Validator::make([...$payload, 'amount' => '25.001'], $rules)->fails());
        $this->assertSame(0, OrderPayment::count());
    }

    /** Direct service entry points reject nonoperational, inactive, and temporary-password accounts. */
    public function test_finance_read_and_write_role_boundaries(): void
    {
        $input = $this->priceInput($this->stem, '20.00');
        $inactive = clone $this->supplier;
        $inactive->is_active = false;
        $temporary = clone $this->supplier;
        $temporary->must_change_password = true;
        foreach ([$this->client->user, $inactive, $temporary] as $actor) {
            foreach ([fn () => $this->sales->data($actor), fn () => $this->sales->price($this->stem, $input, $actor)] as $action) {
                try {
                    $action();
                    $this->fail('Unauthorized financial access must be rejected.');
                } catch (AuthorizationException|HttpException $exception) {
                    if ($exception instanceof HttpException) $this->assertSame(403, $exception->getStatusCode());
                }
            }
        }
        $this->assertSame(0, FinanceOperation::count());
        $this->assertSame(1250, FlowerPrice::where('flower_id', $this->stem->id)->firstOrFail()->price_cents);
    }

    /** Actual named routes enforce session signatures, financial validation, and Admin-only refunds. */
    public function test_http_finance_routes_validate_and_enforce_roles(): void
    {
        $order = $this->orders->store($this->input(), $this->supplier);
        $this->actingAs($this->supplier)->withSession([
            AccountSession::KEY => AccountSession::signature($this->supplier),
        ]);
        $this->getJson(route('supplier.sales-payments.data'))->assertOk()->assertJsonStructure(['flowers', 'orders', 'payments']);
        $payload = $this->paymentInput($order);
        $this->postJson(route('supplier.sales-payments.record'), [...$payload, 'status' => 'Confirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson(route('supplier.sales-payments.record'), $payload)
            ->assertCreated()->assertJsonStructure(['message', 'data' => ['payments']]);
        $payment = OrderPayment::firstOrFail();
        $this->assertSame('Pending', $payment->status);
        $this->postJson(route('supplier.sales-payments.refund', ['payment' => $payment->id]),
            $this->refundInput($payment, '1.00'))->assertForbidden();
        $account = $this->client->user;
        $this->actingAs($account)->withSession([
            AccountSession::KEY => AccountSession::signature($account),
        ]);
        $this->getJson(route('supplier.sales-payments.data'))->assertForbidden();
        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(20, $this->remaining($this->stem));
    }

    /** Read the current order financial DTO version, including cancelled historical orders. */
    private function orderFinanceRow(Order $order): array
    {
        return $this->financeRow('orders', (int) $order->id);
    }

    /** Read a fresh payment DTO version after confirmation/refund changes. */
    private function paymentFinanceRow(OrderPayment $payment): array
    {
        return $this->financeRow('payments', (int) $payment->id);
    }

    /** Find one explicitly identified current financial record. */
    private function financeRow(string $key, int $id): array
    {
        $rows = $this->sales->data($this->supplier)[$key];
        $row = collect($rows)->first(fn ($row) => (int) $row['dbId'] === $id);
        $this->assertNotNull($row, 'Missing '.$key.' DTO '.$id);

        return $row;
    }

    /** Build a complete payment request in exact decimal currency units. */
    private function paymentInput(Order $order, array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(), 'order_id' => $order->id,
            'version' => $this->orderFinanceRow($order)['version'], 'method' => 'Cash',
            'amount' => Money::format((int) $order->fresh()->total_cents), 'reference' => null, 'remarks' => null,
        ], $overrides);
    }

    /** Record a real pending payment through the service. */
    private function record(Order $order, array $overrides = []): OrderPayment
    {
        $this->sales->recordPayment($this->paymentInput($order, $overrides), $this->supplier);

        return OrderPayment::where('order_id', $order->id)->firstOrFail();
    }

    /** Build a documented receipt-verification request. */
    private function confirmInput(OrderPayment $payment): array
    {
        return ['request_key' => (string) Str::uuid(), 'version' => $this->paymentFinanceRow($payment)['version'],
            'reason' => 'Verified the actual receipt and full collection.'];
    }

    /** Build a new bounded refund request from the current financial state. */
    private function refundInput(OrderPayment $payment, string $amount): array
    {
        return ['request_key' => (string) Str::uuid(), 'version' => $this->paymentFinanceRow($payment)['version'],
            'amount' => $amount, 'reason' => 'Approved return to the client.'];
    }

    /** Build a current selling-price edit without changing historical commercial snapshots. */
    private function priceInput(Flower $flower, string $amount): array
    {
        return ['request_key' => (string) Str::uuid(), 'version' => $this->financeRow('flowers', (int) $flower->id)['version'],
            'amount' => $amount];
    }

    /** Create one isolated account with the existing User hashing/casting behavior. */
    private function user(string $label, string $role): User
    {
        return User::forceCreate([
            'name' => 'Test '.$label, 'email' => $label.'@example.test', 'password' => 'Temporary-test-password!',
            'role' => $role, 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    /** Define a catalog item and its current Sales-owned price. */
    private function item(string $code, string $type, int $price): Flower
    {
        $flower = Flower::forceCreate([
            'code' => $code, 'name' => 'Test '.$type, 'product_type' => $type,
            'category' => 'Roses', 'minimum_stock' => 1, 'description' => 'Test item.', 'status' => 'Active',
        ]);
        FlowerPrice::create(['flower_id' => $flower->id, 'price_cents' => $price]);

        return $flower;
    }

    /** Receive real test stock through the shared Inventory service. */
    private function receive(Flower $flower, int $quantity): void
    {
        $this->inventory->stockIn([
            'request_key' => (string) Str::uuid(), 'flower_id' => $flower->id, 'quantity' => $quantity,
            'unit' => $flower->product_type, 'received' => '2026-01-02', 'harvest' => '2026-01-01',
            'expiry' => null, 'remarks' => 'Test receipt.',
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
            'request_key' => (string) Str::uuid(), 'client_id' => $this->client->id,
            'method' => 'Customer Pickup', 'address' => null, 'notes' => null,
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 2]],
        ], $overrides);
    }

    /** Build an optimistic editable payload using the current version. */
    private function edit(Order $order, array $overrides = []): array
    {
        $current = $order->fresh()->load('items');

        return array_replace([
            'client_id' => $current->client_id, 'method' => $current->method,
            'address' => $current->address, 'notes' => $current->notes,
            'items' => $current->items->map(fn ($item) => ['flower_id' => $item->flower_id, 'quantity' => $item->quantity])->all(),
            'version' => $this->orders->row($current, $this->supplier)['version'],
        ], $overrides);
    }

    /** Apply an explicit desired state through the same API used by the controller. */
    private function action(Order $order, string $status, User $actor, array $overrides = []): Order
    {
        return $this->orders->action($order, array_replace([
            'status' => $status, 'version' => $this->orders->row($order->fresh(), $actor)['version'],
        ], $overrides), $actor);
    }

    /** Assert a domain rejection while permitting subsequent atomicity assertions. */
    private function rejected(callable $action, ?string $field = null): void
    {
        try {
            $action(); $this->fail('Expected financial validation to reject this operation.');
        } catch (ValidationException $exception) {
            if ($field !== null) $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}