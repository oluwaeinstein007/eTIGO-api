<?php

namespace App\Enums;

enum KycStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Verified = 'verified';
    case Failed = 'failed';
}
