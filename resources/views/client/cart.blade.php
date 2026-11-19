@extends('layouts.client')
@section('title', $checkout ? 'Checkout review' : 'Shopping cart')
@section('content')
    <section class="shop-width shop-interior {{ $checkout ? 'checkout-page' : 'cart-page' }}">
        <a class="shop-back" href="{{ route($checkout ? 'client.cart' : 'client.catalog') }}"><i class="bi bi-arrow-left"></i> {{ $checkout ? 'Edit cart' : 'Continue shopping' }}</a>
        <h1>{{ $checkout ? 'Checkout review' : 'Your shopping cart' }}</h1>
        @if (session('cart_status'))<p role="status">{{ session('cart_status') }}</p>@endif
        @if ($lines)
            <div class="shop-columns">
                <div class="cart-lines">
                    @foreach ($lines as $line)
                        @php($item = $line['item'])
                        <article class="cart-line">
                            <div class="cart-item-info">
                                @if ($item && $item->image_path && Storage::disk('public')->exists($item->image_path))
                                    <img src="{{ Storage::disk('public')->url($item->image_path) }}" alt="" loading="lazy">
                                @endif
                                <small>{{ $item?->product_type }}</small>
                                <h2>@if ($item)<a href="{{ route('client.product', $item->id) }}">{{ $item->name }}</a>@else Item unavailable @endif</h2>
                                <p>{{ $item?->price_cents > 0 ? '₱'.\App\Support\Money::format((int) $item->price_cents).' / '.strtolower($item->product_type) : 'Price unavailable' }}</p>
                            </div>
                            <strong>{{ $line['amount'] !== null ? '₱'.\App\Support\Money::format($line['amount']) : 'Review item' }}</strong>
                            @if ($checkout)
                                <small>Quantity {{ $line['quantity'] }}</small>
                            @else
                                <div class="cart-tools">
                                    @if ($item && $item->price_cents > 0 && $item->availableQuantity())
                                        <form method="post" action="{{ route('client.cart.update', $line['id']) }}">
                                            @csrf @method('PUT')
                                            <label>Quantity <input type="number" name="quantity" value="{{ $line['quantity'] }}" min="1" max="{{ $item->availableQuantity() }}" required></label>
                                            <button>Update</button>
                                        </form>
                                    @else
                                        <span>Quantity {{ $line['quantity'] }}</span>
                                    @endif
                                    <form method="post" action="{{ route('client.cart.remove', $line['id']) }}">@csrf @method('DELETE') <button>Remove</button></form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
                <aside class="shop-summary">
                    <h2>Order summary</h2>
                    @if ($valid)
                        <div class="summary-row"><span>Item subtotal</span><strong>₱{{ \App\Support\Money::format($subtotal) }}</strong></div>
                        @if ($checkout)
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
                            <a class="shop-action" href="{{ route('client.checkout') }}">Review checkout <i class="bi bi-arrow-right"></i></a>
                        @endif
                    @else
                        <p>Stock or pricing has changed. Update or remove the marked items to review your total.</p>
                    @endif
                </aside>
            </div>
        @else
            <div class="empty-cart-layout">
                <div class="shop-empty"><i class="bi bi-bag" aria-hidden="true"></i><h2>Your cart is empty</h2><p>Choose a flower from the catalog to start your cart.</p><a class="shop-action" href="{{ route('client.catalog') }}">Browse flowers</a></div>
                <img src="{{ asset('images/shop-stems.webp') }}" alt="">
            </div>
        @endif
    </section>
@endsection
