@extends('layouts.client')

@section('title', 'Flower catalog')
@push('page-assets')
    @vite('resources/css/client/catalog.css')
@endpush

@section('content')
    <section class="shop-width catalog" aria-labelledby="catalog-title">
        <header class="catalog-banner">
            <img src="{{ asset($type === 'Bouquet' ? 'images/shop-bouquets.webp' : 'images/shop-stems.webp') }}" width="1040" height="400" alt="">
            <h1 id="catalog-title">{{ $type === '' ? 'Flower catalog' : ($type === 'Stem' ? 'Stems' : 'Bouquets') }}</h1>
        </header>
        <form class="catalog-filters" method="get" action="{{ route('client.catalog') }}">
            <label>Collection
                <select name="type" class="form-select">
                    <option value="">All collections</option>
                    @foreach ($types as $unit)
                        <option value="{{ $unit }}" @selected($unit === $type)>{{ $unit === 'Stem' ? 'Stems' : 'Bouquets' }}</option>
                    @endforeach
                </select>
            </label>
            <label>Category
                <select name="category" class="form-select">
                    <option value="">All categories</option>
                    @foreach ($categories as $choice)
                        <option @selected($choice === $category)>{{ $choice }}</option>
                    @endforeach
                </select>
            </label>
            <button class="catalog-submit">Apply filters</button>
            @if ($type !== '' || $category !== '')
                <a href="{{ route('client.catalog') }}">Clear filters</a>
            @endif
        </form>
        <div class="catalog-grid">
            @forelse ($flowers as $flower)
                @php
                    $unit = strtolower($flower->product_type);
                    $available = $flower->availableQuantity();
                @endphp
                <article class="catalog-item">
                    <div class="catalog-photo">
                        @if ($flower->image_path && Storage::disk('public')->exists($flower->image_path))
                            <img src="{{ Storage::disk('public')->url($flower->image_path) }}" loading="lazy" alt="{{ $flower->name }}">
                        @else
                            <img src="{{ asset($flower->product_type === 'Stem' ? 'images/shop-stems.webp' : 'images/shop-bouquets.webp') }}" loading="lazy" alt="">
                            <small>Collection artwork</small>
                        @endif
                    </div>
                    <div>
                        <small>{{ $flower->category }} · {{ $flower->product_type }}</small>
                        <h2><a href="{{ route('client.product', $flower->id) }}">{{ $flower->name }}</a></h2>
                        <p>{{ $flower->price_cents > 0 ? '₱'.\App\Support\Money::format((int) $flower->price_cents).' / '.$unit : 'Price unavailable' }}</p>
                        <small>{{ $available ? number_format($available).' '.\Illuminate\Support\Str::plural($unit, $available).' available' : 'Out of stock' }}</small>
                    </div>
                </article>
            @empty
                <p class="catalog-empty">{{ $type || $category ? 'No flowers match these filters.' : 'No flowers are listed yet.' }}</p>
            @endforelse
        </div>
        {{ $flowers->links('pagination::simple-bootstrap-5') }}
    </section>
@endsection
