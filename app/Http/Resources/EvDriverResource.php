<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A driver as the admin EV station screens show them: the driver charging at
 * a stall (`stalls[].current_driver`) and each driver in the queue
 * (`queue[].driver`) share this shape. Load `driver.vehicle` first.
 *
 * @mixin User
 */
class EvDriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicle = $this->driver?->vehicle;

        return [
            'id' => $this->id,
            'name' => trim($this->first_name.' '.$this->last_name),
            'phone' => $this->phone,
            'vehicle' => $vehicle ? [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'colour' => $vehicle->colour,
                'plate_number' => $vehicle->plate_number,
            ] : null,
        ];
    }
}
