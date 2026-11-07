<?php

namespace App\Http\Requests;

use App\Services\SalesPaymentService;

class StorePaymentRequest extends SalesFinanceRequest
{
    public function rules(): array
    {
        return SalesPaymentService::rules('record');
    }
}