<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Contracts\PaystackGateway;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreTopupRequest;
use App\Jobs\ProcessTopupWebhookJob;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WalletTopupController extends Controller
{
    public function __construct(
        private LedgerService $ledgerService,
        private PaystackGateway $paystackGateway,
    ) {}

    public function store(StoreTopupRequest $request): JsonResponse
    {
        $user = $request->user();
        $amountKobo = $request->validated('amount');

        $account = $this->ledgerService->findOrCreateAccount(
            $user->getMorphClass(),
            $user->id,
            AccountType::PassengerWallet,
        );

        if (! $account->isActive()) {
            return response()->json(['message' => 'Wallet is frozen or closed.'], 422);
        }

        // Check max balance
        $maxBalance = config('wallet.max_balance');
        if (($account->balance + $amountKobo) > $maxBalance) {
            $maxNaira = $maxBalance / 100;

            return response()->json(['message' => "Top-up would exceed maximum wallet balance of ₦{$maxNaira}."], 422);
        }

        // Check daily cap
        $dailyCap = config('wallet.daily_topup_cap');
        $todayTopups = Cache::get("topup_daily:{$user->id}:".now()->toDateString(), 0);
        if (($todayTopups + $amountKobo) > $dailyCap) {
            return response()->json(['message' => 'Daily top-up limit exceeded.'], 422);
        }

        // Velocity check
        $hourlyKey = "topup_hourly:{$user->id}:".now()->format('Y-m-d-H');
        $hourlyCount = Cache::get($hourlyKey, 0);
        if ($hourlyCount >= config('wallet.max_topups_per_hour', 5)) {
            return response()->json(['message' => 'Too many top-up attempts. Please wait.'], 429);
        }

        $reference = 'TOPUP-'.strtoupper(Str::random(12));

        try {
            $result = $this->paystackGateway->initializeTransaction([
                'email' => $user->email,
                'amount' => $amountKobo,
                'reference' => $reference,
                'callback_url' => $request->validated('callback_url'),
                'metadata' => [
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'type' => 'wallet_topup',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to initialize payment. Please try again.'], 502);
        }

        // Track velocity
        Cache::increment($hourlyKey);
        Cache::put($hourlyKey, Cache::get($hourlyKey, 1), now()->addHour());

        return response()->json([
            'message' => 'Top-up initialized.',
            'authorization_url' => $result['authorization_url'],
            'reference' => $reference,
        ]);
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        try {
            $result = $this->paystackGateway->verifyTransaction($reference);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to verify transaction.'], 502);
        }

        if ($result['status'] !== 'success') {
            return response()->json([
                'message' => 'Transaction was not successful.',
                'status' => $result['status'],
            ], 422);
        }

        $account = $this->ledgerService->findOrCreateAccount(
            $user->getMorphClass(),
            $user->id,
            AccountType::PassengerWallet,
        );

        ProcessTopupWebhookJob::dispatch(
            $account->id,
            (int) $result['amount'],
            $reference,
        );

        return response()->json(['message' => 'Top-up is being processed.']);
    }
}
