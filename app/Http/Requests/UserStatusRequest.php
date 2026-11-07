<?php

namespace App\Http\Requests;

use App\Services\UserService;

class UserStatusRequest extends UserAccountRequest
{
    public function rules(): array
    {
        return UserService::rules('status');
    }
}
