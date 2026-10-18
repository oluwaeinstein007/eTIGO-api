<?php

namespace App\Http\Requests\Admin\City;

use App\Services\CityBoundaryService;
use App\Support\NigerianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCityRequest extends FormRequest
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
        $rules = [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('cities', 'name')->ignore($this->route('city'))],
            'state' => ['sometimes', 'string', Rule::in(NigerianStates::names())],
            'boundary' => ['nullable', 'array'],
            'boundary.type' => ['required_with:boundary', 'string', 'in:Point,Polygon'],
            'boundary.coordinates' => ['required_with:boundary', 'array'],
            'boundary.radius_km' => ['nullable', 'numeric', 'min:0'],
            'timezone' => ['sometimes', 'string', 'timezone', Rule::in(config('cities.supported_timezones'))],
            'currency_code' => ['sometimes', 'string', 'size:3', Rule::in(config('cities.supported_currencies'))],
            'launch_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'vehicle_class_ids' => ['nullable', 'array'],
            'vehicle_class_ids.*' => ['uuid', 'distinct', 'exists:vehicle_classes,id'],
        ];

        if ($this->input('boundary.type') === 'Polygon') {
            $rules['boundary.coordinates.*'] = ['array', 'min:3'];
            $rules['boundary.coordinates.*.*'] = ['array', 'size:2'];
            $rules['boundary.coordinates.*.*.*'] = ['numeric'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.in' => 'Cities must use Lagos time (Africa/Lagos) until the Lagos-time rules (payout day, promo peaks) follow each city\'s time zone.',
            'currency_code.in' => 'Cities must use naira (NGN).',
        ];
    }

    /**
     * A pickup must map to one city, so boundaries cannot overlap (OQ-22).
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! is_array($this->input('boundary'))) {
                    return;
                }

                $overlap = app(CityBoundaryService::class)->findOverlappingCity($this->input('boundary'), $this->route('city')?->id);

                if ($overlap !== null) {
                    $validator->errors()->add('boundary', "This boundary overlaps {$overlap->name}. Adjust it so each pickup maps to one city; shared borders are fine.");
                }
            },
        ];
    }
}
