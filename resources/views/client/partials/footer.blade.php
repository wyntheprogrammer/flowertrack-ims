@php($usesFloatingTop = request()->routeIs('client.home', 'client.catalog'))

<footer @class(['shop-footer', 'shop-footer-featured' => $usesFloatingTop])>
    <div class="shop-width shop-footer-inner">
        <a class="shop-footer-brand" href="{{ route('client.home') }}" aria-label="Gemma’s Flower Shop home">
            <img src="{{ asset('images/gemmas-logo.png') }}" width="733" height="340" loading="lazy" alt="Gemma’s Flower Shop">
        </a>
        <nav aria-labelledby="shop-footer-nav">
            <h2 id="shop-footer-nav">Shop</h2>
            <ul>
                @foreach (['home' => 'Home', 'catalog' => 'Catalog', 'about' => 'About'] as $page => $label)
                    <li>
                        <a href="{{ route('client.'.$page) }}" @if(request()->routeIs('client.'.$page)) aria-current="page" @endif>{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <section aria-labelledby="shop-footer-contact">
            <h2 id="shop-footer-contact">Contact</h2>
            <p>For account access or password reset, contact your administrator.</p>
        </section>
    </div>
    @unless ($usesFloatingTop)
        <div class="shop-width shop-footer-bottom">
            <a href="#top">Back to top <i class="bi bi-arrow-up" aria-hidden="true"></i></a>
        </div>
    @endunless
</footer>

@if ($usesFloatingTop)
    <button class="shop-back-top" type="button" aria-label="Back to top" hidden>
        <i class="bi bi-arrow-up" aria-hidden="true"></i>
    </button>
@endif
