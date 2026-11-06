<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateClientStatusRequest extends ClientRequest
{
    /**
     * Accept an explicit desired state rather than a retry-unsafe toggle.
     */
    public function rules(): array
    {
        return [
            ...$this->protectedRules(),
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'name' => ['prohibited'], 'email' => ['prohibited'],
            'phone' => ['prohibited'], 'address' => ['prohibited'],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'],
        ];
    }
}
