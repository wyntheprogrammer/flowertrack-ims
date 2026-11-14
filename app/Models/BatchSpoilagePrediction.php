<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_id',
    'as_of_date',
    'forecast_end_date',
    'opening_quantity',
    'base_unit',
    'model_version',
    'input_hash',
    'status',
    'method',
    'predicted_fraction',
    'predicted_quantity',
    'risk_level',
    'history_days',
    'message',
    'metadata',
    'generated_at',
])]
class BatchSpoilagePrediction extends Model
{
    protected $table = 'batch_spoilage_predictions';

    // Convert database values into appropriate PHP types.
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'forecast_end_date' => 'date',
            'opening_quantity' => 'integer',
            'predicted_fraction' => 'decimal:10',
            'predicted_quantity' => 'decimal:6',
            'history_days' => 'integer',
            'metadata' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    // The inventory batch this prediction belongs to.
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }
}