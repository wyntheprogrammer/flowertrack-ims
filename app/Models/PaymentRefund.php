<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'payment_id', 'amount_cents', 'reason', 'actor_id', 'actor_name'])]
class PaymentRefund extends Model
{
    protected function casts(): array
    {
        return ['payment_id' => 'integer', 'amount_cents' => 'integer', 'actor_id' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'payment_id');
    }
}