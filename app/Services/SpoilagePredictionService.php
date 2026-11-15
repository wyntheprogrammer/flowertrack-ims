<?php

namespace App\Services;

use App\Models\BatchDailyObservation;
use App\Models\BatchSpoilagePrediction;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SpoilagePredictionService
{
    public function __construct(private SpoilagePythonService $python) {}

    // Build one consistent read snapshot. This method does not run Python.
    public function plan(?array $ids = null): array
    {
        return DB::transaction(function () use ($ids) {
            $today = CarbonImmutable::now(
                config('spoilage.observation_timezone', 'Asia/Manila')
            )->startOfDay();
            $date = $today->toDateString();
            $query = InventoryBatch::query()->with('flower')
                ->where('spoiled', false)->where('remaining_quantity', '>', 0);
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            }
            $batches = $query->orderBy('id')->get();
            if ($batches->isEmpty()) {
                return [];
            }

            // Load reference history once, including depleted older batches.
            $observations = BatchDailyObservation::query()
                ->where('is_complete', true)->whereNull('exclusion_reason')
                ->where('observation_date', '<', $date)
                ->whereNotNull('storage_condition')->where('storage_condition', '<>', '')
                ->orderBy('batch_id')->orderBy('observation_date')->get();
            $history = [];
            foreach ($observations as $row) {
                $opening = (int) $row->opening_quantity;
                $spoiled = (int) $row->spoiled_quantity;
                $released = (int) $row->released_quantity;
                $closing = (int) $row->closing_quantity;
                if ($opening <= 0 || min($spoiled, $released, $closing) < 0
                    || $opening - $spoiled - $released !== $closing
                    || $row->harvest_date->gt($row->received_date)
                    || $row->received_date->gt($row->observation_date)
                    || ! in_array($row->base_unit, ['Stem', 'Bouquet'], true)) {
                    continue;
                }
                $history[] = [
                    'batch_id' => (int) $row->batch_id,
                    'flower_id' => (int) $row->flower_id,
                    'date' => $row->observation_date->toDateString(),
                    'harvest' => $row->harvest_date->toDateString(),
                    'received' => $row->received_date->toDateString(),
                    'storage' => $row->storage_condition,
                    'unit' => $row->base_unit,
                    'age' => (int) $row->harvest_date->diffInDays($row->observation_date),
                    'opening' => $opening, 'spoiled' => $spoiled,
                    'released' => $released, 'closing' => $closing,
                ];
            }
            $settings = $this->settings();
            $engine = [];
            foreach (['scripts/spoilage/predict.py', 'scripts/spoilage/predict_many.py', 'requirements.txt'] as $path) {
                $engine[$path] = is_file(base_path($path)) ? hash_file('sha256', base_path($path)) : null;
            }
            $saved = BatchSpoilagePrediction::query()
                ->whereIn('batch_id', $batches->modelKeys())
                ->whereDate('as_of_date', '<=', $date)
                ->orderByDesc('as_of_date')->orderByDesc('generated_at')->orderByDesc('id')
                ->get()->groupBy('batch_id');
            $plans = [];
            foreach ($batches as $batch) {
                if (! $batch->flower) {
                    continue;
                }
                $signature = $this->signature($batch, $batch->flower);
                $own = array_values(array_filter($history, fn ($row) =>
                    $row['batch_id'] === (int) $batch->id
                    && $row['flower_id'] === (int) $batch->flower_id
                    && $row['unit'] === $signature['unit']
                    && $row['storage'] === $signature['storage']
                    && $row['harvest'] === $signature['harvest']
                    && $row['received'] === $signature['received']
                ));
                $reference = array_values(array_filter($history, fn ($row) =>
                    $row['batch_id'] !== (int) $batch->id && $row['unit'] === $signature['unit']
                ));
                $payload = [
                    'as_of_date' => $date,
                    'opening_quantity' => (int) $batch->remaining_quantity,
                    'history' => $this->continuousHistory($own, $today),
                    'fallback' => $this->fallback($signature, $reference, $today),
                    'settings' => $settings,
                ];
                // Include inputs omitted by the old date/quantity-only check.
                $hash = $this->hash([
                    'signature' => $signature, 'payload' => $payload,
                    'references' => $reference, 'engine' => $engine,
                    'version' => config('spoilage.model_version'),
                ]);
                $candidates = $saved->get($batch->id, collect());
                $cached = $candidates->first(fn ($prediction) =>
                    $prediction->as_of_date->toDateString() === $date
                    && $prediction->model_version === config('spoilage.model_version')
                    && $prediction->input_hash === $hash
                    && in_array($prediction->status, ['Predicted', 'InsufficientData'], true)
                );
                $plans[(int) $batch->id] = [
                    'batch' => $batch, 'signature' => $signature,
                    'payload' => $payload, 'hash' => $hash,
                    'cached' => $cached, 'saved' => $cached ?? $candidates->first(),
                ];
            }
            return $plans;
        });
    }

    // Process changed inputs in one Python call; never hold DB locks during forecasting.
    public function refresh(User $actor, ?array $ids = null): array
    {
        $this->authorize($actor);
        $lock = Cache::lock('spoilage-auto:'.$this->hash([
            config('database.default'), config('database.connections.'.config('database.default').'.database'),
        ]), 240);
        if (! $lock->get()) {
            return ['busy' => true, 'updated' => 0, 'failed' => [], 'skipped' => 0];
        }
        try {
            // Windows includes process waiting time in PHP's execution limit.
            if (function_exists('set_time_limit')) {
                set_time_limit(180);
            }
            $plans = $this->plan($ids);
            $pending = array_filter($plans, fn ($plan) => $plan['cached'] === null);
            $summary = [
                'busy' => false, 'total' => count($plans),
                'unchanged' => count($plans) - count($pending),
                'updated' => 0, 'insufficient' => 0, 'skipped' => 0, 'failed' => [],
            ];
            if ($pending === []) {
                return $summary;
            }
            $items = [];
            foreach ($pending as $id => $plan) {
                $items[] = ['key' => (string) $id, 'payload' => $plan['payload']];
            }
            $results = $this->python->predictMany($items);
            // Inventory/history may have changed while Python was working.
            $after = $this->plan(array_keys($pending));
            foreach ($pending as $id => $plan) {
                if (! isset($after[$id]) || $after[$id]['hash'] !== $plan['hash']) {
                    $summary['skipped']++;
                    continue;
                }
                $result = $results[(string) $id] ?? null;
                if (! $result || $result['status'] === 'Error') {
                    $summary['failed'][] = $plan['batch']->code;
                    Log::warning('Spoilage forecast failed.', [
                        'batch_id' => $id, 'reason' => substr((string) ($result['message'] ?? 'Missing result'), 0, 500),
                    ]);
                    continue;
                }
                try {
                    $prediction = DB::transaction(function () use ($plan, $result) {
                        $flower = Flower::query()->lockForUpdate()->findOrFail($plan['batch']->flower_id);
                        $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($plan['batch']->id);
                        if ($this->signature($batch, $flower) !== $plan['signature']) {
                            return null;
                        }
                        $identity = [
                            'batch_id' => $batch->id,
                            'as_of_date' => $plan['payload']['as_of_date'],
                            'model_version' => config('spoilage.model_version'),
                            'input_hash' => $plan['hash'],
                        ];
                        // SQLite retains the time component of Eloquent date casts.
                        // Compare calendar dates when reusing an existing snapshot.
                        $existing = BatchSpoilagePrediction::query()
                            ->where('batch_id', $identity['batch_id'])
                            ->whereDate('as_of_date', $identity['as_of_date'])
                            ->where('model_version', $identity['model_version'])
                            ->where('input_hash', $identity['input_hash'])
                            ->first();
                        return $existing ?? BatchSpoilagePrediction::create([
                            ...$identity,
                            'forecast_end_date' => CarbonImmutable::parse($plan['payload']['as_of_date'])
                                ->addDays(config('spoilage.horizon_days') - 1)->toDateString(),
                            'opening_quantity' => $batch->remaining_quantity,
                            'base_unit' => $flower->product_type,
                            'status' => $result['status'],
                            'method' => $result['method'] ?? null,
                            'predicted_fraction' => $result['predicted_fraction'] ?? null,
                            'predicted_quantity' => $result['predicted_quantity'] ?? null,
                            'risk_level' => $result['risk_level'] ?? null,
                            'history_days' => count($plan['payload']['history']),
                            'message' => $result['message'] ?? null,
                            'metadata' => ['source' => 'Automatic batched Python forecast', 'signature' => $plan['signature']],
                            'generated_at' => now(),
                        ]);
                    });
                    if ($prediction === null) {
                        $summary['skipped']++;
                    } else {
                        $summary['updated']++;
                        $summary['insufficient'] += (int) ($prediction->status === 'InsufficientData');
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $summary['failed'][] = $plan['batch']->code;
                }
            }
            return $summary;
        } finally {
            $lock->release();
        }
    }

    // Preserve the earlier single-batch API and Tinker command.
    public function generate(InventoryBatch $batch, User $actor): BatchSpoilagePrediction
    {
        $summary = $this->refresh($actor, [(int) $batch->id]);
        if ($summary['busy']) {
            throw ValidationException::withMessages(['batch' => 'Predictions are already updating. Try again shortly.']);
        }
        $plan = $this->plan([(int) $batch->id]);
        $saved = $plan[(int) $batch->id]['cached'] ?? null;
        if (! $saved) {
            throw new RuntimeException('No current prediction could be saved for this batch.');
        }
        return $saved;
    }

    private function signature(InventoryBatch $batch, Flower $flower): array
    {
        return [
            'batch_id' => (int) $batch->id, 'flower_id' => (int) $batch->flower_id,
            'unit' => $flower->product_type, 'remaining' => (int) $batch->remaining_quantity,
            'spoiled' => (bool) $batch->spoiled,
            'harvest' => $batch->harvest_date->toDateString(),
            'received' => $batch->received_date->toDateString(),
            'storage' => $batch->storage_condition,
        ];
    }

    // A gap or stock discontinuity resets own-batch ARIMA history.
    private function continuousHistory(array $rows, CarbonImmutable $today): array
    {
        $expected = $today->subDay()->toDateString();
        $nextOpening = null;
        $result = [];
        foreach (array_reverse($rows) as $row) {
            if ($row['date'] !== $expected
                || ($nextOpening !== null && $row['closing'] !== $nextOpening)) {
                break;
            }
            $after = $row['opening'] - $row['spoiled'];
            $result[] = [
                'observation_date' => $row['date'],
                'spoil_rate_today' => $row['spoiled'] / $row['opening'],
                'release_rate_today' => $after > 0 ? $row['released'] / $after : 0.0,
            ];
            $nextOpening = $row['opening'];
            $expected = CarbonImmutable::parse($expected)->subDay()->toDateString();
        }
        return array_reverse($result);
    }

    // Pool same-unit reference quantities and select nearest historical ages.
    private function fallback(array $signature, array $rows, CarbonImmutable $today): ?array
    {
        if (! $signature['storage'] || ! in_array($signature['unit'], ['Stem', 'Bouquet'], true)
            || $signature['harvest'] > $signature['received']
            || $signature['received'] > $today->toDateString()) {
            return null;
        }
        $supported = false;
        $groups = [];
        $global = [0, 0, 0, 0];
        foreach ($rows as $row) {
            $after = $row['opening'] - $row['spoiled'];
            $values = [$row['opening'], $row['spoiled'], $row['released'], $after];
            foreach ($values as $i => $value) {
                $global[$i] += $value;
            }
            if ($row['flower_id'] !== $signature['flower_id']) {
                continue;
            }
            $supported = true;
            if ($row['storage'] !== $signature['storage']) {
                continue;
            }
            $age = $row['age'];
            $groups[$age] ??= [0, 0, 0, 0];
            foreach ($values as $i => $value) {
                $groups[$age][$i] += $value;
            }
        }
        if (! $supported) {
            return null;
        }
        $horizon = (int) config('spoilage.horizon_days');
        if ($groups === []) {
            return [
                'spoil_rates' => array_fill(0, $horizon, $global[1] / $global[0]),
                'release_rates' => array_fill(0, $horizon, $global[3] > 0 ? $global[2] / $global[3] : 0.0),
                'method' => 'Global profile fallback',
            ];
        }
        ksort($groups, SORT_NUMERIC);
        $age = (int) CarbonImmutable::parse($signature['harvest'], $today->timezone)
            ->startOfDay()->diffInDays($today);
        if ($age < 0 || $signature['received'] > $today->toDateString()) {
            return null;
        }
        $spoil = $release = [];
        foreach (range(0, $horizon - 1) as $day) {
            $nearest = array_key_first($groups);
            foreach ($groups as $candidate => $values) {
                if (abs($candidate - $age - $day) < abs($nearest - $age - $day)) {
                    $nearest = $candidate;
                }
            }
            $values = $groups[$nearest];
            $spoil[] = $values[1] / $values[0];
            $release[] = $values[3] > 0 ? $values[2] / $values[3] : 0.0;
        }
        return ['spoil_rates' => $spoil, 'release_rates' => $release, 'method' => 'Flower/storage/age fallback'];
    }

    private function settings(): array
    {
        return [
            'horizon_days' => (int) config('spoilage.horizon_days'),
            'minimum_history_days' => (int) config('spoilage.minimum_history_days'),
            'arima_order' => config('spoilage.arima_order'),
            'risk_thresholds' => config('spoilage.risk_thresholds'),
        ];
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && ! $actor->must_change_password
            && in_array($actor->role, ['admin', 'supplier'], true), 403);
    }
}
