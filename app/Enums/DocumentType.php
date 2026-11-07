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

    public function description(): string
    {
        return match ($this) {
            self::DrivingLicence => 'Front and back, clear and valid',
            self::GovernmentId => 'NIN slip, passport or voter’s card',
            self::VehicleRegistration => 'Proof of ownership or registration',
            self::InsuranceCertificate => 'Valid commercial insurance certificate',
            self::RoadworthinessCertificate => 'Valid roadworthiness inspection certificate',
            self::VehicleLicence => 'Valid state vehicle licence',
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
     * An expired (past the grace period) or withdrawn paper of this type
     * keeps the driver off the road. Government ID only reminds: who the
     * driver is was settled at KYC, and an ID card's date does not change it.
     */
    public function blocksDriverWhenExpired(): bool
    {
        return $this === self::DrivingLicence;
    }

    /**
     * Vehicle papers whose reminders, grace period and block come from
     * vehicle maintenance (OQ-19), so the document reminder job only emails.
     */
    public function isVehicleLegalPaper(): bool
    {
        return in_array($this, [self::InsuranceCertificate, self::RoadworthinessCertificate, self::VehicleLicence], true);
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
