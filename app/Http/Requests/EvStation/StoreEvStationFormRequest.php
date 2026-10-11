<?php

namespace App\Http\Requests\EvStation;

use App\Enums\EvStationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEvStationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['required', 'string', 'max:500'],
            'total_stalls' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(array_column(EvStationStatus::cases(), 'value'))],
        ];
    }
}
