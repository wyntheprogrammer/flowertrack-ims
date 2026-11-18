<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BatchDailyObservation;
use App\Models\BatchSpoilagePrediction;
use App\Models\Client;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\InventoryBatch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DashboardService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\SalesPaymentService;
use App\Services\SpoilagePredictionService;
use App\Services\SpoilagePythonService;
use App\Support\AccountSession;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardBackendTest extends TestCase
{
    private User $admin;

    private User $supplier;

    private bool $isolated = false;

    private string $timezone;

    /** No RefreshDatabase: all schema changes target this named memory database. */
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Dashboard tests require pdo_sqlite.');
        }

        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        config([
            'database.connections.dashboard_backend_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'dashboard_backend_test',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'app.timezone' => 'UTC',
            'hashing.bcrypt.rounds' => 4,
        ]);

        $this->isolated = true;
        DB::purge('dashboard_backend_test');
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
            'create_finance_tables',
            'create_batch_daily_observations_table',
            'create_batch_spoilage_predictions_table',
        ] as $name) {
            $this->migrateOne($name);
        }

        // Create initial actors before enabling audit storage.
        $this->admin = $this->user('admin', User::ROLE_ADMIN);
        $this->supplier = $this->user('supplier', User::ROLE_SUPPLIER);
        $this->migrateOne('create_audit_events_table');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        if ($this->isolated) {
            DB::disconnect('dashboard_backend_test');
        }

        if (isset($this->timezone)) {
            date_default_timezone_set($this->timezone);
        }

        parent::tearDown();
    }

    public function test_empty_dashboard_returns_real_zeroes_and_does_not_write(): void
    {
        $before = AuditEvent::count();
        $data = app(DashboardService::class)->data($this->admin);
        foreach ($data['summary'] as $value) {
            $this->assertSame(0, $value);
        }
        $this->assertSame([], $data['chart']['items']);
        $this->assertSame([], $data['topProducts']);
        $this->assertSame([], $data['recent']);
        $this->assertSame('Asia/Manila', $data['timezone']);
        $this->assertStringEndsWith('+08:00', $data['generatedAt']);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_stock_units_reservations_and_active_low_stock_are_separate(): void
    {
        $order = $this->newOrder();
        $stem = $order->items()->firstOrFail()->flower;
        InventoryReservation::create([
            'flower_id' => $stem->id, 'reference' => 'manual-audit-fixture',
            'quantity' => 15, 'status' => 'Active',
        ]);
        $bouquet = Flower::create([
            'code' => 'BOUQUET-001', 'name' => 'Finished Bouquet',
            'product_type' => 'Bouquet', 'category' => 'Roses',
            'minimum_stock' => 2, 'description' => 'Fixture', 'status' => 'Active',
        ]);
        app(InventoryService::class)->stockIn([
            'request_key' => (string) Str::uuid(), 'flower_id' => $bouquet->id,
            'quantity' => 1, 'unit' => 'Bouquet', 'received' => '2026-11-01',
            'harvest' => '2026-11-01', 'expiry' => null, 'remarks' => 'Finished bouquet.',
        ], $this->admin);
        $inactive = Flower::create([
            'code' => 'INACTIVE-001', 'name' => 'Inactive Product',
            'product_type' => 'Stem', 'category' => 'Roses', 'minimum_stock' => 10,
            'description' => 'Fixture', 'status' => 'Inactive',
        ]);
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(20, $data['summary']['stems']);
        $this->assertSame(1, $data['summary']['bouquets']);
        $this->assertSame(2, $data['summary']['lowStockItems']);
        $row = collect($data['chart']['items'])->firstWhere('code', $stem->code);
        $this->assertSame(20, $row['onHand']);
        $this->assertSame(15, $row['reserved']);
        $this->assertSame(5, $row['available']);
        $data = app(DashboardService::class)->data($this->admin, 'Bouquet');
        $this->assertCount(1, $data['chart']['items']);
        $this->assertSame($bouquet->code, $data['chart']['items'][0]['code']);
    }

    public function test_negative_availability_is_not_clamped_and_spoiled_batches_are_excluded(): void
    {
        $order = $this->newOrder();
        $flowerId = $order->items()->firstOrFail()->flower_id;
        InventoryReservation::create([
            'flower_id' => $flowerId, 'reference' => 'overbooked-fixture',
            'quantity' => 25, 'status' => 'Active',
        ]);
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(-5, $data['chart']['items'][0]['available']);
        InventoryBatch::where('flower_id', $flowerId)->firstOrFail()
            ->forceFill(['spoiled' => true])->save();
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(0, $data['summary']['stems']);
        $this->assertSame(-25, $data['chart']['items'][0]['available']);
    }

    public function test_pending_card_counts_only_orders_awaiting_confirmation(): void
    {
        $this->actingAs($this->admin);
        $order = $this->newOrder();
        $this->assertSame(1, app(DashboardService::class)->data($this->admin)['summary']['pendingOrders']);
        $this->advance($order, 'Confirmed');
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(0, $data['summary']['pendingOrders']);
        $this->assertSame(20, $data['summary']['stems']);
        $this->assertSame(18, $data['chart']['items'][0]['available']);
        $this->assertNotEmpty($data['recent']);
    }

    public function test_net_sales_use_completed_sales_and_refunds_while_rankings_keep_snapshots(): void
    {
        $this->actingAs($this->admin);
        $order = $this->newOrder();
        foreach (['Confirmed', 'Processing', 'Ready for Pickup', 'Completed'] as $status) {
            $this->advance($order, $status);
        }
        $payment = $this->pay($order->fresh());
        $sales = app(SalesPaymentService::class);
        foreach (['2.00', '3.00'] as $amount) {
            $paymentRow = collect($sales->data($this->admin)['payments'])->firstWhere('dbId', $payment->id);
            $sales->refund($payment, [
                'request_key' => (string) Str::uuid(), 'version' => $paymentRow['version'],
                'amount' => $amount, 'reason' => 'Verified fixture refund.',
            ], $this->admin);
        }
        $price = FlowerPrice::firstOrFail();
        $price->price_cents = 9900;
        $price->save();
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(2000, $data['summary']['grossSalesCents']);
        $this->assertSame(500, $data['summary']['refundCents']);
        $this->assertSame(1500, $data['summary']['netSalesCents']);
        $this->assertSame(18, $data['summary']['stems']);
        $this->assertSame(2000, $data['topProducts'][0]['grossCents']);
        $this->assertSame(2, $data['topProducts'][0]['quantity']);
    }

    public function test_payment_for_unfulfilled_order_does_not_create_sales_or_deduct_stock(): void
    {
        $order = $this->newOrder();
        $this->pay($order);
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(0, $data['summary']['netSalesCents']);
        $this->assertSame(20, $data['summary']['stems']);
        $this->assertSame([], $data['topProducts']);
    }

    public function test_dashboard_routes_render_for_admin_and_supplier_without_admin_link_leaks(): void
    {
        $this->signIn($this->admin);
        $this->get('/supplier/dashboard')->assertOk()
            ->assertSee('On-Hand Inventory')->assertDontSee('sample dashboard figures');
        $this->getJson('/supplier/dashboard/data')->assertOk()
            ->assertJsonPath('summary.stems', 0);
        $this->signIn($this->supplier);
        $this->get('/supplier/dashboard')->assertOk()
            ->assertDontSee('href="'.route('supplier.audit-trail').'"', false)
            ->assertDontSee('href="'.route('supplier.reports').'"', false)
            ->assertDontSee('href="'.route('supplier.users').'"', false);
        $this->getJson('/supplier/dashboard/data')->assertOk();
        $this->getJson('/supplier/dashboard/data?unit=Invalid')->assertUnprocessable();
        $delivery = $this->user('delivery', User::ROLE_DELIVERY);
        $this->signIn($delivery);
        $this->getJson('/supplier/dashboard/data')->assertForbidden();
    }

    public function test_supplier_summary_never_reads_or_exposes_security_audit_details(): void
    {
        $order = $this->newOrder();
        app(AuditService::class)->record(
            'Authentication', 'Private Security Marker',
            description: 'Never expose this security detail on the shared Dashboard.'
        );
        $before = AuditEvent::count();
        $data = app(DashboardService::class)->data($this->supplier);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private Security Marker', $json);
        $this->assertStringNotContainsString('Never expose this security detail', $json);
        $this->assertStringNotContainsString($this->admin->password, $json);
        $this->assertStringNotContainsString('client_email', $json);
        $this->assertSame($before, AuditEvent::count());
        $this->assertNotEmpty($data['recent']);
    }

    public function test_inactive_and_password_change_required_accounts_cannot_read_dashboard_service(): void
    {
        foreach (['is_active' => false, 'must_change_password' => true] as $field => $value) {
            $user = $this->supplier->fresh();
            $user->{$field} = $value;
            try {
                app(DashboardService::class)->data($user);
                $this->fail('Restricted account should not read Dashboard data.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    private function pay(Order $order): OrderPayment
    {
        $sales = app(SalesPaymentService::class);
        $row = collect($sales->data($this->admin)['orders'])->firstWhere('dbId', $order->id);
        $sales->recordPayment([
            'request_key' => (string) Str::uuid(), 'order_id' => $order->id,
            'version' => $row['version'], 'method' => 'Cash',
            'amount' => Money::format((int) $order->total_cents),
        ], $this->admin);
        $payment = OrderPayment::where('order_id', $order->id)->firstOrFail();
        $row = collect($sales->data($this->admin)['payments'])->firstWhere('dbId', $payment->id);
        $sales->confirm($payment, [
            'request_key' => (string) Str::uuid(), 'version' => $row['version'],
            'reason' => 'Actual fixture payment verified.',
        ], $this->admin);

        return $payment->fresh();
    }

    public function test_current_high_and_critical_alerts_are_counted_ordered_and_read_only(): void
    {
        $high = $this->riskBatch();
        $critical = $this->riskBatch('Bouquet');
        $low = $this->riskBatch();
        $this->forecast($high, 'High');
        $this->forecast($critical, 'Critical');
        $this->forecast($low, 'Low');
        $this->mock(SpoilagePythonService::class)->shouldNotReceive('predictMany')->shouldNotReceive('predict');
        $before = [BatchSpoilagePrediction::count(), BatchDailyObservation::count(), AuditEvent::count()];
        $data = app(DashboardService::class)->data($this->supplier);
        $this->assertSame(2, $data['summary']['spoilageRiskBatches']);
        $this->assertSame(1, $data['spoilage']['high']);
        $this->assertSame(1, $data['spoilage']['critical']);
        $this->assertSame(3, $data['spoilage']['current']);
        $this->assertSame(0, $data['spoilage']['unavailable']);
        $this->assertSame([$critical->code, $high->code], array_column($data['spoilage']['items'], 'batch'));
        $this->assertSame('Bouquet', $data['spoilage']['items'][0]['unit']);
        $this->assertSame($before, [BatchSpoilagePrediction::count(), BatchDailyObservation::count(), AuditEvent::count()]);
        $this->signIn($this->supplier);
        $this->get('/supplier/dashboard')->assertOk()->assertSee('Spoilage Risk Alerts')
            ->assertSee($critical->code)->assertSee('href="'.route('supplier.spoilage-prediction').'"', false)
            ->assertDontSee('href="'.route('supplier.audit-trail').'"', false);
    }

    public function test_stale_insufficient_missing_and_depleted_forecasts_never_become_alerts(): void
    {
        $stale = $this->riskBatch();
        $insufficient = $this->riskBatch();
        $missing = $this->riskBatch();
        $depleted = $this->riskBatch();
        $spoiled = $this->riskBatch();
        $this->forecast($stale, 'Critical');
        $this->forecast($insufficient, 'Critical', ['status' => 'InsufficientData', 'risk_level' => null,
            'predicted_fraction' => null, 'predicted_quantity' => null]);
        $this->forecast($depleted, 'High');
        $this->forecast($spoiled, 'Critical');
        $stale->forceFill(['storage_condition' => 'Chilled'])->save();
        $depleted->forceFill(['remaining_quantity' => 0])->save();
        $spoiled->forceFill(['spoiled' => true])->save();
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(0, $data['summary']['spoilageRiskBatches']);
        $this->assertSame(3, $data['spoilage']['total']);
        $this->assertSame(3, $data['spoilage']['unavailable']);
        $this->assertSame([], $data['spoilage']['items']);
    }

    public function test_changed_stock_history_settings_and_old_dates_invalidate_dashboard_alerts(): void
    {
        $stock = $this->riskBatch();
        $history = $this->riskBatch();
        $old = $this->riskBatch();
        $this->forecast($stock, 'High');
        $this->forecast($history, 'Critical');
        $this->forecast($old, 'Critical', ['as_of_date' => CarbonImmutable::now('Asia/Manila')->subDay()->toDateString()]);
        $stock->forceFill(['remaining_quantity' => 19])->save();
        BatchDailyObservation::where('batch_id', $history->id)->update(['is_complete' => false]);
        $this->assertSame(0, app(DashboardService::class)->data($this->supplier)['summary']['spoilageRiskBatches']);
        $fresh = $this->riskBatch();
        $this->forecast($fresh, 'High');
        $this->assertSame(1, app(DashboardService::class)->data($this->supplier)['summary']['spoilageRiskBatches']);
        config(['spoilage.model_version' => 'changed-settings']);
        $this->assertSame(0, app(DashboardService::class)->data($this->supplier)['summary']['spoilageRiskBatches']);
    }

    public function test_alert_list_limit_does_not_truncate_totals_or_mix_stock_units(): void
    {
        $batches = [];
        for ($index = 0; $index < 7; $index++) {
            $batches[] = $this->riskBatch($index % 2 ? 'Bouquet' : 'Stem');
        }
        foreach ($batches as $batch) {
            $this->forecast($batch, 'Critical');
        }
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(7, $data['summary']['spoilageRiskBatches']);
        $this->assertSame(7, $data['spoilage']['critical']);
        $this->assertCount(5, $data['spoilage']['items']);
        $this->assertArrayNotHasKey('totalPredictedQuantity', $data['spoilage']);
    }

    public function test_invalid_saved_results_are_not_shown_as_current_risk(): void
    {
        $risk = $this->riskBatch();
        $quantity = $this->riskBatch();
        $method = $this->riskBatch();
        $this->forecast($risk, 'Critical', ['predicted_fraction' => 0.05, 'predicted_quantity' => 1]);
        $this->forecast($quantity, 'High', ['predicted_quantity' => 100]);
        $this->forecast($method, 'High', ['method' => 'Unsupported model']);
        $data = app(DashboardService::class)->data($this->admin);
        $this->assertSame(0, $data['summary']['spoilageRiskBatches']);
        $this->assertSame(3, $data['spoilage']['unavailable']);
    }

    private function riskBatch(string $unit = 'Stem'): InventoryBatch
    {
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $received = $today->subDays(8)->toDateString();
        $flower = Flower::create([
            'code' => 'RISK-'.Str::ulid(), 'name' => 'Risk fixture '.$unit, 'product_type' => $unit,
            'category' => 'Roses', 'minimum_stock' => 1, 'description' => 'Fixture', 'status' => 'Active',
        ]);
        $result = app(InventoryService::class)->stockIn([
            'request_key' => (string) Str::uuid(), 'flower_id' => $flower->id, 'quantity' => 20,
            'unit' => $unit, 'received' => $received, 'harvest' => $received, 'expiry' => null,
            'storage_condition' => 'Ambient', 'remarks' => 'Isolated prediction fixture',
        ], $this->admin);
        $batch = InventoryBatch::findOrFail($result['batch_id']);
        for ($days = 6; $days >= 1; $days--) {
            BatchDailyObservation::create([
                'batch_id' => $batch->id, 'flower_id' => $flower->id,
                'observation_date' => $today->subDays($days)->toDateString(),
                'harvest_date' => $received, 'received_date' => $received,
                'storage_condition' => 'Ambient', 'base_unit' => $unit, 'opening_quantity' => 20,
                'spoiled_quantity' => 0, 'released_quantity' => 0, 'closing_quantity' => 20, 'is_complete' => true,
            ]);
        }

        return $batch;
    }

    private function forecast(InventoryBatch $batch, string $risk, array $overrides = []): BatchSpoilagePrediction
    {
        $plan = app(SpoilagePredictionService::class)->plan([$batch->id])[$batch->id];
        $fraction = ['Low' => 0.05, 'Medium' => 0.15, 'High' => 0.30, 'Critical' => 0.60][$risk];

        return BatchSpoilagePrediction::create([
            'batch_id' => $batch->id, 'as_of_date' => $plan['payload']['as_of_date'],
            'forecast_end_date' => CarbonImmutable::parse($plan['payload']['as_of_date'])->addDays(6)->toDateString(),
            'opening_quantity' => $batch->remaining_quantity, 'base_unit' => $batch->flower->product_type,
            'model_version' => config('spoilage.model_version'), 'input_hash' => $plan['hash'],
            'status' => 'Predicted', 'method' => 'ARIMA own-batch history', 'risk_level' => $risk,
            'predicted_fraction' => $fraction, 'predicted_quantity' => $fraction * $batch->remaining_quantity,
            'history_days' => 6, 'generated_at' => now(), ...$overrides,
        ]);
    }

    private function migrateOne(string $suffix): void
    {
        $files = glob(database_path('migrations/*_'.$suffix.'.php')) ?: [];
        $this->assertCount(1, $files, 'Expected one '.$suffix.' migration.');
        (require $files[0])->up();
    }

    private function user(string $label, string $role): User
    {
        return User::forceCreate([
            'name' => 'Audit '.$label,
            'email' => $label.'@audit.example.test',
            'password' => 'Audit-test-password-2026!',
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function flower(): Flower
    {
        return Flower::create([
            'code' => 'FLW-AUDIT-001',
            'name' => 'Audit Rose',
            'product_type' => 'Stem',
            'category' => 'Roses',
            'minimum_stock' => 10,
            'description' => 'Isolated audit fixture.',
            'image_path' => null,
            'status' => 'Active',
        ]);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession([
            AccountSession::KEY => AccountSession::signature($user),
        ]);
    }

    private function newOrder(): Order
    {
        $user = $this->user('client', User::ROLE_CLIENT);
        $client = Client::create([
            'code' => 'CLI-AUDIT-001',
            'user_id' => $user->id,
            'phone' => '09170000000',
            'address' => 'Audit test address',
        ]);

        $flower = $this->flower();
        FlowerPrice::create(['flower_id' => $flower->id, 'price_cents' => 1000]);

        app(InventoryService::class)->stockIn([
            'request_key' => (string) Str::uuid(),
            'flower_id' => $flower->id,
            'quantity' => 20,
            'unit' => 'Stem',
            'received' => '2026-11-01',
            'harvest' => '2026-11-01',
            'expiry' => null,
            'remarks' => 'Isolated audit receipt.',
        ], $this->admin);

        return app(OrderService::class)->store([
            'request_key' => (string) Str::uuid(),
            'client_id' => $client->id,
            'method' => 'Customer Pickup',
            'address' => null,
            'notes' => null,
            'items' => [['flower_id' => $flower->id, 'quantity' => 2]],
        ], $this->admin);
    }

    private function advance(Order $order, string $status): void
    {
        $orders = app(OrderService::class);

        $orders->action($order, [
            'status' => $status,
            'version' => $orders->row($order->fresh(), $this->admin)['version'],
            'reason' => 'Isolated cancellation test.',
            'collected' => $status === 'Completed',
        ], $this->admin);
    }
}
