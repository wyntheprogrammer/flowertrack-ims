@php
    $heading = $heading ?? 'h2';
    $isHomepage = $isHomepage ?? false;
    $unit = strtolower($flower->product_type);
    $available = $flower->availableQuantity();
@endphp

<article class="catalog-item">
    <div class="catalog-photo">
        @if ($flower->image_path && Storage::disk('public')->exists($flower->image_path))
            <img src="{{ Storage::disk('public')->url($flower->image_path) }}" loading="lazy" decoding="async" alt="{{ $flower->name }}">
        @elseif ($isHomepage)
            <div class="home-photo-placeholder home-photo-placeholder-{{ $unit }}">
                <i class="bi {{ $unit === 'stem' ? 'bi-flower3' : 'bi-gift' }}" aria-hidden="true"></i>
                <small>Photo unavailable</small>
            </div>
        @else
            <img class="catalog-artwork" src="{{ asset($flower->product_type === \App\Models\Flower::TYPE_STEM ? 'images/shop-stems.webp' : 'images/shop-bouquets.webp') }}"
                 loading="lazy" decoding="async" alt="">
            <small class="catalog-artwork-note">Collection artwork</small>
        @endif
    </div>
    <div class="catalog-item-copy">
        <small>{{ $flower->category }}@unless ($isHomepage) · {{ $flower->product_type }}@endunless</small>
        <{{ $heading }} class="catalog-item-name">
            <a href="{{ route('client.product', $flower->id) }}">
                {{ $flower->name }}
                @unless ($isHomepage)
                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                @endunless
            </a>
        </{{ $heading }}>
        <p>{{ $flower->price_cents > 0 ? '₱'.\App\Support\Money::format((int) $flower->price_cents).' / '.$unit : 'Price unavailable' }}</p>
        <small>{{ $available ? number_format($available).' '.\Illuminate\Support\Str::plural($unit, $available).' available' : 'Out of stock' }}</small>
    </div>
</article>
