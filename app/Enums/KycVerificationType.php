<?php

namespace App\Enums;

enum KycVerificationType: string
{
    case Nin = 'nin';
    case DriversLicense = 'drivers_license';
    case VehiclePlate = 'vehicle_plate';
    case Liveness = 'liveness';

    public function label(): string
    {
        return match ($this) {
            self::Nin => 'National Identification Number',
            self::DriversLicense => "Driver's License",
            self::VehiclePlate => 'Vehicle Plate Verification',
            self::Liveness => 'Liveness Check',
        };
    }

    public function qoreIdProductCode(): string
    {
        return match ($this) {
            self::Nin => 'nin',
            self::DriversLicense => 'drivers_license',
            self::VehiclePlate => 'license_plate_basic',
            self::Liveness => 'liveness',
        };
    }
}
