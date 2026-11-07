<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted order domain data; mutations are coordinated by the order service.
 */
#[Fillable(['order_id', 'client_id', 'code', 'client_name', 'client_code', 'items', 'subtotal_cents', 'fee_cents', 'total_cents', 'completed_at'])]
class SalesRecord extends Model
{
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'client_id' => 'integer',
            'items' => 'array',
            'subtotal_cents' => 'integer',
            'fee_cents' => 'integer',
            'total_cents' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
