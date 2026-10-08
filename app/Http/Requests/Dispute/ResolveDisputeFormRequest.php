<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResolveDisputeFormRequest extends FormRequest
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
        return [
            'status' => ['required', 'string', Rule::in([DisputeStatus::Resolved->value, DisputeStatus::Dismissed->value])],
            'resolution_notes' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dispute = $this->route('dispute');

                if ($dispute->status->isTerminal()) {
                    $validator->errors()->add('status', 'This dispute has already been resolved or dismissed.');
                }
            },
        ];
    }
}
