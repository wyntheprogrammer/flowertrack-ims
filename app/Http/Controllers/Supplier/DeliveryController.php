<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryActionRequest;
use App\Models\Delivery;
use App\Services\DeliveryFeeService;
use App\Services\DeliveryService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveries
    ) {}

    /** Display the approved Delivery Management layout. */
    public function index(Request $request, DeliveryFeeService $fees): View
    {
        $fee = $request->user()->isAdmin() ? $fees->currentFee() : null;

        return view('supplier.deliveries', [
            'deliveryFee' => $fee === null ? null : Money::format($fee),
        ]);
    }

    public function updateFee(Request $request, DeliveryFeeService $fees): RedirectResponse
    {
        $amount = $request->validateWithBag('deliveryFeeSettings', ['delivery_fee' => ['required', 'string']])['delivery_fee'];
        try {
            $fee = Money::parse($amount);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['delivery_fee' => $exception->getMessage()])
                ->errorBag('deliveryFeeSettings');
        }

        $fees->update($fee, $request->user());

        return to_route('supplier.deliveries')->with('deliveryFeeStatus', 'Delivery fee saved.');
    }

    /** Return real deliveries and active assignment options. */
    public function data(Request $request): JsonResponse
    {
        return response()->json(
            $this->deliveries->data($request->user())
        );
    }

    /** Read the current version and history before an action. */
    public function show(Delivery $delivery, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->deliveries->row($delivery, $request->user()),
        ]);
    }

    /** Apply validated inputs; all stock and workflow writes belong to the service. */
    public function action(
        DeliveryActionRequest $request,
        Delivery $delivery
    ): JsonResponse {
        $updated = $this->deliveries->action(
            $delivery,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'message' => 'Delivery updated successfully.',
            'data' => $this->deliveries->row($updated, $request->user()),
        ]);
    }

    /** Stream private proof only through the protected operational routes. */
    public function proof(Delivery $delivery, Request $request): StreamedResponse
    {
        // Apply the same operational-account check as the details endpoint.
        $this->deliveries->row($delivery, $request->user());
        $path = $delivery->proof_path;

        abort_unless(
            is_string($path)
            && str_starts_with($path, 'delivery-proofs/')
            && Storage::disk('local')->exists($path),
            404
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
