<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewDocumentRequest extends FormRequest
{
    /**
     * Route middleware limits reviews to operations and safety.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
            // The reviewer confirms or corrects the expiry date on the paper.
            'expires_at' => ['sometimes', 'nullable', 'date'],
            // The number on the paper; on a driving licence it becomes the driver's licence number.
            'document_number' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }
}
