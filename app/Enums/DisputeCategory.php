<?php

namespace App\Enums;

enum DisputeCategory: string
{
    case FareDispute = 'fare_dispute';
    case DriverBehaviour = 'driver_behaviour';
    case RouteDeviation = 'route_deviation';
    case VehicleCondition = 'vehicle_condition';
    case SafetyConcern = 'safety_concern';
    case PaymentIssue = 'payment_issue';
    case ItemLeftBehind = 'item_left_behind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FareDispute => 'Fare Dispute',
            self::DriverBehaviour => 'Driver Behaviour',
            self::RouteDeviation => 'Route Deviation',
            self::VehicleCondition => 'Vehicle Condition',
            self::SafetyConcern => 'Safety Concern',
            self::PaymentIssue => 'Payment Issue',
            self::ItemLeftBehind => 'Item Left Behind',
            self::Other => 'Other',
        };
    }
}
