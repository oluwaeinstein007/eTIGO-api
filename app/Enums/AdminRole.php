<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case SafetyOperator = 'safety_operator';
    case Support = 'support';
    // Tier 2 of dispute handling (OQ-21): escalations, appeals, missed
    // deadlines and larger refunds. Has the support role's other access too.
    case SupportLead = 'support_lead';
    case Finance = 'finance';
}
