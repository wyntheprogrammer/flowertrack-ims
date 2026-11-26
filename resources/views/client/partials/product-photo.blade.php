@php($unit = strtolower($productType ?? $flower->product_type))

@if ($flower?->image_path && Storage::disk('public')->exists($flower->image_path))
    <img src="{{ Storage::disk('public')->url($flower->image_path) }}" loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" decoding="async" alt="{{ ($thumbnail ?? false) ? '' : $flower->name }}">
@else
    <div class="catalog-photo-placeholder catalog-photo-placeholder-{{ $unit }}">
        <i class="bi {{ $unit === 'stem' ? 'bi-flower3' : 'bi-gift' }}" aria-hidden="true"></i>
        <small @class(['visually-hidden' => $thumbnail ?? false])>Photo unavailable</small>
    </div>
@endif
