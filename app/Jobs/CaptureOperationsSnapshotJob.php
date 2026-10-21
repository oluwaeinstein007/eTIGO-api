<?php

namespace App\Jobs;

use App\Models\OperationsSnapshot;
use App\Services\OperationsMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records active rides, drivers online and drivers on a trip per city, so
 * the admin overview can say how "now" compares with this time yesterday.
 * Online state and ride stages are not kept historically anywhere else.
 */
class CaptureOperationsSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OperationsMetricsService $metrics): void
    {
        $capturedAt = now()->startOfMinute();

        $rows = collect($metrics->countsByCity())
            ->map(fn (array $counts, string $cityId) => [
                'id' => (string) Str::uuid(),
                'captured_at' => $capturedAt,
                'city_id' => $cityId === '' ? null : $cityId,
                ...$counts,
            ])
            ->values()
            ->all();

        if ($rows === []) {
            // Keep a zero row so a quiet moment still has a comparison point.
            $rows[] = [
                'id' => (string) Str::uuid(),
                'captured_at' => $capturedAt,
                'city_id' => null,
                'active_rides' => 0,
                'drivers_online' => 0,
                'drivers_on_trip' => 0,
            ];
        }

        DB::transaction(function () use ($capturedAt, $rows) {
            // A rerun in the same minute replaces that minute's rows.
            OperationsSnapshot::where('captured_at', $capturedAt)->delete();
            OperationsSnapshot::insert($rows);
        });
    }
}
