<?php

namespace App\Enums;

enum PayoutPreference: string
{
    case OnDemand = 'on_demand';
    case WeeklyAuto = 'weekly_auto';
}
