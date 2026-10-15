<?php

namespace App\Enums;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Cng = 'cng';
    case Hybrid = 'hybrid';
    case PlugInHybrid = 'plug_in_hybrid';
    case Electric = 'electric';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Petrol',
            self::Diesel => 'Diesel',
            self::Cng => 'CNG',
            self::Hybrid => 'Hybrid',
            self::PlugInHybrid => 'Plug-in hybrid',
            self::Electric => 'Electric',
        };
    }

    /**
     * Only vehicles with a charging port can use E-tiGo's charging stalls.
     */
    public function canCharge(): bool
    {
        return in_array($this, [self::Electric, self::PlugInHybrid], true);
    }
}
