@extends('layouts.client')

@section('title', 'Checkout review')
@section('body-class', 'shop-checkout')

@push('page-assets')
    @vite(['resources/css/client/catalog.css', 'resources/css/client/checkout.css'])
@endpush

@section('content')
    <section class="shop-width checkout-page" aria-labelledby="checkout-title">
        <header class="checkout-heading" data-shop-back-top-threshold>
            <h1 id="checkout-title">Checkout review</h1>
            @if ($lines)
                <a class="checkout-link" href="{{ route('client.cart') }}">Edit cart</a>
            @endif
        </header>

        @if ($lines || $unavailableLines)
            <div class="checkout-layout">
                <section class="checkout-items" aria-labelledby="checkout-items-title">
                    <h2 id="checkout-items-title">Selected flowers</h2>

                    @if ($unavailableLines)
                        <section class="checkout-notice" aria-labelledby="checkout-unavailable-title">
                            <h3 id="checkout-unavailable-title">Some selected items are unavailable.</h3>
                            <ul>
                                @foreach ($unavailableLines as $unavailable)
                                    <li><strong>{{ $unavailable['name'] }}</strong> <span>{{ $unavailable['issue'] }}</span></li>
                                @endforeach
                            </ul>
                            @if ($lines)
                                <p>The total covers the flowers still available.</p>
                            @endif
                            <a class="checkout-link" href="{{ route('client.cart') }}">Edit cart</a>
                        </section>
                    @endif

                    @foreach ($lines as $line)
                        @php($item = $line['item'])
                        <article class="checkout-item" aria-labelledby="checkout-flower-{{ $line['id'] }}">
                            <div class="catalog-photo checkout-photo">
                                @include('client.partials.product-photo', ['flower' => $item])
                            </div>
                            <div class="checkout-item-info">
                                <h3 id="checkout-flower-{{ $line['id'] }}">{{ $item->name }}</h3>
                                <p>₱{{ \App\Support\Money::format($line['price']) }} per {{ strtolower($item->product_type) }}</p>
                                <p>Quantity {{ number_format($line['quantity']) }}</p>
                            </div>
                            <div class="checkout-line-total">
                                <span class="visually-hidden">Line total</span>
                                <strong>₱{{ \App\Support\Money::format($line['amount']) }}</strong>
                            </div>
                        </article>
                    @endforeach
                </section>

                @if ($lines)
                    <aside class="checkout-summary" aria-labelledby="checkout-summary-title">
                        @if ($valid)
                            <div class="checkout-fulfillment">
                                <h2 id="checkout-summary-title">Pickup or delivery</h2>
                                <nav class="checkout-methods" aria-label="Fulfillment method">
                                    <a href="{{ route('client.checkout') }}" @if (! $delivery) aria-current="true" @endif>Pickup</a>
                                    @if ($deliveryAvailable)
                                        <a href="{{ route('client.checkout', ['method' => 'delivery']) }}" @if ($delivery) aria-current="true" @endif>Delivery</a>
                                    @endif
                                </nav>
                                @unless ($deliveryAvailable)
                                    <p>Delivery is unavailable for this selection.</p>
                                @endunless

                                @unless ($delivery)
                                    <div class="checkout-location">
                                        <h3>Pickup address</h3>
                                        <p>Laon Laan – Juaning Consolidation Area, Barangay 471, Sampaloc, Manila</p>
                                        <a class="checkout-link" href="https://www.google.com/maps/dir/?api=1&amp;destination=14.6114375%2C120.9863871">Get directions</a>
                                    </div>
                                @endunless
                            </div>
                            <div class="checkout-totals">
                                <h2>Order summary</h2>
                                <dl>
                                    <div><dt>Item subtotal</dt><dd>₱{{ \App\Support\Money::format($subtotal) }}</dd></div>
                                    @if ($delivery)
                                        <div><dt>Delivery fee</dt><dd>₱{{ \App\Support\Money::format($fee) }}</dd></div>
                                    @endif
                                    <div class="checkout-final-total">
                                        <dt>{{ $delivery ? 'Delivery total' : 'Pickup total' }}</dt>
                                        <dd>₱{{ \App\Support\Money::format($subtotal + ($delivery ? $fee : 0)) }}</dd>
                                    </div>
                                </dl>
                                @guest
                                    <a class="shop-button primary checkout-sign-in" href="{{ route('client.login', ['continue' => $delivery ? 'client.checkout.delivery' : 'client.checkout']) }}">Sign in to continue</a>
                                @endguest
                            </div>
                        @else
                            <h2 id="checkout-summary-title">Order summary</h2>
                            <p>The selected flowers exceed the cart total limit. Deselect an item to review the total.</p>
                            <a class="checkout-link" href="{{ route('client.cart') }}">Edit cart</a>
                        @endif
                    </aside>
                @endif
            </div>
        @else
            <div class="checkout-empty">
                <h2>Select items in your cart to review checkout</h2>
                <a class="shop-button primary" href="{{ route('client.cart') }}">Return to cart</a>
            </div>
        @endif

        @guest
            @if (! $lines || ! $valid)
                <a class="checkout-link checkout-sign-in" href="{{ route('client.login', ['continue' => 'client.checkout']) }}">Sign in to continue</a>
            @endif
        @endguest
    </section>
@endsection
