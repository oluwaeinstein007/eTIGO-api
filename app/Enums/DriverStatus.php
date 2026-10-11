<?php

namespace App\Enums;

enum DriverStatus: string
{
    case Onboarding = 'onboarding';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
}
