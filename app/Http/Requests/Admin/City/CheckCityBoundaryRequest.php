<?php

namespace App\Http\Requests\Admin\City;

use App\Support\NigerianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A boundary to check before saving it (the admin map editor): the same
 * shape rules as creating or updating a city.
 */
class CheckCityBoundaryRequest extends FormRequest
{
    use ValidatesCityBoundary;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'boundary' => ['required', 'array'],
            'boundary.type' => ['required', 'string', 'in:Point,Polygon'],
            'boundary.coordinates' => ['required', 'array'],
            'boundary.radius_km' => ['nullable', 'numeric', 'min:0'],
            // The city's state, to check the boundary is drawn there.
            'state' => ['nullable', 'string', Rule::in(NigerianStates::names())],
            // The city being edited, which its own boundary never overlaps.
            'city_id' => ['nullable', 'uuid', 'exists:cities,id'],
        ];

        return $this->withBoundaryShapeRules($rules);
    }
}
