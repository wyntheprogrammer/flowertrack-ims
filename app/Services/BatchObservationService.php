<?php

namespace App\Services;

use App\Models\BatchDailyObservation;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BatchObservationService
{
    public const SOURCE = 'inventory-ledger-v1';

    /**
     * Preview unless a caller explicitly confirms the range or requests ledger
     * verification. Login/logout catch-up records its triggering user, without
     * claiming manual confirmation. Receipt-day opening includes the receipt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $from, string $to, ?int $batchId = null, ?User $actor = null, bool $automatic = false, string $origin = 'Console'): array
    {
        $today = CarbonImmutable::now(config('spoilage.observation_timezone'))->toDateString();
        Validator::make(compact('from', 'to', 'batchId'), [
            'from' => ['required', 'date_format:Y-m-d', 'before:'.$today],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:'.$today],
            'batchId' => ['nullable', 'integer', 'min:1', 'exists:inventory_batches,id'],
        ])->validate();
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365) {
            throw ValidationException::withMessages(['to' => 'Collect at most 366 completed days per request.']);
        }

        $results = [];
        InventoryBatch::query()->when($batchId, fn ($query) => $query->whereKey($batchId))
            ->select(['id', 'flower_id'])->chunkById(100, function ($batches) use ($from, $to, $actor, $automatic, $origin, &$results) {
                foreach ($batches as $candidate) {
                    $rows = DB::transaction(function () use ($candidate, $from, $to, $actor, $automatic, $origin) {
                        $verifier = null;
                        if ($actor !== null) {
                            $verifier = User::query()->lockForUpdate()->findOrFail($actor->id);
                            if (! $verifier->is_active || $verifier->must_change_password
                                || ! in_array($verifier->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true)) {
                                throw ValidationException::withMessages(['actor' => 'Use an active Admin or Supplier with an updated password.']);
                            }
                        }
                        $flower = Flower::query()->lockForUpdate()->findOrFail($candidate->flower_id);
                        $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($candidate->id);
                        $movements = $batch->movements()->with('operation')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
                        $ledger = $this->ledger($batch, $flower, $movements);
                        $rows = [];
                        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $to; $date = $date->addDay()) {
                            $day = $date->toDateString();
                            $values = $this->day($day, $ledger, $flower);
                            $existing = BatchDailyObservation::query()->where('batch_id', $batch->id)
                                ->whereDate('observation_date', $day)->lockForUpdate()->first();
                            $status = ($values['is_complete'] ?? false) ? 'Ready' : 'Excluded';
                            if ($existing && $existing->source !== self::SOURCE) {
                                $status = 'Preserved';
                                $values = ['exclusion_reason' => 'Existing observation belongs to another source.'];
                            } elseif ($verifier !== null || $automatic) {
                                if (isset($values['opening_quantity'])) {
                                    $record = $existing ?? new BatchDailyObservation;
                                    $record->fill([
                                        'batch_id' => $batch->id, 'flower_id' => $flower->id,
                                        'observation_date' => $day, 'source' => self::SOURCE,
                                        ...$values,
                                    ]);
                                    if (! $record->exists || $record->isDirty()) {
                                        $record->collected_by = $verifier?->id;
                                        $record->verified_at = $values['is_complete'] ? now() : null;
                                        $record->save();
                                        $audit = app(AuditService::class);
                                        if ($audit->enabled($record)) {
                                            $audit->record('Spoilage Prediction', 'Daily Observation Collected', $record,
                                                after: ['quantity' => $record->closing_quantity, 'status' => $status],
                                                description: $batch->code.' observation for '.$day.': '.($values['exclusion_reason'] ?? 'completed and reconciled'),
                                                actor: $verifier, source: $origin);
                                        }
                                    }
                                    $status = $values['is_complete'] ? 'Saved' : 'Excluded';
                                } elseif ($existing) {
                                    $existing->fill(['is_complete' => false, 'verified_at' => null,
                                        'exclusion_reason' => $values['exclusion_reason']])->save();
                                }
                            }
                            $rows[] = ['batch' => $batch->code, 'date' => $day, 'status' => $status, ...$values];
                        }

                        return $rows;
                    }, 3);
                    array_push($results, ...$rows);
                }
            });

        return $results;
    }

    /**
     * Called synchronously after a ledger insert, inside the inventory transaction.
     * Rebuild rather than increment so retries and closing cannot double-count.
     */
    public function refreshDraft(InventoryMovement $movement): void
    {
        $flower = Flower::query()->lockForUpdate()->findOrFail($movement->flower_id);
        $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($movement->batch_id);
        $movements = $batch->movements()->with('operation')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $ledger = $this->ledger($batch, $flower, $movements);
        $day = $movement->created_at->copy()->setTimezone(config('spoilage.observation_timezone'))->toDateString();
        $today = CarbonImmutable::now(config('spoilage.observation_timezone'))->toDateString();
        $metadata = $movement->metadata ?? [];
        $correctsHistory = $movement->type === 'Receipt Correction'
            || ($metadata['before']['harvest'] ?? null) !== ($metadata['after']['harvest'] ?? null)
            || ($metadata['before']['received'] ?? null) !== ($metadata['after']['received'] ?? null);
        $affected = BatchDailyObservation::query()->where('batch_id', $batch->id)->where('source', self::SOURCE);
        if (! $correctsHistory) {
            $affected->whereDate('observation_date', '>=', $day);
        }
        $earliest = (clone $affected)->min('observation_date');
        $affected->update(['is_complete' => false, 'verified_at' => null,
            'exclusion_reason' => 'Inventory changed; daily closing must reverify this day.']);

        $dates = [$day];
        if ($day < $today && BatchDailyObservation::query()->where('batch_id', $batch->id)
            ->where('source', self::SOURCE)->whereDate('observation_date', $today)->exists()) {
            $dates[] = $today;
        }
        foreach ($dates as $date) {
            $values = $this->day($date, $ledger, $flower, draft: true);
            $row = BatchDailyObservation::query()->where('batch_id', $batch->id)
                ->whereDate('observation_date', $date)->lockForUpdate()->first();
            if (($row && $row->source !== self::SOURCE) || ! isset($values['opening_quantity'])) {
                continue;
            }
            $row ??= new BatchDailyObservation;
            $row->fill(['batch_id' => $batch->id, 'flower_id' => $flower->id,
                'observation_date' => $date, 'source' => self::SOURCE, ...$values,
                'is_complete' => false, 'verified_at' => null, 'collected_by' => null,
            ]);
            if ($row->isDirty()) {
                $row->save();
            }
        }

        if (Schema::hasColumn('batch_observation_closing_state', 'revision')) {
            $state = DB::table('batch_observation_closing_state')->where('id', 1)->lockForUpdate()->first();
            $earliest = $earliest !== null ? min($day, substr($earliest, 0, 10)) : $day;
            if ($state) {
                $restart = max($earliest, $state->started_on);
                if ($restart <= $state->next_date) {
                    DB::table('batch_observation_closing_state')->where('id', 1)->update([
                        'next_date' => $restart, 'revision' => DB::raw('revision + 1'), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Use actual entry timestamps, never fabricate days from backdated receipt dates.
     * Corrections cannot be interpreted as spoilage or customer releases.
     *
     * @return array{events: array, error: ?string, correction_day: ?string}
     */
    private function ledger(InventoryBatch $batch, Flower $flower, Collection $movements): array
    {
        $events = [];
        $balance = 0;
        $initial = 0;
        $state = null;
        $correctionDay = null;
        $error = $movements->isEmpty() ? 'No receipt ledger is available.' : null;
        foreach ($movements as $movement) {
            $day = $movement->created_at->copy()->setTimezone(config('spoilage.observation_timezone'))->toDateString();
            $metadata = $movement->metadata ?? [];
            $quantity = (int) $movement->quantity;
            $spoiled = 0;
            $released = 0;
            $changedStorage = false;
            if ((int) $movement->flower_id !== (int) $flower->id
                || (int) $movement->operation?->flower_id !== (int) $flower->id
                || $movement->created_at->isFuture()) {
                $error = 'Ledger identity or timestamp is inconsistent.';
            }
            if ($movement->type === 'Stock-In' && $state === null && $quantity > 0
                && $movement->operation?->kind === 'StockIn') {
                $state = $metadata['receipt'] ?? [];
                $initial = $quantity;
                if (($state['initial'] ?? null) !== $quantity || ($state['remaining'] ?? null) !== $quantity) {
                    $error = 'Receipt snapshot does not reconcile.';
                }
            } elseif ($movement->type === 'Stock-Out' && $state !== null && $quantity < 0
                && in_array($movement->operation?->kind, ['StockOut', 'OrderFulfillment'], true)
                && in_array($metadata['reason'] ?? null, ['Spoilage', 'Other use', 'Customer order'], true)) {
                if ($metadata['reason'] === 'Spoilage') {
                    $spoiled = -$quantity;
                } else {
                    $released = -$quantity;
                }
            } elseif (in_array($movement->type, ['Receipt Correction', 'Batch Metadata Update'], true) && $state !== null) {
                $before = $metadata['before'] ?? [];
                $after = $metadata['after'] ?? [];
                $expectedKind = $movement->type === 'Receipt Correction' ? 'ReceiptCorrection' : 'BatchMetadataUpdate';
                if ($movement->operation?->kind !== $expectedKind || ($before['remaining'] ?? null) !== $balance
                    || ($after['remaining'] ?? null) !== $balance + $quantity
                    || ($before['initial'] ?? null) !== $initial
                    || ($after['initial'] ?? null) !== $initial + $quantity
                    || ($movement->type === 'Batch Metadata Update' && $quantity !== 0)) {
                    $error = 'Correction snapshot does not reconcile.';
                }
                $changedStorage = ($state['storage_condition'] ?? null) !== ($after['storage_condition'] ?? null);
                if ($movement->type === 'Receipt Correction'
                    || ($before['harvest'] ?? null) !== ($after['harvest'] ?? null)
                    || ($before['received'] ?? null) !== ($after['received'] ?? null)) {
                    $correctionDay = $day;
                }
                $state = $after;
                $initial += $quantity;
            } else {
                $error = 'Unsupported or incomplete inventory movement.';
            }
            $balance += $quantity;
            if ($balance < 0 || $balance > 4294967295) {
                $error = 'Ledger balance is outside the supported quantity range.';
            }
            $events[] = compact('day', 'state', 'balance', 'spoiled', 'released', 'changedStorage');
        }
        if ($balance !== (int) $batch->remaining_quantity || $initial !== (int) $batch->initial_quantity) {
            $error = 'Ledger totals do not match the current batch.';
        }
        if ($state !== null && (($state['harvest'] ?? null) !== $batch->harvest_date->toDateString()
            || ($state['received'] ?? null) !== $batch->received_date->toDateString()
            || ($state['storage_condition'] ?? null) !== $batch->storage_condition)) {
            $error = 'Batch metadata is not supported by its ledger snapshots.';
        }

        return ['events' => $events, 'error' => $error, 'correction_day' => $correctionDay];
    }

    /** @return array<string, mixed> */
    private function day(string $day, array $ledger, Flower $flower, bool $draft = false): array
    {
        if ($ledger['error'] !== null) {
            return ['exclusion_reason' => $ledger['error']];
        }
        $state = null;
        $closing = 0;
        $spoiled = 0;
        $released = 0;
        $changedStorage = false;
        foreach ($ledger['events'] as $event) {
            if ($event['day'] > $day) {
                break;
            }
            $state = $event['state'];
            $closing = $event['balance'];
            if ($event['day'] === $day) {
                $spoiled += $event['spoiled'];
                $released += $event['released'];
                $changedStorage = $changedStorage || $event['changedStorage'];
            }
        }
        if ($state === null) {
            return ['exclusion_reason' => 'Receipt was not recorded by this day; missing history is not zero.'];
        }
        $opening = $closing + $spoiled + $released;
        if ($opening === 0) {
            return ['exclusion_reason' => 'Batch was already depleted before this day.'];
        }
        $metadata = Validator::make($state, [
            'harvest' => ['required', 'date_format:Y-m-d', 'before_or_equal:received'],
            'received' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$day],
            'unit' => ['required', 'in:Stem,Bunch,Bundle,Box,Bouquet'],
            'storage_condition' => ['nullable', 'string', 'max:30'],
        ]);
        if ($metadata->fails() || ($state['unit'] === 'Bouquet' ? 'Bouquet' : 'Stem') !== $flower->product_type) {
            return ['exclusion_reason' => 'Historical dates, storage or base unit are inconsistent.'];
        }
        $reason = $changedStorage ? 'Storage changed during this day.' : null;
        if ($ledger['correction_day'] !== null && $day <= $ledger['correction_day']) {
            $reason = 'Receipt or date correction prevents verification of this historical day.';
        }
        if (trim($state['storage_condition'] ?? '') === '') {
            $reason = 'Actual storage condition is unknown for this day.';
        }

        return [
            'harvest_date' => $state['harvest'], 'received_date' => $state['received'],
            'storage_condition' => $state['storage_condition'] ?? null, 'base_unit' => $flower->product_type,
            'opening_quantity' => $opening, 'spoiled_quantity' => $spoiled,
            'released_quantity' => $released, 'closing_quantity' => $closing,
            'is_complete' => ! $draft && $reason === null, 'exclusion_reason' => $reason,
        ];
    }
}
