<?php

namespace Tests\Feature;

use App\Http\Requests\StockInRequest;
use App\Http\Requests\UpdateBatchRequest;
use App\Models\BatchDailyObservation;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchBackendTest extends TestCase
{
    private InventoryService $inventory;

    private User $actor;

    private Flower $flower;

    private bool $testConnectionConfigured = false;

    /**
     * Build only an isolated named in-memory database; never migrate or refresh the user's DB.
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The isolated inventory tests require pdo_sqlite.');
        }
        config([
            'database.connections.batch_backend_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'batch_backend_test',
        ]);
        $this->testConnectionConfigured = true;
        DB::purge('batch_backend_test');
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
        // Artisan timestamps vary; locate the migration by its stable descriptive name.
        $migrationFiles = glob(database_path('migrations/*_create_inventory_tables.php')) ?: [];
        $this->assertCount(
            1,
            $migrationFiles,
            'Expected exactly one *_create_inventory_tables.php file in database/migrations.'
        );
        (require $migrationFiles[0])->up();
        (require database_path('migrations/2026_11_14_144642_create_batch_daily_observations_table.php'))->up();
        $this->actor = User::forceCreate([
            'name' => 'Inventory Test Admin', 'email' => 'inventory@example.test',
            'password' => 'test-only-password', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'must_change_password' => false,
        ]);
        $this->flower = $this->item();
        $this->inventory = app(InventoryService::class);
    }

    /**
     * Close only the named ephemeral test connection.
     */
    protected function tearDown(): void
    {
        if ($this->testConnectionConfigured) {
            DB::disconnect('batch_backend_test');
        }
        parent::tearDown();
    }

    /**
     * Metadata edits append a zero movement while preserving all physical receipt fields.
     */
    public function test_metadata_edit_keeps_shared_inventory_stock_and_identity(): void
    {
        $batch = $this->receive(10);
        $before = $batch->getRawOriginal();
        $version = $this->inventory->batchVersion($batch);
        $this->inventory->updateBatchMetadata($batch, $this->metadataInput($batch, [
            'harvest' => '2025-12-31', 'expiry' => '2026-01-01', 'remarks' => 'Corrected freshness note.',
        ]), $this->actor);
        $after = $batch->fresh();
        foreach (['id', 'code', 'flower_id', 'received_date', 'received_quantity', 'receiving_unit',
            'conversion_factor', 'initial_quantity', 'remaining_quantity', 'spoiled'] as $field) {
            $this->assertSame($before[$field], $after->getRawOriginal($field), $field);
        }
        $this->assertSame('2025-12-31', $after->harvest_date->format('Y-m-d'));
        $this->assertSame('2026-01-01', $after->expiry_date->format('Y-m-d'));
        $this->assertSame(10, $after->remaining_quantity);
        $this->assertFalse($after->spoiled); // A past freshness date is advisory only.
        $movement = InventoryMovement::orderByDesc('id')->first();
        $this->assertSame('Batch Metadata Update', $movement->type);
        $this->assertSame(0, $movement->quantity);
        $this->assertSame('2026-01-01', $movement->metadata['before']['harvest']);
        $this->assertSame('2025-12-31', $movement->metadata['after']['harvest']);
        $this->assertSame(10, (int) InventoryMovement::sum('quantity'));
        $this->assertNotSame($version, $this->inventory->batchVersion($after));
        $stable = $this->inventory->batchVersion($after);
        $after->updated_at = now()->addMinute();
        $this->assertSame($stable, $this->inventory->batchVersion($after));
    }

    /**
     * Date checks use the locked current received date and reject invalid ordering atomically.
     */
    public function test_invalid_metadata_dates_leave_stock_and_audit_unchanged(): void
    {
        $batch = $this->receive(10);
        $this->rejected(fn () => $this->inventory->updateBatchMetadata($batch,
            $this->metadataInput($batch, ['harvest' => '2026-01-03']), $this->actor), 'harvest');
        $this->rejected(fn () => $this->inventory->updateBatchMetadata($batch,
            $this->metadataInput($batch, ['expiry' => '2025-12-31']), $this->actor), 'expiry');
        $this->assertSame('2026-01-01', $batch->fresh()->harvest_date->format('Y-m-d'));
        $this->assertSame(10, $batch->fresh()->remaining_quantity);
        $this->assertSame(1, InventoryOperation::count());
        $this->assertSame(1, InventoryMovement::count());
    }

    /**
     * Stock changes after form opening invalidate its version rather than overwriting new data.
     */
    public function test_stale_version_is_rejected_after_inventory_stock_out(): void
    {
        $batch = $this->receive(10);
        $payload = $this->metadataInput($batch);
        $this->issue(2);
        $this->rejected(fn () => $this->inventory->updateBatchMetadata($batch, $payload, $this->actor), 'version');
        $this->assertSame(8, $batch->fresh()->remaining_quantity);
        $this->assertSame(2, InventoryOperation::count());
        $this->assertSame(2, InventoryMovement::count());
    }

    /**
     * A matching retry replays before comparing the now-old version, without duplicate history.
     */
    public function test_metadata_uuid_retry_replays_after_original_version_changes(): void
    {
        $batch = $this->receive(10);
        $payload = $this->metadataInput($batch, ['remarks' => 'Saved once.']);
        $first = $this->inventory->updateBatchMetadata($batch, $payload, $this->actor);
        $retry = $this->inventory->updateBatchMetadata($batch->fresh(), $payload, $this->actor);
        $this->assertSame($first, $retry);
        $this->assertSame(2, InventoryOperation::count());
        $this->assertSame(2, InventoryMovement::count());
    }

    /**
     * A reused UUID cannot silently apply different metadata.
     */
    public function test_conflicting_uuid_does_not_apply_a_second_metadata_change(): void
    {
        $batch = $this->receive(10);
        $payload = $this->metadataInput($batch, ['remarks' => 'First note.']);
        $this->inventory->updateBatchMetadata($batch, $payload, $this->actor);
        $payload['remarks'] = 'Different note.';
        $this->rejected(fn () => $this->inventory->updateBatchMetadata($batch, $payload, $this->actor), 'request_key');
        $this->assertSame('First note.', $batch->fresh()->remarks);
        $this->assertSame(2, InventoryOperation::count());
        $this->assertSame(2, InventoryMovement::count());
    }

    /**
     * Editing a spoiled batch's notes cannot revive it or remove spoilage history.
     */
    public function test_spoiled_batch_metadata_preserves_spoilage_and_zero_stock(): void
    {
        $batch = $this->receive(10);
        $this->issue(10, ['reason' => 'Spoilage', 'remarks' => 'All stems deteriorated.']);
        $spoiled = $batch->fresh();
        $this->inventory->updateBatchMetadata($spoiled,
            $this->metadataInput($spoiled, ['remarks' => 'Additional inspection note.']), $this->actor);
        $this->assertTrue($batch->fresh()->spoiled);
        $this->assertSame(0, $batch->fresh()->remaining_quantity);
        $this->assertSame(0, (int) InventoryMovement::sum('quantity'));
        $this->assertSame('Spoilage', InventoryMovement::where('type', 'Stock-Out')->first()->metadata['reason']);
    }

    /**
     * HTTP validation rejects stock/identity tampering and noncanonical metadata dates.
     */
    public function test_request_prohibits_stock_fields_and_requires_valid_metadata(): void
    {
        $batch = $this->receive(10);
        $rules = (new UpdateBatchRequest)->rules();
        $payload = $this->metadataInput($batch);
        $this->assertTrue(Validator::make($payload, $rules)->passes());
        foreach ($rules as $field => $fieldRules) {
            if ($fieldRules === ['prohibited']) {
                $validator = Validator::make([...$payload, $field => 1], $rules);
                $this->assertTrue($validator->fails(), $field);
                $this->assertArrayHasKey($field, $validator->errors()->toArray());
            }
        }
        $this->assertTrue(Validator::make([...$payload, 'harvest' => '01/01/2026'], $rules)->fails());
        $this->assertTrue(Validator::make([...$payload, 'version' => str_repeat('z', 64)], $rules)->fails());
    }

    /**
     * Storage edits preserve stock and historical observations while recording changes.
     */
    public function test_storage_capture_and_edits_preserve_stock_and_append_history(): void
    {
        $unknown = $this->receive(10);
        $batch = $this->receive(20, ['storage_condition' => ' Ambient ']);
        $this->assertNull($unknown->fresh()->storage_condition);
        $this->assertSame('Ambient', $batch->storage_condition);
        $receipt = InventoryMovement::where('batch_id', $batch->id)->first();
        $this->assertSame('Ambient', $receipt->metadata['receipt']['storage_condition']);
        $observation = BatchDailyObservation::create([
            'batch_id' => $batch->id, 'flower_id' => $batch->flower_id,
            'observation_date' => '2026-01-02', 'harvest_date' => '2026-01-01',
            'received_date' => '2026-01-02', 'storage_condition' => 'Ambient',
            'base_unit' => 'Stem', 'opening_quantity' => 20, 'spoiled_quantity' => 0,
            'released_quantity' => 0, 'closing_quantity' => 20, 'is_complete' => true,
        ]);
        $historicalValues = $observation->fresh()->getRawOriginal();
        $oldVersion = $this->inventory->batchVersion($batch);
        $payload = $this->metadataInput($batch, ['storage_condition' => 'Chilled']);
        $this->inventory->updateBatchMetadata($batch, $payload, $this->actor);
        $this->inventory->updateBatchMetadata($batch, $payload, $this->actor);
        $batch->refresh();
        $this->assertSame('Chilled', $batch->storage_condition);
        $this->assertSame(20, $batch->remaining_quantity);
        $this->assertNotSame($oldVersion, $this->inventory->batchVersion($batch));
        $movement = InventoryMovement::where('batch_id', $batch->id)->latest('id')->first();
        $this->assertSame(0, $movement->quantity);
        $this->assertSame('Ambient', $movement->metadata['before']['storage_condition']);
        $this->assertSame('Chilled', $movement->metadata['after']['storage_condition']);
        $this->assertSame(2, InventoryMovement::where('batch_id', $batch->id)->count());
        $this->assertSame($historicalValues, $observation->fresh()->getRawOriginal());
        $this->rejected(fn () => $this->inventory->updateBatchMetadata($batch,
            $this->metadataInput($batch, ['version' => $oldVersion, 'storage_condition' => 'Ambient']),
            $this->actor), 'version');
        $this->inventory->updateBatchMetadata($batch,
            $this->metadataInput($batch, ['storage_condition' => null]), $this->actor);
        $this->assertNull($batch->fresh()->storage_condition);
    }

    public function test_storage_validation_requires_new_receipt_condition_and_allows_unknown_legacy_batches(): void
    {
        $rules = (new StockInRequest)->rules();
        $receipt = $this->receiptInput(10);
        foreach ([null, '', str_repeat('x', 31), ['Ambient']] as $condition) {
            $this->assertTrue(Validator::make([...$receipt, 'storage_condition' => $condition], $rules)->fails());
        }
        $this->assertTrue(Validator::make([...$receipt, 'storage_condition' => 'Ambient'], $rules)->passes());
        $batch = $this->receive(10);
        $rules = (new UpdateBatchRequest)->rules();
        $this->assertTrue(Validator::make($this->metadataInput($batch, ['storage_condition' => null]), $rules)->passes());
        $this->assertTrue(Validator::make($this->metadataInput($batch, ['storage_condition' => str_repeat('x', 31)]), $rules)->fails());
        $snapshot = app(AuditService::class)->snapshot($batch);
        $this->assertArrayHasKey('storage_condition', $snapshot);
        $this->assertNull($snapshot['storage_condition']);
    }

    /**
     * Supply an edit token from the shared current inventory record.
     */
    private function metadataInput(InventoryBatch $batch, array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'version' => $this->inventory->batchVersion($batch),
            'harvest' => $batch->harvest_date->format('Y-m-d'),
            'expiry' => $batch->expiry_date?->format('Y-m-d'),
            'remarks' => $batch->remarks,
        ], $overrides);
    }

    /**
     * Create a catalog item without depending on seeders or production data.
     */
    private function item(string $type = 'Stem'): Flower
    {
        return Flower::forceCreate([
            'code' => 'TST-'.Str::ulid(), 'name' => 'Test '.$type,
            'product_type' => $type, 'category' => 'Roses', 'minimum_stock' => 1,
            'description' => 'Isolated test item.', 'status' => 'Active',
        ]);
    }

    /**
     * Supply valid canonical receipt fields with a fresh operation UUID.
     */
    private function receiptInput(int $quantity, array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(), 'flower_id' => $this->flower->id,
            'quantity' => $quantity, 'unit' => 'Stem', 'received' => '2026-01-02',
            'harvest' => '2026-01-01', 'expiry' => null, 'remarks' => null,
        ], $overrides);
    }

    /**
     * Receive a test batch through the real service rather than editing quantities directly.
     */
    private function receive(int $quantity, array $overrides = []): InventoryBatch
    {
        $result = $this->inventory->stockIn($this->receiptInput($quantity, $overrides), $this->actor);

        return InventoryBatch::findOrFail($result['batch_id']);
    }

    /**
     * Issue stock through the same service used by the production controller.
     */
    private function issue(int $quantity, array $overrides = []): array
    {
        return $this->inventory->stockOut(array_replace([
            'request_key' => (string) Str::uuid(), 'flower_id' => $this->flower->id,
            'quantity' => $quantity, 'reason' => 'Other use', 'batch_id' => null,
            'remarks' => 'Test stock issue.',
        ], $overrides), $this->actor);
    }

    /**
     * Assert a domain validation failure while allowing rollback assertions afterward.
     */
    private function rejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected inventory validation to reject this operation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
