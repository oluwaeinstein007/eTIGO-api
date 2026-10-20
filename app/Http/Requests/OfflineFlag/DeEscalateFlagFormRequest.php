<?php

namespace App\Http\Requests\OfflineFlag;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeEscalateFlagFormRequest extends FormRequest
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

                if ($flag && $refusal = $flag->deEscalateRefusal()) {
                    $validator->errors()->add('flag', $refusal);
                }
            },
        ];
    }
}
