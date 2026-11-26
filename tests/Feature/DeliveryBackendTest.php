<?php

namespace Tests\Feature;

use App\Models\BatchDailyObservation;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryHistory;
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
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\SalesPaymentService;
use App\Support\AccountSession;
use App\Support\Money;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

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
        foreach (['create_clients_table', 'create_inventory_tables', 'remove_harvest_and_expiry_dates_from_inventory_batches_table', 'create_order_tables', 'create_delivery_fee_settings', 'create_delivery_tables', 'create_finance_tables',
            'create_batch_daily_observations_table', 'remove_harvest_date_from_batch_daily_observations_table', 'add_collection_provenance_to_batch_daily_observations_table'] as $name) {
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
        DB::table('delivery_fee_settings')->update(['fee_cents' => 15000]);
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

    public function test_delivery_issues_stock_once_and_completed_status_waits_for_full_payment(): void
    {
        [$order, $delivery] = $this->dispatched();
        $payload = $this->deliveryInput($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Actual Recipient', 'proof' => $this->photo(),
        ]);
        $this->assertSame(0, (int) BatchDailyObservation::sum('released_quantity'));
        $service = app(DeliveryService::class);
        $result = $service->action($delivery, $payload, $this->supplier);
        $movements = InventoryMovement::count();
        $service->action($delivery, $payload, $this->supplier);

        $this->assertSame('Delivered', $result->status);
        $this->assertSame('Actual Recipient', $result->recipient_name);
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
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
        $this->assertSame(2, BatchDailyObservation::count());
        $this->assertSame(4, BatchDailyObservation::where('flower_id', $this->stem->id)->first()->released_quantity);
        $this->assertSame(2, BatchDailyObservation::where('flower_id', $this->bouquet->id)->first()->released_quantity);
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->withoutVite();
        $customer = $this->client->user;
        $this->actingAs($customer)->withSession([AccountSession::KEY => AccountSession::signature($customer)]);
        $this->get(route('client.tracking', $order))->assertOk()
            ->assertSeeTextInOrder(['Delivered', 'Fulfillment updates', 'Out for Delivery', 'Delivered'])
            ->assertDontSee($result->proof_path)->assertDontSeeText('Actual Recipient');
        Storage::disk('local')->assertExists($result->proof_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('delivery-proofs'));
        $this->assertArrayNotHasKey('proof_path', $service->row($result, $this->supplier));

        $payload['recipient_name'] = 'Changed Recipient';
        $this->rejected(fn () => $service->action($delivery, $payload, $this->supplier), 'request_key');
        $this->assertSame(1, SalesRecord::count());

        $courier = User::findOrFail($delivery->assigned_to);
        $this->actingAs($courier)->withSession([AccountSession::KEY => AccountSession::signature($courier)]);
        $collection = [
            'action' => 'payment', 'reason' => 'Received full GCash payment.',
            'request_key' => (string) Str::uuid(), 'version' => $service->row($result, $courier)['version'],
            'payment_method' => 'GCash', 'payment_amount' => Money::format($order->total_cents),
            'payment_reference' => 'GCASH-DELIVERY-TEST',
        ];
        $url = route('delivery.deliveries.action', $delivery);
        $this->post($url, $collection, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->post($url, [...$collection, 'payment_amount' => '1.00', 'proof' => $this->photo()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_amount');
        $this->post($url, [...$collection, 'proof' => $this->photo()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.paymentRecorded', true);
        $this->post($url, [...$collection, 'proof' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, OrderPayment::count());
        $payment = $order->payment()->firstOrFail();
        $this->assertSame('Pending', $payment->status);
        $this->assertSame('GCASH-DELIVERY-TEST', $payment->reference);
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-proofs'));
        $proofUrl = route('supplier.sales-payments.proof', $payment);
        $this->getJson($proofUrl)->assertForbidden();

        $this->actingAs($this->supplier)->withSession([AccountSession::KEY => AccountSession::signature($this->supplier)]);
        $this->get($proofUrl)->assertOk();
        $data = app(SalesPaymentService::class)->data($this->supplier);
        $paymentRow = collect($data['payments'])->firstWhere('dbId', $payment->id);
        $this->assertSame($proofUrl, $paymentRow['proofUrl']);
        $this->postJson(route('supplier.sales-payments.confirm', $payment), [
            'request_key' => (string) Str::uuid(), 'version' => $paymentRow['version'], 'reason' => 'Payment photo verified.',
        ])->assertOk();
        $this->assertSame('Completed', $order->fresh()->status);
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(1, SalesRecord::count());
    }

    public function test_rider_can_submit_cash_payment_without_a_payment_photo(): void
    {
        [$order, $delivery] = $this->dispatched();
        $delivery = $this->deliveryAction($delivery, [
            'action' => 'deliver', 'recipient_name' => 'Cash Customer', 'proof' => $this->photo(),
        ]);
        $courier = User::findOrFail($delivery->assigned_to);
        $this->actingAs($courier)->withSession([AccountSession::KEY => AccountSession::signature($courier)]);

        $this->postJson(route('delivery.deliveries.action', $delivery), $this->deliveryInput($delivery, [
            'action' => 'payment', 'payment_method' => 'Cash', 'payment_amount' => Money::format($order->total_cents),
        ]))->assertOk()->assertJsonPath('data.paymentStatus', 'Pending');

        $payment = $order->payment()->firstOrFail();
        $finance = app(SalesPaymentService::class)->data($this->supplier);
        $paymentRow = collect($finance['payments'])->firstWhere('dbId', $payment->id);
        $this->assertNull($paymentRow['proofUrl']);
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('payment-proofs'));
    }

    /** A later bouquet shortage rolls back earlier stem fulfillment and the upload. */
    public function test_shortage_rolls_back_every_completion_effect_and_removes_unused_proof(): void
    {
        [$order, $delivery] = $this->dispatched();
        InventoryBatch::where('flower_id', $this->bouquet->id)->update(['remaining_quantity' => 1]);
        $observations = BatchDailyObservation::orderBy('id')->get()->toArray();
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
        $this->assertSame($observations, BatchDailyObservation::orderBy('id')->get()->toArray());
        $this->assertFalse($order->fresh()->stocked_out);
        $this->assertNull($delivery->fresh()->proof_path);
        $this->assertCount(0, Storage::disk('local')->allFiles('delivery-proofs'));
    }

    public function test_assigned_rider_can_restart_a_failed_delivery_without_scheduling_or_issuing_stock(): void
    {
        [$order, $delivery] = $this->dispatched();
        $delivery = $this->deliveryAction($delivery, ['action' => 'fail']);
        $this->assertSame('Failed', $delivery->status);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
        $courier = User::findOrFail($delivery->assigned_to);
        $this->actingAs($courier)->withSession([AccountSession::KEY => AccountSession::signature($courier)]);
        $this->postJson(route('delivery.deliveries.action', $delivery), $this->deliveryInput($delivery, ['action' => 'dispatch']))
            ->assertOk()->assertJsonPath('data.status', 'Out for Delivery');
        $this->assertNull($delivery->fresh()->scheduled_at);
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

    public function test_delivery_list_includes_each_order_items_flower_photo(): void
    {
        Storage::fake('public');
        $this->stem->update(['image_path' => 'flowers/stem.webp']);
        [, $delivery] = $this->dispatched();
        $courier = User::findOrFail($delivery->assigned_to);

        $this->actingAs($courier)->withSession([AccountSession::KEY => AccountSession::signature($courier)]);
        $this->withoutVite();
        $this->get(route('delivery.deliveries'))->assertOk()
            ->assertSee('supplierMenuToggle')
            ->assertSee('supplierNotificationToggle')
            ->assertDontSee(route('supplier.dashboard'));
        $this->getJson(route('delivery.deliveries.data'))->assertOk()
            ->assertJsonPath('data.0.id', $delivery->code)
            ->assertJsonPath('data.0.items', [
                [
                    'flowerId' => $this->stem->code,
                    'flowerName' => $this->stem->name,
                    'imageUrl' => Storage::disk('public')->url('flowers/stem.webp'),
                ],
                [
                    'flowerId' => $this->bouquet->code,
                    'flowerName' => $this->bouquet->name,
                    'imageUrl' => null,
                ],
            ]);
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
        foreach (['schedule', 'assign', 'cancel'] as $action) {
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
        $this->confirmPayment($order);
        $this->assertSame('Out for Delivery', $order->fresh()->status);
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

    public function test_tracking_uses_recorded_delivery_events_and_hides_staff_details(): void
    {
        $this->withoutVite();
        [$order, $delivery] = $this->ready();
        $customer = $this->client->user;
        $this->actingAs($customer)->withSession([AccountSession::KEY => AccountSession::signature($customer)]);
        $url = route('client.tracking', $order);
        $slot = now('Asia/Manila')->addDay()->setTime(14, 0);
        $delivery = $this->deliveryAction($delivery, ['action' => 'schedule', 'scheduled_at' => $slot->toIso8601String()]);
        $this->travelTo($slot->copy()->addHour());
        $this->get($url)->assertOk()->assertSeeText('Scheduled for 2:00 PM')->assertDontSeeText('Out for Delivery');
        $delivery = $this->deliveryAction($delivery, ['action' => 'assign', 'assigned_to' => $this->user('courier', User::ROLE_DELIVERY)->id]);
        $delivery = $this->deliveryAction($delivery, ['action' => 'dispatch']);
        $this->get($url)->assertOk()->assertSeeText('Out for Delivery');
        $delivery = $this->deliveryAction($delivery, ['action' => 'fail']);
        $this->get($url)->assertSeeTextInOrder(['Delivery attempt failed', 'Check back for a new delivery schedule']);
        $this->get(route('client.tracking', ['status' => 'Delivery attempt failed']))->assertOk()
            ->assertSeeText($order->code)->assertSeeText('Quantity 4')->assertSeeText('Quantity 2');
        $this->get(route('client.tracking', ['status' => 'Ready for Delivery']))->assertOk()
            ->assertSeeText('No orders match your filters.')->assertDontSeeText($order->code);
        $delivery = $this->deliveryAction($delivery, ['action' => 'schedule', 'scheduled_at' => $slot->copy()->addDay()->toIso8601String()]);
        $this->get($url)->assertOk()->assertSeeTextInOrder(['Fulfillment updates', 'Delivery scheduled', 'Out for Delivery', 'Delivery attempt failed', 'Delivery rescheduled'])
            ->assertDontSeeText('Actual delivery operation test.')->assertDontSeeText('Test courier');
        $this->deliveryAction($delivery, ['action' => 'cancel']);
        $this->get($url)->assertSeeText('Delivery cancelled');
        $this->assertSame(2, InventoryReservation::where('status', 'Active')->count());
        $this->assertSame(0, SalesRecord::count());
    }

    private function confirmPayment(Order $order): void
    {
        $sales = app(SalesPaymentService::class);
        $orderRow = collect($sales->data($this->supplier)['orders'])->firstWhere('dbId', $order->id);
        $data = $sales->recordPayment([
            'request_key' => (string) Str::uuid(),
            'order_id' => $order->id,
            'version' => $orderRow['version'],
            'method' => 'Cash',
            'amount' => Money::format($order->total_cents),
        ], $this->supplier);
        $payment = $order->payment()->firstOrFail();
        $paymentRow = collect($data['payments'])->firstWhere('dbId', $payment->id);
        $sales->confirm($payment, [
            'request_key' => (string) Str::uuid(),
            'version' => $paymentRow['version'],
            'reason' => 'Full cash payment verified.',
        ], $this->supplier);
    }

    private function dispatched(): array
    {
        [$order, $delivery] = $this->ready();
        $person = User::query()->where('role', User::ROLE_DELIVERY)->first()
            ?? $this->user('delivery', User::ROLE_DELIVERY);
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
        if ($this->testConnectionConfigured) {
            DB::disconnect('order_backend_test');
        }
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
            'unit' => $flower->product_type, 'received' => '2026-01-02',
            'remarks' => 'Test receipt.',
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
            $action();
            $this->fail('Expected order validation to reject this operation.');
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    private function remaining(Flower $flower): int
    {
        return (int) InventoryBatch::where('flower_id', $flower->id)
            ->sum('remaining_quantity');
    }
}
