<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted order domain data; mutations are coordinated by the order service.
 */
#[Fillable(['order_id', 'code', 'method', 'amount_cents', 'status', 'reference', 'remarks', 'confirmed_by', 'confirmed_at'])]
class OrderPayment extends Model
{
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'confirmed_by' => 'integer',
            'amount_cents' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
