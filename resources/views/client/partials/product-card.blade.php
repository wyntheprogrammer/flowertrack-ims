@php
    $heading = $heading ?? 'h2';
    $unit = strtolower($flower->product_type);
    $available = $flower->availableQuantity();
@endphp

<article class="catalog-item">
    <div class="catalog-photo">
        @include('client.partials.product-photo')
    </div>
    <div class="catalog-item-copy">
        <small>{{ $flower->category }}</small>
        <{{ $heading }} class="catalog-item-name">
            <a href="{{ route('client.product', $flower->id) }}">{{ $flower->name }}</a>
        </{{ $heading }}>
        <p>{{ $flower->price_cents > 0 ? '₱'.\App\Support\Money::format((int) $flower->price_cents).' / '.$unit : 'Price unavailable' }}</p>
        <small>{{ $available ? number_format($available).' '.\Illuminate\Support\Str::plural($unit, $available).' available' : 'Out of stock' }}</small>
    </div>
</article>
