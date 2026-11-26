<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Support\CustomerHistoryFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OrderTrackingController extends Controller
{
    private const string DELIVERY_SCHEDULED = 'Delivery scheduled';

    private const string DELIVERY_FAILED = 'Delivery attempt failed';

    private const string DELIVERY_CANCELLED = 'Delivery cancelled';

    private const string TRACKING_STATUS_SQL = 'CASE
        WHEN orders.status IN (\'Completed\', \'Cancelled\') THEN orders.status
        WHEN deliveries.status = \'Failed\' THEN ?
        WHEN deliveries.status = \'Cancelled\' THEN ?
        WHEN deliveries.status IN (\'Scheduled\', \'Assigned\', \'Rescheduled\')
            AND deliveries.scheduled_at IS NOT NULL THEN ?
        ELSE orders.status
    END';

    private const array TRACKING_STATUS_BINDINGS = [self::DELIVERY_FAILED, self::DELIVERY_CANCELLED, self::DELIVERY_SCHEDULED];

    public function __invoke(Request $request, ?int $order = null): View
    {
        $owned = $request->user()->clientProfile->orders()
            ->select('orders.*', 'deliveries.scheduled_at as tracking_schedule')
            ->selectRaw(self::TRACKING_STATUS_SQL.' AS tracking_status', self::TRACKING_STATUS_BINDINGS)
            ->leftJoin('deliveries', 'deliveries.order_id', '=', 'orders.id')
            ->with('items.flower:id,image_path')
            ->withCasts(['tracking_schedule' => 'datetime']);

        if ($order === null) {
            $filters = new CustomerHistoryFilters($request, [
                ...CustomerHistoryFilters::ORDER_STATUSES,
                self::DELIVERY_SCHEDULED, self::DELIVERY_FAILED, self::DELIVERY_CANCELLED,
            ]);
            $orders = $owned
                ->when($filters->search !== '', $filters->matchOrder(...))
                ->when($filters->status !== '', fn (Builder $query) => $query->whereRaw(
                    '('.self::TRACKING_STATUS_SQL.') = ?',
                    [...self::TRACKING_STATUS_BINDINGS, $filters->status],
                ))
                ->latest('orders.created_at')->orderByDesc('orders.id')->paginate(10)->appends($filters->parameters());

            return view('client.order-tracking', compact('orders', 'filters'));
        }

        $order = $owned->with('histories')->findOrFail($order);
        $delivery = Delivery::with('histories')->whereBelongsTo($order)->first();

        return view('client.order-tracking', [
            'order' => $order, 'status' => $order->tracking_status,
            'schedule' => $order->tracking_schedule, 'events' => $this->timeline($order, $delivery),
        ]);
    }

    private function timeline(Order $order, ?Delivery $delivery): Collection
    {
        $events = collect();
        $mirrored = [];
        foreach ($delivery?->histories->sortBy('id') ?? [] as $entry) {
            $label = match ($entry->action) {
                'schedule' => $entry->status === 'Rescheduled' ? 'Delivery rescheduled' : self::DELIVERY_SCHEDULED,
                'dispatch' => 'Out for Delivery',
                'fail' => self::DELIVERY_FAILED,
                'deliver' => 'Delivered',
                'cancel' => self::DELIVERY_CANCELLED,
                default => null,
            };
            if ($label === null) {
                continue;
            }
            $mirrored[$label] = true;
            $slot = $entry->action === 'schedule' ? data_get($entry->metadata, 'after.scheduled_at') : null;
            $events->push(['label' => $label, 'at' => $entry->created_at, 'slot' => $slot ? Carbon::parse($slot) : null]);
        }

        $milestones = collect();
        foreach ($order->histories->sortBy('id')->unique('status') as $entry) {
            if (! isset($mirrored[$entry->status])) {
                $milestones->push(['label' => $entry->status, 'at' => $entry->created_at, 'slot' => null]);
            }
        }

        return $milestones->concat($events)->sortBy('at')->values();
    }
}
