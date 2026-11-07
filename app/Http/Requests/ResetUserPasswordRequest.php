<?php

namespace App\Http\Requests;

use App\Services\UserService;

class ResetUserPasswordRequest extends UserAccountRequest
{
    public function rules(): array
    {
        return UserService::rules('reset');
    }
}
