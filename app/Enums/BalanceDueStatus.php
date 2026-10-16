<?php

namespace App\Enums;

enum BalanceDueStatus: string
{
    case Outstanding = 'outstanding';
    case Paid = 'paid';
    case Waived = 'waived';
}
