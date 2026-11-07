<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Persisted order domain data; mutations are coordinated by the order service.
 */
#[Fillable(['code', 'request_key', 'request_hash', 'client_id', 'client_code', 'client_name', 'client_email', 'method', 'address', 'notes', 'status', 'subtotal_cents', 'fee_cents', 'total_cents', 'stocked_out', 'completed_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'subtotal_cents' => 'integer',
            'fee_cents' => 'integer',
            'total_cents' => 'integer',
            'stocked_out' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderHistory::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(SalesRecord::class);
    }
}
