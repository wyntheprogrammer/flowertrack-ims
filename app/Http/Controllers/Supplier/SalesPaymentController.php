<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\RefundPaymentRequest;
use App\Http\Requests\SalesPriceRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Models\FinanceOperation;
use App\Models\Flower;
use App\Models\OrderPayment;
use App\Services\SalesPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesPaymentController extends Controller
{
    public function __construct(private readonly SalesPaymentService $sales) {}

    public function index(): View
    {
        return view('supplier.sales-payments');
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->sales->data($request->user()));
    }

    public function price(Flower $flower, SalesPriceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Selling price saved. Existing order prices are unchanged.',
            'data' => $this->sales->price($flower, $request->validated(), $request->user()),
        ]);
    }

    public function recordPayment(StorePaymentRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Full payment recorded as Pending. Confirm after checking actual collection.',
            'data' => $this->sales->recordPayment($request->validated(), $request->user(), requireReadyPickup: true),
        ], 201);
    }

    public function confirm(OrderPayment $payment, ConfirmPaymentRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Payment confirmed.',
            'data' => $this->sales->confirm($payment, $request->validated(), $request->user()),
        ]);
    }

    public function proof(OrderPayment $payment): StreamedResponse
    {
        $operation = FinanceOperation::where('kind', 'delivery_payment')->where('payment_id', $payment->id)->first();
        $path = $operation?->metadata['proof_path'] ?? null;
        $disk = Storage::disk('local');
        abort_unless(is_string($path) && str_starts_with($path, 'payment-proofs/') && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function refund(OrderPayment $payment, RefundPaymentRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Refund recorded. Original receipt retained; this does not transfer money or return stock.',
            'data' => $this->sales->refund($payment, $request->validated(), $request->user()),
        ]);
    }
}
