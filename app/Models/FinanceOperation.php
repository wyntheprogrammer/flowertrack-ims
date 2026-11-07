<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['request_key', 'request_hash', 'reference_key', 'kind', 'actor_id', 'order_id', 'payment_id', 'flower_id', 'result_id', 'metadata'])]
class FinanceOperation extends Model
{
    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'order_id' => 'integer', 'payment_id' => 'integer',
            'flower_id' => 'integer', 'result_id' => 'integer', 'metadata' => 'array'];
    }
}