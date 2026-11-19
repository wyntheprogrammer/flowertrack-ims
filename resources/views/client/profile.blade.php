@extends('layouts.client-account')
@section('title', 'My profile')
@section('account-content')
    <i class="bi bi-person-circle account-icon"></i><h2>Account details</h2>
    <p>Customer sign-in is pending. Personal details and profile editing will require a connected customer account.</p>
    <dl class="profile-details">
        @foreach (['Name', 'Email', 'Contact number', 'Delivery address'] as $field)
            <div><dt>{{ $field }}</dt><dd><span aria-hidden="true">—</span><span class="visually-hidden">Unavailable until sign-in</span></dd></div>
        @endforeach
    </dl>
    <a href="{{ route('client.cart') }}">View your session cart <i class="bi bi-arrow-right"></i></a>
@endsection
