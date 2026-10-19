<?php

namespace App\Http\Requests\Driver;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRepairAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attachment' => ['required_without:photo', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'photo' => ['required_without:attachment', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'title' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user?->isDriver()) {
                    $validator->errors()->add('driver', 'Only drivers can add attachments to repair requests.');
                }
            },
        ];
    }
}
