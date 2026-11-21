@extends('layouts.client-account')
@section('title', 'My Payments')
@section('body-class', 'shop-orders')
@section('account-content')
    @forelse ($payments as $payment)
        <article class="order-entry">
            <div>
                <h2><a href="{{ route('client.orders.show', $payment->order) }}">{{ $payment->order->code }}</a></h2>
                {{ $payment->method }} ·
                <time datetime="{{ $payment->created_at->toIso8601String() }}">{{ $payment->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time>
            </div>
            <strong><span class="visually-hidden">Original payment </span>₱{{ number_format($payment->amount_cents / 100, 2) }}</strong>
            <div class="order-entry-actions"><span>{{ $payment->status === 'Pending' ? 'Pending confirmation' : $payment->status }}</span></div>
            @foreach ($payment->refunds as $refund)
                <p class="payment-refund">Refund issued · ₱{{ number_format($refund->amount_cents / 100, 2) }}
                    @if ($refund->created_at)
                        · <time datetime="{{ $refund->created_at->toIso8601String() }}">{{ $refund->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time>
                    @endif
                </p>
            @endforeach
        </article>
    @empty
        <p>No payments recorded yet</p>
        <a class="shop-action" href="{{ route('client.orders') }}">View my orders</a>
    @endforelse
    {{ $payments->links('pagination::simple-bootstrap-5') }}
@endsection
