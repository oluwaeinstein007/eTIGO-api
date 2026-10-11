<?php

namespace App\Http\Requests\OfflineFlag;

use App\Enums\DisputeOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewFlagFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in([DisputeOutcome::Upheld->value, DisputeOutcome::Overturned->value])],
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

                if (! $flag->is_disputed) {
                    $validator->errors()->add('flag', 'This flag has not been disputed.');
                }

                if ($flag->dispute_outcome && $flag->dispute_outcome !== DisputeOutcome::Pending) {
                    $validator->errors()->add('flag', 'This dispute has already been resolved.');
                }
            },
        ];
    }
}
