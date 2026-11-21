<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flower;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public const MAX_REQUEST_QUANTITY = 4294967295;

    public function save(Request $request, int $flower): RedirectResponse
    {
        $item = Flower::storefront()->findOrFail($flower);
        $cart = $request->session()->get('shop.cart', []);
        $input = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_REQUEST_QUANTITY]]);
        $requestedQuantity = (int) $input['quantity'];
        $adding = $request->isMethod('post');
        $alreadyInCart = $adding ? ($cart[$flower] ?? 0) : 0;
        $cartQuantity = $alreadyInCart + $requestedQuantity;
        $limit = $item->cartQuantityLimit();
        $unit = strtolower($item->product_type);

        if ($cartQuantity > $limit) {
            if (! $adding) {
                return back()->withErrors(['quantity' => 'This quantity is unavailable at the current price and stock level.']);
            }

            $remaining = max(0, $limit - $alreadyInCart);
            $message = match (true) {
                $item->price_cents < 1 => 'The price for this flower is unavailable.',
                $item->availableQuantity() === 0 => 'This flower is out of stock.',
                $remaining > 0 => 'You can add '.number_format($remaining).' more '.Str::plural($unit, $remaining).'.',
                $alreadyInCart >= $item->availableQuantity() => 'Your cart already contains the available quantity.',
                default => 'You cannot add more at the current price.',
            };

            return to_route('client.product', $flower)->withErrors(['quantity' => $message])->withInput($input);
        }

        $cart[$flower] = $cartQuantity;
        $request->session()->put('shop.cart', $cart);

        $status = $adding
            ? number_format($requestedQuantity).' '.Str::plural($unit, $requestedQuantity).' of '.$item->name.' added to your cart.'
            : 'Cart updated.';

        return to_route('client.cart')->with(['cart_status' => $status, 'cart_addition' => $adding]);
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
