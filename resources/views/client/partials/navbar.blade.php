<header class="shop-header">
    <div class="shop-header-inner">
        <a class="shop-brand" href="{{ route('client.home') }}" aria-label="Gemma’s Flower Shop home">
            <img src="{{ asset('images/gemmas-logo.png') }}" width="733" height="340" alt="Gemma’s Flower Shop">
        </a>
        <button class="shop-menu-toggle" type="button" hidden aria-controls="shop-links" aria-expanded="false">
            <i class="bi bi-list" aria-hidden="true"></i> Menu
        </button>
        <nav id="shop-links" aria-label="Shop navigation">
            <a href="{{ route('client.home') }}" @if(request()->routeIs('client.home')) aria-current="page" @endif>Home</a>
            <a href="{{ route('client.catalog') }}" @if(request()->routeIs('client.catalog', 'client.product')) aria-current="{{ request()->routeIs('client.catalog') ? 'page' : 'location' }}" @endif>Catalog</a>
            <a href="{{ route('client.about') }}" @if(request()->routeIs('client.about')) aria-current="page" @endif>About</a>
            <a class="nav-cart" href="{{ route('client.cart') }}" @if(request()->routeIs('client.cart', 'client.checkout')) aria-current="{{ request()->routeIs('client.cart') ? 'page' : 'location' }}" @endif><i class="bi bi-bag" aria-hidden="true"></i> Cart</a>
            @if (auth()->user()?->isClient())
                @unless (auth()->user()->must_change_password)
                    <a href="{{ route('client.profile') }}" @if(request()->routeIs('client.orders', 'client.tracking', 'client.payments', 'client.profile')) aria-current="{{ request()->routeIs('client.profile') ? 'page' : 'location' }}" @endif>Account</a>
                @endunless
                <form class="shop-sign-out" method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Sign out</button>
                </form>
            @endif
        </nav>
        @unless (auth()->user()?->isClient())
            @php
                $continue = request()->routeIs('client.checkout')
                    ? ['continue' => request()->query('method') === 'delivery' ? 'client.checkout.delivery' : 'client.checkout']
                    : [];
            @endphp
            <a class="shop-sign-in-link" href="{{ route('client.login', $continue) }}" @if(request()->routeIs('client.login')) aria-current="page" @endif><i class="bi bi-person" aria-hidden="true"></i> Sign in</a>
        @endunless
    </div>
</header>
