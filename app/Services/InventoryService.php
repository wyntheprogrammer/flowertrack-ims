<?php

namespace App\Services;

use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryUnitSetting;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    private const MAX_QUANTITY = 4294967295;

    /**
     * Receive physical stock using a server-calculated, immutable conversion snapshot.
     */
    public function stockIn(array $input, User $actor): array
    {
        $payload = $this->receiptPayload($input);
        $flowerId = (int) $input['flower_id'];

        return $this->operate($input['request_key'], 'StockIn', $flowerId, $payload, $actor,
            function (Flower $flower, InventoryOperation $operation) use ($payload): array {
                if ($flower->status !== 'Active') {
                    $this->invalid('flower_id', 'Only active items can receive new stock.');
                }
                $factor = $this->factor($flower, $payload['unit']);
                $initial = $this->convertedQuantity($payload['quantity'], $factor);
                $this->checkTotal($this->onHand($flower->id) + $initial);

                $batch = InventoryBatch::create([
                    'flower_id' => $flower->id,
                    'code' => 'TMP-'.Str::ulid(),
                    'received_date' => $payload['received'],
                    'harvest_date' => $payload['harvest'],
                    'expiry_date' => $payload['expiry'],
                    'received_quantity' => $payload['quantity'],
                    'receiving_unit' => $payload['unit'],
                    'conversion_factor' => $factor,
                    'initial_quantity' => $initial,
                    'remaining_quantity' => $initial,
                    'spoiled' => false,
                    'remarks' => $payload['remarks'],
                ]);
                $batch->code = 'BAT-'.str_pad((string) $batch->id, 3, '0', STR_PAD_LEFT);
                $batch->save();
                $this->movement($operation, $batch, 'Stock-In', $initial, $payload['remarks'], [
                    'receipt' => $this->receiptSnapshot($batch),
                ]);

                return ['batch_id' => $batch->id];
            });
    }

    /**
     * Append a receipt correction without rewriting previous inventory movements.
     */
    public function correctReceipt(InventoryBatch $batch, array $input, User $actor): array
    {
        $payload = ['batch_id' => (int) $batch->id, ...$this->receiptPayload($input)];
        if ($payload['remarks'] === null) {
            $this->invalid('remarks', 'Explain why this receipt is being corrected.');
        }

        return $this->operate($input['request_key'], 'ReceiptCorrection', (int) $batch->flower_id, $payload, $actor,
            function (Flower $flower, InventoryOperation $operation) use ($payload): array {
                $locked = InventoryBatch::query()->lockForUpdate()->findOrFail($payload['batch_id']);
                if ((int) $locked->flower_id !== (int) $flower->id) {
                    $this->invalid('batch_id', 'The receipt does not belong to this item.');
                }
                if ($locked->spoiled) {
                    $this->invalid('batch_id', 'A spoiled receipt cannot be corrected.');
                }
                $before = $this->receiptSnapshot($locked);
                $factor = $payload['unit'] === $locked->receiving_unit
                    ? (int) $locked->conversion_factor
                    : $this->factor($flower, $payload['unit']);
                $initial = $this->convertedQuantity($payload['quantity'], $factor);
                $consumed = (int) $locked->initial_quantity - (int) $locked->remaining_quantity;
                if ($initial < $consumed) {
                    $this->invalid('quantity', 'The corrected receipt cannot be less than stock already consumed.');
                }
                $remaining = $initial - $consumed;
                $delta = $remaining - (int) $locked->remaining_quantity;
                $newTotal = $this->onHand($flower->id) + $delta;
                $this->checkTotal($newTotal);
                if ($newTotal < $this->reserved($flower->id)) {
                    $this->invalid('quantity', 'This correction would reduce physical stock below active reservations.');
                }
                $locked->fill([
                    'received_date' => $payload['received'],
                    'harvest_date' => $payload['harvest'],
                    'expiry_date' => $payload['expiry'],
                    'received_quantity' => $payload['quantity'],
                    'receiving_unit' => $payload['unit'],
                    'conversion_factor' => $factor,
                    'initial_quantity' => $initial,
                    'remaining_quantity' => $remaining,
                    'remarks' => $payload['remarks'],
                ]);
                $locked->save();
                $this->movement($operation, $locked, 'Receipt Correction', $delta, $payload['remarks'], [
                    'before' => $before,
                    'after' => $this->receiptSnapshot($locked),
                ]);

                return ['batch_id' => $locked->id];
            });
    }

    /**
     * Deduct unreserved physical stock FIFO, or from a specifically documented batch.
     */
    public function stockOut(array $input, User $actor): array
    {
        $payload = [
            'quantity' => (int) $input['quantity'],
            'reason' => $input['reason'],
            'batch_id' => isset($input['batch_id']) ? (int) $input['batch_id'] : null,
            'remarks' => $this->text($input['remarks'] ?? null),
        ];

        return $this->operate($input['request_key'], 'StockOut', (int) $input['flower_id'], $payload, $actor,
            function (Flower $flower, InventoryOperation $operation) use ($payload): array {
                if (! in_array($payload['reason'], ['Other use', 'Spoilage'], true)) {
                    $this->invalid('reason', 'Customer-order deductions belong to the future order completion workflow.');
                }
                if (($payload['reason'] === 'Spoilage' || $payload['batch_id'] !== null) && $payload['remarks'] === null) {
                    $this->invalid('remarks', 'Explain spoilage or a specific batch override.');
                }
                $this->checkQuantity($payload['quantity']);
                $available = $this->onHand($flower->id) - $this->reserved($flower->id);
                if ($payload['quantity'] > $available) {
                    $this->invalid('quantity', 'The quantity exceeds stock available after active reservations.');
                }
                $query = InventoryBatch::query()->where('flower_id', $flower->id)
                    ->where('spoiled', false)->where('remaining_quantity', '>', 0);
                if ($payload['batch_id'] !== null) {
                    $query->whereKey($payload['batch_id']);
                }
                $batches = $query->orderBy('received_date')->orderBy('id')->lockForUpdate()->get();
                $pending = $payload['quantity'];
                $allocations = [];
                $ledgerRemarks = $payload['reason']
                    .($payload['batch_id'] !== null ? ' (specific batch override)' : '')
                    .($payload['remarks'] !== null ? ' — '.$payload['remarks'] : '');
                foreach ($batches as $batch) {
                    if ($pending === 0) {
                        break;
                    }
                    $amount = min($pending, (int) $batch->remaining_quantity);
                    $batch->remaining_quantity = (int) $batch->remaining_quantity - $amount;
                    if ($payload['reason'] === 'Spoilage' && $batch->remaining_quantity === 0) {
                        $batch->spoiled = true;
                    }
                    $batch->save();
                    $this->movement($operation, $batch, 'Stock-Out', -$amount, $ledgerRemarks, [
                        'reason' => $payload['reason'],
                        'batch_override' => $payload['batch_id'] !== null,
                    ]);
                    $allocations[] = ['batch_id' => $batch->id, 'quantity' => $amount];
                    $pending -= $amount;
                }
                if ($pending !== 0) {
                    $this->invalid('batch_id', 'The selected usable batch does not have enough stock or does not belong to this item.');
                }

                return ['allocations' => $allocations];
            });
    }

    /**
     * Change future stem conversions while preserving historical receipt factors.
     */
    public function updateUnits(Flower $flower, array $values): array
    {
        return DB::transaction(function () use ($flower, $values): array {
            $locked = Flower::query()->lockForUpdate()->findOrFail($flower->id);
            if ($locked->product_type !== 'Stem') {
                $this->invalid('flower_id', 'Finished bouquets use Bouquet factor 1 and have no stem conversions.');
            }
            $factors = [];
            foreach (['bunch_factor', 'bundle_factor', 'box_factor'] as $key) {
                $factor = (int) $values[$key];
                if ($factor < 1 || $factor > 1000000) {
                    $this->invalid($key, 'Conversion factors must be whole numbers from 1 to 1000000.');
                }
                $factors[$key] = $factor;
            }
            InventoryUnitSetting::updateOrCreate(['flower_id' => $locked->id], $factors);

            return ['flower_id' => $locked->id, ...$factors];
        });
    }

    /**
     * Serialize all writes per item and replay matching UUID operations exactly once.
     */
    private function operate(string $requestKey, string $kind, int $flowerId, array $payload, User $actor, Closure $write): array
    {
        $requestKey = strtolower($requestKey);
        ksort($payload);
        $hash = hash('sha256', json_encode([
            'actor_id' => (int) $actor->id,
            'flower_id' => $flowerId,
            'kind' => $kind,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($requestKey, $kind, $flowerId, $hash, $actor, $write): array {
                // All current and future reservation writers must take this same parent lock.
                $flower = Flower::query()->lockForUpdate()->findOrFail($flowerId);
                $existing = InventoryOperation::query()->where('request_key', $requestKey)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }
                $operation = InventoryOperation::create([
                    'request_key' => $requestKey,
                    'payload_hash' => $hash,
                    'flower_id' => $flowerId,
                    'kind' => $kind,
                    'actor_id' => $actor->id,
                    'actor_name' => Str::limit((string) $actor->name, 255, ''),
                ]);
                $result = ['operation_id' => $operation->id, ...$write($flower, $operation)];
                $operation->result = $result;
                $operation->save();

                return $result;
            });
        } catch (QueryException $exception) {
            // A different-item request may race the global UUID unique constraint.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $existing = InventoryOperation::query()->where('request_key', $requestKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $hash);
        }
    }

    /**
     * Reject a UUID reused for a different actor, item, operation, or normalized payload.
     */
    private function replay(InventoryOperation $operation, string $hash): array
    {
        if (! hash_equals($operation->payload_hash, $hash)) {
            $this->invalid('request_key', 'This request key has already been used for a different operation. Refresh before retrying.');
        }
        if (! is_array($operation->result)) {
            $this->invalid('request_key', 'This operation has no completed result. Refresh before retrying.');
        }

        return $operation->result;
    }

    /**
     * Normalize only business fields so equivalent valid retries produce the same hash.
     */
    private function receiptPayload(array $input): array
    {
        return [
            'quantity' => (int) $input['quantity'],
            'unit' => $input['unit'],
            'received' => $input['received'],
            'harvest' => $input['harvest'],
            'expiry' => $this->text($input['expiry'] ?? null),
            'remarks' => $this->text($input['remarks'] ?? null),
        ];
    }

    /**
     * Calculate a receiving factor from the current database settings, never client data.
     */
    private function factor(Flower $flower, string $unit): int
    {
        if ($flower->product_type === 'Bouquet') {
            if ($unit !== 'Bouquet') {
                $this->invalid('unit', 'Finished bouquets must be received as Bouquet units.');
            }

            return 1;
        }
        if ($flower->product_type !== 'Stem') {
            $this->invalid('unit', 'This item has an unsupported base unit.');
        }
        if ($unit === 'Stem') {
            return 1;
        }
        $columns = ['Bunch' => 'bunch_factor', 'Bundle' => 'bundle_factor', 'Box' => 'box_factor'];
        if (! isset($columns[$unit])) {
            $this->invalid('unit', 'Select a valid stem receiving unit.');
        }
        $settings = InventoryUnitSetting::query()->where('flower_id', $flower->id)->first();
        $defaults = ['Bunch' => 10, 'Bundle' => 20, 'Box' => 100];

        return $settings !== null ? (int) $settings->{$columns[$unit]} : $defaults[$unit];
    }

    /**
     * Bound physical quantities to the unsigned database range before any insert.
     */
    private function convertedQuantity(int $quantity, int $factor): int
    {
        $this->checkQuantity($quantity);
        if ($factor < 1 || $factor > 1000000) {
            $this->invalid('unit', 'The stored receiving conversion is outside the supported range.');
        }
        $initial = $quantity * $factor;
        $this->checkQuantity($initial);

        return $initial;
    }

    /**
     * Validate a positive base-unit quantity.
     */
    private function checkQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            $this->invalid('quantity', 'Quantity must be between 1 and 4294967295 base units.');
        }
    }

    /**
     * Enforce the per-item physical-stock aggregate cap under the parent item lock.
     */
    private function checkTotal(int $total): void
    {
        if ($total < 0 || $total > self::MAX_QUANTITY) {
            $this->invalid('quantity', 'The resulting item stock exceeds the supported inventory range.');
        }
    }

    /**
     * Total usable physical stock; expiry alone never causes an automatic deduction.
     */
    private function onHand(int $flowerId): int
    {
        return (int) InventoryBatch::query()->where('flower_id', $flowerId)
            ->where('spoiled', false)->sum('remaining_quantity');
    }

    /**
     * Read active reservations while the shared parent item lock prevents concurrent writes.
     */
    private function reserved(int $flowerId): int
    {
        return (int) InventoryReservation::query()->where('flower_id', $flowerId)
            ->where('status', 'Active')->sum('quantity');
    }

    /**
     * Append an immutable signed movement associated with its idempotent operation.
     */
    private function movement(InventoryOperation $operation, InventoryBatch $batch, string $type, int $quantity, ?string $remarks, array $metadata): void
    {
        InventoryMovement::create([
            'operation_id' => $operation->id,
            'batch_id' => $batch->id,
            'flower_id' => $batch->flower_id,
            'type' => $type,
            'quantity' => $quantity,
            'remarks' => $remarks,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Preserve receipt before/after values in correction and receiving history.
     */
    private function receiptSnapshot(InventoryBatch $batch): array
    {
        return [
            'received' => $batch->received_date->format('Y-m-d'),
            'harvest' => $batch->harvest_date->format('Y-m-d'),
            'expiry' => $batch->expiry_date?->format('Y-m-d'),
            'quantity' => (int) $batch->received_quantity,
            'unit' => $batch->receiving_unit,
            'factor' => (int) $batch->conversion_factor,
            'initial' => (int) $batch->initial_quantity,
            'remaining' => (int) $batch->remaining_quantity,
            'remarks' => $batch->remarks,
        ];
    }

    /**
     * Canonicalize optional text consistently across requests and retries.
     */
    private function text(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Return domain failures using Laravel's standard JSON validation contract.
     */
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
