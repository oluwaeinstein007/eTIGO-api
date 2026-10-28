<?php

namespace App\Models;

use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverRepair extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'driver_repairs';

    protected $fillable = [
        'reference_number',
        'driver_id',
        'vehicle_id',
        'type',
        'status',
        'title',
        'details',
        'preferred_date',
        'preferred_time',
        'summary_note',
        'workshop_visit',
        'appointment',
        'cancellation_reason',
        'cancelled_by',
        'progress_steps',
        'attachments',
        'scheduled_at',
        'completed_at',
        'cancelled_at',
        'completion_summary',
        'handled_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => RepairServiceType::class,
            'status' => RepairRequestStatus::class,
            'preferred_date' => 'date',
            'workshop_visit' => 'array',
            'appointment' => 'array',
            'progress_steps' => 'array',
            'attachments' => 'array',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** The operations staff member who last scheduled, completed or cancelled it. */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_admin_id');
    }

    /** Still waiting on E-tiGo: received or booked, not finished or cancelled. */
    public function isOpen(): bool
    {
        return in_array($this->status, [RepairRequestStatus::InProgress, RepairRequestStatus::Scheduled], true);
    }

    public function scopeForDriver(Builder $query, string $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public static function generateReferenceNumber(): string
    {
        $connection = (new static)->getConnection();

        // A sequence gives each request its own number, so two repair
        // requests submitted together cannot collide on the reference.
        $base = $connection->getDriverName() === 'pgsql'
            ? (int) $connection->selectOne("SELECT nextval('driver_repairs_reference_number_seq') AS value")->value
            : 1040 + static::count() + 1;

        while (static::where('reference_number', "REP-{$base}")->exists()) {
            $base++;
        }

        return "REP-{$base}";
    }
}
