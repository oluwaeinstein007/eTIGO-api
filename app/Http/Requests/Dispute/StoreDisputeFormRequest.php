<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeCategory;
use App\Enums\RideStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDisputeFormRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(array_column(DisputeCategory::cases(), 'value'))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ride = $this->route('ride');

                if ($ride->status !== RideStatus::Completed) {
                    $validator->errors()->add('ride', 'You can only dispute a completed ride.');

                    return;
                }

                $user = $this->user();
                $isParticipant = $ride->passenger_id === $user->id || $ride->driver_id === $user->id;

                if (! $isParticipant) {
                    $validator->errors()->add('ride', 'You are not a participant of this ride.');

                    return;
                }

                $alreadyDisputed = $ride->disputes()
                    ->where('reported_by_user_id', $user->id)
                    ->exists();

                if ($alreadyDisputed) {
                    $validator->errors()->add('ride', 'You have already filed a dispute for this ride.');
                }
            },
        ];
    }
}
