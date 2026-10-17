<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Enums\AccountType;
use App\Enums\WalletTransactionStatus;
use App\Exceptions\TopupRefusedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreTopupRequest;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use App\Services\WalletTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletTopupController extends Controller
{
    public function __construct(
        private LedgerService $ledgerService,
        private WalletTopupService $topups,
    ) {}

    public function store(StoreTopupRequest $request): JsonResponse
    {
        try {
            $started = $this->topups->start(
                $request->user(),
                $request->validated('amount'),
                $request->validated('callback_url'),
                saveCard: $request->boolean('save_card'),
            );
        } catch (TopupRefusedException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'Top-up initialized.',
            'payment_link' => $started['link'],
            'tx_ref' => $started['transaction']->reference,
        ]);
    }

    /**
     * Fallback for a missed webhook. {reference} is the tx_ref returned when the
     * top-up was initialised (Paystack also appends it to the callback URL);
     * only the caller's own top-ups can be confirmed.
     */
    public function verify(Request $request, string $reference): JsonResponse
    {
        $account = $this->ledgerService->findOrCreateAccount(
            $request->user()->getMorphClass(),
            $request->user()->id,
            AccountType::PassengerWallet,
        );

        $transaction = WalletTransaction::where('reference', $reference)
            ->where('account_id', $account->id)
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Top-up not found.'], 404);
        }

        if ($transaction->status === WalletTransactionStatus::Completed) {
            return response()->json(['message' => 'Top-up has already been credited.']);
        }

        // The wallet gateway reports amounts in kobo; the passenger is credited
        // with what the provider actually collected.
        try {
            $result = $this->topups->confirm($transaction);
        } catch (TopupRefusedException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        if ($result['status'] !== 'successful') {
            return response()->json([
                'message' => 'Transaction was not successful.',
                'status' => $result['status'],
            ], 422);
        }

        return response()->json(['message' => 'Top-up is being processed.']);
    }
}
