@extends('layouts.client')

@section('title', 'Shopping cart')
@section('body-class', 'shop-cart')

@push('page-assets')
    @vite(['resources/css/client/catalog.css', 'resources/css/client/cart.css', 'resources/js/client/cart.js'])
@endpush

@section('content')
    <section class="shop-width shopping-cart" aria-labelledby="cart-title">
        <header class="shopping-cart-heading" data-shop-back-top-threshold>
            <h1 id="cart-title" tabindex="-1">Your shopping cart</h1>
            @if ($lines && ! session('cart_addition'))
                <a class="cart-text-link" href="{{ route('client.catalog') }}">Continue shopping</a>
            @endif
        </header>

        @if (session('cart_status'))
            <div class="shopping-cart-feedback">
                <p role="status">{{ session('cart_status') }}</p>
                @if (session('cart_addition'))
                    <div class="shopping-cart-actions">
                        <a class="cart-text-link" href="#cart-items">View cart</a>
                        <a class="cart-text-link" href="{{ route('client.catalog') }}">Continue shopping</a>
                    </div>
                @endif
            </div>
        @endif

        @if ($lines)
            <form id="cart-selection" method="post" action="{{ route('client.cart.selection') }}" data-subtotal-limit="{{ \App\Support\Money::MAX_CENTS }}">
                @csrf
            </form>
            <div class="shopping-cart-layout">
                <div class="shopping-cart-items" id="cart-items" tabindex="-1">
                    <label class="cart-select-all" hidden>
                        <input type="checkbox" id="cart-select-all" @checked($eligibleCount > 0 && $selectedCount === $eligibleCount) @disabled($eligibleCount === 0)>
                        Select all
                    </label>
                    @foreach ($lines as $line)
                        @php
                            $item = $line['item'];
                            $id = $line['id'];
                            $unit = $item ? strtolower($item->product_type) : null;
                            $updateError = $errors->getBag('cart')->first('items.'.$id);
                        @endphp
                        <article class="shopping-cart-item" aria-labelledby="cart-name-{{ $id }}">
                            <label class="cart-line-select">
                                <input type="checkbox" form="cart-selection" name="selected[]" value="{{ $id }}"
                                       data-line-amount="{{ $line['amount'] }}" @checked($line['selected']) @disabled($line['issue'] !== null)>
                                <span class="visually-hidden">Select {{ $item?->name ?? 'unavailable item' }} for checkout</span>
                            </label>
                            <div class="catalog-photo shopping-cart-photo">
                                @if ($item)
                                    @include('client.partials.product-photo', ['flower' => $item])
                                @else
                                    <div class="catalog-photo-placeholder">
                                        <i class="bi bi-bag" aria-hidden="true"></i>
                                        <small>Photo unavailable</small>
                                    </div>
                                @endif
                            </div>

                            <div class="shopping-cart-item-details">
                                <h2 id="cart-name-{{ $id }}">
                                    @if ($item)
                                        <a class="cart-text-link" href="{{ route('client.product', $item->id) }}">{{ $item->name }}</a>
                                    @else
                                        Item unavailable
                                    @endif
                                </h2>

                                @if ($line['priceAvailable'])
                                    <p class="cart-equation">
                                        <span><span data-cart-unit-price>₱{{ \App\Support\Money::format($line['price']) }}</span> per {{ $unit }}</span>
                                        <span data-cart-equation @if ($line['amount'] === null) hidden @endif>× <span data-cart-saved-quantity>{{ number_format($line['quantity']) }}</span> = <strong data-cart-line-total>@if ($line['amount'] !== null)₱{{ \App\Support\Money::format($line['amount']) }}@endif</strong></span>
                                    </p>
                                @endif

                                <div class="cart-item-issue" id="cart-issue-{{ $id }}" role="alert" @if (! $line['issue'] && ! $updateError) hidden @endif>
                                    <div data-cart-stock-issue>
                                        @if ($line['issue'])
                                            <p>{{ $line['issue'] }}</p>
                                        @endif
                                    </div>
                                    <p data-cart-save-error>{{ $updateError !== $line['issue'] ? $updateError : '' }}</p>
                                </div>

                                <div class="shopping-cart-actions">
                                    @if ($line['maxQuantity'] > 0)
                                        <form class="cart-quantity-form" method="post" action="{{ route('client.cart.update', $id) }}">
                                            @csrf
                                            @method('PUT')
                                            <label for="cart-quantity-{{ $id }}">Quantity<span class="visually-hidden"> of {{ $item->name }}</span></label>
                                            <div class="cart-quantity-controls">
                                                <button class="cart-step" type="button" data-cart-step="-1" aria-label="Decrease quantity of {{ $item->name }}" hidden>−</button>
                                                <input class="form-control" id="cart-quantity-{{ $id }}" name="quantity" type="number"
                                                       inputmode="numeric" min="1" max="{{ $line['maxQuantity'] }}" step="1" value="{{ $line['quantity'] }}" required
                                                       aria-describedby="cart-issue-{{ $id }}" @if ($line['issue'] || $updateError) aria-invalid="true" @endif>
                                                <button class="cart-step" type="button" data-cart-step="1" aria-label="Increase quantity of {{ $item->name }}" hidden>+</button>
                                            </div>
                                            <span class="cart-quantity-status" data-cart-quantity-status role="status"></span>
                                            <noscript><small>Press Enter to save the quantity.</small></noscript>
                                        </form>
                                    @else
                                        <p class="cart-saved-quantity">Quantity {{ number_format($line['quantity']) }}</p>
                                    @endif

                                    <form method="post" action="{{ route('client.cart.remove', $id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="cart-remove" type="submit">Remove<span class="visually-hidden"> {{ $item?->name ?? 'unavailable item' }}</span></button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <aside class="shopping-cart-summary" aria-labelledby="cart-summary-title">
                    <h2 id="cart-summary-title">Order summary</h2>
                    <div class="shopping-cart-subtotal">
                        <span id="cart-subtotal-label">Selected subtotal</span>
                        <output id="cart-subtotal" aria-labelledby="cart-subtotal-label">{{ $overLimit ? '—' : ($selectedCount ? '₱'.\App\Support\Money::format($subtotal) : '₱0') }}</output>
                    </div>
                    <p class="cart-selection-limit" id="cart-selection-limit" role="status" @if (! $overLimit) hidden @endif>Deselect an item to bring the selected subtotal within the cart limit.</p>
                    <button class="shop-button primary" id="cart-review" type="submit" form="cart-selection" name="review" value="1"
                            @disabled($selectedCount === 0 || $overLimit)>Review checkout ({{ $selectedCount }} {{ \Illuminate\Support\Str::plural('item', $selectedCount) }})</button>
                    <button class="cart-update" id="cart-save-selection" type="submit" form="cart-selection">Save selection</button>
                    <p class="cart-selection-error" id="cart-selection-error" role="alert" hidden>Could not save the selection. Try Save selection again.</p>
                </aside>
            </div>
        @else
            <div class="shopping-cart-empty">
                <i class="bi bi-bag" aria-hidden="true"></i>
                <h2>Your cart is empty</h2>
                <a class="shop-button primary" href="{{ route('client.catalog') }}">Browse flowers</a>
            </div>
        @endif
    </section>
@endsection
