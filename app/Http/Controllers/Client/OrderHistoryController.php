<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\CustomerHistoryFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OrderHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = new CustomerHistoryFilters($request, CustomerHistoryFilters::ORDER_STATUSES);
        $orders = $request->user()->clientProfile->orders()
            ->with('items.flower:id,image_path')
            ->when($filters->search !== '', $filters->matchOrder(...))
            ->when($filters->status !== '', fn (Builder $query) => $query->where('status', $filters->status))
            ->latest()->orderByDesc('id')->paginate(10)->appends($filters->parameters());

        return view('client.orders', compact('orders', 'filters'));
    }

    public function show(Request $request, int $order): View
    {
        $receipt = $request->user()->clientProfile->orders()->with('items.flower:id,image_path')->findOrFail($order);

        return view('client.order-confirmation', ['order' => $receipt, 'history' => true]);
    }
}
