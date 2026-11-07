<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\InventoryBatch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\SalesRecord;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderService
{
    /** Create a pending order without reserving or deducting stock. */
    public function store(array $input, User $actor): Order
    {
        $payload = $this->normalize($input);
        $key = strtolower($input['request_key']);
        $hash = hash('sha256', json_encode(['actor' => (int) $actor->id, ...$payload], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($payload, $key, $hash, $actor): Order {
                $existing = Order::query()->where('request_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }
                $client = $this->lockClient($payload['client_id'], false, false);
                // A retry may have waited for the original transaction's account lock.
                $existing = Order::query()->where('request_key', $key)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }
                $this->assertClient($client, true);
                $flowers = $this->lockFlowers(array_keys($payload['items']));
                $lines = $this->lines($flowers, $payload['items'], collect());
                $this->assertAvailability($flowers, $payload['items']);
                [$subtotal, $fee, $total] = $this->totals($lines, $payload['method']);
                $order = Order::create([
                    'code' => 'TMP-'.Str::ulid(), 'request_key' => $key, 'request_hash' => $hash,
                    'client_id' => $client->id, 'client_code' => $client->code,
                    'client_name' => $client->user->name, 'client_email' => $client->user->email,
                    'method' => $payload['method'], 'address' => $payload['address'], 'notes' => $payload['notes'],
                    'status' => 'Pending', 'subtotal_cents' => $subtotal, 'fee_cents' => $fee,
                    'total_cents' => $total, 'stocked_out' => false,
                ]);
                $order->code = 'ORD-'.str_pad((string) $order->id, 3, '0', STR_PAD_LEFT);
                $order->save();
                $this->replaceItems($order, $lines);
                $this->history($order, $actor, 'Order created.');

                return $order;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true)) {
                $existing = Order::query()->where('request_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }
            }
            throw $exception;
        }
    }

    /** Edit pending/confirmed orders and atomically rebalance confirmed reservations. */
    public function update(Order $order, array $input, User $actor): Order
    {
        $payload = $this->normalize($input);

        return DB::transaction(function () use ($order, $input, $payload, $actor): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->load(['items', 'payment']);
            $this->assertVersion($locked, $input['version']);
            if (! in_array($locked->status, ['Pending', 'Confirmed'], true) || $locked->payment !== null) {
                $this->invalid('order', 'Only pending or confirmed orders without any payment record can be edited.');
            }
            $changedClient = (int) $locked->client_id !== $payload['client_id'];
            $client = $this->lockClient($payload['client_id'], $changedClient);
            $old = $locked->items->keyBy('flower_id');
            $flowers = $this->lockFlowers(array_unique([...$old->keys()->all(), ...array_keys($payload['items'])]));
            $lines = $this->lines($flowers, $payload['items'], $old);
            $this->assertAvailability($flowers, $payload['items'], $locked->code);
            [$subtotal, $fee, $total] = $this->totals($lines, $payload['method'], $locked);
            if ($changedClient) {
                $locked->client_id = $client->id;
                $locked->client_code = $client->code;
                $locked->client_name = $client->user->name;
                $locked->client_email = $client->user->email;
            }
            $locked->fill([
                'method' => $payload['method'], 'address' => $payload['address'], 'notes' => $payload['notes'],
                'subtotal_cents' => $subtotal, 'fee_cents' => $fee, 'total_cents' => $total,
            ])->save();
            $this->replaceItems($locked, $lines);
            if ($locked->status === 'Confirmed') {
                $this->syncHolds($locked, $payload['items']);
            }
            $this->history($locked, $actor, 'Order details updated.');
            $locked->unsetRelations();

            return $locked;
        });
    }

    /** Coordinate explicit transitions, reservations, and once-only pickup completion. */
    public function action(Order $order, array $input, User $actor): Order
    {
        return DB::transaction(function () use ($order, $input, $actor): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->load(['items', 'payment']);
            $desired = $input['status'];
            if ($desired === 'Confirmed' && $actor->role !== User::ROLE_ADMIN) {
                abort(403, 'Only an administrator can confirm an order.');
            }
            if ($locked->status === $desired) {
                return $locked;
            }
            $this->assertVersion($locked, $input['version']);
            if (in_array($locked->status, ['Completed', 'Cancelled'], true)) {
                $this->invalid('status', 'Completed and cancelled orders cannot change status.');
            }
            $next = $this->nextStatus($locked);
            if ($desired !== 'Cancelled' && $desired !== $next) {
                $this->invalid('status', 'This transition is not available from the current order state.');
            }
            $flowers = $this->lockFlowers($locked->items->pluck('flower_id')->all());
            $quantities = $locked->items->mapWithKeys(fn (OrderItem $item) => [(int) $item->flower_id => (int) $item->quantity])->all();
            $note = 'Order marked '.$desired.'.';
            if ($desired === 'Cancelled') {
                $reason = trim($input['reason'] ?? '');
                if ($reason === '') {
                    $this->invalid('reason', 'Explain why this order is cancelled.');
                }
                InventoryReservation::query()->where('reference', $locked->code)->where('status', 'Active')->update(['status' => 'Released']);
                $note = $reason;
            } elseif ($desired === 'Confirmed') {
                $this->assertAvailability($flowers, $quantities, $locked->code);
                $this->syncHolds($locked, $quantities);
            } elseif ($desired === 'Completed') {
                if ($locked->method !== 'Customer Pickup' || ! filter_var($input['collected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $this->invalid('collected', 'Confirm actual pickup collection before completing this order.');
                }
                if ($locked->stocked_out) {
                    $this->invalid('order', 'This order has already issued stock. Refresh its current state.');
                }
                foreach ($locked->items->sortBy('flower_id') as $item) {
                    $reservation = InventoryReservation::query()->where('flower_id', $item->flower_id)
                        ->where('reference', $locked->code)->lockForUpdate()->first();
                    if ($reservation === null || $reservation->status !== 'Active' || (int) $reservation->quantity !== (int) $item->quantity) {
                        $this->invalid('order', 'The order reservation is missing or inconsistent.');
                    }
                    app(InventoryService::class)->fulfillOrderReservation($reservation, $item->fulfillment_key, $actor, $locked->code);
                }
                $locked->stocked_out = true;
                $locked->completed_at = now();
                $sale = SalesRecord::create([
                    'order_id' => $locked->id, 'client_id' => $locked->client_id, 'code' => 'TMP-'.Str::ulid(),
                    'client_name' => $locked->client_name, 'client_code' => $locked->client_code,
                    'items' => $locked->items->map(fn (OrderItem $item) => $this->itemRow($item))->values()->all(),
                    'subtotal_cents' => $locked->subtotal_cents, 'fee_cents' => $locked->fee_cents,
                    'total_cents' => $locked->total_cents, 'completed_at' => $locked->completed_at,
                ]);
                $sale->code = 'SALE-'.str_pad((string) $sale->id, 3, '0', STR_PAD_LEFT);
                $sale->save();
                $note = 'Pickup collected; reserved stock issued and sale recorded.';
            }
            $locked->status = $desired;
            $locked->save();
            $this->history($locked, $actor, $note);
            $locked->unsetRelations();

            return $locked;
        });
    }

    /** Expose a credential-free order DTO with immutable item snapshots and exact cents. */
    public function row(Order $order, User $actor): array
    {
        $order->loadMissing(['items', 'histories', 'payment']);
        $payment = $order->payment;
        return [
            'dbId' => (int) $order->id, 'id' => $order->code,
            'clientDbId' => (int) $order->client_id, 'clientId' => $order->client_code,
            'clientName' => $order->client_name,
            'date' => $order->created_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d'),
            'method' => $order->method, 'address' => $order->address ?? '', 'notes' => $order->notes ?? '',
            'status' => $order->status,
            'payment' => $payment?->status === 'Refunded' ? 'Refunded' : ($payment?->status === 'Confirmed' ? 'Paid' : 'Unpaid'),
            'paidCents' => $payment?->status === 'Confirmed' ? (int) $payment->amount_cents : 0,
            'subtotalCents' => (int) $order->subtotal_cents, 'deliveryFeeCents' => (int) $order->fee_cents,
            'totalCents' => (int) $order->total_cents, 'stockedOut' => (bool) $order->stocked_out,
            'version' => $this->version($order),
            'items' => $order->items->sortBy('flower_id')->map(fn (OrderItem $item) => $this->itemRow($item))->values()->all(),
            'history' => $order->histories->sortBy('id')->map(fn (OrderHistory $entry) => [
                'status' => $entry->status, 'date' => $entry->created_at->copy()->setTimezone('Asia/Manila')->toIso8601String(),
                'actor' => $entry->actor_name, 'note' => $entry->note,
            ])->values()->all(),
            'canConfirm' => $actor->role === User::ROLE_ADMIN && $order->status === 'Pending',
            'editable' => in_array($order->status, ['Pending', 'Confirmed'], true) && $payment === null,
            'nextStatus' => $order->status === 'Pending' && $actor->role !== User::ROLE_ADMIN ? null : $this->nextStatus($order),
        ];
    }

    /** Read the explicitly configured fee; null means new Delivery orders are unavailable. */
    public function configuredDeliveryFee(): ?int
    {
        $value = config('orders.delivery_fee');
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException('Fee must be an exact decimal string.');
            }
            return Money::parse((string) $value);
        } catch (InvalidArgumentException $exception) {
            $this->invalid('method', 'The delivery fee configuration is invalid. Ask an administrator to configure an approved decimal amount.');
        }
    }

    /** Normalize duplicate lines before hashing, pricing, and availability checks. */
    private function normalize(array $input): array
    {
        $items = [];
        foreach ($input['items'] as $line) {
            $id = (int) $line['flower_id'];
            $quantity = (int) $line['quantity'];
            if ($quantity < 1 || $quantity > 100000) {
                $this->invalid('items', 'Each requested quantity must be from 1 to 100000.');
            }
            $items[$id] = ($items[$id] ?? 0) + $quantity;
            if ($items[$id] > 100000) {
                $this->invalid('items', 'The combined quantity for each catalog item must not exceed 100000.');
            }
        }
        if (count($input['items']) < 1 || count($input['items']) > 50) {
            $this->invalid('items', 'Provide from one to fifty item rows.');
        }
        ksort($items, SORT_NUMERIC);
        $method = $input['method'];
        if (! in_array($method, ['Customer Pickup', 'Delivery'], true)) {
            $this->invalid('method', 'Select Customer Pickup or Delivery.');
        }
        $address = $method === 'Delivery' ? trim($input['address'] ?? '') : null;
        if ($method === 'Delivery' && $address === '') {
            $this->invalid('address', 'A delivery address is required.');
        }
        $notes = trim($input['notes'] ?? '');
        return ['client_id' => (int) $input['client_id'], 'method' => $method, 'address' => $address,
            'notes' => $notes === '' ? null : $notes, 'items' => $items];
    }

    /** Lock client account first, then its profile, matching Client Management. */
    private function lockClient(int $id, bool $requireActive, bool $validate = true): Client
    {
        $profile = Client::query()->findOrFail($id);
        $user = User::query()->lockForUpdate()->findOrFail($profile->user_id);
        $client = Client::query()->lockForUpdate()->findOrFail($id);
        if ((int) $client->user_id !== (int) $user->id) {
            $this->invalid('client_id', 'The client account association changed. Refresh before retrying.');
        }
        $client->setRelation('user', $user);
        if ($validate) {
            $this->assertClient($client, $requireActive);
        }
        return $client;
    }

    /** Validate a locked account only after checking for a completed creation replay. */
    private function assertClient(Client $client, bool $requireActive): void
    {
        if ($client->user->role !== User::ROLE_CLIENT) {
            $this->invalid('client_id', 'Select a valid client account.');
        }
        if ($requireActive && ! $client->user->is_active) {
            $this->invalid('client_id', 'Only active clients can be selected for a new order.');
        }
    }

    /** Lock the full old/new item union in ascending order before touching stock or holds. */
    private function lockFlowers(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        $rows = Flower::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($rows->count() !== count($ids)) {
            $this->invalid('items', 'A requested catalog item no longer exists.');
        }
        return $rows;
    }

    /** Preserve existing line snapshots; new selections use active Sales-owned current prices. */
    private function lines(Collection $flowers, array $quantities, Collection $old): array
    {
        $prices = FlowerPrice::query()->whereIn('flower_id', array_keys($quantities))->lockForUpdate()->get()->keyBy('flower_id');
        $lines = [];
        foreach ($quantities as $id => $quantity) {
            $flower = $flowers->get($id);
            $previous = $old->get($id);
            if ($flower->status !== 'Active' && ($previous === null || $quantity > $previous->quantity)) {
                $this->invalid('items', 'Inactive items may only retain or reduce an existing line quantity.');
            }
            $price = $previous !== null ? (int) $previous->unit_price_cents : (int) ($prices->get($id)?->price_cents ?? 0);
            if ($price < 1 || $price > Money::MAX_CENTS || $quantity > intdiv(Money::MAX_CENTS, $price)) {
                $this->invalid('items', 'A valid selling price is required and item totals must fit the supported monetary range.');
            }
            $lines[] = [
                'flower_id' => $id, 'flower_code' => $previous?->flower_code ?? $flower->code,
                'name' => $previous?->name ?? $flower->name,
                'product_type' => $previous?->product_type ?? $flower->product_type,
                'base_unit' => $previous?->base_unit ?? $flower->product_type,
                'quantity' => $quantity, 'unit_price_cents' => $price, 'subtotal_cents' => $price * $quantity,
                'fulfillment_key' => $previous?->fulfillment_key ?? (string) Str::uuid(),
            ];
        }
        return $lines;
    }

    /** Compute bounded integer monetary totals while preserving existing Delivery fee snapshots. */
    private function totals(array $lines, string $method, ?Order $old = null): array
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            if ($subtotal > Money::MAX_CENTS - $line['subtotal_cents']) {
                $this->invalid('items', 'The order exceeds the supported monetary range.');
            }
            $subtotal += $line['subtotal_cents'];
        }
        $fee = $method === 'Customer Pickup' ? 0 : ($old?->method === 'Delivery' ? (int) $old->fee_cents : $this->configuredDeliveryFee());
        if ($fee === null) {
            $this->invalid('method', 'Delivery orders are unavailable until the delivery fee is configured.');
        }
        if ($subtotal > Money::MAX_CENTS - $fee) {
            $this->invalid('items', 'The order total exceeds the supported monetary range.');
        }
        return [$subtotal, $fee, $subtotal + $fee];
    }

    /** Use current locking reads even when earlier account reads created a repeatable-read snapshot. */
    private function assertAvailability(Collection $flowers, array $quantities, ?string $ownReference = null): void
    {
        $ids = $flowers->keys()->all();
        $batches = InventoryBatch::query()->whereIn('flower_id', $ids)->where('spoiled', false)->lockForUpdate()->get();
        $holds = InventoryReservation::query()->whereIn('flower_id', $ids)->where('status', 'Active')->lockForUpdate()->get();
        foreach ($quantities as $id => $quantity) {
            $physical = (int) $batches->where('flower_id', $id)->sum('remaining_quantity');
            $reserved = (int) $holds->where('flower_id', $id)->filter(fn ($hold) => $hold->reference !== $ownReference)->sum('quantity');
            if ($quantity > $physical - $reserved) {
                $this->invalid('items', 'Insufficient stock is available for one or more requested items.');
            }
        }
    }

    /** Replace editable lines only; immutable snapshots and fulfillment UUIDs are retained per item. */
    private function replaceItems(Order $order, array $lines): void
    {
        $order->items()->delete();
        foreach ($lines as $line) {
            $order->items()->create($line);
        }
        $order->unsetRelation('items');
    }

    /** Rebalance one active reservation per order/item, releasing removed lines. */
    private function syncHolds(Order $order, array $quantities): void
    {
        $existing = InventoryReservation::query()->where('reference', $order->code)->lockForUpdate()->get();
        foreach ($existing as $hold) {
            if (! isset($quantities[$hold->flower_id])) {
                $hold->status = 'Released';
                $hold->save();
            }
        }
        foreach ($quantities as $id => $quantity) {
            InventoryReservation::updateOrCreate(['flower_id' => $id, 'reference' => $order->code], [
                'quantity' => $quantity, 'status' => 'Active',
            ]);
        }
    }

    /** Determine only transitions owned by this module; Delivery completion is intentionally absent. */
    private function nextStatus(Order $order): ?string
    {
        return match ($order->status) {
            'Pending' => 'Confirmed', 'Confirmed' => 'Processing',
            'Processing' => $order->method === 'Customer Pickup' ? 'Ready for Pickup' : 'Ready for Delivery',
            'Ready for Pickup' => $order->method === 'Customer Pickup' ? 'Completed' : null,
            default => null,
        };
    }

    /** Fingerprint public business state plus the current payment record, excluding credentials. */
    private function version(Order $order): string
    {
        $order->loadMissing(['items', 'payment']);
        return hash('sha256', json_encode([
            'order' => $order->only(['id', 'code', 'client_id', 'client_code', 'client_name', 'client_email', 'method', 'address', 'notes',
                'status', 'subtotal_cents', 'fee_cents', 'total_cents', 'stocked_out', 'completed_at']),
            'items' => $order->items->sortBy('flower_id')->map(fn ($item) => $item->only([
                'flower_id', 'flower_code', 'name', 'product_type', 'base_unit', 'quantity', 'unit_price_cents', 'subtotal_cents', 'fulfillment_key',
            ]))->values()->all(),
            'payment' => $order->payment?->only(['id', 'code', 'amount_cents', 'status', 'method', 'reference', 'remarks', 'confirmed_by', 'confirmed_at']),
        ], JSON_THROW_ON_ERROR));
    }

    /** Reject an edit/action issued against stale business state. */
    private function assertVersion(Order $order, string $version): void
    {
        if (! hash_equals($this->version($order), strtolower($version))) {
            $this->invalid('version', 'This order changed. Refresh and review its latest state before saving.');
        }
    }

    /** Return the existing request's order only when actor and canonical payload match. */
    private function replay(Order $order, string $hash): Order
    {
        if (! hash_equals($order->request_hash, $hash)) {
            $this->invalid('request_key', 'This creation request key was already used for different order details.');
        }
        return $order;
    }

    /** Expose the immutable commercial item snapshot in exact integer cents. */
    private function itemRow(OrderItem $item): array
    {
        return ['flowerDbId' => (int) $item->flower_id, 'flowerId' => $item->flower_code, 'flowerName' => $item->name,
            'itemType' => $item->product_type, 'baseUnit' => $item->base_unit, 'quantity' => (int) $item->quantity,
            'unitPriceCents' => (int) $item->unit_price_cents, 'subtotalCents' => (int) $item->subtotal_cents];
    }

    /** Append workflow history with an actor-name snapshot. */
    private function history(Order $order, User $actor, string $note): void
    {
        OrderHistory::create(['order_id' => $order->id, 'status' => $order->status,
            'note' => Str::limit($note, 1000, ''), 'actor_id' => $actor->id, 'actor_name' => Str::limit($actor->name, 255, '')]);
    }

    /** Surface domain failures using standard JSON validation errors. */
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
