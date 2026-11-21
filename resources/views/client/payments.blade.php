@extends('layouts.client-account')
@section('title', 'My payments')
@section('account-content')
    <i class="bi bi-wallet2 account-icon"></i><h2>Payment history</h2>
    <div class="account-records"><table><caption class="visually-hidden">Your payment history</caption><thead><tr><th>Order</th><th>Amount</th><th>Payment status</th></tr></thead>
        <tbody><tr><td colspan="3">Payment history is currently unavailable in the storefront.</td></tr></tbody></table></div>
    <dl class="account-facts"><dt>Order payment</dt><dd>One full payment covers the order total.</dd><dt>Verification</dt><dd>A recorded payment stays pending until an administrator verifies it.</dd></dl>
@endsection
