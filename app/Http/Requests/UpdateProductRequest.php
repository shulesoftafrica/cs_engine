<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'string', 'max:30', Rule::unique('products', 'phone_number')->ignore($productId)],
            'session_id' => ['sometimes', 'string', 'max:50', Rule::unique('products', 'session_id')->ignore($productId)],
            'wasender_api_key' => ['sometimes', 'string'],
            'webhook_secret' => ['sometimes', 'string'],
            'config' => ['sometimes', 'array'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
