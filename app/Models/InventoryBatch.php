<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Received batch with its original unit conversion snapshot.
 */
#[Fillable(['flower_id', 'code', 'received_date', 'harvest_date', 'expiry_date', 'received_quantity', 'receiving_unit', 'conversion_factor', 'initial_quantity', 'remaining_quantity', 'spoiled', 'remarks'])]
class InventoryBatch extends Model
{
    protected $table = 'inventory_batches';

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'harvest_date' => 'date',
            'expiry_date' => 'date',
            'received_quantity' => 'integer',
            'conversion_factor' => 'integer',
            'initial_quantity' => 'integer',
            'remaining_quantity' => 'integer',
            'spoiled' => 'boolean',
        ];
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'batch_id');
    }
}
