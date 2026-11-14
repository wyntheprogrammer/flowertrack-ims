<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_id',
    'flower_id',
    'observation_date',
    'harvest_date',
    'received_date',
    'storage_condition',
    'base_unit',
    'opening_quantity',
    'spoiled_quantity',
    'released_quantity',
    'closing_quantity',
    'is_complete',
    'exclusion_reason',
])]
class BatchDailyObservation extends Model
{
    protected $table = 'batch_daily_observations';

    // Convert database values into the appropriate PHP types.
    protected function casts(): array
    {
        return [
            'observation_date' => 'date',
            'harvest_date' => 'date',
            'received_date' => 'date',
            'opening_quantity' => 'integer',
            'spoiled_quantity' => 'integer',
            'released_quantity' => 'integer',
            'closing_quantity' => 'integer',
            'is_complete' => 'boolean',
        ];
    }

    // The inventory batch this daily record belongs to.
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    // The flower associated with this daily record.
    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }
}