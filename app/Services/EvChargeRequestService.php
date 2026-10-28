<?php

namespace App\Services;

use App\Enums\EvReservationStatus;
use App\Enums\EvStationStatus;
use App\Enums\NotificationType;
use App\Models\AuditLog;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Operations review of the charge requests drivers submit from the app
 * (POST /driver/ev-reservations). A confirmed request books a slot: when the
 * slot starts, a stall is held for the driver exactly as a direct stall
 * reservation is (fee, 30-minute expiry, queue when the station is full).
 */
class EvChargeRequestService
{
    private const TIMEZONE = 'Africa/Lagos';

    /** The name drivers see on a reviewed request; staff names stay internal. */
    private const APPROVER_NAME = 'E-tiGo Operations';

    public function __construct(
        private readonly StallAvailabilityService $stallService,
        private readonly EvReservationFeeService $fees,
        private readonly NotificationService $notifications,
    ) {}

    public function confirm(
        EvReservation $request,
        User $admin,
        CarbonInterface $slotAt,
        ?EvChargingStation $station = null,
    ): EvReservation {
        // Stored as the app's time: the datetime cast keeps an input offset as is.
        $slotAt = $slotAt->copy()->setTimezone(config('app.timezone'));

        $reservation = DB::transaction(function () use ($request, $admin, $slotAt, $station) {
            $request = EvReservation::lockForUpdate()->findOrFail($request->id);

            if ($request->status !== EvReservationStatus::PendingReview) {
                throw new \RuntimeException('Only charge requests awaiting review can be confirmed.');
            }

            $station ??= $request->station;

            if (! $station) {
                throw new \RuntimeException('Choose a station for this charge request.');
            }

            if ($station->status !== EvStationStatus::Active) {
                throw new \RuntimeException('This station is not taking reservations.');
            }

            $local = $slotAt->copy()->setTimezone(self::TIMEZONE);

            $request->update([
                'status' => EvReservationStatus::Confirmed,
                'station_id' => $station->id,
                'confirmed_slot_at' => $slotAt,
                'confirmed_slot' => $local->format('h:i a'),
                'check_in_time' => $local->copy()->addMinutes($this->holdMinutes())->format('h:i a'),
                'approver_name' => self::APPROVER_NAME,
                'reviewed_by_admin_id' => $admin->id,
                'reviewed_at' => now(),
                ...$this->fees->termsFor($request->driver_id),
                'updated_at' => now(),
            ]);

            AuditLog::record($request, 'ev_charge_request.confirmed', $admin, null, [
                'station_id' => $station->id,
                'confirmed_slot_at' => $slotAt->toIso8601String(),
            ]);

            return $request->fresh(['station']);
        });

        $this->notify(
            $reservation,
            NotificationType::EvChargeRequestConfirmed,
            "Your charging slot at {$reservation->station->name} is confirmed for "
                .$this->describeSlot($reservation->confirmed_slot_at)
                .". We'll hold a stall from then; check in by {$reservation->check_in_time}.",
        );

        // A slot that has already started is held at once.
        if ($reservation->confirmed_slot_at->lte(now())) {
            $reservation = $this->startSlot($reservation);
        }

        return $reservation;
    }

    public function decline(EvReservation $request, User $admin, string $reason): EvReservation
    {
        $reservation = DB::transaction(function () use ($request, $admin, $reason) {
            $request = EvReservation::lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->status, [EvReservationStatus::PendingReview, EvReservationStatus::Confirmed], true)) {
                throw new \RuntimeException('Only charge requests awaiting review or a confirmed slot that has not started can be declined.');
            }

            $request->update([
                'status' => EvReservationStatus::Cancelled,
                'decline_reason' => $reason,
                'reviewed_by_admin_id' => $admin->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record($request, 'ev_charge_request.declined', $admin, null, ['reason' => $reason]);

            return $request->fresh(['station']);
        });

        $this->notify(
            $reservation,
            NotificationType::EvChargeRequestDeclined,
            "We couldn't book your charging request: ".rtrim($reason, '. ').'. You can reserve a stall from the station list.',
        );

        return $reservation;
    }

    /**
     * Hold stalls for confirmed slots that have started, and expire requests
     * nobody reviewed before their day ended. A reservation that fails is
     * reported and retried on the next run without holding up the others.
     *
     * @return array{started: int, expired: int}
     */
    public function processDue(): array
    {
        $started = 0;

        EvReservation::where('status', EvReservationStatus::Confirmed)
            ->where('confirmed_slot_at', '<=', now())
            ->orderBy('confirmed_slot_at')
            ->get()
            ->each(function (EvReservation $reservation) use (&$started) {
                if (rescue(fn () => $this->startSlot($reservation)) !== null) {
                    $started++;
                }
            });

        $today = now(self::TIMEZONE)->toDateString();
        $expired = 0;

        EvReservation::where('status', EvReservationStatus::PendingReview)
            ->whereDate('requested_date', '<', $today)
            ->get()
            ->each(function (EvReservation $reservation) use (&$expired) {
                if (rescue(fn () => $this->expireUnreviewed($reservation), false)) {
                    $expired++;
                }
            });

        return ['started' => $started, 'expired' => $expired];
    }

