@extends('layouts.client')

@section('title', 'Customer sign-in')
@section('body-class', 'shop-auth')

@push('page-assets')
    @vite('resources/css/client/sign-in.css')
@endpush

@section('content')
    <section class="shop-width shop-auth-page" aria-labelledby="sign-in-title" data-shop-back-top-threshold>
        <h1 id="sign-in-title">Customer sign-in</h1>
        @auth
            <p>You’re signed in to the staff portal in this browser. Sign out to use a customer account.</p>
            <form class="shop-auth-form" method="post" action="{{ route('client.login.switch') }}">
                @csrf
                <input type="hidden" name="continue" value="{{ $destination }}">
                <button class="shop-button primary" type="submit">Sign out of staff account</button>
            </form>
        @else
            <form class="shop-auth-form" method="post" action="{{ route('client.login.store') }}">
                @csrf
                <input type="hidden" name="continue" value="{{ $destination }}">

                <div>
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}"
                           autocomplete="username" maxlength="254" required @error('email') aria-invalid="true" @enderror>
                </div>
                <div>
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password"
                           autocomplete="current-password" required @error('password') aria-invalid="true" @enderror>
                </div>
                <button class="shop-button primary" type="submit">Sign in</button>
            </form>
            <p class="shop-auth-note">Contact Gemma’s Flower Shop for account access.</p>
            <p class="shop-auth-note"><a href="#shop-footer-contact">Forgot Password?</a> Contact the shop for password reset assistance.</p>
        @endauth
    </section>
@endsection
