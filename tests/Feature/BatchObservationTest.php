<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BatchDailyObservation;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\BatchDayClosingService;
use App\Services\BatchObservationService;
use App\Services\InventoryService;
use App\Services\SpoilagePredictionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchObservationTest extends TestCase
{
    private User $actor;

    private Flower $flower;

    private InventoryService $inventory;

    private BatchObservationService $collector;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Requires isolated SQLite.');
        }
        config([
            'database.default' => 'observation_test',
            'database.connections.observation_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'spoilage.observation_timezone' => 'Asia/Manila',
        ]);
        DB::purge('observation_test');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach (glob(database_path('migrations/*.php')) as $migration) {
            (require $migration)->up();
        }
        $this->at('2026-11-10 01:00:00');
        $this->actor = User::factory()->create([
            'role' => User::ROLE_ADMIN, 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->flower = Flower::create([
            'code' => 'FLR-001', 'name' => 'Rose', 'product_type' => 'Stem',
            'category' => 'Rose', 'minimum_stock' => 1, 'description' => 'Test', 'status' => 'Active',
        ]);
        $this->inventory = app(InventoryService::class);
        $this->collector = app(BatchObservationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        DB::purge('observation_test');
        parent::tearDown();
    }

    public function test_preview_never_invents_confirmed_zero_days_and_save_is_idempotent(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-13 01:00:00');
        $preview = $this->collector->collect('2026-11-10', '2026-11-12', $batch->id);
        $this->assertSame(['Ready', 'Ready', 'Ready'], array_column($preview, 'status'));
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $stock = $batch->getRawOriginal();
        $movements = InventoryMovement::count();
        $this->collector->collect('2026-11-10', '2026-11-12', $batch->id, $this->actor);
        $before = BatchDailyObservation::orderBy('id')->get()->toArray();
        $audits = AuditEvent::where('action', 'Daily Observation Collected')->count();
        $this->at('2026-11-13 02:00:00');
        $this->collector->collect('2026-11-10', '2026-11-12', $batch->id, $this->actor);
        $this->assertSame($before, BatchDailyObservation::orderBy('id')->get()->toArray());
        $this->assertSame(3, $audits);
        $this->assertSame($audits, AuditEvent::where('action', 'Daily Observation Collected')->count());
        $this->assertEquals($stock, $batch->fresh()->getRawOriginal());
        $this->assertSame($movements, InventoryMovement::count());
        $row = BatchDailyObservation::first();
        $this->assertTrue($row->is_complete);
        $this->assertSame(0, $row->spoiled_quantity);
        $this->assertSame(100, $row->opening_quantity);
        $this->assertSame($this->actor->id, $row->collected_by);
        $this->assertNotNull($row->verified_at);
    }

    public function test_spoilage_other_use_and_order_fulfillment_reconcile_separately(): void
    {
        $batch = $this->receive();
        $this->issue($batch, 5, 'Spoilage');
        $this->issue($batch, 10);
        $hold = InventoryReservation::create([
            'flower_id' => $this->flower->id, 'reference' => 'ORD-001',
            'quantity' => 20, 'status' => 'Active',
        ]);
        $this->inventory->fulfillOrderReservation($hold, (string) Str::uuid(), $this->actor, 'ORD-001');
        $this->at('2026-11-12 01:00:00');
        $rows = $this->collector->collect('2026-11-10', '2026-11-11', $batch->id, $this->actor);
        $this->assertSame([100, 5, 30, 65], array_map(fn ($key) => $rows[0][$key],
            ['opening_quantity', 'spoiled_quantity', 'released_quantity', 'closing_quantity']));
        $this->assertSame(65, $rows[1]['opening_quantity']);
        $this->assertSame(0, $rows[1]['spoiled_quantity']);
        $this->assertSame(65, $batch->fresh()->remaining_quantity);
        $this->assertSame('Consumed', $hold->fresh()->status);
    }

    public function test_same_day_depletion_is_observed_but_later_empty_days_are_not(): void
    {
        $batch = $this->receive(10);
        $this->issue($batch, 10, 'Spoilage');
        $this->at('2026-11-12 01:00:00');
        $rows = $this->collector->collect('2026-11-10', '2026-11-11', $batch->id, $this->actor);
        $this->assertSame(10, $rows[0]['spoiled_quantity']);
        $this->assertSame(0, $rows[0]['closing_quantity']);
        $this->assertSame('Excluded', $rows[1]['status']);
        $this->assertSame(1, BatchDailyObservation::count());
    }

    public function test_philippine_midnight_and_backdated_receipts_do_not_fabricate_past_days(): void
    {
        $this->at('2026-11-10 15:59:59');
        $batch = $this->receive(100, ['received' => '2026-11-08']);
        $this->at('2026-11-10 16:00:00');
        $this->issue($batch, 7, 'Spoilage');
        $this->at('2026-11-12 01:00:00');
        $rows = $this->collector->collect('2026-11-09', '2026-11-11', $batch->id, $this->actor);
        $this->assertSame('Excluded', $rows[0]['status']);
        $this->assertSame(0, $rows[1]['spoiled_quantity']);
        $this->assertSame(7, $rows[2]['spoiled_quantity']);
        $this->assertSame(2, BatchDailyObservation::count());
    }

    public function test_stem_conversions_and_finished_bouquets_keep_distinct_base_units(): void
    {
        $stem = $this->receive(2, ['unit' => 'Bunch']);
        $this->flower = Flower::create([
            'code' => 'BQT-001', 'name' => 'Finished bouquet', 'product_type' => 'Bouquet',
            'category' => 'Bouquet', 'minimum_stock' => 1, 'description' => 'Test', 'status' => 'Active',
        ]);
        $bouquet = $this->receive(3, ['unit' => 'Bouquet']);
        $this->at('2026-11-11 01:00:00');
        $rows = $this->collector->collect('2026-11-10', '2026-11-10', actor: $this->actor);
        $this->assertSame(['Stem', 'Bouquet'], array_column($rows, 'base_unit'));
        $this->assertSame([20, 3], array_column($rows, 'opening_quantity'));
        $this->assertSame(20, $stem->fresh()->remaining_quantity);
        $this->assertSame(3, $bouquet->fresh()->remaining_quantity);
    }

    public function test_unknown_storage_and_storage_changes_never_rewrite_earlier_history(): void
    {
        $batch = $this->receive(100, ['storage_condition' => null]);
        $this->at('2026-11-11 01:00:00');
        $this->metadata($batch, ['storage_condition' => 'Chilled']);
        $this->at('2026-11-13 01:00:00');
        $rows = $this->collector->collect('2026-11-10', '2026-11-12', $batch->id, $this->actor);
        $this->assertSame(['Excluded', 'Excluded', 'Saved'], array_column($rows, 'status'));
        $this->assertNull(BatchDailyObservation::orderBy('id')->first()->storage_condition);
        $this->assertSame('Chilled', $rows[2]['storage_condition']);
        $this->assertSame(1, BatchDailyObservation::where('is_complete', true)->count());
    }

    public function test_receipt_correction_invalidates_collected_history_and_recovers_after_correction_day(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-12 01:00:00');
        $this->collector->collect('2026-11-10', '2026-11-11', $batch->id, $this->actor);
        $this->inventory->correctReceipt($batch, $this->receiptInput(90, ['remarks' => 'Correct original receipt.']), $this->actor);
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->at('2026-11-14 01:00:00');
        $rows = $this->collector->collect('2026-11-10', '2026-11-13', $batch->id, $this->actor);
        $this->assertSame(['Excluded', 'Excluded', 'Excluded', 'Saved'], array_column($rows, 'status'));
        $this->assertSame(90, $rows[3]['opening_quantity']);
        $this->assertSame(0, $rows[3]['spoiled_quantity']);
    }

    public function test_late_movement_invalidates_source_rows_and_recollection_updates_without_duplicates(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-12 01:00:00');
        $this->collector->collect('2026-11-10', '2026-11-11', $batch->id, $this->actor);
        $this->at('2026-11-10 05:00:00');
        $this->issue($batch, 8, 'Spoilage');
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->at('2026-11-12 01:00:00');
        $this->collector->collect('2026-11-10', '2026-11-11', $batch->id, $this->actor);
        $this->assertSame(2, BatchDailyObservation::count());
        $this->assertSame(8, BatchDailyObservation::orderBy('id')->first()->spoiled_quantity);
        $this->assertSame(92, BatchDailyObservation::orderByDesc('id')->first()->opening_quantity);
    }

    public function test_imported_observations_are_preserved_even_when_inventory_changes(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-12 01:00:00');
        $this->collector->collect('2026-11-10', '2026-11-10', $batch->id, $this->actor);
        $imported = BatchDailyObservation::first();
        $imported->source = null;
        $imported->save();
        $before = $imported->fresh()->getRawOriginal();
        $this->inventory->correctReceipt($batch, $this->receiptInput(90, ['remarks' => 'Correction']), $this->actor);
        $rows = $this->collector->collect('2026-11-10', '2026-11-10', $batch->id, $this->actor);
        $this->assertSame('Preserved', $rows[0]['status']);
        $this->assertSame($before, $imported->fresh()->getRawOriginal());
    }

    public function test_inconsistent_stock_or_missing_ledger_is_excluded_without_invented_numbers(): void
    {
        $batch = $this->receive();
        $batch->remaining_quantity = 200;
        $batch->save();
        $this->at('2026-11-12 01:00:00');
        $rows = $this->collector->collect('2026-11-10', '2026-11-10', $batch->id, $this->actor);
        $this->assertSame('Excluded', $rows[0]['status']);
        $this->assertArrayNotHasKey('opening_quantity', $rows[0]);
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        DB::table('inventory_movements')->delete();
        $rows = $this->collector->collect('2026-11-10', '2026-11-10', $batch->id);
        $this->assertSame('Excluded', $rows[0]['status']);
    }

    public function test_command_defaults_to_preview_requires_confirmation_and_validates_dates_and_actor(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-12 01:00:00');
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-10', '--batch' => (string) $batch->id])
            ->expectsOutputToContain('PREVIEW ONLY')->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-10', '--confirm-complete' => true])
            ->assertFailed();
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-12'])->assertFailed();
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-10', '--batch' => 'abc'])->assertFailed();
        $this->actor->forceFill(['role' => User::ROLE_DELIVERY])->save();
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-10',
            '--confirm-complete' => true, '--actor' => $this->actor->email])->assertFailed();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->actor->forceFill(['role' => User::ROLE_SUPPLIER])->save();
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-10',
            '--confirm-complete' => true, '--actor' => $this->actor->email])->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::where('is_complete', true)->count());
        foreach (['2026-11-13', '2026-02-30', '10/11/2026'] as $date) {
            try {
                $this->collector->collect($date, $date);
                $this->fail('Expected invalid completed-day date.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_provenance_migration_can_roll_back_without_removing_existing_observations(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-12 01:00:00');
        $this->collector->collect('2026-11-10', '2026-11-10', $batch->id, $this->actor);
        $files = glob(database_path('migrations/*_add_collection_provenance_to_batch_daily_observations_table.php'));
        (require $files[0])->down();
        $this->assertFalse(Schema::hasColumn('batch_daily_observations', 'source'));
        $this->assertSame(1, DB::table('batch_daily_observations')->count());
        $this->artisan('spoilage:collect-observations', ['from' => '2026-11-10'])
            ->expectsOutputToContain('Run php artisan migrate first')->assertFailed();
    }

    public function test_collected_history_enters_prediction_inputs_and_corrections_remove_it(): void
    {
        $batch = $this->receive();
        $this->at('2026-11-16 01:00:00');
        $this->collector->collect('2026-11-10', '2026-11-15', $batch->id, $this->actor);
        $predictions = app(SpoilagePredictionService::class);
        $plan = $predictions->plan([$batch->id])[$batch->id];
        $this->assertCount(6, $plan['payload']['history']);
        $this->inventory->correctReceipt($batch, $this->receiptInput(100, [
            'received' => '2026-11-08', 'remarks' => 'Correct received date.',
        ]), $this->actor);
        $nextPlan = $predictions->plan([$batch->id])[$batch->id];
        $this->assertSame([], $nextPlan['payload']['history']);
        $this->assertNotSame($plan['hash'], $nextPlan['hash']);
    }

    public function test_explicit_day_closing_saves_yesterday_as_system_and_is_idempotent(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-10 16:10:00');
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $row = BatchDailyObservation::first();
        $this->assertTrue($row->is_complete);
        $this->assertSame('2026-11-10', $row->observation_date->toDateString());
        $this->assertNull($row->collected_by);
        $this->assertNotNull($row->verified_at);
        $this->assertSame('2026-11-11', DB::table('batch_observation_closing_state')->value('next_date'));
        $audit = AuditEvent::where('action', 'Daily Observation Collected')->first();
        $this->assertSame('System', $audit->actor_name);
        $this->assertNull($audit->actor_id);
        $this->assertSame('Console', $audit->source);
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(1, AuditEvent::where('action', 'Daily Observation Collected')->count());
    }

    public function test_closing_waits_for_philippine_midnight_and_preview_never_saves(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-10 15:59:59');
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->at('2026-11-10 16:00:00');
        $this->artisan('spoilage:close-days', ['--preview' => true])
            ->expectsOutputToContain('PREVIEW ONLY')->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->assertSame('2026-11-10', DB::table('batch_observation_closing_state')->value('next_date'));
    }

    public function test_day_closing_catches_up_in_bounded_runs_without_creating_preactivation_history(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-11');
        config(['spoilage.closing_catchup_days' => 2]);
        $this->at('2026-11-15 01:00:00');
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame('2026-11-13', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertSame(3, BatchDailyObservation::count());
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame('2026-11-15', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertSame(5, BatchDailyObservation::count());
        $this->assertFalse(BatchDailyObservation::whereDate('observation_date', '2026-11-10')->first()->is_complete);
        $this->assertFalse(BatchDailyObservation::whereDate('observation_date', '2026-11-15')->exists());
    }

    public function test_day_closing_keeps_checkpoint_on_failure_and_retries(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-11 01:00:00');
        $this->mock(BatchObservationService::class)->shouldReceive('collect')->once()
            ->andThrow(new \RuntimeException('Simulated collector interruption'));
        $this->artisan('spoilage:close-days')->assertFailed();
        $this->assertSame('2026-11-10', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->app->instance(BatchObservationService::class, new BatchObservationService);
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame('2026-11-11', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertSame(1, BatchDailyObservation::count());
    }

    public function test_day_closing_respects_lock_disable_setting_and_unknown_storage(): void
    {
        $this->receive(100, ['storage_condition' => null]);
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-11 01:00:00');
        $key = 'spoilage-day-closing:'.hash('sha256', config('database.default').':'.DB::connection()->getDatabaseName());
        $lock = Cache::lock($key, 3600);
        $this->assertTrue($lock->get());
        $this->artisan('spoilage:close-days')->expectsOutputToContain('Another day-closing run')->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $lock->release();
        config(['spoilage.activity_collection' => false]);
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        config(['spoilage.activity_collection' => true]);
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertFalse(BatchDailyObservation::first()->is_complete);
        $this->assertNull(BatchDailyObservation::first()->verified_at);
    }

    public function test_no_periodic_observation_closing_is_scheduled(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event) => str_contains($event->command ?? '', 'spoilage:close-days'));
        $this->assertNull($event);
    }

    public function test_stock_in_and_retried_movements_refresh_the_same_live_draft_without_double_counting(): void
    {
        $input = $this->receiptInput(100, ['received' => '2026-11-08']);
        $result = $this->inventory->stockIn($input, $this->actor);
        $this->inventory->stockIn($input, $this->actor);
        $batch = InventoryBatch::findOrFail($result['batch_id']);
        $row = BatchDailyObservation::first();
        $this->assertSame('2026-11-10', $row->observation_date->toDateString());
        $this->assertSame(100, $row->opening_quantity);
        $this->assertSame(100, $row->closing_quantity);
        $this->assertFalse($row->is_complete);
        $this->assertNull($row->verified_at);
        $payload = ['request_key' => (string) Str::uuid(), 'flower_id' => $batch->flower_id,
            'quantity' => 5, 'reason' => 'Spoilage', 'batch_id' => $batch->id, 'remarks' => 'Actual spoilage'];
        $this->inventory->stockOut($payload, $this->actor);
        $this->inventory->stockOut($payload, $this->actor);
        $this->issue($batch, 4);
        $row->refresh();
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame([100, 5, 4, 91], [$row->opening_quantity, $row->spoiled_quantity,
            $row->released_quantity, $row->closing_quantity]);
        $this->assertFalse($row->is_complete);
    }

    public function test_new_day_movement_creates_another_draft_and_only_closed_history_enters_predictions(): void
    {
        $batch = $this->receive();
        $first = BatchDailyObservation::first();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-10 16:00:00');
        $this->issue($batch, 6, 'Spoilage');
        $today = BatchDailyObservation::whereDate('observation_date', '2026-11-11')->first();
        $this->assertSame(0, $first->fresh()->spoiled_quantity);
        $this->assertSame(6, $today->spoiled_quantity);
        $this->assertFalse($today->is_complete);
        $this->assertSame([], app(SpoilagePredictionService::class)->plan([$batch->id])[$batch->id]['payload']['history']);
        $this->at('2026-11-10 16:10:00');
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertTrue($first->fresh()->is_complete);
        $this->assertFalse($today->fresh()->is_complete);
        $plan = app(SpoilagePredictionService::class)->plan([$batch->id])[$batch->id];
        $this->assertCount(1, $plan['payload']['history']);
        $this->assertSame('2026-11-10', $plan['payload']['history'][0]['observation_date']);
    }

    public function test_failed_inventory_transaction_rolls_back_movement_stock_and_live_draft_together(): void
    {
        $fail = true;
        InventoryMovement::created(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('Failure after the live draft was written');
            }
        });
        $input = $this->receiptInput(100);
        try {
            $this->inventory->stockIn($input, $this->actor);
            $this->fail('Expected transaction failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failure after the live draft was written', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(0, InventoryBatch::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryOperation::count());
        $this->assertSame(0, BatchDailyObservation::count());
        $this->inventory->stockIn($input, $this->actor);
        $this->assertSame(1, BatchDailyObservation::count());
        $this->assertSame(100, BatchDailyObservation::first()->closing_quantity);
    }

    public function test_late_movement_rewinds_closing_and_reverifies_existing_days(): void
    {
        $batch = $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-12 01:00:00');
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $firstId = BatchDailyObservation::whereDate('observation_date', '2026-11-10')->value('id');
        $this->at('2026-11-10 05:00:00');
        $this->issue($batch, 8, 'Spoilage');
        $this->assertSame('2026-11-10', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->at('2026-11-12 01:00:00');
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertSame(2, BatchDailyObservation::count());
        $this->assertSame(2, BatchDailyObservation::where('is_complete', true)->count());
        $this->assertSame($firstId, BatchDailyObservation::whereDate('observation_date', '2026-11-10')->value('id'));
        $this->assertSame(92, BatchDailyObservation::whereDate('observation_date', '2026-11-11')->first()->opening_quantity);
    }

    public function test_closing_cannot_advance_past_a_late_inventory_change_during_collection(): void
    {
        $batch = $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-11 01:00:00');
        $realCollector = $this->collector;
        $this->mock(BatchObservationService::class)->shouldReceive('collect')->once()->andReturnUsing(function () use ($batch, $realCollector) {
            $rows = $realCollector->collect('2026-11-10', '2026-11-10', automatic: true);
            $this->at('2026-11-10 05:00:00');
            $this->app->instance(BatchObservationService::class, $realCollector);
            $this->issue($batch, 3, 'Spoilage');
            $this->at('2026-11-11 01:00:00');

            return $rows;
        });
        $this->artisan('spoilage:close-days')->assertFailed();
        $this->assertSame('2026-11-10', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertFalse(BatchDailyObservation::first()->is_complete);
        $this->artisan('spoilage:close-days')->assertSuccessful();
        $this->assertTrue(BatchDailyObservation::first()->is_complete);
        $this->assertSame(3, BatchDailyObservation::first()->spoiled_quantity);
    }

    public function test_real_login_and_logout_catch_up_completed_days_and_never_close_today(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-12 01:00:00');
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'password'])
            ->assertRedirect(route('supplier.dashboard'));
        $this->assertAuthenticatedAs($this->actor);
        $this->assertSame(2, BatchDailyObservation::where('is_complete', true)->count());
        $this->assertSame(2, BatchDailyObservation::where('collected_by', $this->actor->id)->count());
        $this->assertSame('2026-11-12', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertSame(2, AuditEvent::where('action', 'Daily Observation Collected')->where('source', 'User Activity')->count());
        $this->assertFalse(BatchDailyObservation::whereDate('observation_date', '2026-11-12')->exists());
        $this->at('2026-11-13 01:00:00');
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(3, BatchDailyObservation::where('is_complete', true)->count());
        $this->assertSame('2026-11-13', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertFalse(BatchDailyObservation::whereDate('observation_date', '2026-11-13')->exists());
    }

    public function test_failed_inactive_temporary_client_and_delivery_authentication_do_not_trigger_catch_up(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-12 01:00:00');
        $this->postJson('/login', ['email' => $this->actor->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $this->actor->forceFill(['is_active' => false])->save();
        $this->postJson('/login', ['email' => $this->actor->email, 'password' => 'password'])->assertUnprocessable();
        $this->actor->forceFill(['is_active' => true, 'must_change_password' => true])->save();
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'password'])
            ->assertRedirect(route('password.change'));
        $this->post('/logout')->assertRedirect(route('login'));
        $this->actor->forceFill(['must_change_password' => false, 'role' => User::ROLE_DELIVERY])->save();
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'password'])
            ->assertRedirect(route('delivery.deliveries'));
        $this->post('/logout')->assertRedirect(route('login'));
        $this->actor->forceFill(['role' => User::ROLE_CLIENT])->save();
        $this->postJson('/login', ['email' => $this->actor->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertSame(0, BatchDailyObservation::where('is_complete', true)->count());
        $this->assertSame('2026-11-10', DB::table('batch_observation_closing_state')->value('next_date'));
    }

    public function test_catch_up_failure_never_blocks_real_login_or_logout(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        $this->at('2026-11-12 01:00:00');
        $this->mock(BatchDayClosingService::class)->shouldReceive('close')->twice()
            ->andThrow(new \RuntimeException('Simulated catch-up failure'));
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'password'])
            ->assertRedirect(route('supplier.dashboard'));
        $this->assertAuthenticatedAs($this->actor);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame('2026-11-10', DB::table('batch_observation_closing_state')->value('next_date'));
        $this->assertFalse(BatchDailyObservation::first()->is_complete);
    }

    public function test_login_and_logout_share_bounded_catch_up_and_do_not_repeat_completed_rows(): void
    {
        $this->receive();
        $this->closingStarts('2026-11-10');
        config(['spoilage.closing_catchup_days' => 2]);
        $this->at('2026-11-15 01:00:00');
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'password'])
            ->assertRedirect(route('supplier.dashboard'));
        $this->assertSame(2, BatchDailyObservation::where('is_complete', true)->count());
        $first = BatchDailyObservation::orderBy('id')->first()->getRawOriginal();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertSame(4, BatchDailyObservation::where('is_complete', true)->count());
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'password'])
            ->assertRedirect(route('supplier.dashboard'));
        $this->assertSame(5, BatchDailyObservation::where('is_complete', true)->count());
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertSame(5, BatchDailyObservation::count());
        $this->assertSame($first, BatchDailyObservation::orderBy('id')->first()->getRawOriginal());
        $this->assertSame(5, AuditEvent::where('action', 'Daily Observation Collected')->count());
    }

    private function closingStarts(string $day): void
    {
        DB::table('batch_observation_closing_state')->where('id', 1)
            ->update(['started_on' => $day, 'next_date' => $day]);
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function receiptInput(int $quantity, array $overrides = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(), 'flower_id' => $this->flower->id,
            'quantity' => $quantity, 'unit' => $this->flower->product_type, 'received' => '2026-11-10',
            'remarks' => null, 'storage_condition' => 'Ambient',
        ], $overrides);
    }

    private function receive(int $quantity = 100, array $overrides = []): InventoryBatch
    {
        $result = $this->inventory->stockIn($this->receiptInput($quantity, $overrides), $this->actor);

        return InventoryBatch::findOrFail($result['batch_id']);
    }

    private function issue(InventoryBatch $batch, int $quantity, string $reason = 'Other use'): void
    {
        $this->inventory->stockOut([
            'request_key' => (string) Str::uuid(), 'flower_id' => $batch->flower_id,
            'quantity' => $quantity, 'reason' => $reason, 'batch_id' => $batch->id, 'remarks' => 'Actual test outcome',
        ], $this->actor);
    }

    private function metadata(InventoryBatch $batch, array $changes): void
    {
        $this->inventory->updateBatchMetadata($batch, [
            'request_key' => (string) Str::uuid(), 'version' => $this->inventory->batchVersion($batch->fresh()),
            'remarks' => 'Storage update',
            ...$changes,
        ], $this->actor);
    }
}
