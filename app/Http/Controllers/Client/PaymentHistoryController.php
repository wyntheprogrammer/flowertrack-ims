<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\OrderPayment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PaymentHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $payments = OrderPayment::query()
            ->select(['id', 'order_id', 'amount_cents', 'method', 'status', 'created_at'])
            ->whereIn('order_id', $request->user()->clientProfile->orders()->select('id'))
            ->with(['order:id,code', 'refunds:id,payment_id,amount_cents,created_at'])
            ->latest()->orderByDesc('id')->paginate(10);

        return view('client.payments', compact('payments'));
    }
}
