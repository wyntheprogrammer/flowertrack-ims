<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Services\SpoilagePredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SpoilagePredictionController extends Controller
{
    public function index(Request $request): View
    {
        $this->checkAccess($request);

        return view('supplier.spoilage-prediction');
    }

    // Show saved results immediately; freshness uses the complete input fingerprint.
    public function data(Request $request, SpoilagePredictionService $service): JsonResponse
    {
        $this->checkAccess($request);
        $rows = [];
        $plans = $service->plan();
        foreach ($plans as $plan) {
            $batch = $plan['batch'];
            $prediction = $plan['saved'];
            $rows[] = [
                'id' => $batch->id, 'batch' => $batch->code,
                'flowerCode' => $batch->flower->code,
                'flowerName' => $batch->flower->name,
                'category' => $batch->flower->category,
                'baseUnit' => $batch->flower->product_type,
                'remaining' => (int) $batch->remaining_quantity,
                'receivedDate' => $batch->received_date->toDateString(),
                'storageCondition' => $batch->storage_condition,
                'predictionStatus' => $prediction?->status ?? 'NotGenerated',
                'risk' => $prediction?->risk_level,
                'predictedFraction' => $prediction?->predicted_fraction !== null
                    ? (float) $prediction->predicted_fraction : null,
                'predictedQuantity' => $prediction?->predicted_quantity !== null
                    ? (float) $prediction->predicted_quantity : null,
                'openingQuantity' => $prediction?->opening_quantity,
                'asOfDate' => $prediction?->as_of_date?->toDateString(),
                'forecastEndDate' => $prediction?->forecast_end_date?->toDateString(),
                'method' => $prediction?->method,
                'historyDays' => $prediction?->history_days ?? 0,
                'message' => $prediction?->message,
                'needsRefresh' => $plan['cached'] === null,
            ];
        }

        return response()->json(['data' => $rows, 'forecasts' => $service->forecasts($plans)])->header('Cache-Control', 'no-store');
    }

    // The browser sends one automatic POST after loading saved data.
    public function refresh(Request $request, SpoilagePredictionService $service): JsonResponse
    {
        $this->checkAccess($request);

        return response()->json($service->refresh($request->user()))
            ->header('Cache-Control', 'no-store');
    }

    // Retain compatibility with the existing single-batch route and Tinker tests.
    public function generate(Request $request, InventoryBatch $batch, SpoilagePredictionService $service): JsonResponse
    {
        $this->checkAccess($request);
        $prediction = $service->generate($batch, $request->user());

        return response()->json([
            'message' => $prediction->status === 'Predicted' ? 'Prediction saved.' : 'Insufficient supporting history.',
            'data' => $prediction->only(['batch_id', 'status', 'risk_level', 'predicted_quantity', 'method', 'history_days', 'message']),
        ])->header('Cache-Control', 'no-store');
    }

    private function checkAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ! $user->must_change_password
            && in_array($user->role, ['admin', 'supplier'], true), 403);
    }
}
