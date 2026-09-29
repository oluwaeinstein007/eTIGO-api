<?php

namespace App\Enums;

enum UserType: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
    case Admin = 'admin';
}
