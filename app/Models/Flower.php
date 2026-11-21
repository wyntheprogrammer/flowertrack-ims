<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'product_type',
    'category',
    'minimum_stock',
    'description',
    'image_path',
    'status',
])]
class Flower extends Model
{
    use HasFactory;

    public const TYPE_STEM = 'Stem';

    public const TYPE_BOUQUET = 'Bouquet';

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_INACTIVE = 'Inactive';

    public static function storefront(): Builder
    {
        return self::query()->where('flowers.status', self::STATUS_ACTIVE)
            ->leftJoin('flower_prices', 'flower_prices.flower_id', '=', 'flowers.id')
            ->select(['flowers.id', 'flowers.name', 'flowers.category', 'flowers.description', 'flowers.product_type', 'flowers.image_path', 'flower_prices.price_cents'])
            ->selectSub(InventoryBatch::query()->selectRaw('COALESCE(SUM(remaining_quantity), 0)')
                ->whereColumn('flower_id', 'flowers.id')->where('spoiled', false), 'on_hand')
            ->selectSub(InventoryReservation::query()->selectRaw('COALESCE(SUM(quantity), 0)')
                ->whereColumn('flower_id', 'flowers.id')->where('status', 'Active'), 'reserved');
    }

    public function availableQuantity(): int
    {
        return max(0, (int) $this->on_hand - (int) $this->reserved);
    }

    public function cartQuantityLimit(): int
    {
        $price = (int) $this->price_cents;

        return $price > 0 ? min($this->availableQuantity(), intdiv(Money::MAX_CENTS, $price)) : 0;
    }

    protected function casts(): array
    {
        return ['minimum_stock' => 'integer'];
    }
}
