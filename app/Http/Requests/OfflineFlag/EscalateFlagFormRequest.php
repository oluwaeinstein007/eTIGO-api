<?php

namespace App\Http\Requests\OfflineFlag;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EscalateFlagFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
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

                if ($flag->sanction_tier->value >= 3) {
                    $validator->errors()->add('flag', 'This flag is already at the maximum sanction tier.');
                }

                if ($flag->isResolved()) {
                    $validator->errors()->add('flag', 'This flag has already been resolved.');
                }
            },
        ];
    }
}
