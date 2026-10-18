<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeCategory;
use App\Enums\RideStatus;
use App\Services\ScheduledRideCancellationFeeService;
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
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'audio' => ['nullable', 'file', 'mimes:mp3,m4a,wav,ogg,webm,aac', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ride = $this->route('ride');
                $user = $this->user();

                // A cancelled booking can be disputed by its passenger only for
                // the late-cancellation fee they were charged (SR-4).
                $feeDispute = $ride->status === RideStatus::Cancelled
                    && $ride->passenger_id === $user->id
                    && app(ScheduledRideCancellationFeeService::class)->disputableFee($ride) !== null;

                if ($ride->status !== RideStatus::Completed && ! $feeDispute) {
                    $validator->errors()->add('ride', 'You can only dispute a completed ride, or a cancellation fee you were charged.');

                    return;
                }

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
