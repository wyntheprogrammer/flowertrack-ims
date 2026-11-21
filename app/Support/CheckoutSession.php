<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

class CheckoutSession
{
    public const CART = 'shop.cart';

    public const SELECTION = 'shop.cart_selection';

    public const REVIEW = 'shop.checkout_review';

    /** @return array<int, int> */
    public static function selected(Session $session): array
    {
        $cart = $session->get(self::CART, []);
        $selected = array_intersect_key($cart, array_flip($session->get(self::SELECTION, array_keys($cart))));
        ksort($selected, SORT_NUMERIC);

        return $selected;
    }

    /** @param array<int, int> $items */
    public static function issue(Session $session, int $clientId, string $method, array $items, int $total): string
    {
        $review = compact('clientId', 'method', 'items', 'total');
        $previous = $session->get(self::REVIEW);

        if ($previous && array_diff_key($previous, ['key' => true]) === $review) {
            return $previous['key'];
        }

        $key = (string) Str::uuid();
        $session->put(self::REVIEW, [...$review, 'key' => $key]);

        return $key;
    }

    public static function recover(Session $session, ?User $user): ?Order
    {
        $review = $session->get(self::REVIEW);
        if (! $review || ! $user?->isClient()) {
            return null;
        }

        $order = Order::query()->where('request_key', $review['key'])
            ->where('client_id', $review['clientId'])
            ->whereHas('client', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if ($order) {
            self::complete($session, $order);
        }

        return $order;
    }

    public static function complete(Session $session, Order $order): void
    {
        $review = $session->get(self::REVIEW);
        if (! $review || $review['key'] !== $order->request_key || $review['clientId'] !== $order->client_id) {
            return;
        }

        $cart = $session->get(self::CART, []);
        $selection = $session->get(self::SELECTION, array_keys($cart));
        foreach ($review['items'] as $id => $quantity) {
            $remaining = ($cart[$id] ?? 0) - $quantity;
            if ($remaining > 0) {
                $cart[$id] = $remaining;
            } else {
                unset($cart[$id]);
            }
        }

        $session->put(self::CART, $cart);
        $session->put(self::SELECTION, array_values(array_intersect($selection, array_keys($cart))));
        $session->forget(self::REVIEW);
    }
}
