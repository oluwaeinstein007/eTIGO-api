<?php

namespace App\Enums;

enum SurgeType: string
{
    case TimeBased = 'time_based';
    case DemandBased = 'demand_based';
    case Manual = 'manual';
}
