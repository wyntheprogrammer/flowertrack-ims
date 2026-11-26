<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\OrderPayment;
use App\Support\CustomerHistoryFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PaymentHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = new CustomerHistoryFilters($request, ['Pending', 'Confirmed', 'Refunded']);
        $pattern = $filters->searchPattern();
        $payments = OrderPayment::query()
            ->select(['id', 'order_id', 'code', 'reference', 'amount_cents', 'method', 'status', 'created_at'])
            ->whereIn('order_id', $request->user()->clientProfile->orders()->select('id'))
            ->with(['order:id,code', 'order.items.flower:id,image_path', 'refunds:id,payment_id,amount_cents,created_at'])
            ->when($filters->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($filters, $pattern): void {
                $query->whereRaw("LOWER(order_payments.code) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(order_payments.reference) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereHas('order', $filters->matchOrder(...));
            }))
            ->when($filters->status !== '', fn (Builder $query) => $query->where('status', $filters->status))
            ->latest()->orderByDesc('id')->paginate(10)->appends($filters->parameters());

        return view('client.payments', compact('payments', 'filters'));
    }
}
