<?php

/*
|--------------------------------------------------------------------------
| Account deletion
|--------------------------------------------------------------------------
|
| A passenger or driver who deletes their account is logged out and the
| account is kept for this many days. Logging in again within that window
| cancels the deletion; after it, PurgeDeletedAccountsJob erases the
| personal data (or the whole account when it has no rides).
|
*/

return [

    'deletion_grace_days' => (int) env('ACCOUNT_DELETION_GRACE_DAYS', 30),

];
