<?php

namespace App\Http\Requests;

class UpdateInventoryUnitsRequest extends InventoryRequest
{
    /**
     * Bound future stem receiving conversions without changing existing receipts.
     */
    public function rules(): array
    {
        return [
            'bunch_factor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'bundle_factor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'box_factor' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
