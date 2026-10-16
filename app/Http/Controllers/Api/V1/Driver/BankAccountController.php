<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Contracts\WalletGateway;
use App\Enums\BankAccountNameMatch;
use App\Enums\BankAccountReviewStatus;
use App\Enums\PayoutAccountNameCheck;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\ResolveBankAccountRequest;
use App\Http\Requests\Driver\StoreBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Driver;
use App\Notifications\PayoutAccountChangedNotification;
use App\Services\OtpService;
use App\Services\PayoutAccountSafeguards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    private const OTP_PURPOSE = 'bank_account_change';

    public function __construct(
        private WalletGateway $walletGateway,
        private OtpService $otpService,
        private PayoutAccountSafeguards $safeguards,
    ) {}

    /**
     * Send a one-time code to the driver's phone. It is required to add or replace
     * the payout account, so a stolen session cannot direct payouts elsewhere.
     */
    public function requestChangeCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        if (! $user->phone) {
            return response()->json(['message' => 'Add a phone number to your account before adding bank details.'], 422);
        }

        $result = $this->otpService->sendAuthOtp($user->phone, self::OTP_PURPOSE);

        if (! $result['sent']) {
            return response()->json(['message' => $result['error']], 429);
        }

        return response()->json([
            'message' => 'Verification code sent to your phone.',
            'expires_at' => $result['expires_at'],
        ]);
    }

    public function banks(): JsonResponse
    {
        $banks = Cache::remember('banks_nigeria', 86400, function () {
            return $this->walletGateway->listBanks();
        });

        return response()->json([
            'banks' => $banks,
        ]);
    }

    public function resolve(ResolveBankAccountRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        try {
            $resolved = $this->walletGateway->resolveAccountNumber(
                $request->validated('account_number'),
                $request->validated('bank_code'),
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to resolve bank account details. Please check the account number and bank.',
            ], 422);
        }

        return response()->json([
            'bank_code' => $request->validated('bank_code'),
            'account_number' => $resolved['account_number'],
            'account_name' => $resolved['account_name'],
            'name_check' => $this->nameCheckPrompt($driver, $resolved['account_name']),
            'payout_hold' => $this->payoutHoldPrompt($driver),
        ]);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        // Resolve first: a Paystack failure must not use up the driver's code.
        try {
            $resolved = $this->walletGateway->resolveAccountNumber(
                $request->validated('account_number'),
                $request->validated('bank_code'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to verify bank account. Please check the details and try again.'], 422);
        }

        $nameCheck = $this->safeguards->checkName($driver, $resolved['account_name']);
        $nameCheckMode = $this->safeguards->nameCheckMode();
        $mismatch = $nameCheck['status'] === BankAccountNameMatch::Mismatch;

        // Refused before the code is checked, so the driver keeps their code.
        if ($mismatch && $nameCheckMode === PayoutAccountNameCheck::Block) {
            $message = $this->mismatchMessage($resolved['account_name'], $nameCheck['verified_name'], $nameCheckMode);

            return response()->json([
                'message' => $message,
                'code' => 'payout_account_name_mismatch',
                'errors' => ['account_number' => [$message]],
            ], 422);
        }

        $verification = $this->otpService->verifyAuthOtp(
            (string) $request->user()->phone,
            $request->validated('otp_code'),
            purpose: self::OTP_PURPOSE,
        );

        if (! $verification['valid']) {
            return response()->json([
                'message' => $verification['error'],
                'errors' => ['otp_code' => [$verification['error']]],
            ], 422);
        }

        $replacedAnother = $driver->bankAccounts()->exists();
        $holdHours = $this->safeguards->holdHours($replacedAnother);

        $safeguards = [
            'payouts_held_until' => $holdHours > 0 ? now()->addHours($holdHours) : null,
            'verified_name' => $nameCheck['verified_name'] ?: null,
            'name_match_status' => $nameCheck['status'],
            'review_status' => $mismatch && $nameCheckMode === PayoutAccountNameCheck::Review
                ? BankAccountReviewStatus::Pending
                : null,
        ];

        $bankAccount = DB::transaction(function () use ($driver, $request, $resolved, $safeguards) {
            // Set existing accounts as non-primary
            BankAccount::where('driver_id', $driver->id)->update(['is_primary' => false]);

            $bankAccount = BankAccount::create([
                'driver_id' => $driver->id,
                'bank_code' => $request->validated('bank_code'),
                'account_number' => $request->validated('account_number'),
                'account_name' => $resolved['account_name'],
                'is_verified' => true,
                'is_primary' => true,
                ...$safeguards,
            ]);

            AuditLog::record($bankAccount, 'bank_account.created', $request->user(), null, [
                'name_match_status' => $safeguards['name_match_status']->value,
                'review_status' => $safeguards['review_status']?->value,
                'payouts_held_until' => $safeguards['payouts_held_until']?->toIso8601String(),
            ]);

            return $bankAccount;
        });

        rescue(fn () => $request->user()->notify(new PayoutAccountChangedNotification($bankAccount, $replacedAnother)));

        return response()->json([
            'message' => 'Bank account added and verified.',
            'bank_account' => new BankAccountResource($bankAccount),
            'payout_eligibility' => $this->safeguards->eligibility($bankAccount),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $bankAccount = $driver->primaryBankAccount;

        if (! $bankAccount) {
            return response()->json(['message' => 'No bank account on file.'], 404);
        }

        return response()->json([
            'bank_account' => new BankAccountResource($bankAccount),
            'payout_eligibility' => $this->safeguards->eligibility($bankAccount),
        ]);
    }

    /**
     * Shown before the driver saves the account, so a name mismatch is not a
     * surprise after they have entered their code.
     *
     * @return array{status: string, verified_name: string, outcome: string, message: ?string}
     */
    private function nameCheckPrompt(Driver $driver, string $accountName): array
    {
        $check = $this->safeguards->checkName($driver, $accountName);
        $mode = $this->safeguards->nameCheckMode();
        $mismatch = $check['status'] === BankAccountNameMatch::Mismatch;

        return [
            'status' => $check['status']->value,
            'verified_name' => $check['verified_name'],
            // ok: nothing to show; review: can save, payouts wait for E-tiGo; blocked: cannot save.
            'outcome' => match (true) {
                ! $mismatch, $mode === PayoutAccountNameCheck::Off => 'ok',
                $mode === PayoutAccountNameCheck::Block => 'blocked',
                default => 'review',
            },
            'message' => $mismatch && $mode !== PayoutAccountNameCheck::Off
                ? $this->mismatchMessage($accountName, $check['verified_name'], $mode)
                : null,
        ];
    }

    /**
     * @return array{applies: bool, hours: int, message: ?string}
     */
    private function payoutHoldPrompt(Driver $driver): array
    {
        $hours = $this->safeguards->holdHours($driver->bankAccounts()->exists());

        return [
            'applies' => $hours > 0,
            'hours' => $hours,
            'message' => $hours > 0
                ? "For your security, payouts to this account will start {$hours} hours after you save it."
                : null,
        ];
    }

    private function mismatchMessage(string $accountName, string $verifiedName, PayoutAccountNameCheck $mode): string
    {
        $intro = "This account is in the name {$accountName}, which does not match your verified name {$verifiedName}.";

        return $mode === PayoutAccountNameCheck::Block
            ? "{$intro} Payouts can only go to a bank account in your own name."
            : "{$intro} You can still save it, but payouts will be paused until E-tiGo checks it. Use an account in your own name to be paid without delay.";
    }
}
