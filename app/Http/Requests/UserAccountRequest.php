<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class UserAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->role === User::ROLE_ADMIN
            && $user->is_active && !$user->must_change_password;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if (is_string($this->input('name'))) $values['name'] = trim($this->input('name'));
        if (is_string($this->input('email'))) $values['email'] = Str::lower(trim($this->input('email')));
        if ($values !== []) $this->merge($values);
    }
}
