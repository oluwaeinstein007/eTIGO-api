<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Contracts\FlutterwaveWalletGateway;
use App\Enums\AccountType;
use App\Enums\WalletTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreTopupRequest;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WalletTopupController extends Controller
{
    public function __construct(
        private LedgerService $ledgerService,
        private FlutterwaveWalletGateway $flutterwaveGateway,
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

        $maxBalance = config('wallet.max_balance');
        if (($account->balance + $amountKobo) > $maxBalance) {
            $maxNaira = $maxBalance / 100;

            return response()->json(['message' => "Top-up would exceed maximum wallet balance of ₦{$maxNaira}."], 422);
        }

        $dailyCap = config('wallet.daily_topup_cap');
        $todayTopups = Cache::get("topup_daily:{$user->id}:".now()->toDateString(), 0);
        if (($todayTopups + $amountKobo) > $dailyCap) {
            return response()->json(['message' => 'Daily top-up limit exceeded.'], 422);
        }

        $hourlyKey = "topup_hourly:{$user->id}:".now()->format('Y-m-d-H');
        $hourlyCount = Cache::get($hourlyKey, 0);
        if ($hourlyCount >= config('wallet.max_topups_per_hour', 5)) {
            return response()->json(['message' => 'Too many top-up attempts. Please wait.'], 429);
        }

        $dailyCountKey = "topup_daily_count:{$user->id}:".now()->toDateString();
        $dailyCount = Cache::get($dailyCountKey, 0);
        if ($dailyCount >= config('wallet.max_topups_per_day', 10)) {
            return response()->json(['message' => 'Daily top-up attempt limit reached.'], 429);
        }

        $txRef = 'TOPUP-'.strtoupper(Str::random(12));

        try {
            $result = $this->flutterwaveGateway->initializePayment([
                'tx_ref' => $txRef,
                'amount' => $amountKobo / 100,
                'currency' => 'NGN',
                'redirect_url' => $request->validated('callback_url'),
                'customer' => [
                    'email' => $user->email,
                    'name' => "{$user->first_name} {$user->last_name}",
                ],
                'meta' => [
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'type' => 'wallet_topup',
                ],
                'payment_options' => 'card,banktransfer,ussd',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to initialize payment. Please try again.'], 502);
        }

        WalletTransaction::create([
            'account_id' => $account->id,
            'reference' => $txRef,
            'amount' => $amountKobo,
            'status' => WalletTransactionStatus::Pending,
        ]);

        Cache::increment($hourlyKey);
        Cache::put($hourlyKey, Cache::get($hourlyKey, 1), now()->addHour());
        Cache::increment($dailyCountKey);
        Cache::put($dailyCountKey, Cache::get($dailyCountKey, 1), now()->endOfDay());

        return response()->json([
            'message' => 'Top-up initialized.',
            'payment_link' => $result['link'],
            'tx_ref' => $txRef,
        ]);
    }

    public function verify(Request $request, string $transactionId): JsonResponse
    {
        $user = $request->user();

        try {
            $result = $this->flutterwaveGateway->verifyTransaction($transactionId);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to verify transaction.'], 502);
        }

        if ($result['status'] !== 'successful') {
            return response()->json([
                'message' => 'Transaction was not successful.',
                'status' => $result['status'],
            ], 422);
        }

        $txRef = $result['tx_ref'] ?? '';
        if (! str_starts_with($txRef, 'TOPUP-')) {
            return response()->json(['message' => 'Invalid transaction reference.'], 422);
        }

        $account = $this->ledgerService->findOrCreateAccount(
            $user->getMorphClass(),
            $user->id,
            AccountType::PassengerWallet,
        );

        $amountKobo = (int) ($result['amount'] * 100);

        ProcessTopupWebhookJob::dispatch(
            $account->id,
            $amountKobo,
            $txRef,
        );

        return response()->json(['message' => 'Top-up is being processed.']);
    }
}
