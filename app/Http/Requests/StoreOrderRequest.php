<?php

namespace App\Http\Requests;

class StoreOrderRequest extends OrderRequest
{
    /** Require a stable creation UUID for safe network retries. */
    public function rules(): array
    {
        return [...$this->profileRules(), ...$this->protectedRules(), 'request_key' => ['required', 'uuid']];
    }
}
