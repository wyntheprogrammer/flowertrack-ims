<footer class="shop-footer shop-footer-featured">
    <div class="shop-width shop-footer-inner">
        <div class="shop-footer-location">
            <a class="shop-footer-brand" href="{{ route('client.home') }}" aria-label="Gemma’s Flower Shop home">
                <img src="{{ asset('images/gemmas-logo.png') }}" width="733" height="340" loading="lazy" alt="Gemma’s Flower Shop">
            </a>
            <address>
                Laon Laan – Juaning Consolidation Area<br>
                Barangay 471, Sampaloc, Manila 1008
            </address>
            <p class="shop-footer-hours">
                <i class="bi bi-clock" aria-hidden="true"></i>
                <span class="visually-hidden">Business hours</span>
                <span>Always open</span>
            </p>
        </div>
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
        <section class="shop-footer-contact" aria-labelledby="shop-footer-contact">
            <h2 id="shop-footer-contact">Contact</h2>
            <ul>
                <li>
                    <a href="tel:+639273574751">
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <span>0927 357 4751</span>
                    </a>
                </li>
                <li>
                    <a href="mailto:gemmasflowershop7@gmail.com">
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <span>gemmasflowershop7@gmail.com</span>
                    </a>
                </li>
            </ul>
            <p>Contact the shop for account access or a password reset.</p>
        </section>
        <section aria-labelledby="shop-footer-social">
            <h2 id="shop-footer-social">Follow us</h2>
            <a class="shop-footer-social-link" href="{{ config('services.facebook.shop_url') }}" target="_blank" rel="noopener noreferrer" aria-label="Gemma’s Flower Shop on Facebook (opens in a new tab)">
                <i class="bi bi-facebook" aria-hidden="true"></i>
            </a>
        </section>
    </div>
</footer>

<button class="shop-back-top" type="button" aria-label="Back to top" hidden>
    <i class="bi bi-arrow-up" aria-hidden="true"></i>
</button>
