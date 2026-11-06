<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class ClientRequest extends FormRequest
{
    /**
     * Restrict supplier-managed client changes to active operational accounts.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (bool) $user->is_active
            && ! (bool) $user->must_change_password
            && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true);
    }

    /**
     * Canonicalize editable contact values without ever trimming or transforming passwords.
     */
    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['name', 'email', 'phone', 'address'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        if (isset($values['email'])) {
            $values['email'] = mb_strtolower($values['email']);
        }
        $this->merge($values);
    }

    /**
     * Validate global case-insensitive email ownership, including operational users.
     */
    protected function emailRules(?int $ignoreUserId = null): array
    {
        return ['bail', 'required', 'string', 'email', 'max:254',
            function (string $attribute, mixed $value, \Closure $fail) use ($ignoreUserId): void {
                $query = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))]);
                if ($ignoreUserId !== null) {
                    $query->where('id', '!=', $ignoreUserId);
                }
                if ($query->exists()) {
                    $fail('This email address is already in use.');
                }
            },
        ];
    }

    /**
     * Forbid changes to account privileges, verification, identity, and registration dates.
     */
    protected function protectedRules(): array
    {
        $rules = [];
        foreach (['id', 'dbId', 'code', 'user_id', 'role', 'is_active', 'must_change_password',
            'registered', 'registered_at', 'created_at', 'updated_at', 'email_verified_at', 'remember_token'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
