<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flower;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function save(Request $request, int $flower): RedirectResponse
    {
        $item = Flower::storefront()->findOrFail($flower);
        $cart = $request->session()->get('shop.cart', []);
        $input = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:4294967295']]);
        $quantity = (int) $input['quantity'];
        if ($request->isMethod('post')) {
            $quantity += $cart[$flower] ?? 0;
        }
        if ($quantity > $item->availableQuantity() || $item->price_cents < 1 ||
            $quantity > intdiv(Money::MAX_CENTS, (int) $item->price_cents)) {
            return back()->withErrors(['quantity' => 'This quantity is unavailable at the current price and stock level.']);
        }
        $cart[$flower] = $quantity;
        $request->session()->put('shop.cart', $cart);

        return to_route('client.cart')->with('cart_status', 'Cart updated.');
    }

    public function remove(Request $request, int $flower): RedirectResponse
    {
        $request->session()->forget('shop.cart.'.$flower);

        return to_route('client.cart')->with('cart_status', 'Item removed.');
    }

    public function index(Request $request, OrderService $orders): View
    {
        $cart = $request->session()->get('shop.cart', []);
        $flowers = Flower::storefront()->whereIn('flowers.id', array_keys($cart))->get()->keyBy('id');
        $lines = [];
        $subtotal = 0;
        $valid = true;
        foreach ($cart as $id => $quantity) {
            $item = $flowers->get($id);
            $price = (int) $item?->price_cents;
            $available = $item && $quantity <= $item->availableQuantity() && $price > 0
                && $quantity <= intdiv(Money::MAX_CENTS - $subtotal, $price);
            $amount = $available ? $price * $quantity : null;
            $valid = $valid && $available;
            $subtotal += $amount ?? 0;
            $lines[] = compact('id', 'quantity', 'item', 'amount');
        }
        $checkout = $request->routeIs('client.checkout');
        $fee = $orders->configuredDeliveryFee();
        $deliveryAvailable = $fee !== null && $subtotal <= Money::MAX_CENTS - $fee;
        $delivery = $deliveryAvailable && $request->query('method') === 'delivery';

        return view('client.cart', compact('lines', 'subtotal', 'valid', 'checkout', 'fee', 'deliveryAvailable', 'delivery'));
    }
}
