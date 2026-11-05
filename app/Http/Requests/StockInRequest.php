<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class StockInRequest extends InventoryRequest
{
    /**
     * Validate receipt inputs; the service calculates and snapshots conversion factors.
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();

        return [
            'request_key' => ['required', 'uuid'],
            'flower_id' => ['required', 'integer', 'min:1', 'exists:flowers,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'unit' => ['required', Rule::in(['Stem', 'Bunch', 'Bundle', 'Box', 'Bouquet'])],
            'received' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'harvest' => ['required', 'date_format:Y-m-d', 'before_or_equal:received'],
            'expiry' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:harvest'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'factor' => ['prohibited'],
        ];
    }
}
