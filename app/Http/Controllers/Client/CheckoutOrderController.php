<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\CheckoutSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutOrderController extends Controller
{
    public function store(Request $request, OrderService $orders): RedirectResponse
    {
        $key = Str::lower($request->validate(['request_key' => ['required', 'uuid']])['request_key']);
        $user = $request->user();
        $client = $user->clientProfile;
        $session = $request->session();

        $existing = Order::query()->where('client_id', $client->id)->where('request_key', $key)->first();
        if ($existing) {
            CheckoutSession::complete($session, $existing);

            return to_route('client.orders.confirmation', $existing);
        }

        if ($recovered = CheckoutSession::recover($session, $user)) {
            return to_route('client.orders.confirmation', $recovered);
        }

        $review = $session->get(CheckoutSession::REVIEW);
        $selected = CheckoutSession::selected($session);
        if (! $review || $review['key'] !== $key || $review['clientId'] !== $client->id || $review['items'] !== $selected) {
            return to_route('client.checkout')->withErrors([
                'checkout' => 'Your checkout changed. Review the selected flowers and confirm again.',
            ]);
        }

        $delivery = $review['method'] === 'Delivery';

        try {
            $address = $delivery
                ? $request->validate(['address' => ['required', 'string', 'max:500']])['address']
                : null;

            $items = [];
            foreach ($selected as $id => $quantity) {
                $items[] = ['flower_id' => $id, 'quantity' => $quantity];
            }

            $order = $orders->store([
                'request_key' => $key,
                'client_id' => $client->id,
                'method' => $review['method'],
                'address' => $address,
                'items' => $items,
            ], $user, reviewedTotal: $review['total'], reviewedDeliveryFee: $review['deliveryFee'] ?? null);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['review_total'])) {
                $session->forget(CheckoutSession::REVIEW);
            }

            return to_route('client.checkout', $delivery ? ['method' => 'delivery'] : [])
                ->withErrors($exception->errors())
                ->withInput($request->only('address'));
        }

        CheckoutSession::complete($session, $order);

        return to_route('client.orders.confirmation', $order);
    }

    public function show(Request $request, Order $order): View
    {
        abort_unless($order->client_id === $request->user()->clientProfile->id, 404);

        CheckoutSession::complete($request->session(), $order);

        return view('client.order-confirmation', ['order' => $order->load('items.flower:id,image_path')]);
    }
}
