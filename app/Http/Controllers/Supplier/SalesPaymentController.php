<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\RefundPaymentRequest;
use App\Http\Requests\SalesPriceRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Flower;
use App\Models\OrderPayment;
use App\Services\SalesPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesPaymentController extends Controller
{
    public function __construct(private readonly SalesPaymentService $sales)
    {
    }

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
            'data' => $this->sales->recordPayment($request->validated(), $request->user()),
        ], 201);
    }

    public function confirm(OrderPayment $payment, ConfirmPaymentRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Payment confirmed. Order fulfillment and inventory are unchanged.',
            'data' => $this->sales->confirm($payment, $request->validated(), $request->user()),
        ]);
    }

    public function refund(OrderPayment $payment, RefundPaymentRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Refund recorded. Original receipt retained; this does not transfer money or return stock.',
            'data' => $this->sales->refund($payment, $request->validated(), $request->user()),
        ]);
    }
}