<?php

namespace App\Http\Requests;

use App\Services\SalesPaymentService;

class RefundPaymentRequest extends SalesFinanceRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->role === \App\Models\User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return SalesPaymentService::rules('refund');
    }
}