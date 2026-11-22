@extends('layouts.client-account')
@section('title', 'My Profile')
@section('body-class', 'shop-orders')
@section('account-content')
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <section aria-labelledby="account-details-title">
        <h2 id="account-details-title">Account details</h2>
        <dl class="profile-details">
            @foreach (['Name' => $user->name, 'Sign-in email' => $user->email, 'Phone number' => $client?->phone, 'Address on file' => $client?->address] as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : 'Not on file' }}</dd></div>
            @endforeach
        </dl>
        <p class="mb-0">Contact the shop to correct your account details.</p>
        <a class="account-text-link" href="{{ config('services.facebook.shop_url') }}">Contact the shop</a>
    </section>

    <section class="profile-security" aria-labelledby="profile-security-title">
        <h2 id="profile-security-title">Security</h2>
        <a class="shop-button primary" href="{{ route('password.change') }}">Change password</a>
    </section>
@endsection
