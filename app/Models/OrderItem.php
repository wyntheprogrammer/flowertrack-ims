<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted order domain data; mutations are coordinated by the order service.
 */
#[Fillable(['order_id', 'flower_id', 'flower_code', 'name', 'product_type', 'base_unit', 'quantity', 'unit_price_cents', 'subtotal_cents', 'fulfillment_key'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'flower_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'subtotal_cents' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }
}
