<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
            // The reviewer confirms or corrects the expiry date on the paper.
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
