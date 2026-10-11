<?php

namespace App\Http\Requests\Sos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResolveSosFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $incident = $this->route('incident');

                if ($incident && $incident->isTerminal()) {
                    $validator->errors()->add('incident', 'This incident has already been closed.');
                }
            },
        ];
    }
}
