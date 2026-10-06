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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', Rule::enum(CancellationReason::class)],
            'reason_details' => ['nullable', 'string', 'max:500'],
        ];
    }
}
