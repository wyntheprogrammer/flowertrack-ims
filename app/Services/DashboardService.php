<?php

namespace App\Services;

use App\Models\Flower;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\SalesRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardService
{
    /** Read operational summaries without accessing Admin-only audit data. */
    public function data(User $actor, string $unit = 'Stem'): array
    {
        abort_unless(
            in_array($actor->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true)
                && $actor->is_active && ! $actor->must_change_password,
            403
        );

        if (! in_array($unit, ['Stem', 'Bouquet'], true)) {
            throw ValidationException::withMessages(['unit' => 'Choose Stem or Bouquet.']);
        }

        return DB::transaction(function () use ($unit) {
            $physical = InventoryBatch::where('spoiled', false)
                ->select('flower_id')->selectRaw('SUM(remaining_quantity) AS quantity')
                ->groupBy('flower_id')->pluck('quantity', 'flower_id');

            $holds = InventoryReservation::where('status', 'Active')
                ->select('flower_id')->selectRaw('SUM(quantity) AS quantity')
                ->groupBy('flower_id')->pluck('quantity', 'flower_id');

            $inventory = Flower::orderBy('code')->get()->map(function ($flower) use ($physical, $holds) {
                $onHand = $this->number($physical[$flower->id] ?? 0);
                $reserved = $this->number($holds[$flower->id] ?? 0);

                return [
                    'code' => $flower->code,
                    'name' => $flower->name,
                    'unit' => $flower->product_type,
                    'active' => $flower->status === 'Active',
                    'onHand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $onHand - $reserved,
                    'minimum' => $this->number($flower->minimum_stock),
                ];
            });

            $totals = ['Stem' => 0, 'Bouquet' => 0];

            foreach ($inventory as $item) {
                if (! array_key_exists($item['unit'], $totals)) {
                    throw ValidationException::withMessages(['dashboard' => 'Unknown product stock unit.']);
                }

                $totals[$item['unit']] = $this->number($totals[$item['unit']] + $item['onHand']);
            }

            // Include active items with no receipts; preserve negative availability.
            $low = $inventory->filter(fn ($item) => $item['active']
                && $item['available'] < $item['minimum'])->values();

            $pending = Order::where('status', 'Pending')->count();

            // Aggregate refunds per order before joining to avoid multiplying sales.
            $refunds = DB::table('payment_refunds AS refunds')
                ->join('order_payments AS payments', 'payments.id', '=', 'refunds.payment_id')
                ->select('payments.order_id')
                ->selectRaw('SUM(refunds.amount_cents) AS refunded')
                ->groupBy('payments.order_id');

            $sales = DB::table('sales_records AS sales')
                ->leftJoinSub($refunds, 'refunds', 'refunds.order_id', '=', 'sales.order_id')
                ->selectRaw('COALESCE(SUM(sales.total_cents), 0) AS gross')
                ->selectRaw('COALESCE(SUM(refunds.refunded), 0) AS refunded')
                ->selectRaw('COALESCE(MAX(CASE WHEN COALESCE(refunds.refunded, 0) > sales.total_cents THEN 1 ELSE 0 END), 0) AS invalid_refund')
                ->first();

            $gross = $this->number($sales->gross);
            $refunded = $this->number($sales->refunded);

            if ((int) $sales->invalid_refund !== 0 || $refunded > $gross) {
                throw ValidationException::withMessages(['dashboard' => 'Refund exceeds a recorded sale. Review finance records.']);
            }

            // Completed-order snapshots, not mutable catalog prices or payment totals.
            // Refunds cannot be allocated to individual products without line-level refund data.
            $top = DB::table('order_items AS items')
                ->join('sales_records AS sales', 'sales.order_id', '=', 'items.order_id')
                ->select('items.flower_id', 'items.product_type')
                ->selectRaw('MAX(items.flower_code) AS code, MAX(items.name) AS name')
                ->selectRaw('SUM(items.quantity) AS quantity, SUM(items.subtotal_cents) AS gross')
                ->groupBy('items.flower_id', 'items.product_type')
                ->orderByDesc('gross')->orderBy('items.flower_id')->orderBy('items.product_type')
                ->limit(5)->get()->map(fn ($row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->product_type,
                    'quantity' => $this->number($row->quantity),
                    'grossCents' => $this->number($row->gross),
                ])->all();

            $chart = $inventory->where('unit', $unit)
                ->sort(function ($a, $b) {
                    return ($b['onHand'] <=> $a['onHand']) ?: strcmp($a['code'], $b['code']);
                })->take(6)->values()->all();

            return [
                'generatedAt' => now('Asia/Manila')->toIso8601String(),
                'timezone' => 'Asia/Manila',
                'summary' => [
                    'stems' => $totals['Stem'],
                    'bouquets' => $totals['Bouquet'],
                    'grossSalesCents' => $gross,
                    'refundCents' => $refunded,
                    'netSalesCents' => $gross - $refunded,
                    'lowStockItems' => $low->count(),
                    'pendingOrders' => $pending,
                ],
                'chart' => ['unit' => $unit, 'items' => $chart],
                'topProducts' => $top,
                'recent' => $this->recent(),
            ];
        });
    }

    /** Shared business activity only: no authentication/security/audit records. */
    private function recent(): array
    {
        $events = [];

        foreach (OrderHistory::with('order:id,code')->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get() as $history) {
            $events[] = [
                'key' => 'order-'.$history->id,
                'date' => $this->timestamp($history->created_at),
                'icon' => 'bi-cart-check',
                'label' => ($history->order?->code ?? 'Order').' · '.$history->status,
                'detail' => 'Order update',
            ];
        }

        foreach (InventoryMovement::with('batch:id,code')->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get() as $movement) {
            $events[] = [
                'key' => 'stock-'.$movement->id,
                'date' => $this->timestamp($movement->created_at),
                'icon' => 'bi-box-seam',
                'label' => ($movement->batch?->code ?? 'Batch').' · '.$movement->type,
                'detail' => 'Inventory movement',
            ];
        }

        foreach (SalesRecord::orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(['id', 'code', 'created_at']) as $sale) {
            $events[] = [
                'key' => 'sale-'.$sale->id,
                'date' => $this->timestamp($sale->created_at),
                'icon' => 'bi-cash-stack',
                'label' => $sale->code.' · Sale recorded',
                'detail' => 'Fulfilled order',
            ];
        }

        usort($events, fn ($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['key'], $a['key']));

        return array_slice($events, 0, 5);
    }

    private function timestamp($value): string
    {
        return CarbonImmutable::parse($value)->setTimezone('Asia/Manila')->toIso8601String();
    }

    /** Reject unsupported totals instead of emitting rounded or fabricated figures. */
    private function number(mixed $value): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);

        if ($number === false || $number < 0 || $number > 9007199254740991) {
            throw ValidationException::withMessages(['dashboard' => 'A dashboard total exceeds the supported numeric range.']);
        }

        return $number;
    }
}