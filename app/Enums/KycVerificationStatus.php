<?php

namespace App\Enums;

enum KycVerificationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
}
