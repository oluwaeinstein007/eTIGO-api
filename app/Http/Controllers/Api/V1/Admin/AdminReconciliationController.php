<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ReconciliationReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReconciliationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $validated['date'] ?? now()->subDay()->toDateString();

        $report = ReconciliationReport::where('report_date', $date)->first();

        if (! $report) {
            return response()->json([
                'message' => "No reconciliation report found for {$date}.",
                'report' => null,
            ], 404);
        }

        return response()->json([
            'report' => [
                'id' => $report->id,
                'report_date' => $report->report_date->toDateString(),
                'gateway_charges_total' => $report->gateway_charges_total,
                'ledger_credits_total' => $report->ledger_credits_total,
                'gateway_transfers_total' => $report->gateway_transfers_total,
                'ledger_payouts_total' => $report->ledger_payouts_total,
                'mismatches_count' => $report->mismatches_count,
                'mismatches' => $report->mismatches,
                'status' => $report->status,
                'generated_at' => $report->created_at->toIso8601String(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = ReconciliationReport::orderByDesc('report_date')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json($reports);
    }

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
