<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user || !$user->is_active || $user->must_change_password) return false;
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true)) return true;
        $delivery = $this->route('delivery');
        return $user->role === User::ROLE_DELIVERY
            && $delivery instanceof \App\Models\Delivery
            && (int) $delivery->assigned_to === (int) $user->id
            && in_array($this->input('action'), ['deliver', 'fail'], true);
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'action' => ['required', Rule::in(['schedule', 'assign', 'dispatch', 'deliver', 'fail', 'cancel'])],
            'scheduled_at' => ['exclude_unless:action,schedule', 'required', 'date_format:Y-m-d\TH:i:sP'],
            'assigned_to' => ['exclude_unless:action,assign', 'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', User::ROLE_DELIVERY)->where('is_active', true))],
            'reason' => ['required_unless:action,dispatch', 'nullable', 'string', 'max:500'],
            'recipient_name' => ['exclude_unless:action,deliver', 'required', 'string', 'max:120'],
            'proof' => ['exclude_unless:action,deliver', 'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'order_id' => ['prohibited'], 'status' => ['prohibited'],
            'proof_path' => ['prohibited'], 'delivered_at' => ['prohibited'], 'stocked_out' => ['prohibited'],
        ];
    }
}
