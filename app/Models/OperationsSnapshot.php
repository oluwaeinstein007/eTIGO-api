<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Live counts for one city at one moment (CaptureOperationsSnapshotJob).
 * Rows from the same run share captured_at; a null city holds drivers and
 * rides with no city.
 */
class OperationsSnapshot extends Model
{
    use HasUuids, Prunable;

    public const RETENTION_DAYS = 35;

    /** How far from the target time a snapshot may be and still count. */
    public const MATCH_TOLERANCE_MINUTES = 10;

    public $timestamps = false;

    protected $fillable = [
        'captured_at',
        'city_id',
        'active_rides',
        'drivers_online',
        'drivers_on_trip',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'active_rides' => 'integer',
            'drivers_online' => 'integer',
            'drivers_on_trip' => 'integer',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function prunable(): Builder
    {
        return static::where('captured_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * Totals across all cities from the run closest to $at, or null when no
     * run landed within the tolerance (e.g. the job was not running then).
     *
     * @return array{captured_at: Carbon, active_rides: int, drivers_online: int, drivers_on_trip: int}|null
     */
    public static function totalsNear(CarbonInterface $at): ?array
    {
        $capturedAt = static::query()
            ->whereBetween('captured_at', [
                $at->copy()->subMinutes(self::MATCH_TOLERANCE_MINUTES),
                $at->copy()->addMinutes(self::MATCH_TOLERANCE_MINUTES),
            ])
            ->orderByRaw('ABS(EXTRACT(EPOCH FROM (captured_at - ?::timestamp)))', [$at->toDateTimeString()])
            ->value('captured_at');

        if ($capturedAt === null) {
            return null;
        }

        $totals = static::where('captured_at', $capturedAt)
            ->selectRaw('SUM(active_rides) AS active_rides, SUM(drivers_online) AS drivers_online, SUM(drivers_on_trip) AS drivers_on_trip')
            ->first();

        return [
            'captured_at' => Carbon::parse($capturedAt),
            'active_rides' => (int) $totals->active_rides,
            'drivers_online' => (int) $totals->drivers_online,
            'drivers_on_trip' => (int) $totals->drivers_on_trip,
        ];
    }
}
