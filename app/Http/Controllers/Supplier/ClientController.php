<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Requests\UpdateClientStatusRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClientController extends Controller
{
    /**
     * Display the approved supplier Client Management screen.
     */
    public function index(): View
    {
        return view('supplier.clients');
    }

    /**
     * List real client accounts with persisted order history available.
     */
    public function data(ClientService $clients): JsonResponse
    {
        $rows = DB::transaction(fn () => Client::query()->with('user')
            ->whereHas('user', fn ($query) => $query->where('role', User::ROLE_CLIENT))
            ->orderBy('id')->get()->map(fn (Client $client) => $clients->row($client))->values());

        return response()->json(['data' => $rows, 'order_history_available' => true]);
    }

    /**
     * Expose real order history and gross completed-order purchase totals.
     */
    public function show(Client $client, ClientService $clients): JsonResponse
    {
        $result = DB::transaction(function () use ($client, $clients): array {
            $profile = Client::query()->with('user')->findOrFail($client->id);
            $row = $clients->row($profile);
            $orders = Order::query()->with('payment')->where('client_id', $profile->id)->orderByDesc('id')->get();
            $completedPurchases = 0;
            foreach ($orders->where('status', 'Completed') as $completed) {
                abort_if($completedPurchases > PHP_INT_MAX - (int) $completed->total_cents, 422, 'The purchase summary exceeds the supported integer range.');
                $completedPurchases += (int) $completed->total_cents;
            }

            return [
                'data' => $row,
                'orders' => $orders->map(fn (Order $order) => [
                    'dbId' => (int) $order->id, 'id' => $order->code,
                    'date' => $order->created_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d'),
                    'status' => $order->status, 'method' => $order->method,
                    'totalCents' => (int) $order->total_cents,
                    'payment' => $order->payment?->status === 'Refunded' ? 'Refunded'
                        : ($order->payment?->status === 'Confirmed' ? 'Paid' : 'Unpaid'),
                ])->values()->all(),
                'summary' => [
                    'total_orders' => $orders->count(),
                    'completed_orders' => $orders->where('status', 'Completed')->count(),
                    'ongoing_orders' => $orders->whereNotIn('status', ['Completed', 'Cancelled'])->count(),
                    'completed_purchases_cents' => $completedPurchases,
                ],
                'order_history_available' => true,
            ];
        });

        return response()->json($result);
    }

    /**
     * Create account credentials for the later client portal without changing supplier login.
     */
    public function store(StoreClientRequest $request, ClientService $clients): JsonResponse
    {
        return response()->json([
            'message' => 'Client account created successfully.',
            'data' => $clients->row($clients->store($request->validated())),
        ], 201);
    }

    /**
     * Update the public profile while leaving its password and role unchanged.
     */
    public function update(UpdateClientRequest $request, Client $client, ClientService $clients): JsonResponse
    {
        return response()->json([
            'message' => 'Client profile updated.',
            'data' => $clients->row($clients->update($client, $request->validated())),
        ]);
    }

    /**
     * Activate or deactivate the explicit client account without deleting history.
     */
    public function status(UpdateClientStatusRequest $request, Client $client, ClientService $clients): JsonResponse
    {
        return response()->json([
            'message' => 'Client account status saved.',
            'data' => $clients->row($clients->updateStatus($client, $request->validated())),
        ]);
    }
}
