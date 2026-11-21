@extends('layouts.client')

@section('title', ($history ?? false) ? 'Order details' : 'Order confirmation')
@section('body-class', ($history ?? false) ? 'shop-checkout shop-orders' : 'shop-checkout')

@push('page-assets')
    @vite('resources/css/client/checkout.css')
@endpush

@section('content')
    <section class="shop-width checkout-page" aria-labelledby="order-title">
        <header class="checkout-heading" data-shop-back-top-threshold>
            <div>
                <h1 id="order-title" tabindex="-1">Order {{ $order->code }}</h1>
                <p class="checkout-confirmation-status">{{ $order->status === 'Pending' ? 'Pending confirmation' : $order->status }}</p>
            </div>
            <div>
                <a class="checkout-link" href="{{ route('client.orders') }}">{{ ($history ?? false) ? 'Back to My Orders' : 'My Orders' }}</a>
                @if ($history ?? false)
                    <a class="checkout-link ms-3" href="{{ route('client.tracking', $order) }}">Track order</a>
                @else
                    <a class="checkout-link ms-3" href="{{ route('client.cart') }}">Back to cart</a>
                @endif
            </div>
        </header>

        <div class="checkout-layout">
            <section aria-labelledby="ordered-flowers">
                <h2 id="ordered-flowers">Ordered flowers</h2>
                @foreach ($order->items as $item)
                    <article class="checkout-item checkout-confirmation-item" aria-labelledby="order-item-{{ $item->id }}">
                        <div class="checkout-item-info">
                            <h3 id="order-item-{{ $item->id }}">{{ $item->name }}</h3>
                            <p>₱{{ number_format($item->unit_price_cents / 100, 2) }} per {{ strtolower($item->base_unit) }}</p>
                            <p>Quantity {{ number_format($item->quantity) }}</p>
                        </div>
                        <div class="checkout-line-total">
                            <span class="visually-hidden">Line total</span>
                            <strong>₱{{ number_format($item->subtotal_cents / 100, 2) }}</strong>
                        </div>
                    </article>
                @endforeach
            </section>

            <aside class="checkout-summary" aria-labelledby="order-summary">
                <h2 id="order-summary">Order summary</h2>
                <p>{{ $order->method }}</p>
                @if ($order->method === 'Delivery')
                    <p class="checkout-confirmation-address">{{ $order->address }}</p>
                @endif
                <div class="checkout-totals">
                    <dl>
                        <div><dt>Item subtotal</dt><dd>₱{{ number_format($order->subtotal_cents / 100, 2) }}</dd></div>
                        @if ($order->method === 'Delivery')
                            <div><dt>Delivery fee</dt><dd>₱{{ number_format($order->fee_cents / 100, 2) }}</dd></div>
                        @endif
                        <div class="checkout-final-total"><dt>Order total</dt><dd>₱{{ number_format($order->total_cents / 100, 2) }}</dd></div>
                    </dl>
                </div>
            </aside>
        </div>
    </section>
@endsection
