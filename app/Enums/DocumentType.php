<?php

namespace App\Enums;

enum DocumentType: string
{
    case DrivingLicence = 'driving_licence';
    case VehicleRegistration = 'vehicle_registration';
    case InsuranceCertificate = 'insurance_certificate';
    case GovernmentId = 'government_id';
}
