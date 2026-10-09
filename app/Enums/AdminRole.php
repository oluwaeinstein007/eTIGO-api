<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case SafetyOperator = 'safety_operator';
    case Support = 'support';
    case Finance = 'finance';
}
