<?php

namespace App\Http\Requests\EvStation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ConfirmChargeRequestFormRequest extends FormRequest
{
    /** How far ahead operations can book a charging slot. */
    public const MAX_DAYS_AHEAD = 7;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmed_slot_at' => ['required', 'date'],
            'station_id' => ['nullable', 'uuid', 'exists:ev_charging_stations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('confirmed_slot_at')) {
                    return;
                }

                $slot = Carbon::parse($this->input('confirmed_slot_at'));

                // A few minutes of slack: the operator may take a moment to submit.
                if ($slot->lt(now()->subMinutes(5))) {
                    $validator->errors()->add('confirmed_slot_at', 'The slot cannot be in the past.');
                } elseif ($slot->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
                    $validator->errors()->add('confirmed_slot_at', 'The slot can be at most '.self::MAX_DAYS_AHEAD.' days ahead.');
                }
            },
        ];
    }
}
