@extends('layouts.client-account')
@section('title', 'My Payments')
@section('body-class', 'shop-orders')
@section('account-content')
    @include('client.partials.history-filters', [
        'action' => route('client.payments'),
        'searchLabel' => 'My payments',
        'searchPlaceholder' => 'Product name, order or payment reference',
    ])

    @forelse ($payments as $payment)
        <article class="order-entry">
            <div>
                <h2><a href="{{ route('client.orders.show', $payment->order) }}">{{ $payment->order->code }}</a></h2>
                {{ $payment->method }} ·
                <time datetime="{{ $payment->created_at->toIso8601String() }}">{{ $payment->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time>
                <p class="history-payment-reference">Payment {{ $payment->code }}
                    @if ($payment->reference)
                        <span>Reference {{ $payment->reference }}</span>
                    @endif
                </p>
            </div>
            <strong><span class="visually-hidden">Original payment </span>₱{{ number_format($payment->amount_cents / 100, 2) }}</strong>
            <div class="order-entry-actions"><span>{{ $payment->status === 'Pending' ? 'Pending confirmation' : $payment->status }}</span></div>
            @include('client.partials.history-items', ['items' => $payment->order->items])
            @foreach ($payment->refunds as $refund)
                <p class="payment-refund">Refund issued · ₱{{ number_format($refund->amount_cents / 100, 2) }}
                    @if ($refund->created_at)
                        · <time datetime="{{ $refund->created_at->toIso8601String() }}">{{ $refund->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time>
                    @endif
                </p>
            @endforeach
        </article>
    @empty
        @if ($payments->total() > 0)
            <p>There are no payments on this page.</p>
        @elseif ($filters->parameters())
            <p>No payments match your filters.</p>
        @else
            <p>No payments recorded yet</p>
            <a class="shop-action" href="{{ route('client.orders') }}">View my orders</a>
        @endif
    @endforelse
    {{ $payments->links('pagination::simple-bootstrap-5') }}
@endsection
