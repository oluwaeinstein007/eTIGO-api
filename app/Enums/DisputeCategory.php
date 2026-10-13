<?php

namespace App\Enums;

enum DisputeCategory: string
{
    case FareDispute = 'fare_dispute';
    case DriverBehaviour = 'driver_behaviour';
    case RouteDeviation = 'route_deviation';
    case VehicleCondition = 'vehicle_condition';
    case ServiceQuality = 'service_quality';
    case SafetyConcern = 'safety_concern';
    case PaymentIssue = 'payment_issue';
    case ItemLeftBehind = 'item_left_behind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FareDispute => 'Fare is incorrect',
            self::DriverBehaviour => 'Driver behaviour',
            self::RouteDeviation => 'Wrong route taken',
            self::VehicleCondition => 'Vehicle condition',
            self::ServiceQuality => 'Service quality',
            self::SafetyConcern => 'Safety concern',
            self::PaymentIssue => 'Payment issue',
            self::ItemLeftBehind => 'Lost item',
            self::Other => 'Something else',
        };
    }
}
