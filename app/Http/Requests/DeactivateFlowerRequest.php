<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DeactivateFlowerRequest extends FormRequest
{
    /**
     * Restrict catalog changes to active operational users with an updated password.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && (bool) $user->is_active
            && ! (bool) $user->must_change_password
            && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true);
    }

    /**
     * Validate this catalog operation against the approved database and upload limits.
     */
    public function rules(): array
    {
        return [];
    }
}
