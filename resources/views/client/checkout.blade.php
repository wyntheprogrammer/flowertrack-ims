@extends('layouts.client')

@section('title', 'Checkout review')

@section('content')
    <section class="shop-width shop-interior checkout-page">
        <a class="shop-back" href="{{ route('client.cart') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Edit cart</a>
        <h1>Checkout review</h1>

        @if ($excludedCount)
            <p class="alert alert-warning" role="status">Some selected items are unavailable. Check the details in your cart.</p>
        @endif

        @if ($lines)
            <div class="shop-columns">
                <div class="cart-lines" id="cart-items" tabindex="-1">
                    @foreach ($lines as $line)
                        @php($item = $line['item'])
                        <article class="cart-line">
                            <div class="cart-item-info">
                                @if ($item && $item->image_path && Storage::disk('public')->exists($item->image_path))
                                    <img src="{{ Storage::disk('public')->url($item->image_path) }}" alt="" loading="lazy">
                                @endif
                                <small>{{ $item?->product_type }}</small>
                                <h2>@if ($item)<a href="{{ route('client.product', $item->id) }}">{{ $item->name }}</a>@else Item unavailable @endif</h2>
                                <p>{{ $line['priceAvailable'] ? '₱'.\App\Support\Money::format($line['price']).' / '.strtolower($item->product_type) : 'Price unavailable' }}</p>
                            </div>
                            <strong>{{ $line['amount'] !== null ? '₱'.\App\Support\Money::format($line['amount']) : 'Review item' }}</strong>
                            <small>Quantity {{ $line['quantity'] }}</small>
                        </article>
                    @endforeach
                </div>

                <aside class="shop-summary">
                    <h2>Order summary</h2>
                    @if ($valid)
                        <div class="summary-row"><span>Item subtotal</span><strong>₱{{ \App\Support\Money::format($subtotal) }}</strong></div>
                        <nav class="fulfillment-options" aria-label="Review fulfillment totals">
                            <a href="{{ route('client.checkout') }}" @if(!$delivery) aria-current="page" @endif><i class="bi bi-shop"></i> Pickup</a>
                            @if ($deliveryAvailable)
                                <a href="{{ route('client.checkout', ['method' => 'delivery']) }}" @if($delivery) aria-current="page" @endif><i class="bi bi-truck"></i> Delivery</a>
                            @endif
                        </nav>
                        @if ($delivery)
                            <div class="summary-row"><span>Delivery fee</span><span>₱{{ \App\Support\Money::format($fee) }}</span></div>
                        @elseif (!$deliveryAvailable)
                            <p>Delivery unavailable for this cart.</p>
                        @endif
                        <div class="summary-row"><span>{{ $delivery ? 'Delivery total' : 'Pickup total' }}</span><strong>₱{{ \App\Support\Money::format($subtotal + ($delivery ? $fee : 0)) }}</strong></div>
                        <p>Online order submission is pending. Your items are saved in this session.</p>
                    @else
                        <p>The selected items exceed the cart total limit. Edit your selection to review checkout.</p>
                    @endif
                </aside>
            </div>
        @elseif ($hasCart)
            <div class="shop-empty">
                <h2>No items selected for checkout</h2>
                <p>Select available items in your cart to review checkout.</p>
            </div>
        @else
            <div class="empty-cart-layout">
                <div class="shop-empty"><i class="bi bi-bag" aria-hidden="true"></i><h2>Your cart is empty</h2><p>Choose a flower from the catalog to start your cart.</p><a class="shop-action" href="{{ route('client.catalog') }}">Browse flowers</a></div>
                <img src="{{ asset('images/shop-stems.webp') }}" alt="">
            </div>
        @endif
    </section>
@endsection
