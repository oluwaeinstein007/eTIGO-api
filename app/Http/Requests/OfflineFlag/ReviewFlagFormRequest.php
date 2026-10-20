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
                $outcome = DisputeOutcome::tryFrom((string) $this->input('outcome'));

                // A flag can be reviewed whether or not the driver disputed
                // it, and an upheld flag can be overturned on appeal.
                if ($flag && $outcome && $refusal = $flag->reviewRefusal($outcome, $this->user())) {
                    $validator->errors()->add('flag', $refusal);
                }
            },
        ];
    }
}
