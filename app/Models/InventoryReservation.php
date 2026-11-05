<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Order reservation foundation; only Active quantities reduce available stock.
 */
#[Fillable(['flower_id', 'reference', 'quantity', 'status'])]
class InventoryReservation extends Model
{
    protected $table = 'inventory_reservations';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }
}
