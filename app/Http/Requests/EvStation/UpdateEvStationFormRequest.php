<?php

namespace App\Http\Requests\EvStation;

use App\Enums\EvStationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEvStationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'city_id' => ['sometimes', 'uuid', 'exists:cities,id'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'address' => ['sometimes', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(array_column(EvStationStatus::cases(), 'value'))],
        ];
    }
}
