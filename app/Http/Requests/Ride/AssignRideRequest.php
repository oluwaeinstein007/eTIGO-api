<?php

namespace App\Http\Requests\Ride;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Foundation\Http\FormRequest;

class AssignRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'driver_id' => ['required', 'uuid', 'exists:users,id'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $driverId = $this->input('driver_id');
                $driver = Driver::with('vehicle')->where('user_id', $driverId)->first();

                if (! $driver) {
                    $validator->errors()->add('driver_id', 'No driver profile found for this user.');

                    return;
                }

                if ($driver->status !== DriverStatus::Approved) {
                    $validator->errors()->add('driver_id', 'Driver must be approved.');
                }

                if (! $driver->is_online) {
                    $validator->errors()->add('driver_id', 'Driver must be online.');
                }

                $ride = $this->route('ride');
                if ($ride && $driver->vehicle?->vehicle_class_id !== $ride->vehicle_class_id) {
                    $validator->errors()->add('driver_id', 'Driver vehicle class does not match the ride request.');
                }

                $hasActiveRide = Ride::where('driver_id', $driverId)
                    ->whereIn('status', RideStatus::activeStatuses())
                    ->exists();

                if ($hasActiveRide) {
                    $validator->errors()->add('driver_id', 'Driver already has an active ride.');
                }
            },
        ];
    }
}
