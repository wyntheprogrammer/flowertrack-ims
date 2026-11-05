<?php

namespace Tests\Feature;

use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryBackendTest extends TestCase
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
            'database.connections.inventory_backend_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'inventory_backend_test',
        ]);
        $this->testConnectionConfigured = true;
        DB::purge('inventory_backend_test');
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
            DB::disconnect('inventory_backend_test');
        }
        parent::tearDown();
    }

    /**
     * A same-date FIFO tie follows numeric primary keys, including BAT-010.
     */
    public function test_fifo_uses_received_date_then_numeric_batch_id(): void
    {
        $batches = [];
        for ($i = 0; $i < 10; $i++) {
            $batches[] = $this->receive(1);
        }
        $this->issue(2);
        $this->assertSame(0, $batches[0]->fresh()->remaining_quantity);
        $this->assertSame(0, $batches[1]->fresh()->remaining_quantity);
        $this->assertSame(1, $batches[9]->fresh()->remaining_quantity);
        $this->assertSame('BAT-010', $batches[9]->code);

        $older = $this->receive(2, ['received' => '2025-12-30', 'harvest' => '2025-12-29']);
        $this->issue(1);
        $this->assertSame(1, $older->fresh()->remaining_quantity);
        $this->assertSame(1, $batches[2]->fresh()->remaining_quantity);
    }

    /**
     * Physical stock reserved for orders cannot be manually issued.
     */
    public function test_stock_out_protects_active_reservations(): void
    {
        $batch = $this->receive(10);
        InventoryReservation::create([
            'flower_id' => $this->flower->id, 'reference' => 'TEST-ORDER',
            'quantity' => 7, 'status' => 'Active',
        ]);
        $this->rejected(fn () => $this->issue(4), 'quantity');
        $this->assertSame(10, $batch->fresh()->remaining_quantity);
        $this->assertSame(1, InventoryOperation::count());
        $this->issue(3);
        $this->assertSame(7, $batch->fresh()->remaining_quantity);
        $this->assertSame('Active', InventoryReservation::first()->status);
    }

    /**
     * A failed selected-batch allocation rolls back stock, ledger, and operation together.
     */
    public function test_insufficient_batch_override_is_fully_rolled_back(): void
    {
        $first = $this->receive(10);
        $second = $this->receive(10);
        $this->rejected(fn () => $this->issue(15, [
            'batch_id' => $first->id, 'remarks' => 'Use this exact batch.',
        ]), 'batch_id');
        $this->assertSame(10, $first->fresh()->remaining_quantity);
        $this->assertSame(10, $second->fresh()->remaining_quantity);
        $this->assertSame(2, InventoryOperation::count());
        $this->assertSame(2, InventoryMovement::count());
    }

    /**
     * A repeated operation UUID returns its original result without another physical receipt.
     */
    public function test_matching_uuid_retry_does_not_duplicate_receipt_or_ledger(): void
    {
        $payload = $this->receiptInput(10);
        $first = $this->inventory->stockIn($payload, $this->actor);
        $retry = $this->inventory->stockIn($payload, $this->actor);
        $this->assertSame($first, $retry);
        $this->assertSame(1, InventoryBatch::count());
        $this->assertSame(1, InventoryOperation::count());
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(10, (int) InventoryBatch::sum('remaining_quantity'));
    }

    /**
     * The same UUID cannot be reused to receive a different quantity.
     */
    public function test_reused_uuid_with_changed_payload_is_rejected(): void
    {
        $payload = $this->receiptInput(10);
        $this->inventory->stockIn($payload, $this->actor);
        $payload['quantity'] = 20;
        $this->rejected(fn () => $this->inventory->stockIn($payload, $this->actor), 'request_key');
        $this->assertSame(10, (int) InventoryBatch::sum('remaining_quantity'));
        $this->assertSame(1, InventoryOperation::count());
        $this->assertSame(1, InventoryMovement::count());
    }

    /**
     * Corrections preserve consumption, append a signed delta, and retain original history.
     */
    public function test_receipt_correction_preserves_consumption_and_append_only_history(): void
    {
        $batch = $this->receive(10);
        $original = InventoryMovement::first()->getRawOriginal();
        $this->issue(4);
        $this->inventory->correctReceipt($batch, $this->receiptInput(8, [
            'remarks' => 'Original receipt overstated by two.',
        ]), $this->actor);
        $this->assertSame(8, $batch->fresh()->initial_quantity);
        $this->assertSame(4, $batch->fresh()->remaining_quantity);
        $correction = InventoryMovement::orderByDesc('id')->first();
        $this->assertSame('Receipt Correction', $correction->type);
        $this->assertSame(-2, $correction->quantity);
        $this->assertSame(10, $correction->metadata['before']['initial']);
        $this->assertSame(8, $correction->metadata['after']['initial']);
        $this->assertSame($original, InventoryMovement::findOrFail($original['id'])->getRawOriginal());
        $this->rejected(fn () => $this->inventory->correctReceipt($batch, $this->receiptInput(3, [
            'remarks' => 'Invalid below consumed quantity.',
        ]), $this->actor), 'quantity');
        $this->assertSame(4, $batch->fresh()->remaining_quantity);
        $this->assertSame(3, InventoryMovement::count());
        $this->assertSame(3, InventoryOperation::count());
    }

    /**
     * Settings affect new receipts, while same-unit corrections retain their original factor.
     */
    public function test_conversion_snapshots_and_finished_bouquet_stock_stay_separate(): void
    {
        $old = $this->receive(2, ['unit' => 'Bunch']);
        $this->inventory->updateUnits($this->flower, [
            'bunch_factor' => 12, 'bundle_factor' => 24, 'box_factor' => 120,
        ]);
        $new = $this->receive(1, ['unit' => 'Bunch']);
        $this->inventory->correctReceipt($old, $this->receiptInput(3, [
            'unit' => 'Bunch', 'remarks' => 'Correct original bunch count.',
        ]), $this->actor);
        $this->assertSame(10, $old->fresh()->conversion_factor);
        $this->assertSame(30, $old->fresh()->remaining_quantity);
        $this->assertSame(12, $new->conversion_factor);
        $bouquet = $this->item('Bouquet');
        $result = $this->inventory->stockIn($this->receiptInput(2, [
            'flower_id' => $bouquet->id, 'unit' => 'Bouquet',
        ]), $this->actor);
        $finished = InventoryBatch::findOrFail($result['batch_id']);
        $this->assertSame(1, $finished->conversion_factor);
        $this->assertSame(2, $finished->remaining_quantity);
        $this->assertSame(42, (int) InventoryBatch::where('flower_id', $this->flower->id)->sum('remaining_quantity'));
        $this->rejected(fn () => $this->inventory->stockIn($this->receiptInput(1, [
            'flower_id' => $bouquet->id, 'unit' => 'Stem',
        ]), $this->actor), 'unit');
    }

    /**
     * Partial spoilage leaves a usable remainder; only a fully depleted allocation is spoiled.
     */
    public function test_partial_and_full_spoilage_and_spoiled_receipt_lock(): void
    {
        $batch = $this->receive(10);
        $this->issue(3, ['reason' => 'Spoilage', 'remarks' => 'Three damaged stems.']);
        $this->assertSame(7, $batch->fresh()->remaining_quantity);
        $this->assertFalse($batch->fresh()->spoiled);
        $this->issue(7, ['reason' => 'Spoilage', 'remarks' => 'Remaining stems spoiled.']);
        $this->assertSame(0, $batch->fresh()->remaining_quantity);
        $this->assertTrue($batch->fresh()->spoiled);
        $this->rejected(fn () => $this->inventory->correctReceipt($batch, $this->receiptInput(11, [
            'remarks' => 'Cannot revive a spoiled receipt.',
        ]), $this->actor), 'batch_id');
        $this->assertSame(0, (int) InventoryMovement::sum('quantity'));
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
