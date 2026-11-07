<?php

namespace App\Http\Requests;

use App\Services\UserService;

class StoreUserRequest extends UserAccountRequest
{
    public function rules(): array
    {
        return UserService::rules('store');
    }
}
