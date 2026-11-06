<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplier-managed client contact details linked to one authoritative user account.
 * Update workflows must lock the User before this profile to share a consistent lock order.
 */
#[Fillable(['code', 'user_id', 'phone', 'address'])]
class Client extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
