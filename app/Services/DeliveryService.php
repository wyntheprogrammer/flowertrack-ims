<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryHistory;
use App\Models\Flower;
use App\Models\InventoryReservation;
use App\Models\SalesRecord;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class DeliveryService
{
    /** Load real delivery records and active assignment options. */
    public function data(User $actor): array
    {
        $this->assertActor($actor, true);
        $query = Delivery::with(['order', 'personnel', 'histories']);
        if ($actor->role === User::ROLE_DELIVERY) {
            $query->where('assigned_to', $actor->id);
        }

        return [
            'data' => $query
                ->orderByDesc('id')
                ->get()
                ->map(fn (Delivery $delivery) => $this->row($delivery, $actor))
                ->all(),
            'personnel' => $actor->role === User::ROLE_DELIVERY ? [] : User::query()
                ->where('role', User::ROLE_DELIVERY)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                ])
                ->all(),
        ];
    }

    /** Return browser fields without exposing the stored proof path. */
    public function row(Delivery $delivery, User $actor): array
    {
        $this->assertActor($actor, true);
        if ($actor->role === User::ROLE_DELIVERY) {
            abort_unless((int) $delivery->assigned_to === (int) $actor->id, 403);
        }
        $delivery->loadMissing(['order', 'personnel', 'histories']);

        return [
            'dbId' => $delivery->id,
            'id' => $delivery->code,
            'orderId' => $delivery->order->code,
            'client' => $delivery->order->client_name,
            'address' => $delivery->order->address,
            'method' => $delivery->order->method,
            'orderStatus' => $delivery->order->status,
            'status' => $delivery->status,
            'personId' => $delivery->assigned_to === null
                ? null : (int) $delivery->assigned_to,
            'personName' => $delivery->personnel?->name,
            'schedule' => $delivery->scheduled_at?->toIso8601String(),
            'recipient' => $delivery->recipient_name,
            'remarks' => $delivery->remarks,
            'hasProof' => filled($delivery->proof_path),
            'completedAt' => $delivery->delivered_at?->toIso8601String(),
            'version' => hash('sha256', json_encode([
                $delivery->getAttributes(),
                $delivery->order->getAttributes(),
                $delivery->histories->max('id'),
            ], JSON_THROW_ON_ERROR)),
            'history' => $delivery->histories
                ->sortBy('id')
                ->values()
                ->map(fn ($history) => [
                    'date' => $history->created_at->toIso8601String(),
                    'actor' => $history->actor_name,
                    'status' => $history->status,
                    'note' => $history->note,
                ])
                ->all(),
        ];
    }

    /** Limit service access to active operational accounts. */
    private function assertActor(User $actor, bool $allowDelivery = false): void
    {
        abort_unless(
            $actor->is_active
            && !$actor->must_change_password
            && in_array($actor->role, [
                User::ROLE_ADMIN,
                User::ROLE_SUPPLIER,
                ...($allowDelivery ? [User::ROLE_DELIVERY] : []),
            ], true),
            403
        );
    }

    /** Create one delivery record for a ready delivery order. */
    public function createForOrder(Order $order, User $actor): Delivery
    {
        $this->assertActor($actor);

        return DB::transaction(function () use ($order, $actor) {
            $locked = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (
                $locked->method !== 'Delivery'
                || $locked->status !== 'Ready for Delivery'
            ) {
                throw ValidationException::withMessages([
                    'order' => 'The order must be Ready for Delivery.',
                ]);
            }

            $existing = Delivery::query()
                ->where('order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $delivery = Delivery::create([
                'code' => 'TMP-' . Str::ulid(),
                'order_id' => $locked->id,
                'status' => 'Pending',
            ]);

            $delivery->code = 'DEL-' . str_pad(
                (string) $delivery->id,
                3,
                '0',
                STR_PAD_LEFT
            );
            $delivery->save();

            DeliveryHistory::create([
                'delivery_id' => $delivery->id,
                'request_key' => (string) Str::uuid(),
                'request_hash' => hash('sha256', 'create:' . $locked->id),
                'action' => 'create',
                'status' => 'Pending',
                'note' => 'Delivery created from ' . $locked->code . '.',
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
            ]);

            return $delivery;
        });
    }


    /** Apply delivery actions and actual completion with atomic stock fulfillment. */
    public function action(
        Delivery $delivery,
        array $input,
        User $actor
    ): Delivery {
        $this->assertActor($actor, true);

        $action = $input['action'];
        if ($actor->role === User::ROLE_DELIVERY) {
            abort_unless(in_array($action, ['deliver', 'fail'], true), 403);
        }

        if (!in_array($action, ['schedule', 'assign', 'dispatch', 'fail', 'deliver', 'cancel'], true)) {
            throw ValidationException::withMessages([
                'action' => 'This delivery action is not connected yet.',
            ]);
        }

        Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'reason' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['required_if:action,schedule', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'assigned_to' => ['required_if:action,assign', 'nullable', 'integer', 'min:1'],
            'recipient_name' => ['required_if:action,deliver', 'nullable', 'string', 'max:120'],
            'proof' => ['required_if:action,deliver', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ])->validate();

        $reason = trim($input['reason'] ?? '');

        if ($reason === '' && $action !== 'dispatch') {
            throw ValidationException::withMessages([
                'reason' => 'Enter the required remarks.',
            ]);
        }

        $proofHash = null;
        if ($action === 'deliver') {
            if (trim($input['recipient_name']) === '') {
                throw ValidationException::withMessages([
                    'recipient_name' => 'Enter the actual recipient name.',
                ]);
            }
            // Inspect actual bytes as well as the upload's reported MIME type.
            $proofPath = $input['proof']->getRealPath();
            $image = $proofPath ? @getimagesize($proofPath) : false;
            if (
                $image === false
                || !in_array($image['mime'] ?? '', [
                    'image/jpeg', 'image/png', 'image/webp',
                ], true)
                || ($image[0] ?? 0) < 1
                || ($image[1] ?? 0) < 1
            ) {
                throw ValidationException::withMessages([
                    'proof' => 'Select a valid JPEG, PNG or WebP image.',
                ]);
            }

            $proofHash = hash_file('sha256', $proofPath);
            if ($proofHash === false) {
                throw ValidationException::withMessages(['proof' => 'Unable to read proof image.']);
            }
        }

        $payload = [
            'delivery_id' => $delivery->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'reason' => $reason,
            'scheduled_at' => $input['scheduled_at'] ?? null,
            'recipient_name' => $action === 'deliver' ? trim($input['recipient_name']) : null,
            'proof_hash' => $proofHash,
            'assigned_to' => isset($input['assigned_to'])
                ? (int) $input['assigned_to']
                : null,
        ];

        $hash = hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        // Keep one generated private file across transaction deadlock retries.
        $storedProof = null;

        try {
            $result = DB::transaction(function () use (
                $delivery, $input, $actor, $action, $reason, $hash, &$storedProof
            ) {
                // Lock the order first, consistently with Order Management.
                $order = Order::query()
                    ->lockForUpdate()
                    ->findOrFail($delivery->order_id);

                $locked = Delivery::query()
                    ->lockForUpdate()
                    ->findOrFail($delivery->id);

                if ($actor->role === User::ROLE_DELIVERY) {
                    abort_unless((int) $locked->assigned_to === (int) $actor->id, 403);
                }

                $previous = DeliveryHistory::query()
                    ->where('request_key', $input['request_key'])
                    ->lockForUpdate()
                    ->first();

                if ($previous) {
                    if (
                        (int) $previous->delivery_id !== (int) $locked->id
                        || !hash_equals($previous->request_hash, $hash)
                    ) {
                        throw ValidationException::withMessages([
                            'request_key' => 'This request key was already used.',
                        ]);
                    }

                    return $locked;
                }

                $current = $this->row($locked, $actor);

                if (!hash_equals($current['version'], $input['version'])) {
                    throw ValidationException::withMessages([
                        'version' => 'Delivery changed. Refresh and try again.',
                    ]);
                }

                $allowedStates = match ($action) {
                    'schedule', 'assign' => [
                        'Pending', 'Scheduled', 'Assigned', 'Rescheduled', 'Failed',
                    ],
                    'dispatch' => ['Assigned', 'Scheduled', 'Rescheduled'],
                    'cancel' => ['Pending', 'Scheduled', 'Assigned', 'Rescheduled', 'Failed'],
                    'fail', 'deliver' => ['Out for Delivery'],
                };

                $validOrderStatus = $order->status === 'Ready for Delivery';

                if (
                    $locked->status === 'Failed'
                    && in_array($action, ['schedule', 'assign', 'cancel'], true)
                ) {
                    $validOrderStatus = in_array(
                        $order->status,
                        ['Ready for Delivery', 'Out for Delivery'],
                        true
                    );
                }

                if (in_array($action, ['fail', 'deliver'], true)) {
                    $validOrderStatus = $order->status === 'Out for Delivery';
                }

                if (
                    $order->method !== 'Delivery'
                    || !in_array($locked->status, $allowedStates, true)
                    || !$validOrderStatus
                    || $order->stocked_out
                ) {
                    throw ValidationException::withMessages([
                        'action' => 'This action is unavailable for the current delivery.',
                    ]);
                }

                $before = [
                    'scheduled_at' => $locked->scheduled_at?->toIso8601String(),
                    'assigned_to' => $locked->assigned_to,
                ];

                if ($action === 'schedule') {
                    $schedule = CarbonImmutable::parse($input['scheduled_at'])
                        ->utc();

                    if (
                        !$schedule->isFuture()
                        || $locked->scheduled_at?->equalTo($schedule)
                    ) {
                        throw ValidationException::withMessages([
                            'scheduled_at' => 'Choose a different future schedule.',
                        ]);
                    }

                    $rescheduled = $locked->scheduled_at !== null
                        || $locked->status === 'Failed';

                    $locked->scheduled_at = $schedule;
                    $locked->status = $rescheduled
                        ? 'Rescheduled'
                        : ($locked->assigned_to ? 'Assigned' : 'Scheduled');

                    if ($order->status === 'Out for Delivery') {
                        $order->status = 'Ready for Delivery';
                        $order->save();

                        $order->histories()->create([
                            'status' => $order->status,
                            'note' => 'Failed delivery rescheduled. ' . $reason,
                            'actor_id' => $actor->id,
                            'actor_name' => $actor->name,
                        ]);
                    }
                } elseif ($action === 'assign') {
                    $person = User::query()
                        ->lockForUpdate()
                        ->find($input['assigned_to']);

                    if (
                        !$person
                        || !$person->is_active
                        || $person->role !== User::ROLE_DELIVERY
                        || (int) $locked->assigned_to === (int) $person->id
                    ) {
                        throw ValidationException::withMessages([
                            'assigned_to' => 'Choose different, active delivery personnel.',
                        ]);
                    }

                    $locked->assigned_to = $person->id;

                    if ($locked->status !== 'Failed') {
                        $locked->status = 'Assigned';
                    }
                }


                if ($action === 'dispatch') {
                    $person = User::query()
                        ->lockForUpdate()
                        ->find($locked->assigned_to);

                    if (
                        !in_array(
                            $locked->status,
                            ['Assigned', 'Scheduled', 'Rescheduled'],
                            true
                        )
                        || !$locked->scheduled_at
                        || !$person
                        || !$person->is_active
                        || $person->role !== User::ROLE_DELIVERY
                        || $order->status !== 'Ready for Delivery'
                        || $order->stocked_out
                    ) {
                        throw ValidationException::withMessages([
                            'action' => 'Dispatch requires a scheduled delivery, active personnel, and a ready order.',
                        ]);
                    }

                    $locked->status = 'Out for Delivery';
                    $locked->dispatched_at = now();

                    $order->status = 'Out for Delivery';
                    $order->save();

                    $reason = $reason !== ''
                        ? $reason
                        : 'Order handed to assigned delivery personnel.';

                    $order->histories()->create([
                        'status' => $order->status,
                        'note' => $reason,
                        'actor_id' => $actor->id,
                        'actor_name' => $actor->name,
                    ]);
                }


                if ($action === 'fail') {
                    $locked->status = 'Failed';

                    $order->histories()->create([
                        'status' => $order->status,
                        'note' => 'Delivery attempt failed. '.$reason,
                        'actor_id' => $actor->id,
                        'actor_name' => $actor->name,
                    ]);
                }

                if ($action === 'cancel') {
                    $locked->status = 'Cancelled';

                    $order->histories()->create([
                        'status' => $order->status,
                        'note' => 'Delivery cancelled; linked order and reservation retained. '.$reason,
                        'actor_id' => $actor->id,
                        'actor_name' => $actor->name,
                    ]);
                }

                if ($action === 'deliver') {
                    $this->completeDelivery($locked, $order, $input, $actor, $storedProof);
                }

                $locked->remarks = $reason;
                $locked->save();

                DeliveryHistory::create([
                    'delivery_id' => $locked->id,
                    'request_key' => $input['request_key'],
                    'request_hash' => $hash,
                    'action' => $action,
                    'status' => $locked->status,
                    'note' => $reason,
                    'actor_id' => $actor->id,
                    'actor_name' => $actor->name,
                    'metadata' => [
                        'before' => $before,
                        'after' => [
                            'scheduled_at' => $locked->scheduled_at?->toIso8601String(),
                            'assigned_to' => $locked->assigned_to,
                        ],
                    ],
                ]);

                return $locked->fresh();
            }, 3);
        } catch (\Throwable $exception) {
            $this->cleanupUnusedProof($storedProof);
            throw $exception;
        }

        // A matching replay can return the existing record without using this file.
        if ($storedProof !== null && $result->proof_path !== $storedProof) {
            $this->cleanupUnusedProof($storedProof);
        }

        return $result;
    }

    /** Complete the delivery, stock issue and sale in the caller's transaction. */
    private function completeDelivery(
        Delivery $delivery,
        Order $order,
        array $input,
        User $actor,
        ?string &$storedProof
    ): void {
        if (
            $delivery->status !== 'Out for Delivery'
            || $order->status !== 'Out for Delivery'
            || $order->method !== 'Delivery'
            || $order->stocked_out
        ) {
            throw ValidationException::withMessages([
                'action' => 'Only an outstanding dispatched delivery can be completed.',
            ]);
        }

        $items = $order->items()->orderBy('flower_id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['order' => 'This order has no items.']);
        }

        // Acquire every Flower lock before any reservation lock, matching OrderService.
        $flowers = Flower::query()
            ->whereIn('id', $items->pluck('flower_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($flowers->count() !== $items->pluck('flower_id')->unique()->count()) {
            throw ValidationException::withMessages(['order' => 'An order item is missing.']);
        }

        if ($storedProof === null) {
            $path = $input['proof']->store('delivery-proofs', 'local');
            if (!is_string($path) || $path === '') {
                throw ValidationException::withMessages(['proof' => 'Proof upload failed.']);
            }
            $storedProof = $path;
        }

        foreach ($items as $item) {
            $reservation = InventoryReservation::query()
                ->where('flower_id', $item->flower_id)
                ->where('reference', $order->code)
                ->lockForUpdate()
                ->first();

            if (
                !$reservation
                || $reservation->status !== 'Active'
                || (int) $reservation->quantity !== (int) $item->quantity
            ) {
                throw ValidationException::withMessages([
                    'order' => 'The active stock reservation is missing or inconsistent.',
                ]);
            }

            app(InventoryService::class)->fulfillOrderReservation(
                $reservation,
                $item->fulfillment_key,
                $actor,
                $order->code
            );
        }

        $completedAt = now();
        $order->status = 'Completed';
        $order->stocked_out = true;
        $order->completed_at = $completedAt;
        $order->save();

        $sale = SalesRecord::create([
            'code' => 'TMP-'.Str::ulid(),
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'client_code' => $order->client_code,
            'items' => $items->map(fn ($item) => [
                'flowerDbId' => (int) $item->flower_id,
                'flowerId' => $item->flower_code,
                'flowerName' => $item->name,
                'itemType' => $item->product_type,
                'baseUnit' => $item->base_unit,
                'quantity' => (int) $item->quantity,
                'unitPriceCents' => (int) $item->unit_price_cents,
                'subtotalCents' => (int) $item->subtotal_cents,
            ])->values()->all(),
            'subtotal_cents' => $order->subtotal_cents,
            'fee_cents' => $order->fee_cents,
            'total_cents' => $order->total_cents,
            'completed_at' => $completedAt,
        ]);
        $sale->code = 'SALE-'.str_pad((string) $sale->id, 3, '0', STR_PAD_LEFT);
        $sale->save();

        $delivery->status = 'Delivered';
        $delivery->recipient_name = trim($input['recipient_name']);
        $delivery->proof_path = $storedProof;
        $delivery->delivered_at = $completedAt;

        $order->histories()->create([
            'status' => 'Completed',
            'note' => 'Delivery received by '.$delivery->recipient_name
                .'; reserved stock issued and sale recorded.',
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
        ]);
    }

    /** Remove rolled-back uploads only after confirming no committed delivery uses them. */
    private function cleanupUnusedProof(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            if (!Delivery::query()->where('proof_path', $path)->exists()) {
                if (!Storage::disk('local')->delete($path)) {
                    Log::warning('Delivery proof cleanup did not complete.', ['path' => $path]);
                }
            }
        } catch (\Throwable $exception) {
            // Retain the file if commit state cannot be verified; preserve the original error.
            Log::warning('Delivery proof retained for reconciliation.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}


