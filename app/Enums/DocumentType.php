<?php

namespace App\Enums;

enum DocumentType: string
{
    case DrivingLicence = 'driving_licence';
    case VehicleRegistration = 'vehicle_registration';
    case InsuranceCertificate = 'insurance_certificate';
    case RoadworthinessCertificate = 'roadworthiness_certificate';
    case VehicleLicence = 'vehicle_licence';
    case GovernmentId = 'government_id';

    public function label(): string
    {
        return match ($this) {
            self::DrivingLicence => 'Driving licence',
            self::VehicleRegistration => 'Vehicle registration',
            self::InsuranceCertificate => 'Insurance certificate',
            self::RoadworthinessCertificate => 'Roadworthiness certificate',
            self::VehicleLicence => 'Vehicle licence',
            self::GovernmentId => 'Government ID',
        };
    }

    /**
     * Papers that belong to the car, not the driver, so they stay with a
     * car that changes hands. Driving licence and government ID stay with
     * the driver.
     */
    public function isVehicleDocument(): bool
    {
        return in_array($this, [
            self::VehicleRegistration,
            self::InsuranceCertificate,
            self::RoadworthinessCertificate,
            self::VehicleLicence,
        ], true);
    }

    /**
     * Papers whose expiry date decides whether the car may be driven.
     */
    public function requiresExpiry(): bool
    {
        return in_array($this, [self::RoadworthinessCertificate, self::VehicleLicence], true);
    }

    /**
     * @return list<string>
     */
    public static function vehicleDocumentValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->isVehicleDocument()),
        ));
    }
}
