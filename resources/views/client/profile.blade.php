@extends('layouts.client-account')
@section('title', 'My profile')
@section('account-content')
    <i class="bi bi-person-circle account-icon"></i><h2>Account details</h2>
    <p>Contact the shop to update your account details.</p>
    <dl class="profile-details">
        @foreach (['Name' => auth()->user()->name, 'Email' => auth()->user()->email] as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <a href="{{ route('client.cart') }}">View your session cart <i class="bi bi-arrow-right"></i></a>
@endsection
