<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreClientRequest extends ClientRequest
{
    /**
     * Validate a contact profile and a temporary password suitable for bcrypt.
     */
    public function rules(): array
    {
        return [
            ...$this->protectedRules(),
            'name' => ['required', 'string', 'max:120'],
            'email' => $this->emailRules(),
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'password' => ['bail', 'required', 'string', Password::min(12),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The temporary password must not exceed 72 bytes.');
                    }
                    if (is_string($value) && str_contains($value, "\0")) {
                        $fail('The temporary password cannot contain a null character.');
                    }
                },
            ],
        ];
    }
}
