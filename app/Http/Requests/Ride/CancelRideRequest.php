<?php

namespace App\Http\Requests\Ride;

use App\Enums\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('reason') && is_string($this->reason)) {
            $normalized = trim(strtolower($this->reason));
            if ($normalized === 'others') {
                $merge['reason'] = 'other';
            }
        }

        if (! $this->filled('reason_details')) {
            if ($this->filled('custom_description')) {
                $merge['reason_details'] = $this->input('custom_description');
            } elseif ($this->filled('custom_reason')) {
                $merge['reason_details'] = $this->input('custom_reason');
            } elseif ($this->filled('description')) {
                $merge['reason_details'] = $this->input('description');
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', Rule::enum(CancellationReason::class)],
            'reason_details' => ['nullable', 'string', 'max:500'],
            'custom_description' => ['nullable', 'string', 'max:500'],
            'custom_reason' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
