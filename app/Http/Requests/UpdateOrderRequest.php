<?php

namespace App\Http\Requests;

class UpdateOrderRequest extends OrderRequest
{
    /** Optimistic edits cannot assign price or workflow state. */
    public function rules(): array
    {
        return [...$this->profileRules(), ...$this->protectedRules(),
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'request_key' => ['prohibited'],
        ];
    }
}
