<?php

namespace App\Models;

use App\Enums\LostItemCategory;
use App\Enums\LostItemClosureReason;
use App\Enums\LostItemStatus;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item left in the car after a completed trip (Phase 2 Lost & Found).
 * The passenger reports it, or the driver hands it in; the driver says
 * whether they have it, the two arrange the return through the report's
 * messages, and support steps in when either side goes quiet.
 */
class LostItem extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    public const REPORTED_BY_PASSENGER = 'passenger';

    public const REPORTED_BY_DRIVER = 'driver';

    protected $fillable = [
        'reference_number',
        'ride_id',
        'passenger_id',
        'driver_id',
        'reported_by',
        'reported_by_user_id',
        'category',
        'description',
        'photos',
        'contact_phone',
        'status',
        'driver_response',
        'driver_notified_at',
        'driver_responded_at',
        'returned_at',
        'escalated_at',
        'escalation_reason',
        'escalation_source',
        'resolved_at',
        'closed_at',
        'closure_reason',
        'cancelled_at',
        'resolution_notes',
        'resolved_by_admin_id',
        'return_fee',
        'return_fee_status',
    ];

    protected static function referencePrefix(): string
    {
        return 'LF-';
    }

    protected function casts(): array
    {
        return [
            'category' => LostItemCategory::class,
            'status' => LostItemStatus::class,
            'closure_reason' => LostItemClosureReason::class,
            'photos' => 'array',
            'return_fee' => 'decimal:2',
            'driver_notified_at' => 'datetime',
            'driver_responded_at' => 'datetime',
            'returned_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LostItemMessage::class)->oldest();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', LostItemStatus::terminal());
    }

    /**
     * Open reports support has to act on.
     */
    public function scopeNeedsAttention(Builder $query): void
    {
        $query->open()->where(fn (Builder $q) => $q
            ->whereNotNull('escalated_at')
            ->orWhere('status', LostItemStatus::Reported));
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }

    public function isPassenger(User $user): bool
    {
        return $this->passenger_id === $user->id;
    }

    public function isDriver(User $user): bool
    {
        return $this->driver_id !== null && $this->driver_id === $user->id;
    }

    /**
     * The return fee the passenger pays when the item comes back, in NGN.
     */
    public function returnFeeAmount(): float
    {
        return round((float) ($this->return_fee ?? 0), 2);
    }

    /**
     * Passenger and driver see each other's phone number only while the
     * driver has the item and it is on its way back.
     */
    public function sharesContactDetails(): bool
    {
        return in_array($this->status, [LostItemStatus::DriverConfirmed, LostItemStatus::Returned], true)
            && AppSetting::lostAndFound()['share_contact_details'];
    }

    /**
     * The number the driver should call to return the item.
     */
    public function passengerContactPhone(): ?string
    {
        return $this->contact_phone ?: $this->passenger?->phone;
    }

    /**
     * What this user can do with the report right now, so the apps show the
     * right buttons.
     *
     * @return list<string>
     */
    public function actionsFor(User $user): array
    {
        if (! $this->isOpen()) {
            return [];
        }

        $actions = [];

        if ($this->isPassenger($user)) {
            if (in_array($this->status, [LostItemStatus::Reported, LostItemStatus::DriverNotified, LostItemStatus::DriverDenied], true)) {
                $actions[] = 'cancel';
            }
            if (in_array($this->status, [LostItemStatus::DriverConfirmed, LostItemStatus::Returned], true)) {
                $actions[] = 'confirm_received';
            }
            if ($this->escalated_at === null && $this->status !== LostItemStatus::Reported) {
                $actions[] = 'escalate';
            }
            if ($this->driver_id !== null) {
                $actions[] = 'message';
            }
        } elseif ($this->isDriver($user)) {
            if (in_array($this->status, [LostItemStatus::Reported, LostItemStatus::DriverNotified, LostItemStatus::DriverDenied], true)) {
                $actions[] = 'respond';
            }
            if ($this->status === LostItemStatus::DriverConfirmed) {
                $actions[] = 'mark_returned';
            }
            $actions[] = 'message';
        }

        return $actions;
    }
}
