<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class InventoryRequest extends FormRequest
{
    /**
     * Require an active operational user who has replaced their temporary password.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && (bool) $user->is_active
            && ! (bool) $user->must_change_password
            && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true);
    }
}
