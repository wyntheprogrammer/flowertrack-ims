<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OrderTrackingController extends Controller
{
    public function __invoke(Request $request, ?int $order = null): View
    {
        $owned = $request->user()->clientProfile->orders();
        if ($order === null) {
            return view('client.order-tracking', ['orders' => $owned->latest()->orderByDesc('id')->paginate(10)]);
        }

        $order = $owned->with('histories')->findOrFail($order);
        $delivery = Delivery::with('histories')->whereBelongsTo($order)->first();
        $status = $order->status;
        if ($delivery && ! in_array($status, ['Completed', 'Cancelled'], true)) {
            $status = match ($delivery->status) {
                'Failed' => 'Delivery attempt failed',
                'Cancelled' => 'Delivery cancelled',
                'Scheduled', 'Assigned', 'Rescheduled' => $delivery->scheduled_at ? 'Delivery scheduled' : $status,
                default => $status,
            };
        }

        return view('client.order-tracking', [
            'order' => $order, 'status' => $status,
            'schedule' => $delivery?->scheduled_at, 'events' => $this->timeline($order, $delivery),
        ]);
    }

    private function timeline(Order $order, ?Delivery $delivery): Collection
    {
        $events = collect();
        $mirrored = [];
        foreach ($delivery?->histories->sortBy('id') ?? [] as $entry) {
            $label = match ($entry->action) {
                'schedule' => $entry->status === 'Rescheduled' ? 'Delivery rescheduled' : 'Delivery scheduled',
                'dispatch' => 'Out for Delivery',
                'fail' => 'Delivery attempt failed',
                'deliver' => 'Completed',
                'cancel' => 'Delivery cancelled',
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
