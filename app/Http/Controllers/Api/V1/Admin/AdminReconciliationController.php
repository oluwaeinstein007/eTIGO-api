<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminReconciliationController extends Controller
{
    public function walletLiability(): JsonResponse
    {
        $walletLiability = Account::where('type', AccountType::PassengerWallet)
            ->where('status', '!=', 'closed')
            ->sum('balance');

        $commissionCollected = Account::where('type', AccountType::PlatformCommission)
            ->sum('balance');

        $driverEarningsPayable = Account::where('type', AccountType::DriverEarningsAvailable)
            ->sum('balance');

        $totalPayoutsProcessed = DB::table('payouts')
            ->where('status', 'paid')
            ->sum('amount');

        return response()->json([
            'reconciliation' => [
                'currency' => 'NGN',
                'wallet_liability' => (int) $walletLiability,
                'commission_collected' => (int) $commissionCollected,
                'driver_earnings_payable' => (int) $driverEarningsPayable,
                'total_payouts_processed' => (int) $totalPayoutsProcessed,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
