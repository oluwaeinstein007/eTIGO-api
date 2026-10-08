<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class DriverEarningsController extends Controller
{
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

        return response()->json([
            'earnings' => [
                'period' => $period,
                'currency' => 'NGN',
                'total' => round($total, 2),
                'completed_rides' => $rides->count(),
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
}
