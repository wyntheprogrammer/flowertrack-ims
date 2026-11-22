@extends($required ? 'layouts.client' : 'layouts.client-account')

@section('title', 'Change your password')
@section('body-class', $required ? 'shop-auth' : 'shop-orders')
@section('field-errors', 'true')

@push('page-assets')
    @vite('resources/css/client/sign-in.css')
@endpush

@section('password-form')
    @if ($required)
        <p>Replace your temporary password to continue. Use at least 12 characters.</p>
    @else
        <p>Use at least 12 characters for your new password.</p>
    @endif
    <form class="shop-auth-form" method="post" action="{{ route('password.update') }}">
        @csrf
        @method('PUT')

        @foreach (['current_password' => 'Current password', 'password' => 'New password', 'password_confirmation' => 'Confirm new password'] as $field => $label)
            <div>
                <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                <input class="form-control" id="{{ $field }}" name="{{ $field }}" type="password" required
                       autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}"
                       @if ($field !== 'current_password') minlength="12" @endif
                       @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                @error($field)
                    <p class="invalid-feedback d-block" id="{{ $field }}-error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
        <div class="shop-auth-actions">
            <button class="shop-button primary" type="submit">Save password</button>
            @unless ($required)
                <a class="account-text-link" href="{{ route('client.profile') }}">Back to My Profile</a>
            @endunless
        </div>
    </form>
@endsection

@if ($required)
    @section('content')
        <section class="shop-width shop-auth-page" aria-labelledby="change-password-title">
            <header data-shop-back-top-threshold>
                <h1 id="change-password-title" tabindex="-1">Change your password</h1>
            </header>
            @yield('password-form')
        </section>
    @endsection
@else
    @section('account-content')
        @yield('password-form')
    @endsection
@endif
