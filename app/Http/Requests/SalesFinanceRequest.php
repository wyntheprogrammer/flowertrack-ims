<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class SalesFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->is_active && !$user->must_change_password
            && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('request_key'))) {
            $this->merge(['request_key' => Str::lower($this->input('request_key'))]);
        }
    }
}