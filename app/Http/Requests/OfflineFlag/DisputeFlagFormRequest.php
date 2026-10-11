<?php

namespace App\Http\Requests\OfflineFlag;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DisputeFlagFormRequest extends FormRequest
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
                $flag = $this->route('flag');

                if (! $flag) {
                    return;
                }

                if ($flag->is_disputed) {
                    $validator->errors()->add('flag', 'This flag has already been disputed.');
                }

                if ($flag->driver_id !== $this->user()->id) {
                    $validator->errors()->add('flag', 'You are not authorized to dispute this flag.');
                }
            },
        ];
    }
}