    /**
     * Hold a stall for a confirmed slot: reserved when one is free (fee
     * taken), otherwise first in line for the next one. A slot found long
     * after it started (the scheduler was down) expires instead.
     */
    public function startSlot(EvReservation $reservation): EvReservation
    {
        $outcome = null;

        $reservation = DB::transaction(function () use ($reservation, &$outcome) {
            $reservation = EvReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== EvReservationStatus::Confirmed) {
                return $reservation;
            }

            $station = EvChargingStation::whereKey($reservation->station_id)->lockForUpdate()->first();

            if (! $station || $station->status !== EvStationStatus::Active) {
                $reservation->update([
                    'status' => EvReservationStatus::Cancelled,
                    'notes' => $this->appendNote($reservation, 'Cancelled at the slot: the station is not taking reservations.'),
                    'updated_at' => now(),
                ]);
                $outcome = 'station_unavailable';

                return $reservation->fresh(['station', 'stall']);
            }

            if ($reservation->confirmed_slot_at->lte(now()->subMinutes($this->holdMinutes()))) {
                $reservation->update(['status' => EvReservationStatus::Expired, 'updated_at' => now()]);
                $outcome = 'missed';

                return $reservation->fresh(['station', 'stall']);
            }

            $stall = $this->stallService->findAvailableStall($station);

            try {
                // A free stall is paid for now; a queued driver pays when the
                // stall is handed over, so only their balance is checked here.
                $stall
                    ? $this->fees->charge($reservation)
                    : $this->fees->assertCanPay(User::findOrFail($reservation->driver_id), $this->fees->feeKobo($reservation));
            } catch (\RuntimeException $e) {
                $reservation->update([
                    'status' => EvReservationStatus::Cancelled,
                    'notes' => $this->appendNote($reservation, 'Cancelled at the slot: '.$e->getMessage()),
                    'updated_at' => now(),
                ]);
                $outcome = 'fee_unpaid';

                return $reservation->fresh(['station', 'stall']);
            }

            if ($stall) {
                $this->stallService->reserveStall($stall);
                $reservation->update([
                    'stall_id' => $stall->id,
                    'status' => EvReservationStatus::Reserved,
                    'reserved_at' => now(),
                    'updated_at' => now(),
                ]);
                $outcome = 'reserved';

                return $reservation->fresh(['station', 'stall']);
            }

            // Station full: first come, first served for the next free stall.
            $reservation->update([
                'status' => EvReservationStatus::Queued,
                'queue_position' => EvReservation::forStation($station->id)->queued()->count() + 1,
                'reserved_at' => now(),
                'updated_at' => now(),
            ]);
            $outcome = 'queued';

            return $reservation->fresh(['station', 'stall']);
        });

        $stationName = $reservation->station?->name ?? 'the station';

        match ($outcome) {
            'reserved' => $this->notify(
                $reservation,
                NotificationType::EvReservationReady,
                "Stall {$reservation->stall?->stall_number} at {$stationName} is ready for you. Check in by {$reservation->check_in_time}.",
                ['stall_number' => (string) ($reservation->stall?->stall_number ?? '')],
            ),
            'queued' => $this->notify(
                $reservation,
                NotificationType::EvReservationReady,
                "Your slot at {$stationName} has started, but every stall is busy. You're next in line; we'll tell you when a stall is free.",
            ),
            'fee_unpaid', 'station_unavailable', 'missed' => $this->notify(
                $reservation,
                NotificationType::EvReservationCancelled,
                "Your charging slot at {$stationName} was cancelled. ".match ($outcome) {
                    'fee_unpaid' => 'Your earnings balance could not cover the reservation fee.',
                    'station_unavailable' => 'The station is not taking reservations right now.',
                    'missed' => 'We could not hold a stall before your check-in time passed. You can reserve a stall from the station list.',
                },
            ),
            default => null,
        };

        if ($outcome !== null) {
            Log::info('EV charge request slot started', ['reservation_id' => $reservation->id, 'outcome' => $outcome]);
        }

        return $reservation;
    }

    private function expireUnreviewed(EvReservation $reservation): bool
    {
        $expired = DB::transaction(function () use ($reservation) {
            $reservation = EvReservation::lockForUpdate()->find($reservation->id);

            if ($reservation?->status !== EvReservationStatus::PendingReview) {
                return null;
            }

            $reservation->update(['status' => EvReservationStatus::Expired, 'updated_at' => now()]);

            return $reservation->fresh(['station']);
        });

        if (! $expired) {
            return false;
        }

        $this->notify(
            $expired,
            NotificationType::EvChargeRequestDeclined,
            'Your charging request for '.$expired->requested_date->format('j M').' was not booked in time. You can reserve a stall from the station list.',
        );

        return true;
    }

    private function holdMinutes(): int
    {
        return (int) config('ev_charging.reservation_expiry_minutes', 30);
    }

    private function describeSlot(CarbonInterface $slotAt): string
    {
        $local = $slotAt->copy()->setTimezone(self::TIMEZONE);
        $today = now(self::TIMEZONE);

        return match (true) {
            $local->isSameDay($today) => 'today at '.$local->format('g:i A'),
            $local->isSameDay($today->copy()->addDay()) => 'tomorrow at '.$local->format('g:i A'),
            default => $local->format('D j M, g:i A'),
        };
    }

    private function appendNote(EvReservation $reservation, string $note): string
    {
        return trim(($reservation->notes ? $reservation->notes."\n" : '').$note);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function notify(EvReservation $reservation, NotificationType $type, string $body, array $extra = []): void
    {
        rescue(fn () => $this->notifications->send(
            userId: $reservation->driver_id,
            type: $type,
            title: $type->label(),
            body: $body,
            data: [
                'reservation_id' => (string) $reservation->id,
                'station_name' => (string) ($reservation->station?->name ?? ''),
                ...$extra,
            ],
        ));
    }
}
