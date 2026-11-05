<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectReceiptRequest;
use App\Http\Requests\StockInRequest;
use App\Http\Requests\StockOutRequest;
use App\Http\Requests\UpdateInventoryUnitsRequest;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryUnitSetting;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InventoryController extends Controller
{
    /**
     * Display the approved Inventory Management screen.
     */
    public function index(): View
    {
        return view('supplier.inventory');
    }

    /**
     * Map a database snapshot to the approved UI without sample orders or sample stock.
     */
    public function data(): JsonResponse
    {
        $data = DB::transaction(function (): array {
            $flowers = Flower::query()->orderBy('id')->get();
            $byId = $flowers->keyBy('id');
            $settings = InventoryUnitSetting::query()->get()->keyBy('flower_id');
            $batches = InventoryBatch::query()->orderBy('received_date')->orderBy('id')->get();
            $onHand = $batches->where('spoiled', false)->groupBy('flower_id')
                ->map(fn ($rows) => (int) $rows->sum('remaining_quantity'));
            $reservations = InventoryReservation::query()->where('status', 'Active')
                ->select('flower_id')->selectRaw('SUM(quantity) AS total')
                ->groupBy('flower_id')->get()->keyBy('flower_id');
            $movements = InventoryMovement::query()->with('operation')->orderByDesc('id')->get();
            $batchById = $batches->keyBy('id');

            return [
                'flowers' => $flowers->map(function (Flower $flower) use ($settings, $onHand, $reservations): array {
                    $units = $settings->get($flower->id);
                    $physical = (int) $onHand->get($flower->id, 0);
                    $reserved = (int) ($reservations->get($flower->id)?->total ?? 0);
                    $available = $physical - $reserved;

                    return [
                        'id' => $flower->id,
                        'code' => $flower->code,
                        'name' => $flower->name,
                        'category' => $flower->category,
                        'minimum' => (int) $flower->minimum_stock,
                        'itemType' => $flower->product_type,
                        'baseUnit' => $flower->product_type,
                        'units' => $flower->product_type === 'Bouquet' ? ['Bouquet' => 1] : [
                            'Stem' => 1,
                            'Bunch' => (int) ($units?->bunch_factor ?? 10),
                            'Bundle' => (int) ($units?->bundle_factor ?? 20),
                            'Box' => (int) ($units?->box_factor ?? 100),
                        ],
                        'status' => $flower->status,
                        'onHand' => $physical,
                        'reserved' => $reserved,
                        'available' => $available,
                        'stockStatus' => $available <= 0 ? 'Out of Stock'
                            : ($available < $flower->minimum_stock ? 'Low Stock' : 'Normal'),
                    ];
                })->values()->all(),
                'batches' => $batches->map(function (InventoryBatch $batch) use ($byId): array {
                    return [
                        'dbId' => $batch->id,
                        'id' => $batch->code,
                        'flower' => $byId->get($batch->flower_id)->code,
                        'flowerId' => $batch->flower_id,
                        'initial' => (int) $batch->initial_quantity,
                        'remaining' => (int) $batch->remaining_quantity,
                        'received' => $batch->received_date->format('Y-m-d'),
                        'harvest' => $batch->harvest_date->format('Y-m-d'),
                        'expiry' => $batch->expiry_date?->format('Y-m-d') ?? '',
                        'quantity' => (int) $batch->received_quantity,
                        'unit' => $batch->receiving_unit,
                        'factor' => (int) $batch->conversion_factor,
                        'remarks' => $batch->remarks ?? '',
                        'spoiled' => (bool) $batch->spoiled,
                    ];
                })->values()->all(),
                'movements' => $movements->map(function (InventoryMovement $movement) use ($byId, $batchById): array {
                    return [
                        'id' => $movement->id,
                        'batch' => $batchById->get($movement->batch_id)->code,
                        'flower' => $byId->get($movement->flower_id)->code,
                        'type' => $movement->type,
                        'quantity' => (int) $movement->quantity,
                        'remarks' => $movement->remarks ?? '',
                        'date' => $movement->created_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
                        'user' => $movement->operation?->actor_name ?? 'Unknown user',
                    ];
                })->values()->all(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Receive an active item's new physical stock.
     */
    public function stockIn(StockInRequest $request, InventoryService $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Stock received successfully.',
            'result' => $inventory->stockIn($request->validated(), $request->user()),
        ], 201);
    }

    /**
     * Correct a receipt while retaining the signed append-only movement history.
     */
    public function correctReceipt(CorrectReceiptRequest $request, InventoryBatch $batch, InventoryService $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Receipt correction recorded successfully.',
            'result' => $inventory->correctReceipt($batch, $request->validated(), $request->user()),
        ]);
    }

    /**
     * Record manual other-use or spoilage deductions from unreserved stock.
     */
    public function stockOut(StockOutRequest $request, InventoryService $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Stock-out recorded successfully.',
            'result' => $inventory->stockOut($request->validated(), $request->user()),
        ]);
    }

    /**
     * Save stem receiving settings for future receipts only.
     */
    public function updateUnits(UpdateInventoryUnitsRequest $request, Flower $flower, InventoryService $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Receiving units updated. Existing receipt conversions are unchanged.',
            'result' => $inventory->updateUnits($flower, $request->validated()),
        ]);
    }
}
