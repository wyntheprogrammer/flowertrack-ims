@extends('layouts.client')

@section('title', 'About the shop')

@push('page-assets')
    @vite('resources/css/client/editorial.css')
@endpush

@section('content')
    <div class="shop-width shop-editorial">
        <header class="shop-story">
            <div>
                <h1>About Gemma’s Flower Shop</h1>
                <p>Choose individual stems for a favorite vase or pick a ready-made bouquet for a gift, a celebration, or your own table.</p>
                <a class="shop-button primary" href="{{ route('client.catalog') }}">Browse flowers <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="shop-image">
                <img src="{{ asset('images/bloom-business.jpg') }}" decoding="async" alt="A cluster of pink roses against a forest-green background">
            </div>
        </header>

        <section class="about-shopping" aria-labelledby="about-shopping-title" data-shop-reveal>
            <h2 id="about-shopping-title">Shopping with Gemma’s</h2>
            <div class="shop-guide-grid">
                <article class="shop-guide-item">
                    <i class="bi bi-search-heart shop-guide-icon" aria-hidden="true"></i>
                    <div>
                        <h3>Find a flower</h3>
                        <p>Search by name or narrow the catalog by flower type and category. Each item shows its current price and availability.</p>
                        <a class="shop-text-link" href="{{ route('client.catalog') }}">Browse the catalog <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </article>
                <article class="shop-guide-item">
                    <i class="bi bi-bag shop-guide-icon" aria-hidden="true"></i>
                    <div>
                        <h3>Check your cart</h3>
                        <p>Open a flower to select a quantity. You can update quantities or remove items in your cart and review the current totals.</p>
                        <a class="shop-text-link" href="{{ route('client.cart') }}">View your cart <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </article>
            </div>
        </section>
    </div>
@endsection
