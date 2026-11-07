<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OrderActionRequest extends OrderRequest
{
    /** Validate the requested order status change. */
    public function rules(): array
    {
        $rules = $this->protectedRules();

        $rules['status'] = [
            'required',
            Rule::in([
                'Confirmed',
                'Processing',
                'Ready for Pickup',
                'Ready for Delivery',
                'Completed',
                'Cancelled',
            ]),
        ];

        return [
            ...$rules,
            'version' => [
                'required',
                'string',
                'size:64',
                'regex:/\A[a-fA-F0-9]{64}\z/',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:500',
                'required_if:status,Cancelled',
            ],
            'collected' => [
                'required_if:status,Completed',
                'boolean',
                'accepted_if:status,Completed',
            ],
            'items' => ['prohibited'],
            'client_id' => ['prohibited'],
            'method' => ['prohibited'],
            'address' => ['prohibited'],
            'notes' => ['prohibited'],
        ];
    }
}