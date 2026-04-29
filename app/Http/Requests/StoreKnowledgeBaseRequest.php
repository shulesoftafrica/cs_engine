<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'content' => ['required', 'file', 'mimes:txt,pdf,doc,docx'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}