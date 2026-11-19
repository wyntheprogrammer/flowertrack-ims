@extends('layouts.client')
@section('title', $flower->name)
@section('content')
    <section class="shop-width shop-interior">
        <a class="shop-back" href="{{ route('client.catalog') }}"><i class="bi bi-arrow-left"></i> Flower catalog</a>
        <div class="product-spread">
            <figure>
                @if ($flower->image_path && Storage::disk('public')->exists($flower->image_path))
                    <img src="{{ Storage::disk('public')->url($flower->image_path) }}" alt="{{ $flower->name }}">
                @else
                    <img src="{{ asset($flower->product_type === 'Stem' ? 'images/shop-stems.webp' : 'images/shop-bouquets.webp') }}" alt="">
                    <figcaption>{{ $flower->product_type }} collection artwork · Product photo unavailable</figcaption>
                @endif
            </figure>
            <div class="product-copy">
                <small>{{ $flower->category }} · {{ $flower->product_type }}</small>
                <h1>{{ $flower->name }}</h1>
                <p>{{ $flower->description }}</p>
                <p class="product-price">{{ $flower->price_cents > 0 ? '₱'.\App\Support\Money::format((int) $flower->price_cents).' / '.strtolower($flower->product_type) : 'Price unavailable' }}</p>
                <p>{{ $flower->availableQuantity() ? number_format($flower->availableQuantity()).' '.strtolower(\Illuminate\Support\Str::plural($flower->product_type, $flower->availableQuantity())).' available' : 'Out of stock' }}</p>
                @if ($flower->availableQuantity() && $flower->price_cents > 0)
                    <form method="post" action="{{ route('client.cart.add', $flower->id) }}" class="shop-buy">
                        @csrf
                        <label>Quantity <input type="number" name="quantity" min="1" max="{{ $flower->availableQuantity() }}" value="1" required></label>
                        <button class="shop-action">Add to cart <i class="bi bi-bag-plus"></i></button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
