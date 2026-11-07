<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted order domain data; mutations are coordinated by the order service.
 */
#[Fillable(['order_id', 'status', 'note', 'actor_id', 'actor_name'])]
class OrderHistory extends Model
{
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'actor_id' => 'integer',

        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
