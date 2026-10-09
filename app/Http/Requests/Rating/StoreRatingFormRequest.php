<?php

namespace App\Http\Requests\Rating;

use App\Enums\RideStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRatingFormRequest extends FormRequest
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
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ride = $this->route('ride');

                if ($ride->status !== RideStatus::Completed) {
                    $validator->errors()->add('ride', 'You can only rate a completed ride.');

                    return;
                }

                $user = $this->user();
                $isPassenger = $ride->passenger_id === $user->id;
                $isDriver = $ride->driver_id === $user->id;

                if (! $isPassenger && ! $isDriver) {
                    $validator->errors()->add('ride', 'You are not a participant of this ride.');

                    return;
                }

                $alreadyRated = $ride->ratings()
                    ->where('rated_by_user_id', $user->id)
                    ->exists();

                if ($alreadyRated) {
                    $validator->errors()->add('ride', 'You have already rated this ride.');
                }
            },
        ];
    }
}
