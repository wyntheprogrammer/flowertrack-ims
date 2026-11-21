@extends('layouts.client')

@section('title', 'About the shop')
@section('body-class', 'shop-about')

@push('page-assets')
    @vite('resources/css/client/editorial.css')
@endpush

@section('content')
    <div class="shop-width about-page">
        <header class="about-intro" data-shop-back-top-threshold>
            <div>
                <h1 tabindex="-1">About Gemma’s Flower Shop</h1>
                <p>Gemma’s is a flower shop in Dangwa, Sampaloc, Manila. Come by for a few stems to arrange at home, or choose a ready-made bouquet when you have someone in mind.</p>
            </div>
            <img src="{{ asset('images/bloom-business.jpg') }}" decoding="async" alt="Pink roses against a forest-green background">
        </header>

        <section class="about-story" aria-labelledby="about-story-title" data-shop-reveal>
            <h2 id="about-story-title">How the shop got its name</h2>
            <div class="about-story-copy">
                <p>Gemma spent five years selling flowers before opening Roses and Friends in Quiapo. She ran the shop there for four years. When she moved to Dos Castillas near the Dangwa Terminal, she chose a name that would let customers know who was arranging their flowers: Gemma’s Flower Shop.</p>
                <p>Gemma credits the customers who recommended her work to friends with helping the shop grow.</p>
            </div>
        </section>

        <section class="about-flowers" aria-labelledby="about-flowers-title" data-shop-reveal>
            <div>
                <h2 id="about-flowers-title">Stems and bouquets</h2>
                <p>Choose individual stems if you’d like to put together your own arrangement. For a gift that’s ready to give, choose a finished bouquet. The catalog shows the price per stem or bouquet and the quantity available.</p>
            </div>
            <a class="shop-button primary" href="{{ route('client.catalog') }}">Browse flowers</a>
        </section>

        <section class="about-visit" aria-labelledby="about-visit-title" data-shop-reveal>
            <div>
                <h2 id="about-visit-title">Visit the shop</h2>
                <address>
                    Laon Laan – Juaning Consolidation Area<br>
                    Barangay 471, Sampaloc, Manila 1008
                </address>
                <div class="about-visit-links">
                    <a class="shop-button primary" href="https://www.google.com/maps/dir/?api=1&amp;destination=14.6114375%2C120.9863871">Get directions</a>
                    <a class="about-text-link" href="https://www.facebook.com/profile.php?id=100063693678426">Visit Gemma’s on Facebook</a>
                </div>
            </div>
            <iframe
                class="about-map"
                src="https://maps.google.com/maps?q=14.6114375,120.9863871&amp;z=18&amp;output=embed"
                title="Google Map of Gemma’s Flower Shop in Sampaloc, Manila"
                loading="lazy"
            ></iframe>
        </section>
    </div>
@endsection
