@extends('layouts.client-account')
@section('title', 'My Orders')
@section('body-class', 'shop-orders')
@section('account-content')
    @include('client.partials.history-filters', [
        'action' => route('client.orders'),
        'searchLabel' => 'My orders',
        'searchPlaceholder' => 'Product name or order number',
    ])

    @forelse ($orders as $order)
        <article class="order-entry">
            <div>
                <h2>{{ $order->code }}</h2>
                <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time> · {{ $order->method }}
            </div>
            <strong>₱{{ number_format($order->total_cents / 100, 2) }}</strong>
            <div class="order-entry-actions">
                <span>{{ $order->status }}</span>
                <a href="{{ route('client.orders.show', $order) }}">View order<span class="visually-hidden"> {{ $order->code }}</span></a>
            </div>
            @include('client.partials.history-items', ['items' => $order->items])
        </article>
    @empty
        @if ($orders->total() > 0)
            <p>There are no orders on this page.</p>
        @elseif ($filters->parameters())
            <p>No orders match your filters.</p>
        @else
            <p>You haven’t placed an order yet</p>
            <a class="shop-action" href="{{ route('client.catalog') }}">Browse flowers</a>
        @endif
    @endforelse
    {{ $orders->links('pagination::simple-bootstrap-5') }}
@endsection
