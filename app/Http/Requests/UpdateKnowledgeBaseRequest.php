<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'content' => ['sometimes', 'file', 'mimes:txt,pdf,doc,docx'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}