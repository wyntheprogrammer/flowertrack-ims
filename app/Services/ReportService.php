<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Delivery;
use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentRefund;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportService
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** Read one canonical business snapshot; reports never write operational records. */
    public function data(User $actor): array
    {
        abort_unless($actor->role === User::ROLE_ADMIN
            && $actor->is_active && !$actor->must_change_password, 403);

        return DB::transaction(function () {
            $orders = Order::orderBy('id')->get();
            $ordersById = $orders->keyBy('id');
            $payments = OrderPayment::orderBy('id')->get();
            $paymentsByOrder = $payments->keyBy('order_id');
            $refunds = [];
            foreach (PaymentRefund::orderBy('id')->get() as $refund) {
                $id = (int) $refund->payment_id;
                $refunds[$id] = $this->add($refunds[$id] ?? 0, $this->nonnegative($refund->amount_cents));
            }

            $clientOrders = [];
            foreach ($orders as $order) {
                $id = (int) $order->client_id;
                $clientOrders[$id] = $this->add($clientOrders[$id] ?? 0, 1);
            }
            $clientPurchases = [];
            $sales = [];
            foreach (SalesRecord::orderBy('id')->get() as $sale) {
                $payment = $paymentsByOrder->get($sale->order_id);
                $refunded = $payment ? ($refunds[$payment->id] ?? 0) : 0;
                $gross = $this->nonnegative($sale->total_cents);
                if ($refunded > $gross) $this->invalid('Refund exceeds the recorded sale amount. Review financial records.');
                $net = $gross - $refunded;
                $clientId = (int) $sale->client_id;
                $clientPurchases[$clientId] = $this->add($clientPurchases[$clientId] ?? 0, $net);
                $sales[] = [
                    'id' => $sale->code,
                    'order' => $ordersById->get($sale->order_id)?->code ?? 'Unavailable',
                    'client' => $sale->client_name,
                    'date' => $this->day($sale->completed_at),
                    'gross' => $gross, 'refund' => $refunded, 'net' => $net,
                ];
            }

            $paymentRows = [];
            foreach ($payments as $payment) {
                $amount = $this->nonnegative($payment->amount_cents);
                $refunded = $refunds[$payment->id] ?? 0;
                $verified = in_array($payment->status, ['Confirmed', 'Refunded'], true) ? $amount : 0;
                if ($refunded > $verified) $this->invalid('Refund exceeds verified collection. Review financial records.');
                $order = $ordersById->get($payment->order_id);
                $paymentRows[] = [
                    'id' => $payment->code, 'order' => $order?->code ?? 'Unavailable',
                    'client' => $order?->client_name ?? 'Unavailable',
                    'date' => $this->day($payment->created_at), 'method' => $payment->method,
                    'amount' => $amount, 'status' => $payment->status,
                    'refund' => $refunded, 'verified' => $verified, 'net' => $verified - $refunded,
                ];
            }

            $physical = [];
            foreach (InventoryBatch::where('spoiled', false)->orderBy('id')->get() as $batch) {
                $id = (int) $batch->flower_id;
                $physical[$id] = $this->add($physical[$id] ?? 0, $this->nonnegative($batch->remaining_quantity));
            }
            $holds = [];
            foreach (InventoryReservation::where('status', 'Active')->orderBy('id')->get() as $reservation) {
                $id = (int) $reservation->flower_id;
                $holds[$id] = $this->add($holds[$id] ?? 0, $this->nonnegative($reservation->quantity));
            }
            $inventory = [];
            foreach (Flower::orderBy('id')->get() as $flower) {
                $onHand = $physical[$flower->id] ?? 0;
                $reserved = $holds[$flower->id] ?? 0;
                $available = $onHand - $reserved;
                $minimum = $this->nonnegative($flower->minimum_stock);
                $inventory[] = [
                    'code' => $flower->code, 'item' => $flower->name, 'unit' => $flower->product_type,
                    'onHand' => $onHand, 'reserved' => $reserved, 'available' => $available,
                    'minimum' => $minimum,
                    'status' => $available <= 0 ? 'Out of Stock' : ($available < $minimum ? 'Low Stock' : 'Normal'),
                ];
            }

            $orderRows = $orders->map(fn (Order $order) => [
                'id' => $order->code, 'client' => $order->client_name,
                'date' => $this->day($order->created_at), 'method' => $order->method,
                'status' => $order->status, 'total' => $this->nonnegative($order->total_cents),
            ])->all();

            $deliveryRows = [];
            foreach (Delivery::with('personnel:id,name')->orderBy('id')->get() as $delivery) {
                $order = $ordersById->get($delivery->order_id);
                if (!$order || $order->method !== 'Delivery') continue;
                $deliveryRows[] = [
                    'id' => $delivery->code, 'order' => $order->code, 'client' => $order->client_name,
                    'date' => $delivery->scheduled_at ? $this->day($delivery->scheduled_at) : 'Not scheduled',
                    'person' => $delivery->personnel?->name ?? 'Unassigned', 'status' => $delivery->status,
                ];
            }

            $clients = [];
            foreach (Client::with('user:id,name,email,role,is_active')->orderBy('id')->get() as $client) {
                if (!$client->user || $client->user->role !== User::ROLE_CLIENT) continue;
                $clients[] = [
                    'id' => $client->code, 'name' => $client->user->name, 'email' => $client->user->email,
                    'registered' => $this->day($client->created_at),
                    'status' => $client->user->is_active ? 'Active' : 'Inactive',
                    'orders' => $clientOrders[$client->id] ?? 0,
                    'purchases' => $clientPurchases[$client->id] ?? 0,
                ];
            }

            $spoilage = [];
            foreach (InventoryMovement::with(['flower:id,name', 'batch:id,code,receiving_unit'])
                ->where('type', 'Stock-Out')->where('quantity', '<', 0)->orderBy('id')->get() as $movement) {
                if (($movement->metadata['reason'] ?? null) !== 'Spoilage') continue;
                $quantity = -(int) $movement->quantity;
                $this->nonnegative($quantity);
                $spoilage[] = [
                    'id' => 'MOV-'.$movement->id, 'date' => $this->day($movement->created_at),
                    'item' => $movement->flower?->name ?? 'Unavailable',
                    'batch' => $movement->batch?->code ?? 'Unavailable',
                    // Receiving-unit snapshots preserve the historical Stem/Bouquet distinction.
                    'unit' => $movement->batch?->receiving_unit === 'Bouquet' ? 'Bouquet' : 'Stem',
                    'quantity' => $quantity, 'reason' => $movement->remarks ?? '',
                ];
            }

            return [
                'data' => ['sales' => $sales, 'payments' => $paymentRows, 'inventory' => $inventory,
                    'orders' => $orderRows, 'delivery' => $deliveryRows, 'clients' => $clients, 'spoilage' => $spoilage],
                'generatedAt' => now()->setTimezone('Asia/Manila')->toIso8601String(),
                'timezone' => 'Asia/Manila',
            ];
        });
    }

    /** Preserve exact integer sums within the browser's safe integer range. */
    private function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > self::MAX_SAFE_INTEGER - $right) {
            $this->invalid('Report totals exceed the supported exact display range.');
        }
        return $left + $right;
    }

    private function nonnegative($value): int
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value
            || (int) $value < 0 || (int) $value > self::MAX_SAFE_INTEGER) {
            $this->invalid('A report contains an invalid or unsupported numeric value.');
        }
        return (int) $value;
    }

    private function day($date): string
    {
        return $date->copy()->setTimezone('Asia/Manila')->format('Y-m-d');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['report' => $message]);
    }
}