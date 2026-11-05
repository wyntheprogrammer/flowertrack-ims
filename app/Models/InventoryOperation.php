<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Idempotency receipt and actor snapshot for one atomic inventory operation.
 */
#[Fillable(['request_key', 'payload_hash', 'flower_id', 'kind', 'actor_id', 'actor_name', 'result'])]
class InventoryOperation extends Model
{
    protected $table = 'inventory_operations';

    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'operation_id');
    }
}
