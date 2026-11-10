<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\InventoryBatch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\AccountSession;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AuditBackendTest extends TestCase
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
            $this->markTestSkipped('These tests require pdo_sqlite.');
        }

        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        config([
            'database.connections.audit_backend_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'audit_backend_test',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'app.timezone' => 'UTC',
            'hashing.bcrypt.rounds' => 4,
        ]);

        $this->isolated = true;
        DB::purge('audit_backend_test');
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
            DB::disconnect('audit_backend_test');
        }

        if (isset($this->timezone)) {
            date_default_timezone_set($this->timezone);
        }

        parent::tearDown();
    }

    public function test_model_changes_preserve_previous_and_new_values(): void
    {
        $this->actingAs($this->admin);
        $flower = $this->flower();
        $flower->minimum_stock = 11;
        $flower->save();
        $flower->minimum_stock = 12;
        $flower->save();

        $events = AuditEvent::where('record_type', Flower::class)
            ->where('record_id', $flower->id)->orderBy('id')->get();

        $this->assertCount(3, $events);
        $this->assertSame('Created', $events[0]->action);
        $this->assertSame(10, (int) $events[1]->before_values['minimum_stock']);
        $this->assertSame(11, (int) $events[1]->after_values['minimum_stock']);
        $this->assertSame(11, (int) $events[2]->before_values['minimum_stock']);
        $this->assertSame(12, (int) $events[2]->after_values['minimum_stock']);
        $this->assertSame($this->admin->id, $events[2]->actor_id);
    }

    public function test_secrets_are_excluded_and_password_changes_use_a_flag(): void
    {
        $this->actingAs($this->admin);
        $password = 'New-audit-password-2026!';
        $this->supplier->password = $password;
        $this->supplier->save();

        $event = AuditEvent::where('action', 'Credentials Changed')->firstOrFail();
        $this->assertTrue($event->after_values['credential_changed']);
        $this->assertArrayNotHasKey('password', $event->before_values);
        $this->assertArrayNotHasKey('password', $event->after_values);

        $safe = app(AuditService::class)->record(
            'User Management',
            'Safety Test',
            after: [
                'status' => 'Active',
                'password' => $password,
                'token' => 'secret-token-marker',
                'image_path' => 'private/image-marker.jpg',
                'metadata' => ['password' => $password],
            ]
        );

        $this->assertSame(['status' => 'Active'], $safe->after_values);
        $serialized = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString($password, $serialized);
        $this->assertStringNotContainsString($this->supplier->password, $serialized);
        $this->assertStringNotContainsString('secret-token-marker', $serialized);
        $this->assertStringNotContainsString('private/image-marker.jpg', $serialized);
    }

    public function test_transaction_rollback_also_rolls_back_audit_entries(): void
    {
        $flower = $this->flower();
        $before = AuditEvent::count();

        try {
            DB::transaction(function () use ($flower) {
                $flower->minimum_stock = 99;
                $flower->save();
                throw new RuntimeException('Intentional rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Intentional rollback.', $exception->getMessage());
        }

        $this->assertSame(10, (int) $flower->fresh()->minimum_stock);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_audit_models_reject_updates_and_deletes(): void
    {
        $event = app(AuditService::class)->record('Reports', 'Original Event');

        try {
            $event->action = 'Changed Event';
            $event->save();
            $this->fail('Audit updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('Original Event', $event->fresh()->action);
        }

        try {
            $event->delete();
            $this->fail('Audit deletes must be rejected.');
        } catch (LogicException) {
            $this->assertNotNull($event->fresh());
        }
    }

    public function test_real_login_logout_and_bad_password_are_logged_once(): void
    {
        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'Audit-test-password-2026!',
        ])->assertRedirect(route('supplier.dashboard'));

        $this->post('/logout')->assertRedirect(route('login'));

        $this->postJson('/login', [
            'email' => $this->admin->email,
            'password' => 'wrong-audit-password-marker',
        ])->assertUnprocessable();

        foreach (['Login Accepted', 'Session Ended', 'Login Failed'] as $action) {
            $this->assertSame(1, AuditEvent::where('action', $action)->count());
        }

        $this->assertStringNotContainsString(
            'wrong-audit-password-marker',
            AuditEvent::all()->toJson()
        );
    }

    public function test_supplier_is_denied_audit_access_and_denial_is_logged(): void
    {
        $this->signIn($this->supplier);
        $this->getJson('/supplier/audit-trail/data')->assertForbidden();

        $events = AuditEvent::where('action', 'Access Denied')->get();
        $this->assertCount(1, $events);
        $this->assertSame('Failed', $events[0]->result);
        $this->assertSame($this->supplier->id, $events[0]->actor_id);
    }

    public function test_invalid_request_is_logged_once_without_raw_input(): void
    {
        $this->signIn($this->admin);
        $this->getJson('/supplier/audit-trail/data?result=Invalid&token=private-marker')
            ->assertUnprocessable();

        $events = AuditEvent::where('action', 'Validation Rejected')->get();
        $this->assertCount(1, $events);
        $this->assertSame('Failed', $events[0]->result);
        $this->assertSame('supplier.audit-trail.data', $events[0]->route_name);
        $this->assertNotEmpty($events[0]->ip_address);
        $this->assertStringNotContainsString('private-marker', $events->toJson());
    }

    public function test_manila_date_filters_search_and_reads_preserve_history(): void
    {
        $this->signIn($this->admin);
        Date::setTestNow('2026-11-10 15:59:59');
        app(AuditService::class)->record('Reports', 'Before Midnight');
        Date::setTestNow('2026-11-10 16:00:00');
        app(AuditService::class)->record('Reports', 'After Midnight');
        Date::setTestNow();

        $before = AuditEvent::count();
        $response = $this->getJson('/supplier/audit-trail/data?'.http_build_query([
            'from' => '2026-11-10',
            'to' => '2026-11-10',
            'module' => 'Reports',
            'actor' => $this->admin->id,
            'result' => 'Success',
            'search' => 'Before Midnight',
        ]))->assertOk();

        $response->assertJsonPath('filtered', 1);
        $response->assertJsonPath('data.0.action', 'Before Midnight');
        $this->assertStringEndsWith('+08:00', $response->json('data.0.date'));
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_cancellation_preserves_confirmation_and_logs_released_holds(): void
    {
        $this->actingAs($this->admin);
        $order = $this->newOrder();
        $this->advance($order, 'Confirmed');
        $hold = InventoryReservation::where('reference', $order->code)->firstOrFail();
        $this->advance($order, 'Cancelled');

        $events = AuditEvent::where('record_type', Order::class)
            ->where('record_id', $order->id)->orderBy('id')->get();

        $statuses = $events->map(fn ($event) => $event->after_values['status'] ?? null)->all();
        $this->assertSame(['Pending', 'Confirmed', 'Cancelled'], $statuses);

        $release = AuditEvent::where('record_type', InventoryReservation::class)
            ->where('record_id', $hold->id)->where('action', 'Updated')->latest('id')->firstOrFail();

        $this->assertSame('Active', $release->before_values['status']);
        $this->assertSame('Released', $release->after_values['status']);
        $this->assertSame(20, (int) InventoryBatch::sum('remaining_quantity'));
    }

    public function test_order_edits_log_deleted_and_replacement_item_snapshots(): void
    {
        $this->actingAs($this->admin);
        $order = $this->newOrder();
        $oldItem = $order->items()->firstOrFail();
        $orders = app(OrderService::class);

        $orders->update($order, [
            'client_id' => $order->client_id,
            'method' => 'Customer Pickup',
            'address' => null,
            'notes' => null,
            'version' => $orders->row($order->fresh(), $this->admin)['version'],
            'items' => [['flower_id' => $oldItem->flower_id, 'quantity' => 3]],
        ], $this->admin);

        $deleted = AuditEvent::where('record_type', OrderItem::class)
            ->where('record_id', $oldItem->id)->where('action', 'Deleted')->firstOrFail();

        $newItem = $order->items()->firstOrFail();
        $created = AuditEvent::where('record_type', OrderItem::class)
            ->where('record_id', $newItem->id)->where('action', 'Created')->firstOrFail();

        $this->assertSame(2, (int) $deleted->before_values['quantity']);
        $this->assertSame([], $deleted->after_values);
        $this->assertSame(3, (int) $created->after_values['quantity']);
        $this->assertNotSame($oldItem->id, $newItem->id);
    }

    public function test_export_returns_all_matching_rows_not_only_the_visible_page(): void
    {
        $this->signIn($this->admin);

        for ($i = 0; $i < 12; $i++) {
            app(AuditService::class)->record('Reports', 'Export Fixture');
        }

        app(AuditService::class)->record('User Management', 'Excluded Fixture');

        $url = '/supplier/audit-trail/data?module=Reports&per_page=5';
        $this->getJson($url)->assertOk()->assertJsonCount(5, 'data');
        $this->getJson($url.'&export=1')->assertOk()
            ->assertJsonCount(12, 'data')->assertJsonPath('filtered', 12);
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
        ], $this->admin);
    }
}