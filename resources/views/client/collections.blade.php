@extends('layouts.client')

@section('title', 'Flower collections')

@push('page-assets')
    @vite('resources/css/client/editorial.css')
@endpush

@section('content')
    <div class="shop-width shop-editorial">
        <header class="shop-page-heading">
            <h1>Flower collections</h1>
            <span class="floral-rule" aria-hidden="true"><i class="bi bi-flower1"></i></span>
        </header>

        <section class="collection-feature collection-feature-stems" id="stems" aria-labelledby="stems-title" data-shop-reveal>
            <div class="collection-feature-copy">
                <h2 id="stems-title">Stems</h2>
                <p>Pick one variety for a simple vase or mix several flowers to make your own arrangement. Choose your quantity by the individual stem.</p>
                <a class="shop-button primary" href="{{ route('client.catalog', ['type' => \App\Models\Flower::TYPE_STEM]) }}">Shop stems <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="shop-image">
                <img src="{{ asset('images/shop-stems.webp') }}" width="1040" height="400" decoding="async" alt="White lilies on individual stems">
            </div>
        </section>

        <section class="collection-feature collection-feature-bouquets" id="bouquets" aria-labelledby="bouquets-title" data-shop-reveal>
            <div class="collection-feature-copy">
                <h2 id="bouquets-title">Ready-made bouquets</h2>
                <p>Choose a finished arrangement for a gift or for your own table. The listed price is for one complete bouquet.</p>
                <a class="shop-button primary" href="{{ route('client.catalog', ['type' => \App\Models\Flower::TYPE_BOUQUET]) }}">Shop bouquets <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="shop-image">
                <img src="{{ asset('images/shop-bouquets.webp') }}" width="1040" height="400" loading="lazy" decoding="async" alt="A wrapped bouquet of pink roses and eucalyptus">
            </div>
        </section>
    </div>
@endsection
