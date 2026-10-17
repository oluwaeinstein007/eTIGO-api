<?php

namespace App\Http\Requests\OfflineFlag;

use App\Enums\SanctionTier;
use App\Models\Driver;
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

                if (! $flag) {
                    return;
                }

                if ($flag->sanction_tier === SanctionTier::Warning) {
                    $validator->errors()->add('flag', 'This flag is already at the lowest sanction tier.');
                }

                if ($flag->isResolved()) {
                    $validator->errors()->add('flag', 'This flag has already been resolved.');
                }

                if ($flag->sanction_tier === SanctionTier::Deactivation
                    && Driver::where('user_id', $flag->driver_id)->first()?->isDeactivated()) {
                    $validator->errors()->add('flag', 'The driver has been deactivated. Reactivate them from driver management instead.');
                }
            },
        ];
    }
}
