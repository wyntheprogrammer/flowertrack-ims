<a class="flower-collection" href="{{ route('client.catalog', ['type' => $type]) }}">
    <img src="{{ asset('images/'.$image) }}" width="1040" height="400" loading="lazy" decoding="async" alt="">
    <div class="collection-copy">
        <h3>{{ $name }}</h3>
        <p>{{ $description }}</p>
        <span class="collection-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
    </div>
</a>
