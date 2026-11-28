<?php

namespace Tests\Feature;

use App\Models\BatchDailyObservation;
use App\Models\BatchSpoilagePrediction;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\User;
use App\Services\SpoilagePredictionService;
use App\Services\SpoilagePythonService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SpoilageAutoRefreshTest extends TestCase
{
    private SpoilagePredictionService $service;

    private User $actor;

    private InventoryBatch $first;

    private InventoryBatch $second;

    private int $calls = 0;

    private mixed $dispatcher;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        // This test creates only a private in-memory SQLite connection.
        $this->originalConnection = config('database.default');
        config([
            'database.default' => 'spoilage_testing',
            'database.connections.spoilage_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
            'spoilage.observation_timezone' => 'Asia/Manila',
            'spoilage.horizon_days' => 7,
            'spoilage.risk_thresholds' => ['medium' => .1, 'high' => .25, 'critical' => .5],
        ]);
        DB::purge('spoilage_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Carbon::setTestNow('2026-11-15 04:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-15 04:00:00 UTC');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active');
            $table->boolean('must_change_password');
            $table->timestamps();
        });
        Schema::create('flowers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('product_type');
            $table->string('category');
            $table->integer('minimum_stock');
            $table->text('description');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flower_id')->constrained('flowers');
            $table->string('code');
            $table->date('received_date');
            $table->integer('received_quantity');
            $table->string('receiving_unit');
            $table->integer('conversion_factor');
            $table->integer('initial_quantity');
            $table->integer('remaining_quantity');
            $table->boolean('spoiled');
            $table->timestamps();
        });
        // Exercise the real observation/prediction migration constraints.
        foreach (['*_create_batch_daily_observations_table.php', '*_remove_harvest_date_from_batch_daily_observations_table.php', '*_create_batch_spoilage_predictions_table.php', '*_add_spoilage_model_to_flowers_table.php'] as $pattern) {
            $files = glob(database_path('migrations/'.$pattern)) ?: [];
            $this->assertCount(1, $files);
            (require $files[0])->up();
        }
        $this->actor = User::forceCreate([
            'name' => 'Forecast Admin', 'email' => 'forecast@example.test',
            'password' => 'test-password-only', 'role' => 'admin',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $flower = Flower::forceCreate([
            'code' => 'FLW-T1', 'name' => 'Test Rose', 'product_type' => 'Stem',
            'category' => 'Roses', 'minimum_stock' => 10, 'description' => 'Test fixture', 'status' => 'Active',
        ]);
        $this->first = $this->makeBatch($flower, 'BAT-T1');
        $this->second = $this->makeBatch($flower, 'BAT-T2');
        $python = Mockery::mock(SpoilagePythonService::class);
        $python->shouldReceive('predictMany')->andReturnUsing(function (array $items) {
            $this->calls++;
            $this->assertCount(1, $items);

            return $this->results($items);
        });
        $this->service = new SpoilagePredictionService($python);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        if ($this->dispatcher) {
            Model::setEventDispatcher($this->dispatcher);
        }
        DB::purge('spoilage_testing');
        config(['database.default' => $this->originalConnection]);
        parent::tearDown();
    }

    public function test_reads_do_not_run_python_or_write_predictions(): void
    {
        $a = $this->service->plan();
        $b = $this->service->plan();
        $this->assertCount(2, $a);
        $this->assertSame($a[$this->first->id]['hash'], $b[$this->first->id]['hash']);
        $this->assertSame(0, $this->calls);
        $this->assertSame(0, BatchSpoilagePrediction::count());
    }

    public function test_one_flower_forecast_scales_to_each_batch_and_unchanged_refresh_is_reused(): void
    {
        $this->second->update(['remaining_quantity' => 40]);
        BatchDailyObservation::where('batch_id', $this->second->id)->update([
            'opening_quantity' => 50, 'closing_quantity' => 40,
        ]);
        $observations = BatchDailyObservation::orderBy('id')->get()->toArray();

        $first = $this->service->refresh($this->actor);
        $second = $this->service->refresh($this->actor);
        $this->assertSame(2, $first['updated']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame(1, $this->calls);
        $this->assertSame(2, BatchSpoilagePrediction::count());
        $this->assertSame(90, $this->first->fresh()->remaining_quantity);
        $plans = $this->service->plan();
        $this->assertSame([
            ['observation_date' => '2026-11-14', 'opening_quantity' => 150, 'spoiled_quantity' => 10],
        ], $plans[$this->first->id]['payload']['history']);
        $this->assertSame($plans[$this->first->id]['payload'], $plans[$this->second->id]['payload']);
        $this->assertSame('9.000000', $plans[$this->first->id]['cached']->predicted_quantity);
        $this->assertSame('4.000000', $plans[$this->second->id]['cached']->predicted_quantity);
        $this->assertSame('Medium', $plans[$this->second->id]['cached']->risk_level);
        $this->assertSame($observations, BatchDailyObservation::orderBy('id')->get()->toArray());
    }

    public function test_four_periods_share_the_saved_flower_path_and_weekly_risk(): void
    {
        $this->service->refresh($this->actor);
        $rows = $this->service->forecasts($this->service->plan());
        $this->assertCount(1, $rows);
        $periods = $rows[0]['periods'];
        foreach (['daily' => 1, 'weekly' => 7, 'monthly' => 5, 'yearly' => 12] as $name => $count) {
            $this->assertCount($count, $periods[$name]['buckets']);
            $this->assertSame('Medium', $periods[$name]['risk']);
            $this->assertSame(7, $periods[$name]['riskBasisDays']);
        }
        $this->assertEqualsWithDelta(0.1, $periods['weekly']['cumulativeFraction'], 1e-9);
        $this->assertGreaterThan($periods['weekly']['cumulativeFraction'], $periods['monthly']['cumulativeFraction']);
        $this->assertNull($periods['yearly']['predictedQuantity']);
        $this->assertSame($periods['yearly']['endDate'], $periods['yearly']['buckets'][11]['endDate']);
        $this->assertSame(1, $this->calls);
    }

    public function test_stock_change_invalidates_only_the_changed_batch(): void
    {
        $this->service->refresh($this->actor);
        $this->first->update(['remaining_quantity' => 80]);
        $result = $this->service->refresh($this->actor);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(3, BatchSpoilagePrediction::count());
    }

    public function test_storage_and_history_changes_invalidate_saved_results(): void
    {
        $this->service->refresh($this->actor);
        $this->first->forceFill(['storage_condition' => 'Chilled'])->save();
        $this->assertNull($this->service->plan()[$this->first->id]['cached']);
        BatchDailyObservation::where('batch_id', $this->second->id)->update([
            'spoiled_quantity' => 6, 'released_quantity' => 4,
        ]);
        foreach ($this->service->plan() as $plan) {
            $this->assertNull($plan['cached']);
        }
    }

    public function test_new_day_and_settings_changes_invalidate_predictions(): void
    {
        $this->service->refresh($this->actor);
        config(['spoilage.forecast_format_version' => 'changed-period-format']);
        $this->assertNull($this->service->plan()[$this->first->id]['cached']);
        config(['spoilage.forecast_format_version' => 'daily-periods-v1']);
        CarbonImmutable::setTestNow('2026-11-16 04:00:00 UTC');
        $this->assertNull($this->service->plan()[$this->first->id]['cached']);
    }

    public function test_inventory_change_during_python_discards_obsolete_result(): void
    {
        $python = Mockery::mock(SpoilagePythonService::class);
        $python->shouldReceive('predictMany')->once()->andReturnUsing(function ($items) {
            $this->first->update(['remaining_quantity' => 79]);

            return $this->results($items);
        });
        $service = new SpoilagePredictionService($python);
        $result = $service->refresh($this->actor);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, BatchSpoilagePrediction::where('batch_id', $this->first->id)->count());
    }

    public function test_missing_history_saves_insufficient_data_and_reuses_it(): void
    {
        BatchDailyObservation::query()->delete();
        $result = $this->service->refresh($this->actor);
        $this->assertSame(2, $result['insufficient']);
        foreach (BatchSpoilagePrediction::all() as $prediction) {
            $this->assertSame('InsufficientData', $prediction->status);
            $this->assertNull($prediction->risk_level);
            $this->assertNull($prediction->predicted_quantity);
        }
        $this->service->refresh($this->actor);
        $this->assertSame(1, $this->calls);
    }

    public function test_lock_prevents_overlapping_python_runs(): void
    {
        $key = 'spoilage-auto:'.hash('sha256', json_encode(['spoilage_testing', ':memory:'], JSON_THROW_ON_ERROR));
        $lock = Cache::lock($key, 240);
        $this->assertTrue($lock->get());
        try {
            $this->assertTrue($this->service->refresh($this->actor)['busy']);
            $this->assertSame(0, $this->calls);
        } finally {
            $lock->release();
        }
    }

    public function test_depleted_batches_are_excluded(): void
    {
        $this->first->update(['remaining_quantity' => 0]);
        $plans = $this->service->plan();
        $this->assertArrayNotHasKey($this->first->id, $plans);
        $this->assertArrayHasKey($this->second->id, $plans);
    }

    public function test_training_persists_flower_order_and_refresh_uses_it(): void
    {
        $flower = $this->first->flower;
        $report = [
            'status' => 'Trained', 'order' => [2, 1, 0], 'adf_p_value' => .2,
            'split_days' => ['train' => 30, 'validation' => 4, 'test' => 9],
            'validation' => ['mae' => .1, 'mse' => .01, 'rmse' => .1, 'aic' => -10., 'bic' => -9.],
            'test' => ['MAE_rate' => .2, 'MSE_rate' => .04, 'RMSE_rate' => .2],
        ];
        $observation = BatchDailyObservation::where('batch_id', $this->first->id)->firstOrFail();
        for ($offset = 1; $offset <= 42; $offset++) {
            $observation->replicate()->fill([
                'observation_date' => CarbonImmutable::parse('2026-11-14')->subDays($offset)->toDateString(),
                'received_date' => '2026-09-30',
                'spoiled_quantity' => $offset % 5, 'closing_quantity' => 95 - $offset % 5,
            ])->save();
        }
        $this->service->refresh($this->actor);
        $this->assertSame(SpoilagePythonService::FALLBACK, $this->service->plan()[$this->first->id]['cached']->method);

        $python = $this->mock(SpoilagePythonService::class);
        $python->shouldReceive('train')->once()->with($this->service->plan()[$this->first->id]['payload']['history'])
            ->andReturn($report);
        $python->shouldReceive('predictMany')->once()->withArgs(function (array $items) use ($flower): bool {
            return count($items) === 1
                && $items[0]['key'] === (string) $flower->id
                && $items[0]['payload']['order'] === [2, 1, 0]
                && count($items[0]['payload']['history']) === 43;
        })->andReturnUsing(fn (array $items): array => $this->results($items));

        $this->artisan('spoilage:train', ['flower_id' => (string) $flower->id])
            ->expectsOutputToContain('ARIMA(2, 1, 0)')->assertSuccessful();
        $model = $flower->fresh()->spoilage_model;
        $this->assertSame($report['order'], $model['order']);
        $this->assertSame('2026-11-14', $model['history_end']);
        $this->assertSame($report['test'], $model['test']);
        $this->assertNull($this->service->plan()[$this->first->id]['cached']);

        $prediction = app(SpoilagePredictionService::class)->generate($this->first, $this->actor);
        $this->assertSame(SpoilagePythonService::ARIMA, $prediction->method);
        $this->assertSame($report['order'], $prediction->metadata['order']);
    }

    public function test_insufficient_history_during_retraining_preserves_the_saved_order(): void
    {
        $flower = $this->first->flower;
        $model = ['order' => [2, 0, 0], 'version' => config('spoilage.model_version'), 'history_end' => '2026-11-14'];
        $flower->forceFill(['spoilage_model' => $model])->save();
        $this->mock(SpoilagePythonService::class)->shouldReceive('train')->once()
            ->andReturn(['status' => 'InsufficientData', 'message' => 'Training needs at least 43 completed daily observations.']);

        $this->artisan('spoilage:train', ['flower_id' => (string) $flower->id])
            ->expectsOutputToContain('43 completed daily observations')->assertFailed();
        $this->assertSame($model, $flower->fresh()->spoilage_model);
    }

    private function makeBatch(Flower $flower, string $code): InventoryBatch
    {
        $batch = InventoryBatch::forceCreate([
            'flower_id' => $flower->id, 'code' => $code,
            'received_date' => '2026-11-13',
            'received_quantity' => 100, 'receiving_unit' => 'Stem',
            'conversion_factor' => 1, 'initial_quantity' => 100,
            'remaining_quantity' => 90, 'spoiled' => false, 'storage_condition' => 'Ambient',
        ]);
        BatchDailyObservation::create([
            'batch_id' => $batch->id, 'flower_id' => $flower->id,
            'observation_date' => '2026-11-14',
            'received_date' => '2026-11-13', 'storage_condition' => 'Ambient',
            'base_unit' => 'Stem', 'opening_quantity' => 100,
            'spoiled_quantity' => 5, 'released_quantity' => 5, 'closing_quantity' => 90,
            'is_complete' => true,
        ]);

        return $batch;
    }

    private function results(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $p = $item['payload'];
            $results[$item['key']] = $p['history'] === []
                ? ['status' => 'InsufficientData', 'message' => 'No completed spoilage history.']
                : [
                    'status' => 'Predicted',
                    'predicted_fraction' => 1 - pow(.9, $p['horizon_days'] / 7),
                    'daily_rate_forecasts' => array_fill(0, $p['horizon_days'], 1 - pow(.9, 1 / 7)),
                    'method' => $p['order'] === null ? SpoilagePythonService::FALLBACK : SpoilagePythonService::ARIMA,
                    'message' => null,
                ];
        }

        return $results;
    }
}
