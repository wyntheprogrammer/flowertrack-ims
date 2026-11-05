<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StockOutRequest extends InventoryRequest
{
    /**
     * Limit manual deductions to other use and human-confirmed spoilage.
     */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'flower_id' => ['required', 'integer', 'min:1', 'exists:flowers,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'reason' => ['required', Rule::in(['Other use', 'Spoilage'])],
            'batch_id' => ['nullable', 'integer', 'min:1', 'exists:inventory_batches,id'],
            'remarks' => [Rule::requiredIf(fn () => $this->input('reason') === 'Spoilage' || $this->filled('batch_id')), 'nullable', 'string', 'max:5000'],
        ];
    }
}
