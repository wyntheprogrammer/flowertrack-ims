<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderActionRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Client;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\InventoryBatch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderController extends Controller
{
    /** Display the approved Order Management screen. */
    public function index(): View
    {
        return view('supplier.orders');
    }

    /** Read one consistent snapshot of real orders, client choices, prices, and physical balances. */
    public function data(Request $request, OrderService $service): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $service): array {
            $orders = Order::query()->with(['items', 'histories', 'payment'])->orderByDesc('id')->get();
            $clients = Client::query()->with('user')->whereHas('user', fn ($query) => $query->where('role', User::ROLE_CLIENT))->orderBy('id')->get();
            $flowers = Flower::query()->orderBy('id')->get();
            $prices = FlowerPrice::query()->get()->keyBy('flower_id');
            $batches = InventoryBatch::query()->where('spoiled', false)->get()->groupBy('flower_id');
            $holds = InventoryReservation::query()->where('status', 'Active')->get()->groupBy('flower_id');

            return [
                'data' => $orders->map(fn (Order $order) => $service->row($order, $request->user()))->values()->all(),
                'clients' => $clients->map(fn (Client $client) => [
                    'dbId' => (int) $client->id, 'id' => $client->code, 'name' => $client->user->name,
                    'status' => $client->user->is_active ? 'Active' : 'Inactive', 'address' => $client->address,
                ])->values()->all(),
                'flowers' => $flowers->map(function (Flower $flower) use ($prices, $batches, $holds): array {
                    $physical = (int) ($batches->get($flower->id)?->sum('remaining_quantity') ?? 0);
                    $reserved = (int) ($holds->get($flower->id)?->sum('quantity') ?? 0);

                    return [
                        'dbId' => (int) $flower->id, 'id' => $flower->code, 'name' => $flower->name,
                        'itemType' => $flower->product_type, 'baseUnit' => $flower->product_type, 'status' => $flower->status,
                        'priceCents' => $prices->has($flower->id) ? (int) $prices->get($flower->id)->price_cents : null,
                        'onHand' => $physical, 'reserved' => $reserved, 'available' => $physical - $reserved,
                    ];
                })->values()->all(),
                'deliveryFeeCents' => $service->configuredDeliveryFee(),
            ];
        });

        return response()->json($result);
    }

    /** Return current persisted order details and snapshots. */
    public function show(Request $request, Order $order, OrderService $service): JsonResponse
    {
        return response()->json(['data' => $this->currentRow($order, $request->user(), $service)]);
    }

    /** Create a pending order with a server-priced item snapshot. */
    public function store(StoreOrderRequest $request, OrderService $service): JsonResponse
    {
        $order = $service->store($request->validated(), $request->user());

        return response()->json(['message' => 'Order created successfully.',
            'data' => $this->currentRow($order, $request->user(), $service)], 201);
    }

    /** Apply an optimistic edit without accepting client-provided financial values. */
    public function update(UpdateOrderRequest $request, Order $order, OrderService $service): JsonResponse
    {
        $updated = $service->update($order, $request->validated(), $request->user());

        return response()->json(['message' => 'Order updated successfully.',
            'data' => $this->currentRow($updated, $request->user(), $service)]);
    }

    /** Advance one allowed state or cancel with a documented reason. */
    public function action(OrderActionRequest $request, Order $order, OrderService $service): JsonResponse
    {
        $updated = $service->action($order, $request->validated(), $request->user());

        return response()->json(['message' => 'Order status saved.',
            'data' => $this->currentRow($updated, $request->user(), $service)]);
    }

    /** Hydrate response relations inside a fresh read transaction after writes have committed. */
    private function currentRow(Order $order, User $actor, OrderService $service): array
    {
        return DB::transaction(fn () => $service->row(Order::query()->with(['items', 'histories', 'payment'])->findOrFail($order->id), $actor));
    }
}
