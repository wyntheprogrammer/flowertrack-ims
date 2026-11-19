@extends('layouts.client-account')
@section('title', 'Order tracking')
@section('account-content')
    <i class="bi bi-truck account-icon"></i><h2>Fulfillment guide</h2>
    <p>Personal tracking will be available with customer sign-in. These are the usual stages for each fulfillment method.</p>
    @foreach (['Customer pickup' => ['Pending', 'Confirmed', 'Processing', 'Ready for Pickup', 'Completed'], 'Delivery' => ['Pending', 'Confirmed', 'Processing', 'Ready for Delivery', 'Out for Delivery', 'Completed']] as $method => $steps)
        <details class="tracking-guide" @if($loop->first) open @endif><summary>{{ $method }} <i class="bi bi-chevron-down"></i></summary>
            <ol>@foreach ($steps as $step)<li>{{ $step }}</li>@endforeach</ol>
        </details>
    @endforeach
@endsection
