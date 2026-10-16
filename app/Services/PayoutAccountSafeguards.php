<?php

namespace App\Services;

use App\Enums\BankAccountNameMatch;
use App\Enums\BankAccountReviewStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\PayoutAccountNameCheck;
use App\Models\AppSetting;
use App\Models\BankAccount;
use App\Models\Driver;
use Illuminate\Support\Carbon;

/**
 * Decides whether money may be paid to a driver's bank account. Every path
 * that sends a payout (driver request, weekly auto-payout, admin approve and
 * retry) asks blockFor() first, so a held, unreviewed or rejected account is
 * never paid.
 */
class PayoutAccountSafeguards
{
    public const ON_HOLD = 'payout_account_on_hold';

    public const UNDER_REVIEW = 'payout_account_under_review';

    public const REJECTED = 'payout_account_rejected';

    public function __construct(
        private AccountNameMatcher $matcher,
    ) {}

    public function nameCheckMode(): PayoutAccountNameCheck
    {
        return PayoutAccountNameCheck::tryFrom((string) AppSetting::wallet('payout_account_name_check'))
            ?? PayoutAccountNameCheck::Review;
    }

    /**
     * Hours payouts wait after this account is saved; 0 means no hold.
     */
    public function holdHours(bool $replacesAnother): int
    {
        if (! $replacesAnother && ! AppSetting::wallet('payout_account_hold_first_account')) {
            return 0;
        }

        return max(0, (int) AppSetting::wallet('payout_account_hold_hours'));
    }

    /**
     * Compare an account name with the driver's verified name: the name on
     * their profile (checked against their NIN at KYC) plus the middle name
     * from their verified NIN record, when there is one.
     *
     * @return array{status: BankAccountNameMatch, verified_name: string}
     */
    public function checkName(Driver $driver, string $accountName): array
    {
        $user = $driver->user;
        $nin = $this->verifiedNinRecord($driver);
        $middleNames = array_filter([$nin['middlename'] ?? $nin['middle_name'] ?? null]);

        $givenNames = array_filter([$user?->first_name, ...$middleNames, $nin['firstname'] ?? $nin['first_name'] ?? null]);
        $surnames = trim(($user?->last_name ?? '').' '.($nin['lastname'] ?? $nin['last_name'] ?? ''));

        return [
            'status' => $this->matcher->matches($accountName, $givenNames, $surnames)
                ? BankAccountNameMatch::Matched
                : BankAccountNameMatch::Mismatch,
            'verified_name' => trim(implode(' ', array_filter([$user?->first_name, ...$middleNames, $user?->last_name]))),
        ];
    }

    /**
     * Why payouts to this account cannot go out right now, or null when they can.
     *
     * @return array{code: string, message: string, payouts_held_until: ?string}|null
     */
    public function blockFor(BankAccount $account): ?array
    {
        if ($account->review_status === BankAccountReviewStatus::Rejected) {
            return $this->block(self::REJECTED, 'E-tiGo could not confirm that this bank account is in your name. Add a bank account in your own name to receive payouts.');
        }

        if ($account->review_status === BankAccountReviewStatus::Pending) {
            return $this->block(self::UNDER_REVIEW, "Payouts to this account are paused while E-tiGo checks that the account name matches your verified name. We'll notify you when it's done.");
        }

        if ($account->payouts_held_until?->isFuture()) {
            return $this->block(
                self::ON_HOLD,
                'For your security, payouts to a newly added bank account start '.$this->formatTime($account->payouts_held_until).'.',
                $account->payouts_held_until,
            );
        }

        return null;
    }

    /**
     * The driver app's view of whether payouts can be requested to this account.
     *
     * @return array{can_request_payouts: bool, code: ?string, message: ?string, payouts_held_until: ?string}
     */
    public function eligibility(?BankAccount $account): array
    {
        if (! $account) {
            return ['can_request_payouts' => false, 'code' => 'no_payout_account', 'message' => 'Add a bank account to receive payouts.', 'payouts_held_until' => null];
        }

        $block = $this->blockFor($account);

        return [
            'can_request_payouts' => $block === null,
            'code' => $block['code'] ?? null,
            'message' => $block['message'] ?? null,
            'payouts_held_until' => $block['payouts_held_until'] ?? null,
        ];
    }

    public function formatTime(Carbon $time): string
    {
        return $time->copy()->timezone('Africa/Lagos')->format('j M Y, g:i A').' (Lagos time)';
    }

    /**
     * @return array{code: string, message: string, payouts_held_until: ?string}
     */
    private function block(string $code, string $message, ?Carbon $until = null): array
    {
        return ['code' => $code, 'message' => $message, 'payouts_held_until' => $until?->toIso8601String()];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedNinRecord(Driver $driver): array
    {
        $verification = $driver->kycVerifications()
            ->where('type', KycVerificationType::Nin)
            ->where('status', KycVerificationStatus::Verified)
            ->latest('verified_at')
            ->first();

        return is_array($verification?->provider_response) ? $verification->provider_response : [];
    }
}
