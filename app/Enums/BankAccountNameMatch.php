<?php

namespace App\Enums;

enum BankAccountNameMatch: string
{
    case Matched = 'matched';
    case Mismatch = 'mismatch';
}
