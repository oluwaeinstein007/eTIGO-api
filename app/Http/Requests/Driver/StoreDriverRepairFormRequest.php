<?php

namespace App\Http\Requests\Driver;

use App\Enums\RepairServiceType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDriverRepairFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(RepairServiceType::class)],
            'title' => ['required', 'string', 'max:150'],
            'details' => ['required', 'string', 'max:2000'],
            'preferred_date' => ['required', 'date'],
            'preferred_time' => ['required', 'string', 'max:50'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user?->isDriver()) {
                    $validator->errors()->add('driver', 'Only drivers can submit repair and maintenance requests.');
                }
            },
        ];
    }
}
