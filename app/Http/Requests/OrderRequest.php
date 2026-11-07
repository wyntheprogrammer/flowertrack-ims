<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class OrderRequest extends FormRequest
{
    /** Authorize active operational accounts with an updated password. */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (bool) $user->is_active && ! (bool) $user->must_change_password
            && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true);
    }

    /** Validate item identities and quantities; prices and financial fields are server-owned. */
    protected function profileRules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1', 'exists:clients,id'],
            'method' => ['required', Rule::in(['Customer Pickup', 'Delivery'])],
            'address' => ['nullable', 'string', 'max:500', 'required_if:method,Delivery'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:flower_id,quantity'],
            'items.*.flower_id' => ['required', 'integer', 'min:1', 'exists:flowers,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /** Reject direct state, price, payment, and physical-stock assignments. */
    protected function protectedRules(): array
    {
        $rules = [];
        foreach (['id', 'code', 'status', 'payment', 'paidCents', 'paid_cents', 'payment_status',
            'subtotal_cents', 'fee_cents', 'total_cents', 'subtotalCents', 'deliveryFeeCents', 'totalCents',
            'price', 'price_cents', 'unit_price_cents', 'unitPrice', 'unitPriceCents', 'subtotal', 'total',
            'fee', 'deliveryFee', 'paid', 'amount', 'amount_cents', 'delivery_fee', 'stocked_out', 'stockedOut',
            'remaining', 'remaining_quantity', 'onHand', 'reserved', 'available', 'created_at', 'updated_at',
            'completed_at', 'client_name', 'client_code', 'client_email'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
