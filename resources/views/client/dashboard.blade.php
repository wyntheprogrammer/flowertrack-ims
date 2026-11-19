@extends('layouts.client')

@section('title', 'Flowers for Every Story')
@section('body-class', 'shop-home')

@push('page-assets')
    @vite(['resources/css/client/home.css', 'resources/js/client/home.js'])
@endpush

@section('content')
    <section class="floral-hero" aria-labelledby="hero-title" style="--window-light: url('{{ asset('images/shop-window-light.webp') }}')">
        <div class="hero-scene">
            <div class="hero-print">
                <picture>
                    <source media="(max-width: 700px)" srcset="{{ asset('images/shop-hero-mobile.webp') }}" width="960" height="660">
                    <img src="{{ asset('images/shop-hero.webp') }}" width="2022" height="778" fetchpriority="high"
                         alt="Pink roses, a lily and eucalyptus in a cream hatbox tied with a rose ribbon, in a sunlit alcove.">
                </picture>
                <img class="gift-tag-logo" src="{{ asset('images/gemmas-logo.png') }}" width="733" height="340" alt="">
            </div>
        </div>
        <div class="hero-petals" aria-hidden="true">
            <img class="petal petal-high" src="{{ asset('images/shop-petal.webp') }}" width="96" height="64" alt="">
            <img class="petal petal-low" src="{{ asset('images/shop-petal.webp') }}" width="96" height="64" alt="">
        </div>
        <div class="hero-inner">
            <div class="hero-copy">
                <p class="hero-kicker">Fresh flowers <span aria-hidden="true">·</span> Ready-made bouquets</p>
                <h1 id="hero-title">Flowers for<br><span>Every</span> <em>Story</em></h1>
                <p class="hero-intro">From simple stems to elegant bouquets,<br class="desktop-break"> find flowers for life’s special moments.</p>
                <div class="hero-actions">
                    <a class="shop-button primary" href="#collections"><i class="bi bi-flower1" aria-hidden="true"></i> Browse collections <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    <a class="shop-button secondary" href="#about">About Gemma’s <i class="bi bi-arrow-down-right" aria-hidden="true"></i></a>
                </div>
            </div>
            <ul class="hero-notes" aria-label="How our flowers are sold">
                <li><i class="bi bi-flower3" aria-hidden="true"></i><span><strong>Individual stems</strong>For your own vase</span></li>
                <li><i class="bi bi-gift" aria-hidden="true"></i><span><strong>Ready-made bouquets</strong>Sold as a whole arrangement</span></li>
            </ul>
        </div>
    </section>

    <section id="collections" class="shop-collections shop-width" aria-labelledby="collections-title">
        <div class="collections-heading">
            <h2 id="collections-title">The Collections <span class="floral-rule" aria-hidden="true"><i class="bi bi-flower1"></i></span></h2>
            <p>Choose a collection to discover how its flowers are arranged and sold.</p>
        </div>
        <div class="collections-grid">
            @include('client.partials.collection-card', [
                'id' => 'stems',
                'type' => 'Stem',
                'name' => 'Stems',
                'description' => 'Fresh, individual blooms for your own arrangements.',
                'detail' => 'Choose stems when you want to arrange the flowers yourself. Each stem is a single unit.',
            ])
            @include('client.partials.collection-card', [
                'id' => 'bouquets',
                'type' => 'Bouquet',
                'name' => 'Bouquets',
                'description' => 'Flowers gathered into one finished bouquet.',
                'detail' => 'Choose a bouquet for flowers that come already arranged. Each complete bouquet is one unit.',
            ])
        </div>
    </section>
@endsection
