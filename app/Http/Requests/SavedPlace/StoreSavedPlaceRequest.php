<?php

namespace App\Http\Requests\SavedPlace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSavedPlaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['home', 'work', 'custom'])],
            'name' => ['required_if:type,custom', 'nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required_if' => 'A name is required for custom places.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('type') !== 'custom') {
                    return;
                }

                $count = $this->user()
                    ->savedPlaces()
                    ->where('type', 'custom')
                    ->count();

                if ($count >= 10) {
                    $validator->errors()->add('type', 'You can save up to 10 custom places.');
                }
            },
        ];
    }
}
