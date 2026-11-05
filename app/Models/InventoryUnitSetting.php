<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stem receiving factors for future receipts; Bouquet always uses factor one.
 */
#[Fillable(['flower_id', 'bunch_factor', 'bundle_factor', 'box_factor'])]
class InventoryUnitSetting extends Model
{
    protected $table = 'inventory_unit_settings';

    protected function casts(): array
    {
        return [
            'bunch_factor' => 'integer',
            'bundle_factor' => 'integer',
            'box_factor' => 'integer',
        ];
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }
}
