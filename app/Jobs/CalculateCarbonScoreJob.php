<?php

namespace App\Jobs;

use App\Models\GamificationProfile;
use App\Models\Ride;
use App\Models\TripCarbonScore;
use App\Services\CarbonScoreService;
use App\Services\PointMultiplierService;
use App\Services\TierEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateCarbonScoreJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $rideId,
    ) {}

    public function uniqueId(): string
    {
        return $this->rideId;
    }

    public function handle(
        CarbonScoreService $carbonScoreService,
        PointMultiplierService $multiplierService,
        TierEvaluationService $tierEvaluationService,
    ): void {
        $ride = Ride::with('vehicleClass')->find($this->rideId);

        if (! $ride) {
            Log::warning('CalculateCarbonScoreJob: ride not found', ['ride_id' => $this->rideId]);

            return;
        }

        if (TripCarbonScore::where('ride_id', $ride->id)->where('user_id', $ride->passenger_id)->exists()) {
            Log::info('CalculateCarbonScoreJob: score already calculated', ['ride_id' => $this->rideId]);

            return;
        }

        $scoreResult = $carbonScoreService->calculateForRide($ride, $ride->passenger_id);
        $tripScore = $scoreResult['trip_carbon_score'];

        $multiplierResult = $multiplierService->applyMultipliers($tripScore, $ride);

        $profile = GamificationProfile::findOrCreateForUser($ride->passenger_id);

        $tierResult = $tierEvaluationService->evaluateAndPromote(
            $profile,
            $multiplierResult['final_points'],
            $scoreResult['co2_saved_kg'],
        );

        Log::info('Carbon score calculated', [
            'ride_id' => $ride->id,
            'user_id' => $ride->passenger_id,
            'co2_saved_kg' => $scoreResult['co2_saved_kg'],
            'base_points' => $scoreResult['base_points'],
            'multiplier' => $multiplierResult['multiplier'],
            'final_points' => $multiplierResult['final_points'],
            'promoted' => $tierResult['promoted'],
            'new_tier' => $tierResult['new_tier'],
        ]);
    }
}
