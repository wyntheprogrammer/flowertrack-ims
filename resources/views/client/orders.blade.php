@extends('layouts.client-account')
@section('title', 'My orders')
@section('account-content')
    <i class="bi bi-receipt account-icon"></i><h2>Order history</h2>
    <div class="account-records"><table><caption class="visually-hidden">Your order history</caption><thead><tr><th>Order</th><th>Fulfillment</th><th>Payment</th><th>Total</th></tr></thead>
        <tbody><tr><td colspan="4">Order history is currently unavailable in the storefront.</td></tr></tbody></table></div>
    <a class="shop-action" href="{{ route('client.catalog') }}">Explore the catalog</a>
@endsection
