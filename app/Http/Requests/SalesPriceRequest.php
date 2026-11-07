<?php

namespace App\Http\Requests;

use App\Services\SalesPaymentService;

class SalesPriceRequest extends SalesFinanceRequest
{
    public function rules(): array
    {
        return SalesPaymentService::rules('price');
    }
}