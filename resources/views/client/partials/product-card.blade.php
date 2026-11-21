@php
    $heading = $heading ?? 'h2';
    $unit = strtolower($flower->product_type);
    $available = $flower->availableQuantity();
@endphp

<article class="catalog-item">
    <div class="catalog-photo">
        @if ($flower->image_path && Storage::disk('public')->exists($flower->image_path))
            <img src="{{ Storage::disk('public')->url($flower->image_path) }}" loading="lazy" decoding="async" alt="{{ $flower->name }}">
        @else
            <div class="catalog-photo-placeholder catalog-photo-placeholder-{{ $unit }}">
                <i class="bi {{ $unit === 'stem' ? 'bi-flower3' : 'bi-gift' }}" aria-hidden="true"></i>
                <small>Photo unavailable</small>
            </div>
        @endif
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
