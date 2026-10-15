<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Contracts\WalletGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\ResolveBankAccountRequest;
use App\Http\Requests\Driver\StoreBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Notifications\PayoutAccountChangedNotification;
use App\Services\OtpService;
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

        $bankAccount = DB::transaction(function () use ($driver, $request, $resolved) {
            // Set existing accounts as non-primary
            BankAccount::where('driver_id', $driver->id)->update(['is_primary' => false]);

            $bankAccount = BankAccount::create([
                'driver_id' => $driver->id,
                'bank_code' => $request->validated('bank_code'),
                'account_number' => $request->validated('account_number'),
                'account_name' => $resolved['account_name'],
                'is_verified' => true,
                'is_primary' => true,
            ]);

            AuditLog::record($bankAccount, 'bank_account.created', $request->user());

            return $bankAccount;
        });

        $request->user()->notify(new PayoutAccountChangedNotification($bankAccount, $replacedAnother));

        return response()->json([
            'message' => 'Bank account added and verified.',
            'bank_account' => new BankAccountResource($bankAccount),
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
        ]);
    }
}
