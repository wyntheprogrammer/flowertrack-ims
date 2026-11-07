<?php

namespace App\Http\Requests;

use App\Services\UserService;

class UpdateUserRequest extends UserAccountRequest
{
    public function rules(): array
    {
        return UserService::rules('update');
    }
}
