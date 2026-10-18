<?php

namespace App\Enums;

/**
 * What each vehicle is checked for (OQ-19). Legal papers come from the
 * vehicle's approved documents; the rest from maintenance records.
 */
enum MaintenanceItemType: string
{
    case Service = 'service';
    case TyresBrakes = 'tyres_brakes';
    case Insurance = 'insurance';
    case Roadworthiness = 'roadworthiness';
    case VehicleLicence = 'vehicle_licence';
    case BatteryHealth = 'battery_health';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Routine service',
            self::TyresBrakes => 'Tyres & brakes check',
            self::Insurance => 'Insurance',
            self::Roadworthiness => 'Roadworthiness certificate',
            self::VehicleLicence => 'Vehicle licence',
            self::BatteryHealth => 'Battery health check',
        };
    }

    /**
     * Expired legal papers keep the car off the road after the grace period;
     * overdue maintenance only warns.
     */
    public function isLegalPaper(): bool
    {
        return $this->documentType() !== null;
    }

    public function documentType(): ?DocumentType
    {
        return match ($this) {
            self::Insurance => DocumentType::InsuranceCertificate,
            self::Roadworthiness => DocumentType::RoadworthinessCertificate,
            self::VehicleLicence => DocumentType::VehicleLicence,
            default => null,
        };
    }

    public static function forDocumentType(DocumentType $type): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->documentType() === $type) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Battery health only applies to cars with a charging port; a
     * non-plug-in hybrid gets the general items only. A car whose fuel type
     * is not known yet gets the general items.
     */
    public function appliesTo(?FuelType $fuelType): bool
    {
        return $this !== self::BatteryHealth || (bool) $fuelType?->canCharge();
    }

    /**
     * Items whose work is logged as a maintenance record (not a document).
     *
     * @return list<self>
     */
    public static function recordable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => ! $type->isLegalPaper()));
    }
}
