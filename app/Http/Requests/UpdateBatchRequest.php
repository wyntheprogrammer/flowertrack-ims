<?php

namespace App\Http\Requests;

class UpdateBatchRequest extends InventoryRequest
{
    /**
     * Allow metadata only; stock and identity changes belong to their existing workflows.
     */
    public function rules(): array
    {
        $rules = [
            'request_key' => ['required', 'uuid'],
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'harvest' => ['required', 'date_format:Y-m-d'],
            'expiry' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:harvest'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
        foreach ([
            'id', 'batch_id', 'dbId', 'flower', 'flower_id', 'received', 'received_date',
            'quantity', 'received_quantity', 'unit', 'receiving_unit', 'factor', 'conversion_factor',
            'initial', 'initial_quantity', 'remaining', 'remaining_quantity', 'spoiled', 'status',
            'code', 'product_type', 'itemType', 'baseUnit', 'minimum_stock', 'harvest_date', 'expiry_date',
        ] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
