<ul class="history-items" role="list" aria-label="Ordered products">
    @foreach ($items as $item)
        <li class="history-item">
            @include('client.partials.history-item-photo', ['item' => $item])
            <div>
                <div class="history-item-name">{{ $item->name }}</div>
                <span class="history-item-quantity">Quantity {{ number_format($item->quantity) }}</span>
            </div>
        </li>
    @endforeach
</ul>
