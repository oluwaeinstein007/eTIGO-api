<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RideReceiptController extends Controller
{
    public function show(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        if ($ride->passenger_id !== $user->id && $ride->driver_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You do not have access to this receipt.');
        }

        if ($ride->status->value !== 'completed') {
            abort(404, 'Receipt is only available for completed rides.');
        }

        $ride->load(['passenger', 'driver', 'vehicleClass', 'payment', 'city']);

        $snapshot = $ride->pricing_snapshot ?? [];
        $payment = $ride->payment;

        return response()->json([
            'receipt' => [
                'ride_id' => $ride->id,
                'date' => $ride->completed_at?->toIso8601String(),
                'pickup' => [
                    'address' => $ride->pickup_address,
                    'lat' => $ride->pickup_lat,
                    'lng' => $ride->pickup_lng,
                ],
                'destination' => [
                    'address' => $ride->destination_address,
                    'lat' => $ride->destination_lat,
                    'lng' => $ride->destination_lng,
                ],
                'distance_km' => $snapshot['distance_km'] ?? null,
                'duration_minutes' => $snapshot['duration_minutes'] ?? null,
                'vehicle_class' => $ride->vehicleClass?->display_name,
                'driver' => $ride->driver ? [
                    'name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                ] : null,
                'fare_breakdown' => [
                    'base_fare' => (float) ($snapshot['base_fare'] ?? 0),
                    'per_km_rate' => (float) ($snapshot['per_km_rate'] ?? 0),
                    'per_minute_rate' => (float) ($snapshot['per_minute_rate'] ?? 0),
                    'distance_charge' => round(
                        ($snapshot['distance_km'] ?? 0) * ($snapshot['per_km_rate'] ?? 0), 2
                    ),
                    'time_charge' => round(
                        ($snapshot['duration_minutes'] ?? 0) * ($snapshot['per_minute_rate'] ?? 0), 2
                    ),
                    'waiting_charge' => (float) ($snapshot['waiting_time_rate'] ?? 0),
                    'minimum_fare' => (float) ($snapshot['minimum_fare'] ?? 0),
                    'surge_multiplier' => $snapshot['surge_multiplier'] ?? null,
                ],
                'fare_estimate' => $ride->fare_estimate_amount,
                'final_fare' => $ride->final_fare_amount,
                'currency' => $ride->fare_currency,
                'payment' => $payment ? [
                    'method' => $payment->method->value,
                    'method_label' => $payment->method->label(),
                    'status' => $payment->status->value,
                    'status_label' => $payment->status->label(),
                    'tip_amount' => $payment->tip_amount,
                    'total_charged' => round((float) $payment->amount + (float) $payment->tip_amount, 2),
                ] : null,
                'city' => $ride->city?->name,
            ],
        ]);
    }
}
