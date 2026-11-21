@extends('layouts.client')

@section('title', 'Flowers for Every Story')
@section('body-class', 'shop-home')

@push('page-assets')
    @vite(['resources/css/client/catalog.css', 'resources/css/client/home.css', 'resources/js/client/home.js'])
@endpush

@section('content')
    <section class="floral-hero" aria-labelledby="hero-title" data-shop-back-top-threshold>
        <picture class="home-hero-photo">
            <source media="(max-width: 760px)" srcset="{{ asset('images/shop-hero-mobile.webp') }}" width="960" height="660">
            <img src="{{ asset('images/shop-hero.webp') }}" width="2022" height="778" fetchpriority="high"
                 alt="Pink roses, a lily and eucalyptus in a cream hatbox tied with a rose ribbon.">
        </picture>
        <div class="home-hero-copy shop-width">
            <h1 id="hero-title" tabindex="-1">Flowers for<br>Every Story</h1>
            <p>Bring home a few stems for the kitchen table or choose a bouquet to give.</p>
            <div class="home-hero-actions">
                <a class="shop-button primary" href="{{ route('client.catalog', ['type' => \App\Models\Flower::TYPE_BOUQUET]) }}">Shop Bouquets</a>
                <a class="shop-button home-stem-button" href="{{ route('client.catalog', ['type' => \App\Models\Flower::TYPE_STEM]) }}">Shop by Stem</a>
                <a class="home-text-link" href="{{ route('client.about') }}">About the Shop</a>
            </div>
        </div>
    </section>

    <section class="shop-width home-flowers" aria-labelledby="featured-title">
        <h2 class="home-section-title" id="featured-title">Explore Our Flowers</h2>
        @if ($featuredFlowers->isNotEmpty())
            <div class="catalog-grid featured-grid">
                @foreach ($featuredFlowers as $flower)
                    @include('client.partials.product-card', ['heading' => 'h3'])
                @endforeach
            </div>
        @else
            <p class="catalog-empty">No flowers are listed in the catalog yet.</p>
        @endif
        <a class="home-text-link" href="{{ route('client.catalog') }}">{{ $featuredFlowers->isEmpty() ? 'Shop the catalog' : 'View All Flowers' }}</a>
    </section>

    <section class="home-order" aria-labelledby="order-title">
        <div class="shop-width home-order-inner">
            <h2 id="order-title">How to Order</h2>
            <ol>
                <li>
                    <i class="bi bi-flower3" aria-hidden="true"></i>
                    <div><h3>1. Choose flowers</h3><p>Browse the catalog and pick your favorites.</p></div>
                </li>
                <li>
                    <i class="bi bi-cart3" aria-hidden="true"></i>
                    <div><h3>2. Review your cart</h3><p>Check your selections, quantities, and prices.</p></div>
                </li>
                <li>
                    <i class="bi bi-truck" aria-hidden="true"></i>
                    <div><h3>3. Check pickup or delivery</h3><p>See available options and totals in checkout review.</p></div>
                </li>
            </ol>
        </div>
    </section>

    <section class="home-about" aria-labelledby="intro-title">
        <img src="{{ asset('images/bloom-business.jpg') }}" loading="lazy" decoding="async" alt="">
        <div class="home-about-copy">
            <h2 id="intro-title">A Little About Gemma’s</h2>
            <p>Start with a flower you love. For a vase at home, try adding a few stems in a different shape or height, or keep it simple with one variety.</p>
            <a class="home-text-link" href="{{ route('client.about') }}">More about Gemma’s</a>
        </div>
    </section>
@endsection
