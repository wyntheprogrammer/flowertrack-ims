@extends('layouts.client')

@section('title', 'Flower catalog')
@section('body-class', 'shop-catalog')

@push('page-assets')
    @vite('resources/css/client/catalog.css')
@endpush

@section('content')
    @php($filtersApplied = $search !== '' || $type !== '' || $category !== '')
    <section class="shop-width catalog" aria-labelledby="catalog-title">
        <header class="catalog-banner" data-shop-back-top-threshold>
            <h1 id="catalog-title" tabindex="-1">Flower catalog</h1>
            <img src="{{ asset($type === \App\Models\Flower::TYPE_BOUQUET ? 'images/shop-bouquets.webp' : 'images/shop-stems.webp') }}" width="1040" height="400" alt="">
        </header>

        <div class="catalog-tools">
            <form class="catalog-filters" role="search" aria-label="Flower catalog" method="get" action="{{ route('client.catalog') }}">
                @if ($type !== '')
                    <input type="hidden" name="type" value="{{ $type }}">
                @endif
                <label class="catalog-search">Search flowers
                    <span class="catalog-search-field">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Flower name">
                    </span>
                </label>
                <label class="catalog-category">Category
                    <select name="category" class="form-select">
                        <option value="">All categories</option>
                        @foreach ($categories as $choice)
                            <option value="{{ $choice }}" @selected($choice === $category)>{{ $choice }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="catalog-submit" type="submit">Apply filters</button>
            </form>

            <div class="catalog-type-row">
                <nav class="catalog-types" aria-label="Flower type">
                    @foreach (['', ...$types] as $choice)
                        @php($typeFilters = array_filter(['type' => $choice, 'category' => $category, 'q' => $search], fn (string $value): bool => $value !== ''))
                        <a href="{{ route('client.catalog', $typeFilters) }}"@if ($choice === $type) aria-current="page"@endif>
                            {{ $choice === '' ? 'All flowers' : \Illuminate\Support\Str::plural($choice) }}
                        </a>
                    @endforeach
                </nav>
                @if ($filtersApplied && $flowers->isNotEmpty())
                    <a class="catalog-clear" href="{{ route('client.catalog') }}">Clear filters</a>
                @endif
            </div>
        </div>

        @if ($flowers->isNotEmpty())
            <div class="catalog-grid">
                @foreach ($flowers as $flower)
                    @include('client.partials.product-card')
                @endforeach
            </div>
        @else
            <div class="catalog-empty">
                <p>{{ $filtersApplied ? 'No flowers match these filters.' : 'No flowers are listed yet.' }}</p>
                @if ($filtersApplied)
                    <a class="catalog-clear" href="{{ route('client.catalog') }}">Clear filters</a>
                @endif
            </div>
        @endif

        {{ $flowers->links('pagination::simple-bootstrap-5') }}
    </section>
@endsection
