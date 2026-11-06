<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateClientRequest extends ClientRequest
{
    /**
     * Validate profile edits without exposing password or role changes.
     */
    public function rules(): array
    {
        return [
            ...$this->protectedRules(),
            'name' => ['required', 'string', 'max:120'],
            'email' => $this->emailRules((int) $this->route('client')->user_id),
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
        ];
    }
}
