<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flower;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public const MAX_REQUEST_QUANTITY = 4294967295;

    private const SELECTION_KEY = 'shop.cart_selection';

    public function save(Request $request, int $flower): RedirectResponse|JsonResponse
    {
        $adding = $request->isMethod('post');
        $cart = $request->session()->get('shop.cart', []);
        $item = Flower::storefront()->find($flower);

        if (! $item) {
            abort_if($adding, 404);

            return $this->cartUpdateError($request, $flower, 'This flower is no longer available. Remove it to continue.');
        }

        try {
            $input = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_REQUEST_QUANTITY]]);
        } catch (ValidationException $exception) {
            if ($adding) {
                throw $exception;
            }

            return $this->cartUpdateError($request, $flower, $exception->errors()['quantity'][0]);
        }

        $requestedQuantity = (int) $input['quantity'];
        $alreadyInCart = $adding ? ($cart[$flower] ?? 0) : 0;
        $cartQuantity = $alreadyInCart + $requestedQuantity;
        $limit = $item->cartQuantityLimit();
        $unit = strtolower($item->product_type);

        if ($cartQuantity > $limit) {
            if (! $adding) {
                return $this->cartUpdateError($request, $flower, $this->lineIssue($item, $cartQuantity));
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

        $session = $request->session();
        if (! array_key_exists($flower, $cart) && $session->has(self::SELECTION_KEY)) {
            $session->push(self::SELECTION_KEY, $flower);
        }

        $cart[$flower] = $cartQuantity;
        $session->put('shop.cart', $cart);

        if (! $adding && $request->expectsJson()) {
            return response()->json([
                'quantity' => $cartQuantity,
                'price' => (int) $item->price_cents,
                'amount' => $item->price_cents * $cartQuantity,
                'maxQuantity' => min($limit, self::MAX_REQUEST_QUANTITY),
                'selected' => in_array($flower, $session->get(self::SELECTION_KEY, array_keys($cart)), true),
            ]);
        }

        $status = $adding
            ? number_format($requestedQuantity).' '.Str::plural($unit, $requestedQuantity).' of '.$item->name.' added to your cart.'
            : $item->name.' updated to '.number_format($cartQuantity).' '.Str::plural($unit, $cartQuantity).'.';

        return to_route('client.cart')->with(['cart_status' => $status, 'cart_addition' => $adding]);
    }

    public function select(Request $request): RedirectResponse|Response
    {
        $input = $request->validate([
            'selected' => ['sometimes', 'array', 'list'],
            'selected.*' => ['required', 'integer', 'min:1'],
        ]);
        $cart = $request->session()->get('shop.cart', []);
        $selected = array_values(array_intersect(array_keys($cart), $input['selected'] ?? []));
        $request->session()->put(self::SELECTION_KEY, $selected);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return to_route($request->boolean('review') ? 'client.checkout' : 'client.cart');
    }

    public function remove(Request $request, int $flower): RedirectResponse
    {
        $session = $request->session();
        $session->forget('shop.cart.'.$flower);
        if ($session->has(self::SELECTION_KEY)) {
            $session->put(self::SELECTION_KEY, array_values(array_diff($session->get(self::SELECTION_KEY), [$flower])));
        }

        return to_route('client.cart')->with('cart_status', 'Item removed.');
    }

    public function index(Request $request, OrderService $orders): View
    {
        $session = $request->session();
        $cart = $session->get('shop.cart', []);
        $selection = array_fill_keys($session->get(self::SELECTION_KEY, array_keys($cart)), true);
        $flowers = Flower::storefront()->whereIn('flowers.id', array_keys($cart))->get()->keyBy('id');
        $lines = [];
        $selectedLines = [];
        $eligibleCount = 0;
        $excludedCount = 0;
        $subtotal = 0;
        $overLimit = false;

        foreach ($cart as $id => $quantity) {
            $item = $flowers->get($id);
            $price = (int) $item?->price_cents;
            $issue = $this->lineIssue($item, $quantity);
            $amount = $issue === null ? $price * $quantity : null;
            $selected = $amount !== null && isset($selection[$id]);
            $maxQuantity = min($item?->cartQuantityLimit() ?? 0, self::MAX_REQUEST_QUANTITY);
            $priceAvailable = $price > 0 && $price <= Money::MAX_CENTS;
            $line = compact('id', 'quantity', 'item', 'amount', 'price', 'priceAvailable', 'maxQuantity', 'issue', 'selected');
            $lines[] = $line;

            if ($amount === null) {
                $excludedCount += (int) isset($selection[$id]);
            } else {
                $eligibleCount++;
            }

            if ($selected) {
                $selectedLines[] = $line;
                if ($amount > Money::MAX_CENTS - $subtotal) {
                    $overLimit = true;
                } else {
                    $subtotal += $amount;
                }
            }
        }

        if (! $request->routeIs('client.checkout')) {
            $selectedCount = count($selectedLines);

            return view('client.cart', compact('lines', 'subtotal', 'selectedCount', 'eligibleCount', 'overLimit'));
        }

        $lines = $selectedLines;
        $hasCart = $cart !== [];
        $valid = ! $overLimit;
        $fee = $orders->configuredDeliveryFee();
        $deliveryAvailable = $valid && $fee !== null && $subtotal <= Money::MAX_CENTS - $fee;
        $delivery = $deliveryAvailable && $request->query('method') === 'delivery';

        return view('client.checkout', compact('lines', 'subtotal', 'valid', 'fee', 'deliveryAvailable', 'delivery', 'hasCart', 'excludedCount'));
    }

    private function lineIssue(?Flower $item, int $quantity): ?string
    {
        if (! $item) {
            return 'This flower is no longer available. Remove it to continue.';
        }

        $stock = $item->availableQuantity();
        $price = (int) $item->price_cents;

        return match (true) {
            $stock === 0 => 'This flower is sold out. Remove it to continue.',
            $price < 1 || $price > Money::MAX_CENTS => 'The price for this flower is unavailable. Remove it to continue.',
            $quantity > $stock => 'Only '.number_format($stock).' '.Str::plural(strtolower($item->product_type), $stock)
                .' '.($stock === 1 ? 'is' : 'are').' available. Change your quantity to '.number_format($stock).' or fewer.',
            $quantity > $item->cartQuantityLimit() => 'This quantity exceeds the limit at the current price. Reduce it or remove the item.',
            default => null,
        };
    }

    private function cartUpdateError(Request $request, int $flower, ?string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return to_route('client.cart')->withErrors(['items.'.$flower => $message], 'cart');
    }
}
