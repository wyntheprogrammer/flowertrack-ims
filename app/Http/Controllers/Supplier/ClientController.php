<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Requests\UpdateClientStatusRequest;
use App\Models\Client;
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
     * List real client accounts without fabricating purchase summaries.
     */
    public function data(ClientService $clients): JsonResponse
    {
        $rows = DB::transaction(fn () => Client::query()->with('user')
            ->whereHas('user', fn ($query) => $query->where('role', User::ROLE_CLIENT))
            ->orderBy('id')->get()->map(fn (Client $client) => $clients->row($client))->values());

        return response()->json(['data' => $rows, 'order_history_available' => false]);
    }

    /**
     * Expose profile details while order persistence remains unavailable.
     */
    public function show(Client $client, ClientService $clients): JsonResponse
    {
        $row = DB::transaction(fn () => $clients->row(Client::query()->with('user')->findOrFail($client->id)));

        return response()->json([
            'data' => $row, 'orders' => [], 'summary' => null, 'order_history_available' => false,
        ]);
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
