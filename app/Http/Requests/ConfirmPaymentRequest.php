<?php

namespace App\Http\Requests;

use App\Services\SalesPaymentService;

class ConfirmPaymentRequest extends SalesFinanceRequest
{
    public function rules(): array
    {
        return SalesPaymentService::rules('confirm');
    }
}