<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBatchRequest;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BatchController extends Controller
{
    /**
     * Display the approved Batch Management screen.
     */
    public function index(): View
    {
        return view('supplier.batches');
    }

    /**
     * Read shared inventory batches and their actual append-only movement history.
     */
    public function data(InventoryService $inventory): JsonResponse
    {
        $data = DB::transaction(function () use ($inventory): array {
            $flowers = Flower::query()->orderBy('id')->get();
            $batches = InventoryBatch::query()->with(['flower', 'movements.operation'])
                ->orderBy('received_date')->orderBy('id')->get();

            return [
                'flowers' => $flowers->map(fn (Flower $flower): array => [
                    'id' => $flower->id,
                    'code' => $flower->code,
                    'name' => $flower->name,
                    'category' => $flower->category,
                    'itemType' => $flower->product_type,
                    'baseUnit' => $flower->product_type,
                    'status' => $flower->status,
                ])->values()->all(),
                'batches' => $batches->map(function (InventoryBatch $batch) use ($inventory): array {
                    $history = $batch->movements->sortBy('id')->map(fn (InventoryMovement $movement): array => [
                        'id' => $movement->id,
                        'date' => $movement->created_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
                        'type' => $movement->type,
                        'quantity' => (int) $movement->quantity,
                        'remarks' => $movement->remarks ?? '',
                        'user' => $movement->operation?->actor_name ?? 'Unknown user',
                        'metadata' => $movement->metadata ?? [],
                    ])->values();
                    $spoilage = $history->filter(fn (array $entry): bool => ($entry['metadata']['reason'] ?? null) === 'Spoilage')->last();

                    return [
                        'dbId' => $batch->id,
                        'id' => $batch->code,
                        'flower' => $batch->flower->code,
                        'received' => $batch->received_date->format('Y-m-d'),
                        'harvest' => $batch->harvest_date->format('Y-m-d'),
                        'expiry' => $batch->expiry_date?->format('Y-m-d') ?? '',
                        'initial' => (int) $batch->initial_quantity,
                        'remaining' => (int) $batch->remaining_quantity,
                        'remarks' => $batch->remarks ?? '',
                        'spoiled' => (bool) $batch->spoiled,
                        'version' => $inventory->batchVersion($batch),
                        'history' => $history->all(),
                        'spoilageReason' => $spoilage['remarks'] ?? '',
                    ];
                })->values()->all(),
            ];
        });

        return response()->json([
            'data' => $data,
            'today' => CarbonImmutable::now('Asia/Manila')->toDateString(),
            'warning_days' => 3,
        ]);
    }

    /**
     * Append an auditable metadata edit while keeping all stock and identity fields intact.
     */
    public function update(UpdateBatchRequest $request, InventoryBatch $batch, InventoryService $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Batch details updated. Stock quantities are unchanged.',
            'result' => $inventory->updateBatchMetadata($batch, $request->validated(), $request->user()),
        ]);
    }
}
