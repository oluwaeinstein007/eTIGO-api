<?php

namespace App\Http\Requests\LostItem;

use App\Enums\LostItemCategory;
use App\Services\ThirdPartyBookingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLostItemRequest extends FormRequest
{
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
            'category' => ['required', 'string', Rule::enum(LostItemCategory::class)],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            // Another number to reach the passenger on (their phone may be
            // the lost item). Ignored on a driver's report.
            'contact_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('contact_phone') && is_string($this->input('contact_phone'))) {
            $this->merge(['contact_phone' => ThirdPartyBookingService::normalizePhone($this->input('contact_phone'))]);
        }
    }

    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter the contact number in international format, e.g. +2348031234567.',
        ];
    }
}
