<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $orders = $request->user()->clientProfile->orders()
            ->latest()->orderByDesc('id')->paginate(10);

        return view('client.orders', compact('orders'));
    }

    public function show(Request $request, int $order): View
    {
        $receipt = $request->user()->clientProfile->orders()->with('items')->findOrFail($order);

        return view('client.order-confirmation', ['order' => $receipt, 'history' => true]);
    }
}
