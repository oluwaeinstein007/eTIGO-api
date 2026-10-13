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
            'connector_types' => ['sometimes', 'array'],
            'connector_types.*' => ['string', 'in:CCS2,CHAdeMO,Type2,GBT,Tesla'],
            'power_output_kw' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'pricing_per_kwh' => ['sometimes', 'integer', 'min:0'],
            'operating_hours' => ['sometimes', 'array'],
            'operating_hours.open' => ['required_with:operating_hours', 'string', 'date_format:H:i'],
            'operating_hours.close' => ['required_with:operating_hours', 'string', 'date_format:H:i'],
            'status' => ['sometimes', Rule::in(array_column(EvStationStatus::cases(), 'value'))],
        ];
    }
}
