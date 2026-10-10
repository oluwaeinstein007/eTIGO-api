<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\CommissionService;
use App\Services\DriverEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class DriverEarningsController extends Controller
{
    public function __construct(
        private DriverEarningsService $earningsService,
        private CommissionService $commissionService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'required', Rule::in(['today', 'week', 'month'])],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'month' => ['sometimes', 'required', 'date_format:Y-m'],
        ]);
        $period = $validated['period'] ?? 'today';
        $now = Carbon::now();
        $selectedDate = isset($validated['date'])
            ? Carbon::createFromFormat('!Y-m-d', $validated['date'])
            : $now->copy()->startOfDay();
        $selectedMonth = $validated['month'] ?? $now->format('Y-m');

        [$start, $bucketCount, $bucketUnit] = match ($period) {
            'week' => [$selectedDate->copy()->startOfWeek(Carbon::MONDAY), 7, 'day'],
            'month' => [
                Carbon::createFromFormat('!Y-m-d', $selectedMonth.'-01'),
                Carbon::createFromFormat('!Y-m-d', $selectedMonth.'-01')->daysInMonth,
                'day',
            ],
            default => [$selectedDate, 12, 'two_hours'],
        };
        $endExclusive = match ($period) {
            'week' => $start->copy()->addDays(7),
            'month' => $start->copy()->addMonth(),
            default => $start->copy()->addDay(),
        };

        $rides = $request->user()->driverRides()
            ->where('status', RideStatus::Completed->value)
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<', $endExclusive)
            ->get(['completed_at', 'final_fare_amount']);

        $buckets = [];
        for ($index = 0; $index < $bucketCount; $index++) {
            $bucketStart = $bucketUnit === 'two_hours'
                ? $start->copy()->addHours($index * 2)
                : $start->copy()->add($bucketUnit, $index);
            $buckets[] = [
                'label' => match ($period) {
                    'today' => $bucketStart->format('g A'),
                    'week' => $bucketStart->format('D'),
                    default => (string) $bucketStart->day,
                },
                'date' => $period === 'today'
                    ? $bucketStart->toIso8601String()
                    : $bucketStart->toDateString(),
                'amount' => 0.0,
                'completed_rides' => 0,
            ];
        }

        $total = 0.0;
        foreach ($rides as $ride) {
            $completedAt = Carbon::parse($ride->completed_at);
            $bucketIndex = match ($period) {
                'today' => intdiv($completedAt->hour, 2),
                'week' => (int) $start->copy()->startOfDay()->diffInDays(
                    $completedAt->copy()->startOfDay(),
                ),
                default => $completedAt->day - 1,
            };
            $fare = (float) $ride->final_fare_amount;
            $buckets[$bucketIndex]['amount'] += $fare;
            $buckets[$bucketIndex]['completed_rides']++;
            $total += $fare;
        }

        $driver = $request->user()->driver;
        $balances = $driver
            ? $this->earningsService->getSummary($driver->id)
            : ['pending' => 0, 'available' => 0, 'total_paid' => 0];

        $todayEarnings = $request->user()->driverRides()
            ->where('status', RideStatus::Completed->value)
            ->whereDate('completed_at', now()->toDateString())
            ->sum('final_fare_amount');

        $weekEarnings = $request->user()->driverRides()
            ->where('status', RideStatus::Completed->value)
            ->where('completed_at', '>=', now()->startOfWeek(Carbon::MONDAY))
            ->sum('final_fare_amount');

        $monthEarnings = $request->user()->driverRides()
            ->where('status', RideStatus::Completed->value)
            ->where('completed_at', '>=', now()->startOfMonth())
            ->sum('final_fare_amount');

        return response()->json([
            'earnings' => [
                'period' => $period,
                'currency' => 'NGN',
                'total' => round($total, 2),
                'completed_rides' => $rides->count(),
                'balances' => [
                    'pending_kobo' => $balances['pending'],
                    'available_kobo' => $balances['available'],
                    'total_paid_kobo' => $balances['total_paid'],
                ],
                'summaries' => [
                    'today' => round((float) $todayEarnings, 2),
                    'this_week' => round((float) $weekEarnings, 2),
                    'this_month' => round((float) $monthEarnings, 2),
                ],
                'range' => [
                    'start' => $start->toIso8601String(),
                    'end_exclusive' => $endExclusive->toIso8601String(),
                ],
                'filters' => $period === 'month'
                    ? ['month' => $start->format('Y-m')]
                    : ['date' => $selectedDate->toDateString()],
                'chart' => $buckets,
            ],
        ]);
    }

    public function rideBreakdown(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        if ($ride->driver_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($ride->status !== RideStatus::Completed) {
            return response()->json(['message' => 'Earnings breakdown is only available for completed rides.'], 422);
        }

        $fareAmount = (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount);
        $fareKobo = (int) round($fareAmount * 100);
        $driver = $user->driver;
        $commission = $this->commissionService->calculate($fareKobo, $driver->id);

        $payment = $ride->payment;

        return response()->json([
            'breakdown' => [
                'ride_id' => $ride->id,
                'currency' => $ride->fare_currency ?? 'NGN',
                'fare_amount' => $fareAmount,
                'fare_kobo' => $fareKobo,
                'commission_rate' => $commission['rate'],
                'commission_kobo' => $commission['commission'],
                'net_earnings_kobo' => $commission['net_earnings'],
                'payment_method' => $ride->payment_method->value,
                'tip_amount' => (float) ($payment?->tip_amount ?? 0),
                'completed_at' => $ride->completed_at?->toIso8601String(),
            ],
        ]);
    }
}
