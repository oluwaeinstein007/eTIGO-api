<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\TripCarbonScore;
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

        if ($ride->status !== RideStatus::Completed) {
            abort(404, 'Receipt is only available for completed rides.');
        }

        $ride->load(['passenger', 'driver.driver.vehicle', 'vehicleClass', 'payment.paymentMethod', 'city']);

        $snapshot = $ride->pricing_snapshot ?? [];
        $payment = $ride->payment;

        $carbonScore = TripCarbonScore::where('ride_id', $ride->id)
            ->where('user_id', $ride->passenger_id)
            ->first();

        $tierDiscount = $snapshot['tier_discount'] ?? null;

        return response()->json([
            'receipt' => [
                'ride_id' => $ride->id,
                'reference_number' => $ride->reference_number,
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
                    'profile_photo_url' => $ride->driver->profile_photo_path
                        ? \Illuminate\Support\Facades\Storage::disk(config('filesystems.uploads'))->url($ride->driver->profile_photo_path)
                        : null,
                ] : null,
                'vehicle' => $ride->driver?->driver?->vehicle ? [
                    'make' => $ride->driver->driver->vehicle->make,
                    'model' => $ride->driver->driver->vehicle->model,
                    'colour' => $ride->driver->driver->vehicle->colour,
                    'plate_number' => $ride->driver->driver->vehicle->plate_number,
                    'year' => $ride->driver->driver->vehicle->year,
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
                    'waiting_charge' => (float) ($snapshot['waiting_charge'] ?? 0),
                    'tip' => $payment ? (float) ($payment->tip_amount ?? 0) : 0,
                    'tier_discount' => $tierDiscount ? [
                        'tier' => $tierDiscount['tier'] ?? null,
                        'percentage' => $tierDiscount['percentage'] ?? null,
                        'amount' => (float) ($tierDiscount['amount'] ?? 0),
                    ] : null,
                    'minimum_fare' => (float) ($snapshot['minimum_fare'] ?? 0),
                    'surge_multiplier' => $snapshot['surge_multiplier'] ?? null,
                ],
                'fare_estimate' => $ride->fare_estimate_amount,
                'final_fare' => $ride->final_fare_amount,
                'currency' => $ride->fare_currency,
                'payment' => $payment ? $this->formatPayment($payment) : null,
                'carbon' => $carbonScore ? [
                    'estimated_emissions_kg' => round((float) $carbonScore->vehicle_emission, 2),
                    'carbon_credit_accrued_kg' => round((float) $carbonScore->co2_saved, 2),
                    'green_points_earned' => $carbonScore->final_points,
                ] : null,
                'city' => $ride->city?->name,
            ],
        ]);
    }

    private function formatPayment(Payment $payment): array
    {
        $isSettled = in_array($payment->status, [
            PaymentStatus::Captured,
            PaymentStatus::Collected,
            PaymentStatus::Settled,
        ]);

        $data = [
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'tip_amount' => $payment->tip_amount,
        ];

        if ($payment->paymentMethod) {
            $data['card_brand'] = $payment->paymentMethod->card_brand;
            $data['card_last_four'] = $payment->paymentMethod->card_last_four;
        }

        if ($isSettled) {
            $data['total_charged'] = round((float) $payment->amount + (float) $payment->tip_amount, 2);
        } else {
            $data['amount_due'] = $payment->amount;
        }

        return $data;
    }
}
