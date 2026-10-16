<?php

namespace App\Enums;

enum BankAccountReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
