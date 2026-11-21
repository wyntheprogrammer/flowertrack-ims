@extends('layouts.client')

@section('title', 'Change your password')
@section('body-class', 'shop-auth')

@push('page-assets')
    @vite('resources/css/client/sign-in.css')
@endpush

@section('content')
    <section class="shop-width shop-auth-page" aria-labelledby="change-password-title" data-shop-back-top-threshold>
        <h1 id="change-password-title">Change your password</h1>
        <p>Replace your temporary password to continue. Use at least 12 characters.</p>
        <form class="shop-auth-form" method="post" action="{{ route('password.update') }}">
            @csrf
            @method('PUT')

            <div>
                <label class="form-label" for="current_password">Current password</label>
                <input class="form-control" id="current_password" name="current_password" type="password"
                       autocomplete="current-password" required @error('current_password') aria-invalid="true" @enderror>
            </div>
            <div>
                <label class="form-label" for="password">New password</label>
                <input class="form-control" id="password" name="password" type="password"
                       autocomplete="new-password" minlength="12" required @error('password') aria-invalid="true" @enderror>
            </div>
            <div>
                <label class="form-label" for="password_confirmation">Confirm new password</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" minlength="12" required>
            </div>
            <button class="shop-button primary" type="submit">Update password</button>
        </form>
    </section>
@endsection
