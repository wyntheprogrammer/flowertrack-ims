@extends('layouts.client-account')
@section('title', 'Order Tracking')
@section('body-class', 'shop-orders shop-tracking')
@section('account-content')
    @isset($orders)
        @forelse ($orders as $choice)
            <article class="order-entry">
                <div><h2>{{ $choice->code }}</h2>{{ $choice->method }}</div>
                <a href="{{ route('client.tracking', $choice) }}">Track order<span class="visually-hidden"> {{ $choice->code }}</span></a>
            </article>
        @empty
            <p>You haven’t placed an order yet</p>
            <a class="shop-action" href="{{ route('client.catalog') }}">Browse flowers</a>
        @endforelse
        {{ $orders->links('pagination::simple-bootstrap-5') }}
    @else
        <header>
            <h2>{{ $order->code }}</h2>
            <p>{{ $order->method }}</p>
            <strong class="tracking-status">{{ $status }}</strong>
            @switch($status)
                @case('Ready for Pickup')
                    <p>Collect your flowers at Laon Laan – Juaning Consolidation Area, Barangay 471, Sampaloc, Manila 1008.</p>
                    <a href="https://www.google.com/maps/dir/?api=1&amp;destination=14.6114375%2C120.9863871">Get directions</a>
                    @break
                @case('Delivery scheduled')
                    <p>Be available to receive your flowers. Scheduled for {{ $schedule->timezone('Asia/Manila')->format('g:i A · M j, Y') }} PHT.</p>
                    @break
                @case('Out for Delivery')
                    <p>Please be available at your delivery address.</p>
                    @break
                @case('Pending')
                    <p>Check back here for confirmation from the shop.</p>
                    @break
                @case('Delivery attempt failed')
                    <p>Check back for a new delivery schedule.</p>
                    @break
            @endswitch
        </header>
        @if ($events->isNotEmpty())
            <h3 class="mt-4">Fulfillment updates</h3>
            <ol class="tracking-events">
                @foreach ($events as $event)
                    <li>
                        <strong>{{ $event['label'] }}</strong>
                        @if ($event['at'])
                            <time datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time>
                        @endif
                        @if ($event['slot'])
                            <span>Scheduled for {{ $event['slot']->timezone('Asia/Manila')->format('g:i A · M j, Y') }} PHT</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
        <a href="{{ route('client.orders.show', $order) }}">View order details</a>
    @endisset
@endsection
