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
use App\Models\Delivery;
use App\Models\DeliveryHistory;
use App\Services\DeliveryService;
use App\Support\AccountSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DeliveryBackendTest extends TestCase
{
    private OrderService $orders;
    private InventoryService $inventory;
    private User $admin;
    private User $supplier;
    private Client $client;
    private Flower $stem;
    private Flower $bouquet;
    private bool $testConnectionConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Isolated order tests require pdo_sqlite.');
        }
        config([
            'database.connections.order_backend_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
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
        foreach (['create_clients_table', 'create_inventory_tables', 'create_order_tables', 'create_delivery_tables'] as $name) {
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
        $this->receive($this->stem, 20);
        $this->receive($this->bouquet, 5);
        Storage::fake('local');
        config(['orders.delivery_fee' => '150.00']);
    }


    /** Dispatch changes workflow only; physical stock and holds remain unchanged. */
    public function test_dispatch_preserves_stock_and_reservations(): void
    {
        [$order, $delivery] = $this->dispatched();
        $this->assertSame('Out for Delivery', $delivery->status);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(5, $this->remaining($this->bouquet));
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(0, SalesRecord::count());
    }

    /** Actual receipt completes both records, consumes mixed stock and replays once. */
    public function test_actual_delivery_completes_once_with_private_proof_and_no_payment_gate(): void
    {
        [$order, $delivery] = $this->dispatched();
        $payload = $this->deliveryInput($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Actual Recipient', 'proof' => $this->photo(),
        ]);
        $service = app(DeliveryService::class);
        $result = $service->action($delivery, $payload, $this->supplier);
        $movements = InventoryMovement::count();
        $service->action($delivery, $payload, $this->supplier);

        $this->assertSame('Delivered', $result->status);
        $this->assertSame('Actual Recipient', $result->recipient_name);
        $this->assertSame('Completed', $order->fresh()->status);
        $this->assertTrue($order->fresh()->stocked_out);
        $this->assertSame(16, $this->remaining($this->stem));
        $this->assertSame(3, $this->remaining($this->bouquet));
        $this->assertSame(2, InventoryReservation::where('status', 'Consumed')->count());
        $this->assertSame(0, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(1, SalesRecord::where('order_id', $order->id)->count());
        $this->assertSame((int) $order->total_cents, (int) SalesRecord::first()->total_cents);
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(1, DeliveryHistory::where('action', 'deliver')->count());
        $this->assertSame(0, OrderPayment::count());
        $this->assertNotNull($result->delivered_at);
        Storage::disk('local')->assertExists($result->proof_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('delivery-proofs'));
        $this->assertArrayNotHasKey('proof_path', $service->row($result, $this->supplier));

        $payload['recipient_name'] = 'Changed Recipient';
        $this->rejected(fn () => $service->action($delivery, $payload, $this->supplier), 'request_key');
        $this->assertSame(1, SalesRecord::count());
    }

    /** A later bouquet shortage rolls back earlier stem fulfillment and the upload. */
    public function test_shortage_rolls_back_every_completion_effect_and_removes_unused_proof(): void
    {
        [$order, $delivery] = $this->dispatched();
        InventoryBatch::where('flower_id', $this->bouquet->id)->update(['remaining_quantity' => 1]);
        $movements = InventoryMovement::count();
        $operations = InventoryOperation::count();
        $history = DeliveryHistory::count();
        $payload = $this->deliveryInput($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Recipient', 'proof' => $this->photo(),
        ]);
        $this->rejected(fn () => app(DeliveryService::class)->action($delivery, $payload, $this->supplier));
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(1, $this->remaining($this->bouquet));
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame($operations, InventoryOperation::count());
        $this->assertSame($history, DeliveryHistory::count());
        $this->assertSame(0, SalesRecord::count());
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame('Out for Delivery', $delivery->fresh()->status);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
        $this->assertFalse($order->fresh()->stocked_out);
        $this->assertNull($delivery->fresh()->proof_path);
        $this->assertCount(0, Storage::disk('local')->allFiles('delivery-proofs'));
    }

    /** Failed attempts retain holds; rescheduling is mandatory before another dispatch. */
    public function test_failed_delivery_requires_rescheduling_and_does_not_issue_stock(): void
    {
        [$order, $delivery] = $this->dispatched();
        $delivery = $this->deliveryAction($delivery, ['action' => 'fail']);
        $this->assertSame('Failed', $delivery->status);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
        $this->rejected(fn () => $this->deliveryAction($delivery, ['action' => 'dispatch']));
        $delivery = $this->deliveryAction($delivery, [
            'action' => 'schedule', 'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ]);
        $this->assertSame('Rescheduled', $delivery->status);
        $this->assertSame('Ready for Delivery', $order->fresh()->status);
        $delivery = $this->deliveryAction($delivery, ['action' => 'dispatch']);
        $this->assertSame('Out for Delivery', $delivery->status);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(0, SalesRecord::count());
    }

    /** Completion requires a real image and an actual dispatched delivery. */
    public function test_completion_rejects_missing_or_invalid_photo_and_early_completion(): void
    {
        [, $delivery] = $this->ready();
        $this->rejected(fn () => $this->deliveryAction($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Recipient', 'proof' => $this->photo(),
        ]));
        [, $delivery] = $this->dispatched();
        $this->rejected(fn () => $this->deliveryAction($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Recipient',
        ]), 'proof');
        $this->rejected(fn () => $this->deliveryAction($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Recipient',
            'proof' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
        ]), 'proof');
        $this->assertSame(0, SalesRecord::count());
        $this->assertCount(0, Storage::disk('local')->allFiles('delivery-proofs'));
    }

    /** Reusing a request key and overwriting a stale schedule cannot change valid state. */
    public function test_schedule_replay_and_stale_changes_are_protected(): void
    {
        [, $delivery] = $this->ready();
        $payload = $this->deliveryInput($delivery, [
            'action' => 'schedule', 'scheduled_at' => now()->addDay()->toIso8601String(),
        ]);
        $service = app(DeliveryService::class);
        $service->action($delivery, $payload, $this->supplier);
        $service->action($delivery, $payload, $this->supplier);
        $this->assertSame(1, DeliveryHistory::where('action', 'schedule')->count());
        $stale = [...$payload, 'request_key' => (string) Str::uuid(), 'scheduled_at' => now()->addDays(3)->toIso8601String()];
        $this->rejected(fn () => $service->action($delivery, $stale, $this->supplier), 'version');
    }

    /** Cancelling only a delivery preserves its order, stock and reservation. */
    public function test_cancelling_a_ready_delivery_preserves_order_and_holds_and_replays_once(): void
    {
        [$order, $delivery] = $this->ready();
        $movements = InventoryMovement::count();
        $payload = $this->deliveryInput($delivery, [
            'action' => 'cancel', 'reason' => 'Delivery arrangement cancelled.',
        ]);
        $service = app(DeliveryService::class);
        $result = $service->action($delivery, $payload, $this->supplier);
        $service->action($delivery, $payload, $this->supplier);

        $this->assertSame('Cancelled', $result->status);
        $this->assertSame('Ready for Delivery', $order->fresh()->status);
        $this->assertFalse($order->fresh()->stocked_out);
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(5, $this->remaining($this->bouquet));
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(0, SalesRecord::count());
        $this->assertSame(1, DeliveryHistory::where('action', 'cancel')->count());
        $this->rejected(fn () => $this->deliveryAction($result, [
            'action' => 'schedule', 'scheduled_at' => now()->addDay()->toIso8601String(),
        ]), 'action');
        $payload['reason'] = 'Changed reason';
        $this->rejected(fn () => $service->action($delivery, $payload, $this->supplier), 'request_key');
    }

    /** A dispatched delivery must first record a failed attempt before cancellation. */
    public function test_cancellation_requires_reason_and_cannot_cancel_an_active_dispatch(): void
    {
        [$order, $delivery] = $this->dispatched();
        $this->rejected(fn () => $this->deliveryAction($delivery, [
            'action' => 'cancel', 'reason' => 'Cancelled arrangement.',
        ]), 'action');
        $delivery = $this->deliveryAction($delivery, ['action' => 'fail']);
        $this->rejected(fn () => $this->deliveryAction($delivery, [
            'action' => 'cancel', 'reason' => '   ',
        ]), 'reason');
        $delivery = $this->deliveryAction($delivery, [
            'action' => 'cancel', 'reason' => 'No further delivery attempt.',
        ]);
        $this->assertSame('Cancelled', $delivery->status);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(0, SalesRecord::count());
    }

    /** Personnel lists, details and mutations are scoped to the locked assignment. */
    public function test_personnel_can_only_read_their_own_assigned_deliveries(): void
    {
        [, $delivery] = $this->dispatched();
        $person = User::findOrFail($delivery->assigned_to);
        $other = $this->user('other-courier', User::ROLE_DELIVERY);
        $service = app(DeliveryService::class);
        $this->assertCount(1, $service->data($person)['data']);
        $this->assertCount(0, $service->data($other)['data']);
        $this->assertSame([], $service->data($person)['personnel']);
        $this->assertSame($delivery->code, $service->row($delivery, $person)['id']);
        try {
            $service->row($delivery, $other);
            $this->fail('Another courier must not read this delivery.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        foreach (['schedule', 'assign', 'dispatch', 'cancel'] as $action) {
            try {
                $service->action($delivery, $this->deliveryInput($delivery, ['action' => $action]), $person);
                $this->fail('Personnel cannot manage assignment or dispatch.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    /** Actual assigned personnel may complete delivery; other personnel cannot access proof. */
    public function test_personnel_completion_and_private_proof_routes_enforce_ownership(): void
    {
        [$order, $delivery] = $this->dispatched();
        $person = User::findOrFail($delivery->assigned_to);
        $other = $this->user('other-courier', User::ROLE_DELIVERY);
        $service = app(DeliveryService::class);
        $payload = $this->deliveryInput($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Recipient', 'proof' => $this->photo(),
        ]);
        try {
            $service->action($delivery, $payload, $other);
            $this->fail('A different courier must not complete this delivery.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, SalesRecord::count());
        $result = $service->action($delivery, $payload, $person);
        $this->assertSame('Delivered', $result->status);
        $this->assertSame('Completed', $order->fresh()->status);
        $this->assertSame(1, SalesRecord::count());
        $this->actingAs($person)->withSession([AccountSession::KEY => AccountSession::signature($person)])
            ->get('/delivery/deliveries/'.$delivery->id.'/proof')->assertOk();
        $this->actingAs($other)->withSession([AccountSession::KEY => AccountSession::signature($other)])
            ->getJson('/delivery/deliveries/'.$delivery->id.'/proof')->assertForbidden();
        $this->getJson('/supplier/deliveries/data')->assertForbidden();
    }

    /** Personnel may record a failure without consuming stock or releasing holds. */
    public function test_assigned_personnel_can_record_failed_attempt_without_stock_deduction(): void
    {
        [$order, $delivery] = $this->dispatched();
        $person = User::findOrFail($delivery->assigned_to);
        $result = app(DeliveryService::class)->action($delivery, $this->deliveryInput($delivery, [
            'action' => 'fail', 'reason' => 'Recipient unavailable.',
        ]), $person);
        $this->assertSame('Failed', $result->status);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
        $this->assertSame(20, $this->remaining($this->stem));
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(0, SalesRecord::count());
    }

    private function ready(): array
    {
        $order = $this->orders->store($this->input([
            'method' => 'Delivery', 'address' => 'Actual delivery address',
            'items' => [
                ['flower_id' => $this->stem->id, 'quantity' => 4],
                ['flower_id' => $this->bouquet->id, 'quantity' => 2],
            ],
        ]), $this->supplier);
        $this->action($order, 'Confirmed', $this->admin);
        $this->action($order, 'Processing', $this->supplier);
        $this->action($order, 'Ready for Delivery', $this->supplier);
        $delivery = app(DeliveryService::class)->createForOrder($order, $this->supplier);
        $this->assertSame(1, Delivery::where('order_id', $order->id)->count());

        return [$order->fresh(), $delivery];
    }

    private function dispatched(): array
    {
        [$order, $delivery] = $this->ready();
        $person = User::query()->where('role', User::ROLE_DELIVERY)->first()
            ?? $this->user('delivery', User::ROLE_DELIVERY);
        $delivery = $this->deliveryAction($delivery, [
            'action' => 'schedule', 'scheduled_at' => now()->addDay()->toIso8601String(),
        ]);
        $delivery = $this->deliveryAction($delivery, [
            'action' => 'assign', 'assigned_to' => $person->id,
        ]);
        $delivery = $this->deliveryAction($delivery, ['action' => 'dispatch']);

        return [$order->fresh(), $delivery];
    }

    private function deliveryInput(Delivery $delivery, array $overrides): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'version' => app(DeliveryService::class)->row($delivery->fresh(), $this->supplier)['version'],
            'reason' => 'Actual delivery operation test.',
        ], $overrides);
    }

    private function deliveryAction(Delivery $delivery, array $overrides): Delivery
    {
        return app(DeliveryService::class)->action(
            $delivery, $this->deliveryInput($delivery, $overrides), $this->supplier
        );
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII='
        ));
    }

    protected function tearDown(): void
    {
        if ($this->testConnectionConfigured) DB::disconnect('order_backend_test');
        parent::tearDown();
    }

    private function user(string $label, string $role): User
    {
        return User::forceCreate([
            'name' => 'Test '.$label, 'email' => $label.'@example.test', 'password' => 'Temporary-test-password!',
            'role' => $role, 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function item(string $code, string $type, int $price): Flower
    {
        $flower = Flower::forceCreate([
            'code' => $code, 'name' => 'Test '.$type, 'product_type' => $type,
            'category' => 'Roses', 'minimum_stock' => 1, 'description' => 'Test item.', 'status' => 'Active',
        ]);
        FlowerPrice::create(['flower_id' => $flower->id, 'price_cents' => $price]);

        return $flower;
    }

    private function receive(Flower $flower, int $quantity): void
    {
        $this->inventory->stockIn([
            'request_key' => (string) Str::uuid(), 'flower_id' => $flower->id, 'quantity' => $quantity,
            'unit' => $flower->product_type, 'received' => '2026-01-02', 'harvest' => '2026-01-01',
            'expiry' => null, 'remarks' => 'Test receipt.',
        ], $this->admin);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(), 'client_id' => $this->client->id,
            'method' => 'Customer Pickup', 'address' => null, 'notes' => null,
            'items' => [['flower_id' => $this->stem->id, 'quantity' => 4]],
        ], $overrides);
    }

    private function action(Order $order, string $status, User $actor, array $overrides = []): Order
    {
        return $this->orders->action($order, array_replace([
            'status' => $status, 'version' => $this->orders->row($order->fresh(), $actor)['version'],
        ], $overrides), $actor);
    }

    private function rejected(callable $action, ?string $field = null): void
    {
        try {
            $action(); $this->fail('Expected order validation to reject this operation.');
        } catch (ValidationException $exception) {
            if ($field !== null) $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function remaining(Flower $flower): int
    {
        return (int) InventoryBatch::where('flower_id', $flower->id)
            ->sum('remaining_quantity');
    }
}
