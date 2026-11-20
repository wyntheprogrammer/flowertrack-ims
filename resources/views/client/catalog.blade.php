@extends('layouts.client')

@section('title', 'Flower catalog')
@push('page-assets')
    @vite('resources/css/client/catalog.css')
@endpush

@section('content')
    @php($filtersApplied = $search !== '' || $type !== '' || $category !== '')
    <section class="shop-width catalog" aria-labelledby="catalog-title">
        <header class="catalog-banner">
            <img src="{{ asset($type === 'Bouquet' ? 'images/shop-bouquets.webp' : 'images/shop-stems.webp') }}" width="1040" height="400" alt="">
            <h1 id="catalog-title">{{ $type === '' ? 'Flower catalog' : ($type === 'Stem' ? 'Stems' : 'Bouquets') }}</h1>
        </header>
        <form class="catalog-filters" role="search" aria-label="Flower catalog" method="get" action="{{ route('client.catalog') }}">
            <label class="catalog-search">Search flowers
                <span class="catalog-search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Flower name">
                </span>
            </label>
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
            <button class="catalog-submit" type="submit">Apply filters</button>
            @if ($filtersApplied)
                <a href="{{ route('client.catalog') }}">Clear filters</a>
            @endif
        </form>
        <div class="catalog-grid">
            @forelse ($flowers as $flower)
                @include('client.partials.product-card')
            @empty
                <p class="catalog-empty">{{ $filtersApplied ? 'No flowers match these filters.' : 'No flowers are listed yet.' }}</p>
            @endforelse
        </div>
        {{ $flowers->links('pagination::simple-bootstrap-5') }}
    </section>
@endsection
