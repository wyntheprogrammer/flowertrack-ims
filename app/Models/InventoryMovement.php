<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger. Write through the inventory service, never edit historical rows.
 */
#[Fillable(['operation_id', 'batch_id', 'flower_id', 'type', 'quantity', 'remarks', 'metadata'])]
class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'operation_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }
}
