@extends('layouts.client')

@section('title', $flower->name)
@section('body-class', 'shop-product')

@push('page-assets')
    @vite(['resources/css/client/catalog.css', 'resources/css/client/product.css', 'resources/js/client/product.js'])
@endpush

@section('content')
    @php
        $unit = strtolower($flower->product_type);
        $units = \Illuminate\Support\Str::plural($unit);
        $available = $flower->availableQuantity();
        $price = (int) $flower->price_cents;
        $priceAvailable = $price > 0 && $price <= \App\Support\Money::MAX_CENTS;
        $previousQuantity = filter_var(old('quantity', 1), FILTER_VALIDATE_INT);
        $quantity = $previousQuantity >= 1 && $previousQuantity <= $maxToAdd ? $previousQuantity : 1;
    @endphp

    <section class="shop-width product-detail" aria-labelledby="product-title">
        <a class="product-back" href="{{ route('client.catalog') }}">Back to catalog</a>

        <div class="product-detail-grid">
            <div class="catalog-photo product-detail-photo">
                @include('client.partials.product-photo', ['eager' => true])
            </div>

            <div class="product-detail-copy">
                <p class="product-category">{{ $flower->category }}</p>
                <h1 id="product-title">{{ $flower->name }}</h1>
                <p class="product-detail-price">
                    {{ $priceAvailable ? '₱'.\App\Support\Money::format($price).' / '.$unit : 'Price unavailable' }}
                </p>
                <p class="product-availability">
                    {{ $available > 0 ? number_format($available).' '.\Illuminate\Support\Str::plural($unit, $available).' available' : 'Out of stock' }}
                </p>
                <p class="product-description">{{ $flower->description }}</p>

                @if ($maxToAdd > 0)
                    <form class="product-buy" method="post" action="{{ route('client.cart.add', $flower->id) }}" data-price-cents="{{ $price }}">
                        @csrf
                        <div class="product-quantity">
                            <label for="product-quantity">Number of {{ $units }}</label>
                            <input id="product-quantity" class="form-control" type="number" name="quantity" min="1" max="{{ $maxToAdd }}" step="1" value="{{ $quantity }}" required>
                            @if ($quantityInCart > 0)
                                <small>{{ number_format($quantityInCart) }} {{ \Illuminate\Support\Str::plural($unit, $quantityInCart) }} in your cart</small>
                            @endif
                        </div>
                        <div class="product-buy-actions">
                            <div class="product-total">
                                <span id="product-total-label">Item total</span>
                                <output id="product-item-total" for="product-quantity" aria-labelledby="product-total-label">₱{{ \App\Support\Money::format($price * $quantity) }}</output>
                            </div>
                            <button class="shop-button primary" type="submit">Add to cart</button>
                        </div>
                        <noscript><p class="product-no-script">Your cart shows the total for the quantity you add.</p></noscript>
                    </form>
                @elseif ($available > 0 && $priceAvailable && ! $errors->has('quantity'))
                    <p class="product-add-limit">
                        {{ $quantityInCart >= $available ? 'Your cart already contains the available quantity.' : 'You cannot add more at the current price.' }}
                    </p>
                @endif
            </div>
        </div>
    </section>
@endsection
