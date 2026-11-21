@extends('layouts.client')
@section('content')
    <section class="shop-width shop-interior">
        <header class="account-heading" data-shop-back-top-threshold>
            @unless(request()->routeIs('client.orders', 'client.tracking'))
                <a class="shop-back" href="{{ route('client.home') }}"><i class="bi bi-arrow-left"></i> Shop home</a>
            @endunless
            <h1 tabindex="-1">@yield('title')</h1></header>
        <div class="account-layout">
            <aside class="account-rail">
            <img src="{{ asset('images/shop-bouquets.webp') }}" alt="" width="1040" height="400">
            <nav aria-label="Customer account pages" class="account-menu">
                @foreach (['orders' => 'My Orders', 'tracking' => 'Order Tracking', 'payments' => 'My Payments', 'profile' => 'My Profile'] as $page => $label)
                    <a href="{{ route('client.'.$page) }}" @if(request()->routeIs('client.'.$page)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            </aside>
            <div class="account-content">@yield('account-content')</div>
        </div>
    </section>
@endsection
