<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted order domain data; mutations are coordinated by the order service.
 */
#[Fillable(['flower_id', 'price_cents'])]
class FlowerPrice extends Model
{
    protected function casts(): array
    {
        return [
            'flower_id' => 'integer',
            'price_cents' => 'integer',
        ];
    }

    public function flower(): BelongsTo
    {
        return $this->belongsTo(Flower::class);
    }
}
