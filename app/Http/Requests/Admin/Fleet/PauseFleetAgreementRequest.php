<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class PauseFleetAgreementRequest extends FormRequest
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
            // e.g. vehicle maintenance, driver leave; shown on the agreement while paused.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
