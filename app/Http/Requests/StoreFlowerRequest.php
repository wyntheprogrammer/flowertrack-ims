<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlowerRequest extends FormRequest
{
    /**
     * Restrict catalog changes to active operational users with an updated password.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && (bool) $user->is_active
            && ! (bool) $user->must_change_password
            && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true);
    }

    /**
     * Validate this catalog operation against the approved database and upload limits.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'product_type' => ['required', Rule::in(['Stem', 'Bouquet'])],
            'category' => ['required', Rule::in(['Roses', 'Sunflowers', 'Tulips', 'Lilies', 'Carnations', 'Gerberas', 'Fillers', 'Orchids', 'Mixed Flowers', 'Others'])],
            'minimum_stock' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'description' => ['required', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
