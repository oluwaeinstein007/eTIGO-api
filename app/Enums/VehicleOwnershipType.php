<?php

namespace App\Enums;

enum VehicleOwnershipType: string
{
    case OwnVehicle = 'own_vehicle';
    case FleetVehicle = 'fleet_vehicle';

    public function label(): string
    {
        return match ($this) {
            self::OwnVehicle => 'Own Vehicle',
            self::FleetVehicle => 'Fleet Vehicle',
        };
    }
}
