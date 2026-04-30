<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:30', Rule::unique('products', 'phone_number')],
            'session_id' => ['required', 'string', 'max:50', Rule::unique('products', 'session_id')],
            'wasender_api_key' => ['required', 'string'],
            'webhook_secret' => ['required', 'string'],
            'config' => ['required', 'array'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
