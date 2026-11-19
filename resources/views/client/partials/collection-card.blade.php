<details class="flower-collection">
    <summary id="{{ $id }}" aria-label="{{ $name }} collection details">
        <img src="{{ asset('images/shop-'.$id.'.webp') }}" width="1040" height="400" loading="lazy" decoding="async" alt="">
        <span class="collection-copy">
            <span class="collection-name">{{ $name }}</span>
            <span class="collection-description">{{ $description }}</span>
            <span class="collection-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
        </span>
    </summary>
    <p>{{ $detail }} <a href="{{ route('client.catalog', ['type' => $type]) }}">Browse {{ $id }} <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
</details>
