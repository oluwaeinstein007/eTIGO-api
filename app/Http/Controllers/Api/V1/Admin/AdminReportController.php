<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Ride;
use App\Services\ReportExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function __construct(private readonly ReportExportService $exportService) {}

    public function rideVolume(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'city_id' => ['sometimes', 'exists:cities,id'],
            'group_by' => ['sometimes', 'in:day,week,month'],
        ]);

        $query = Ride::query();
        $this->applyDateFilter($query, $request);
        $this->applyCityFilter($query, $request);

        $total = (clone $query)->count();

        $byStatus = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $byCity = (clone $query)
            ->join('cities', 'rides.city_id', '=', 'cities.id')
            ->select('cities.id as city_id', 'cities.name as city_name', DB::raw('count(*) as count'))
            ->groupBy('cities.id', 'cities.name')
            ->orderByDesc('count')
            ->get();

        $byVehicleClass = (clone $query)
            ->join('vehicle_classes', 'rides.vehicle_class_id', '=', 'vehicle_classes.id')
            ->select('vehicle_classes.id as vehicle_class_id', 'vehicle_classes.display_name as vehicle_class', DB::raw('count(*) as count'))
            ->groupBy('vehicle_classes.id', 'vehicle_classes.display_name')
            ->orderByDesc('count')
            ->get();

        $data = [
            'type' => 'ride_volume',
            'period' => $this->periodSummary($request),
            'total_rides' => $total,
            'by_status' => $byStatus,
            'by_city' => $byCity->values(),
            'by_vehicle_class' => $byVehicleClass->values(),
            'generated_at' => now()->toIso8601String(),
        ];

        if ($request->filled('group_by')) {
            $data['trend'] = $this->rideVolumeTrend(clone $query, $request->input('group_by'));
        }

        return response()->json(['report' => $data]);
    }

    public function completionRate(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'city_id' => ['sometimes', 'exists:cities,id'],
        ]);

        $query = Ride::query();
        $this->applyDateFilter($query, $request);
        $this->applyCityFilter($query, $request);

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', RideStatus::Completed)->count();
        $cancelled = (clone $query)->where('status', RideStatus::Cancelled)->count();
        $noDriverFound = (clone $query)->where('status', RideStatus::NoDriverFound)->count();

        $cancellationReasons = (clone $query)
            ->where('status', RideStatus::Cancelled)
            ->whereNotNull('cancellation_reason')
            ->select('cancellation_reason', DB::raw('count(*) as count'))
            ->groupBy('cancellation_reason')
            ->orderByDesc('count')
            ->pluck('count', 'cancellation_reason');

        $cancelledByRole = (clone $query)
            ->where('status', RideStatus::Cancelled)
            ->whereNotNull('cancelled_by')
            ->join('users', 'rides.cancelled_by', '=', 'users.id')
            ->select('users.type as cancelled_by_type', DB::raw('count(*) as count'))
            ->groupBy('users.type')
            ->pluck('count', 'cancelled_by_type');

        $completionRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
        $cancellationRate = $total > 0 ? round(($cancelled / $total) * 100, 2) : 0;

        return response()->json([
            'report' => [
                'type' => 'completion_rate',
                'period' => $this->periodSummary($request),
                'total_rides' => $total,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_driver_found' => $noDriverFound,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'cancellation_reasons' => $cancellationReasons,
                'cancelled_by_role' => $cancelledByRole,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'city_id' => ['sometimes', 'exists:cities,id'],
            'group_by' => ['sometimes', 'in:day,week,month'],
        ]);

        $rideQuery = Ride::where('status', RideStatus::Completed);
        $this->applyDateFilter($rideQuery, $request);
        $this->applyCityFilter($rideQuery, $request);

        $totalRevenue = (clone $rideQuery)->sum('final_fare_amount');
        $rideCount = (clone $rideQuery)->count();
        $averageFare = $rideCount > 0 ? round((float) $totalRevenue / $rideCount, 2) : 0;

        $paymentQuery = Payment::whereIn('status', [
            PaymentStatus::Captured,
            PaymentStatus::Collected,
            PaymentStatus::Settled,
        ])->whereHas('ride', function (Builder $q) use ($request) {
            $this->applyDateFilter($q, $request);
            $this->applyCityFilter($q, $request);
        });

        $totalTips = (clone $paymentQuery)->sum('tip_amount');

        $byPaymentMethod = (clone $rideQuery)
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(final_fare_amount) as total'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method->value,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ]);

        $byCity = (clone $rideQuery)
            ->join('cities', 'rides.city_id', '=', 'cities.id')
            ->select('cities.id as city_id', 'cities.name as city_name', DB::raw('count(*) as count'), DB::raw('sum(final_fare_amount) as total'))
            ->groupBy('cities.id', 'cities.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'city_id' => $row->city_id,
                'city_name' => $row->city_name,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ]);

        $data = [
            'type' => 'revenue',
            'period' => $this->periodSummary($request),
            'currency' => 'NGN',
            'total_revenue' => round((float) $totalRevenue, 2),
            'total_tips' => round((float) $totalTips, 2),
            'ride_count' => $rideCount,
            'average_fare' => $averageFare,
            'by_payment_method' => $byPaymentMethod->values(),
            'by_city' => $byCity->values(),
            'generated_at' => now()->toIso8601String(),
        ];

        if ($request->filled('group_by')) {
            $data['trend'] = $this->revenueTrend(clone $rideQuery, $request->input('group_by'));
        }

        return response()->json(['report' => $data]);
    }

    public function driverUtilisation(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'city_id' => ['sometimes', 'exists:cities,id'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $rideQuery = Ride::where('status', RideStatus::Completed);
        $this->applyDateFilter($rideQuery, $request);
        $this->applyCityFilter($rideQuery, $request);

        $totalDrivers = (clone $rideQuery)->distinct('driver_id')->count('driver_id');
        $totalTrips = (clone $rideQuery)->count();
        $averageTripsPerDriver = $totalDrivers > 0 ? round($totalTrips / $totalDrivers, 2) : 0;

        $totalEarnings = (clone $rideQuery)->sum('final_fare_amount');
        $averageEarningsPerDriver = $totalDrivers > 0 ? round((float) $totalEarnings / $totalDrivers, 2) : 0;

        $averageTripDuration = (clone $rideQuery)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->select(DB::raw('avg(extract(epoch from (completed_at - started_at)) / 60) as avg_minutes'))
            ->value('avg_minutes');

        $limit = min(max($request->integer('limit', 20), 1), 100);

        $topDrivers = (clone $rideQuery)
            ->join('users', 'rides.driver_id', '=', 'users.id')
            ->select(
                'rides.driver_id',
                DB::raw("concat(users.first_name, ' ', users.last_name) as driver_name"),
                DB::raw('count(*) as trip_count'),
                DB::raw('sum(rides.final_fare_amount) as total_earnings'),
                DB::raw('avg(rides.final_fare_amount) as avg_fare'),
            )
            ->groupBy('rides.driver_id', 'users.first_name', 'users.last_name')
            ->orderByDesc('trip_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'driver_id' => $row->driver_id,
                'driver_name' => $row->driver_name,
                'trip_count' => (int) $row->trip_count,
                'total_earnings' => round((float) $row->total_earnings, 2),
                'average_fare' => round((float) $row->avg_fare, 2),
            ]);

        return response()->json([
            'report' => [
                'type' => 'driver_utilisation',
                'period' => $this->periodSummary($request),
                'currency' => 'NGN',
                'total_active_drivers' => $totalDrivers,
                'total_trips' => $totalTrips,
                'average_trips_per_driver' => $averageTripsPerDriver,
                'total_earnings' => round((float) $totalEarnings, 2),
                'average_earnings_per_driver' => $averageEarningsPerDriver,
                'average_trip_duration_minutes' => $averageTripDuration ? round((float) $averageTripDuration, 1) : null,
                'top_drivers' => $topDrivers->values(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'type' => ['required', 'in:ride_volume,completion_rate,revenue,driver_utilisation'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'city_id' => ['sometimes', 'exists:cities,id'],
        ]);

        $type = $request->input('type');

        return match ($type) {
            'ride_volume' => $this->exportRideVolume($request),
            'completion_rate' => $this->exportCompletionRate($request),
            'revenue' => $this->exportRevenue($request),
            'driver_utilisation' => $this->exportDriverUtilisation($request),
        };
    }

    private function exportRideVolume(Request $request): StreamedResponse
    {
        $query = Ride::with(['city', 'vehicleClass'])
            ->orderBy('created_at')
            ->orderBy('id');
        $this->applyDateFilter($query, $request);
        $this->applyCityFilter($query, $request);

        return $this->exportService->streamFromQuery(
            'ride-volume-export-'.now()->format('Y-m-d').'.csv',
            ['Ride ID', 'Status', 'City', 'Vehicle Class', 'Fare Estimate', 'Final Fare', 'Payment Method', 'Created At', 'Completed At'],
            $query,
            fn (Ride $ride) => [
                $ride->id,
                $ride->status->value,
                $ride->city?->name,
                $ride->vehicleClass?->display_name,
                $ride->fare_estimate_amount,
                $ride->final_fare_amount,
                $ride->payment_method?->value,
                $ride->created_at?->toIso8601String(),
                $ride->completed_at?->toIso8601String(),
            ],
        );
    }

    private function exportCompletionRate(Request $request): StreamedResponse
    {
        $query = Ride::whereIn('status', [RideStatus::Completed, RideStatus::Cancelled, RideStatus::NoDriverFound])
            ->with(['city', 'cancelledByUser'])
            ->orderBy('created_at')
            ->orderBy('id');
        $this->applyDateFilter($query, $request);
        $this->applyCityFilter($query, $request);

        return $this->exportService->streamFromQuery(
            'completion-rate-export-'.now()->format('Y-m-d').'.csv',
            ['Ride ID', 'Status', 'City', 'Cancellation Reason', 'Cancelled By', 'Created At'],
            $query,
            fn (Ride $ride) => [
                $ride->id,
                $ride->status->value,
                $ride->city?->name,
                $ride->cancellation_reason?->value,
                $ride->cancelledByUser ? $ride->cancelledByUser->first_name.' '.$ride->cancelledByUser->last_name : null,
                $ride->created_at?->toIso8601String(),
            ],
        );
    }

    private function exportRevenue(Request $request): StreamedResponse
    {
        $query = Ride::where('status', RideStatus::Completed)
            ->with(['city', 'vehicleClass', 'payment'])
            ->orderBy('completed_at')
            ->orderBy('id');
        $this->applyDateFilter($query, $request);
        $this->applyCityFilter($query, $request);

        return $this->exportService->streamFromQuery(
            'revenue-export-'.now()->format('Y-m-d').'.csv',
            ['Ride ID', 'City', 'Vehicle Class', 'Final Fare', 'Tip', 'Payment Method', 'Payment Status', 'Completed At'],
            $query,
            fn (Ride $ride) => [
                $ride->id,
                $ride->city?->name,
                $ride->vehicleClass?->display_name,
                $ride->final_fare_amount,
                $ride->payment?->tip_amount ?? '0.00',
                $ride->payment_method?->value,
                $ride->payment?->status?->value,
                $ride->completed_at?->toIso8601String(),
            ],
        );
    }

    private function exportDriverUtilisation(Request $request): StreamedResponse
    {
        $query = Ride::where('status', RideStatus::Completed)
            ->join('users', 'rides.driver_id', '=', 'users.id')
            ->select(
                'rides.driver_id',
                DB::raw("concat(users.first_name, ' ', users.last_name) as driver_name"),
                DB::raw('count(*) as trip_count'),
                DB::raw('sum(rides.final_fare_amount) as total_earnings'),
                DB::raw('avg(rides.final_fare_amount) as avg_fare'),
                DB::raw('min(rides.created_at) as first_trip'),
                DB::raw('max(rides.created_at) as last_trip'),
            )
            ->groupBy('rides.driver_id', 'users.first_name', 'users.last_name');
        $this->applyDateFilter($query, $request);
        $this->applyCityFilter($query, $request);

        $results = $query->orderByDesc('trip_count')->get();

        $rows = $results->map(fn ($row) => [
            $row->driver_id,
            $row->driver_name,
            $row->trip_count,
            round((float) $row->total_earnings, 2),
            round((float) $row->avg_fare, 2),
            $row->first_trip,
            $row->last_trip,
        ]);

        return $this->exportService->streamCsv(
            'driver-utilisation-export-'.now()->format('Y-m-d').'.csv',
            ['Driver ID', 'Driver Name', 'Trip Count', 'Total Earnings', 'Average Fare', 'First Trip', 'Last Trip'],
            $rows,
        );
    }

    private function rideVolumeTrend(Builder $query, string $groupBy): array
    {
        $dateExpr = match ($groupBy) {
            'day' => "to_char(created_at, 'YYYY-MM-DD')",
            'week' => "to_char(date_trunc('week', created_at), 'YYYY-MM-DD')",
            'month' => "to_char(created_at, 'YYYY-MM')",
        };

        return $query
            ->select(DB::raw("{$dateExpr} as period"), DB::raw('count(*) as count'))
            ->groupBy(DB::raw($dateExpr))
            ->orderBy(DB::raw($dateExpr))
            ->get()
            ->map(fn ($row) => ['period' => $row->period, 'count' => (int) $row->count])
            ->values()
            ->toArray();
    }

    private function revenueTrend(Builder $query, string $groupBy): array
    {
        $dateExpr = match ($groupBy) {
            'day' => "to_char(completed_at, 'YYYY-MM-DD')",
            'week' => "to_char(date_trunc('week', completed_at), 'YYYY-MM-DD')",
            'month' => "to_char(completed_at, 'YYYY-MM')",
        };

        return $query
            ->select(
                DB::raw("{$dateExpr} as period"),
                DB::raw('count(*) as count'),
                DB::raw('sum(final_fare_amount) as total'),
            )
            ->groupBy(DB::raw($dateExpr))
            ->orderBy(DB::raw($dateExpr))
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ])
            ->values()
            ->toArray();
    }

    private function applyDateFilter(Builder $query, Request $request): void
    {
        if ($request->filled('from')) {
            $query->whereDate('rides.created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('rides.created_at', '<=', $request->input('to'));
        }
    }

    private function applyCityFilter(Builder $query, Request $request): void
    {
        if ($request->filled('city_id')) {
            $query->where('rides.city_id', $request->input('city_id'));
        }
    }

    private function periodSummary(Request $request): array
    {
        return [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];
    }
}
