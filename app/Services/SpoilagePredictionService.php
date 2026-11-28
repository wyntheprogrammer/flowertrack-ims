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

    public function train(Flower $flower): array
    {
        $date = $this->today()->toDateString();
        $history = $this->histories([$flower->id], $date)[$flower->id] ?? [];
        $result = $this->python->train($history);
        if ($result['status'] === 'Trained') {
            DB::transaction(function () use ($flower, $date, $history, $result): void {
                $current = Flower::query()->lockForUpdate()->findOrFail($flower->id);
                if ($current->spoilage_model !== $flower->spoilage_model
                    || ($this->histories([$flower->id], $date)[$flower->id] ?? []) !== $history) {
                    throw new RuntimeException('Flower history or model changed during training. Run training again.');
                }
                $current->forceFill(['spoilage_model' => [
                    ...$result,
                    'version' => config('spoilage.model_version'),
                    'history_start' => $history[0]['observation_date'],
                    'history_end' => $history[array_key_last($history)]['observation_date'],
                    'history_hash' => $this->hash($history),
                    'trained_at' => now()->toIso8601String(),
                ]])->save();
            });
        }

        return $result;
    }

    // Build one consistent read snapshot. This method does not run Python.
    public function plan(?array $ids = null): array
    {
        return DB::transaction(function () use ($ids) {
            $start = $this->today()->addDay();
            $startDate = $start->toDateString();
            $query = InventoryBatch::query()->with('flower')
                ->where('spoiled', false)->where('remaining_quantity', '>', 0);
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            }
            $batches = $query->orderBy('id')->get();
            if ($batches->isEmpty()) {
                return [];
            }

            $histories = $this->histories($batches->pluck('flower_id')->unique()->all(), $startDate);
            $engine = [];
            foreach (['scripts/spoilage/predict.py', 'scripts/spoilage/predict_many.py', 'requirements.txt'] as $path) {
                $engine[$path] = is_file(base_path($path)) ? hash_file('sha256', base_path($path)) : null;
            }
            $saved = BatchSpoilagePrediction::query()
                ->whereIn('batch_id', $batches->modelKeys())
                ->where('model_version', config('spoilage.model_version'))
                ->whereDate('as_of_date', '<=', $startDate)
                ->orderByDesc('as_of_date')->orderByDesc('generated_at')->orderByDesc('id')
                ->get()->groupBy('batch_id');
            $plans = [];
            foreach ($batches as $batch) {
                if (! $batch->flower) {
                    continue;
                }
                $signature = $this->signature($batch, $batch->flower);
                $history = $histories[$batch->flower_id] ?? [];
                $historyEndDate = $history === [] ? null : $history[array_key_last($history)]['observation_date'];
                $payload = [
                    'as_of_date' => $startDate,
                    'history' => $history,
                    'order' => $this->selectedOrder($batch->flower, $startDate),
                    'horizon_days' => (int) $start->diffInDays($start->addYearNoOverflow()),
                ];
                $hash = $this->hash([
                    'signature' => $signature,
                    'payload' => $payload,
                    'risk_thresholds' => config('spoilage.risk_thresholds'),
                    'engine' => $engine,
                    'version' => config('spoilage.model_version'),
                    'period_format' => config('spoilage.forecast_format_version'),
                ]);
                $candidates = $saved->get($batch->id, collect());
                $cached = $candidates->first(fn ($prediction) => $prediction->as_of_date->toDateString() === $startDate
                    && $prediction->input_hash === $hash
                    && in_array($prediction->status, ['Predicted', 'InsufficientData'], true)
                );
                $plans[(int) $batch->id] = [
                    'batch' => $batch, 'signature' => $signature,
                    'payload' => $payload, 'hash' => $hash,
                    'history_end_date' => $historyEndDate,
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
            foreach ($pending as $plan) {
                $flowerId = $plan['batch']->flower_id;
                $items[$flowerId] = ['key' => (string) $flowerId, 'payload' => $plan['payload']];
            }
            $results = $this->python->predictMany(array_values($items));
            // Inventory/history may have changed while Python was working.
            $after = $this->plan(array_keys($pending));
            foreach ($pending as $id => $plan) {
                if (! isset($after[$id]) || $after[$id]['hash'] !== $plan['hash']) {
                    $summary['skipped']++;

                    continue;
                }
                $result = $results[$plan['batch']->flower_id] ?? null;
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
                        if ($this->signature($batch, $flower) !== $plan['signature']
                            || $this->selectedOrder($flower, $plan['payload']['as_of_date']) !== $plan['payload']['order']
                            || $this->today()->addDay()->toDateString() !== $plan['payload']['as_of_date']
                            || ($this->histories([$flower->id], $plan['payload']['as_of_date'])[$flower->id] ?? []) !== $plan['payload']['history']) {
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
                        $rates = $result['daily_rate_forecasts'] ?? null;
                        if ($result['status'] === 'Predicted' && (! is_array($rates)
                            || count($rates) !== $plan['payload']['horizon_days'])) {
                            throw new RuntimeException('A complete daily forecast path is required before saving.');
                        }
                        $fraction = $rates === null ? null : round($this->cumulative(array_slice($rates, 0, 7)), 10);

                        return $existing ?? BatchSpoilagePrediction::create([
                            ...$identity,
                            'forecast_end_date' => CarbonImmutable::parse($plan['payload']['as_of_date'])
                                ->addDays(6)->toDateString(),
                            'opening_quantity' => $batch->remaining_quantity,
                            'base_unit' => $flower->product_type,
                            'status' => $result['status'],
                            'method' => $result['method'] ?? null,
                            'predicted_fraction' => $fraction,
                            'predicted_quantity' => $fraction === null ? null : $batch->remaining_quantity * $fraction,
                            'risk_level' => $fraction === null ? null : self::riskLevel($fraction),
                            'history_days' => count($plan['payload']['history']),
                            'message' => $result['message'] ?? null,
                            'metadata' => [
                                'signature' => $plan['signature'],
                                'daily_rate_forecasts' => $rates,
                                'forecast_horizon_days' => $plan['payload']['horizon_days'],
                                'period_format' => config('spoilage.forecast_format_version'),
                                'risk_reference_days' => 7,
                                'order' => ($result['method'] ?? null) === SpoilagePythonService::ARIMA
                                    ? $plan['payload']['order'] : null,
                            ],
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

    /**
     * Present one persisted daily flower forecast through four views, without fitting again.
     * Risk always refers to the existing seven-day inventory advisory, never annual exposure.
     *
     * @return list<array<string, mixed>>
     */
    public function forecasts(array $plans): array
    {
        $groups = collect($plans)->groupBy(fn (array $plan): int => (int) $plan['batch']->flower_id);
        $rows = [];
        foreach ($groups as $group) {
            $first = $group->first(fn (array $plan): bool => $plan['cached'] !== null) ?? $group->first();
            $flower = $first['batch']->flower;
            $prediction = $first['saved'];
            $asOf = $first['payload']['as_of_date'];
            $start = CarbonImmutable::parse($asOf, config('spoilage.observation_timezone'));
            $rates = $prediction?->metadata['daily_rate_forecasts'] ?? null;
            $annualDays = (int) $start->diffInDays($start->addYearNoOverflow());
            $available = $prediction?->status === 'Predicted' && is_array($rates)
                && count($rates) >= $annualDays
                && $group->every(fn (array $plan): bool => $plan['cached'] !== null)
                && ($prediction->metadata['period_format'] ?? null) === config('spoilage.forecast_format_version');
            $remaining = (int) $group->sum(fn (array $plan): int => (int) $plan['batch']->remaining_quantity);
            $periods = [];
            foreach (['daily' => 1, 'weekly' => 7, 'monthly' => 30, 'yearly' => $annualDays] as $period => $days) {
                $path = $available ? array_slice($rates, 0, $days) : null;
                $fraction = $path === null ? null : $this->cumulative($path);
                $status = $available ? 'Predicted' : ($prediction?->status === 'InsufficientData' ? 'InsufficientData' : 'NotGenerated');
                $buckets = [];
                if ($path !== null) {
                    if ($period === 'yearly') {
                        for ($month = 0; $month < 12; $month++) {
                            $bucketStart = $start->addMonthsNoOverflow($month);
                            $bucketEnd = $start->addMonthsNoOverflow($month + 1);
                            $offset = (int) $start->diffInDays($bucketStart);
                            $length = (int) $bucketStart->diffInDays($bucketEnd);
                            $buckets[] = $this->bucket($bucketStart, array_slice($path, $offset, $length), null, $bucketStart->format('M Y'));
                        }
                    } else {
                        $step = $period === 'monthly' ? 7 : 1;
                        for ($offset = 0; $offset < $days; $offset += $step) {
                            $bucketStart = $start->addDays($offset);
                            $surviving = $remaining * (1 - $this->cumulative(array_slice($path, 0, $offset)));
                            $buckets[] = $this->bucket($bucketStart, array_slice($path, $offset, $step), $surviving, $bucketStart->format('M d'));
                        }
                    }
                }
                $periods[$period] = [
                    'startDate' => $asOf,
                    'endDate' => $start->addDays($days - 1)->toDateString(),
                    'status' => $status,
                    'method' => $available ? $prediction->method : null,
                    'historyDays' => $prediction?->history_days ?? 0,
                    'meanDailyFraction' => $path === null ? null : array_sum($path) / count($path),
                    'cumulativeFraction' => $fraction,
                    'predictedQuantity' => $fraction === null || $period === 'yearly' ? null : $remaining * $fraction,
                    'risk' => $available ? $prediction->risk_level : null,
                    'riskBasisDays' => 7,
                    'message' => $available
                        ? ($period === 'yearly'
                            ? 'Long-range daily-rate extrapolation, not validated seasonal demand. Monthly fractions describe conditional exposure; no annual current-stock quantity is projected.'
                            : 'Cumulative exposure assumes today’s usable stock with no arrivals or releases. Risk remains the seven-day reference advisory.')
                        : ($prediction?->message ?? 'Generate the daily forecast path to view these periods.'),
                    'buckets' => $buckets,
                ];
            }
            $rows[] = [
                'flowerId' => (int) $flower->id, 'flowerName' => $flower->name,
                'flowerCode' => $flower->code, 'baseUnit' => $flower->product_type,
                'remaining' => $remaining,
                'historyEndDate' => $first['history_end_date'],
                'needsRefresh' => $group->contains(fn (array $plan): bool => $plan['cached'] === null),
                'periods' => $periods,
            ];
        }

        return $rows;
    }

    /** @param list<float|int> $rates */
    private function cumulative(array $rates): float
    {
        $survival = 1.0;
        foreach ($rates as $rate) {
            if (! is_numeric($rate) || ! is_finite((float) $rate) || $rate < 0 || $rate > 1) {
                throw new RuntimeException('Daily forecast rates must be finite fractions from zero to one.');
            }
            $survival *= 1 - $rate;
        }

        return max(0.0, min(1.0, 1 - $survival));
    }

    /** @param list<float|int> $rates */
    private function bucket(CarbonImmutable $start, array $rates, ?float $remaining, string $label): array
    {
        $fraction = $this->cumulative($rates);

        return [
            'label' => $label, 'startDate' => $start->toDateString(),
            'endDate' => $start->addDays(count($rates) - 1)->toDateString(),
            'meanDailyFraction' => array_sum($rates) / count($rates),
            'cumulativeFraction' => $fraction,
            'predictedQuantity' => $remaining === null ? null : $remaining * $fraction,
        ];
    }

    private function signature(InventoryBatch $batch, Flower $flower): array
    {
        return [
            'batch_id' => (int) $batch->id, 'flower_id' => (int) $batch->flower_id,
            'unit' => $flower->product_type, 'remaining' => (int) $batch->remaining_quantity,
            'spoiled' => (bool) $batch->spoiled,
            'received' => $batch->received_date->toDateString(),
            'storage' => $batch->storage_condition,
        ];
    }

    /**
     * Pool historical batch quantities into one row per flower and calendar date.
     *
     * @return array<int, list<array{observation_date: string, opening_quantity: int, spoiled_quantity: int}>>
     */
    private function histories(array $flowerIds, string $before): array
    {
        $rows = BatchDailyObservation::query()->toBase()
            ->whereIn('flower_id', $flowerIds)
            ->where('is_complete', true)->whereNull('exclusion_reason')
            ->where('observation_date', '<', $before)
            ->where('opening_quantity', '>', 0)->where('spoiled_quantity', '>=', 0)
            ->whereColumn('spoiled_quantity', '<=', 'opening_quantity')
            ->selectRaw('flower_id, DATE(observation_date) AS calendar_date, SUM(opening_quantity) AS opening, SUM(spoiled_quantity) AS spoiled')
            ->groupByRaw('flower_id, DATE(observation_date)')
            ->orderBy('flower_id')->orderBy('calendar_date')->get();

        $histories = [];
        foreach ($rows as $row) {
            $histories[$row->flower_id][] = [
                'observation_date' => $row->calendar_date,
                'opening_quantity' => (int) $row->opening,
                'spoiled_quantity' => (int) $row->spoiled,
            ];
        }

        return $histories;
    }

    private function selectedOrder(Flower $flower, string $asOfDate): ?array
    {
        $model = $flower->spoilage_model;

        return ($model['version'] ?? null) === config('spoilage.model_version')
            && isset($model['history_end'], $model['order']) && $model['history_end'] < $asOfDate
            ? $model['order'] : null;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('spoilage.observation_timezone', 'Asia/Manila'))->startOfDay();
    }

    public static function riskLevel(float $fraction): string
    {
        $thresholds = config('spoilage.risk_thresholds');

        return match (true) {
            $fraction < $thresholds['medium'] => 'Low',
            $fraction < $thresholds['high'] => 'Medium',
            $fraction < $thresholds['critical'] => 'High',
            default => 'Critical',
        };
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
