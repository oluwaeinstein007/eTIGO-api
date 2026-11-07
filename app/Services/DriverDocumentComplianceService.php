<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Notifications\DriverDocumentExpiringNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Driver document renewals. Papers are renewed when the date printed on
 * them runs out, not on a yearly clock, and renewing one is a document
 * check by operations, not a new KYC: identity stays settled from
 * onboarding.
 *
 * An approved paper stays valid until its renewal is approved, so a driver
 * who renews early never loses a day. An expired driving licence (after the
 * grace period) or one operations withdrew keeps the driver off the road:
 * they cannot go online and get no offers until a renewed licence is
 * approved. A trip in progress is never ended. Government ID only reminds.
 *
 * Vehicle papers keep their own block in VehicleMaintenanceService; here
 * they only get reminder emails (the push comes from vehicle maintenance).
 */
class DriverDocumentComplianceService
{
    public const TIMEZONE = 'Africa/Lagos';

    /** The reminder step stored once a paper has expired. */
    public const STAGE_EXPIRED = -1;

    public function __construct(
        private NotificationService $notifications,
        private OperationsAlertService $operations,
    ) {}

    /**
     * Recompute the driver's licence block and act on a change: block (tell
     * the driver, take them offline unless on a trip, release reserved
     * pickups) or lift it.
     */
    public function refresh(Driver $driver): Driver
    {
        $reason = $this->blockReason($driver);

        if ($reason !== null && $driver->documents_blocked_at === null) {
            $driver->forceFill(['documents_blocked_at' => now(), 'documents_block_reason' => $reason])->saveQuietly();
            AuditLog::record($driver, 'driver_documents_blocked', null, null, ['reason' => $reason]);
            DB::afterCommit(fn () => $this->blockStarted($driver->fresh(['user', 'vehicle'])));
        } elseif ($reason !== null && $driver->documents_block_reason !== $reason) {
            $driver->forceFill(['documents_block_reason' => $reason])->saveQuietly();
        } elseif ($reason === null && $driver->documents_blocked_at !== null) {
            $driver->forceFill(['documents_blocked_at' => null, 'documents_block_reason' => null])->saveQuietly();
            AuditLog::record($driver, 'driver_documents_unblocked');
            DB::afterCommit(fn () => $this->blockLifted($driver));
        }

        return $driver;
    }

    /**
     * Why the driver cannot drive on their papers, or null. A driver with no
     * licence on file at all is left to the onboarding rules; this only
     * catches a licence that was valid and no longer is.
     */
    public function blockReason(Driver $driver): ?string
    {
        $settings = AppSetting::driverDocuments();

        if (! $settings['block_on_expired_licence']) {
            return null;
        }

        $licences = $driver->documents()->where('type', DocumentType::DrivingLicence)->get();
        $approved = $licences->filter(fn (DriverDocument $document) => $this->isCurrent($document));

        if ($approved->contains(fn (DriverDocument $document) => $document->expires_at === null || $this->blocksFrom($document->expires_at, $settings)->gt($this->today()))) {
            return null;
        }

        $expired = $approved->sortByDesc(fn (DriverDocument $document) => $document->expires_at)->first();

        if ($expired !== null) {
            return "Your driving licence expired on {$expired->expires_at->format('j M Y')}.";
        }

        $revoked = $licences->whereNotNull('revoked_at')->sortByDesc('revoked_at')->first();

        if ($revoked !== null) {
            return 'Your driving licence was withdrawn by E-tiGo'.($revoked->rejection_reason ? ": {$revoked->rejection_reason}" : '.');
        }

        return null;
    }

    /**
     * What the driver should know when going online: why they are off the
     * road, or a warning about a licence about to expire or in its grace
     * period.
     *
     * @return array{blocked: ?string, notice: ?string}
     */
    public function goOnlineCheck(Driver $driver): array
    {
        $this->refresh($driver);

        if ($driver->isDocumentsBlocked()) {
            return [
                'blocked' => "{$driver->documents_block_reason} Upload {$this->licenceToUpload($driver)}; you can go online once E-tiGo approves it.",
                'notice' => null,
            ];
        }

        $licence = $this->currentDocument($driver, DocumentType::DrivingLicence);

        if ($licence?->expires_at === null) {
            return ['blocked' => null, 'notice' => null];
        }

        $days = $this->daysUntilExpiry($licence);

        if ($days > 7) {
            return ['blocked' => null, 'notice' => null];
        }

        $renewal = $this->pendingRenewal($licence);

        return [
            'blocked' => null,
            'notice' => $renewal !== null
                ? 'Your renewed driving licence is being checked by E-tiGo.'
                : $this->reminderLine($licence, AppSetting::driverDocuments()),
        ];
    }

    /**
     * The driver's papers as the app and dashboard show them: one entry per
     * paper type on file, with its expiry state and any renewal waiting for
     * review. Fleet cars' papers are left out (operations manage them).
     *
     * @return array{can_drive: bool, block_reason: ?string, action_required: bool, documents: list<array<string, mixed>>}
     */
    public function summary(Driver $driver): array
    {
        $settings = AppSetting::driverDocuments();
        $documents = $driver->documents()->with('vehicle:id,is_fleet')->get();
        $entries = [];

        foreach (DocumentType::cases() as $type) {
            $ofType = $documents->filter(fn (DriverDocument $document) => $document->type === $type && ! ($document->vehicle?->is_fleet ?? false));

            if ($ofType->isEmpty()) {
                continue;
            }

            $current = $ofType->filter(fn (DriverDocument $document) => $this->isCurrent($document))
                ->sortByDesc(fn (DriverDocument $document) => $document->expires_at?->timestamp ?? PHP_INT_MAX)
                ->first();
            $renewal = $ofType->filter(fn (DriverDocument $document) => $document->status !== DocumentStatus::Approved && $document->revoked_at === null)
                ->sortByDesc('created_at')
                ->first();
            $revoked = $ofType->whereNotNull('revoked_at')->sortByDesc('revoked_at')->first();
            $expiryStatus = $current !== null ? $this->expiryStatus($current, $settings) : null;

            $entries[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'document_id' => $current?->id,
                'expires_at' => $current?->expires_at?->toDateString(),
                'expiry_status' => $expiryStatus,
                'days_until_expiry' => $current?->expires_at !== null ? $this->daysUntilExpiry($current) : null,
                'blocks_driving' => $type->blocksDriverWhenExpired(),
                'revoked_at' => $current === null ? $revoked?->revoked_at?->toIso8601String() : null,
                'renewal' => $renewal !== null ? [
                    'document_id' => $renewal->id,
                    'status' => $renewal->status->value,
                    'expires_at' => $renewal->expires_at?->toDateString(),
                    'rejection_reason' => $renewal->rejection_reason,
                    'uploaded_at' => $renewal->created_at?->toIso8601String(),
                ] : null,
                // Something for the driver to do: upload a renewal, or fix a rejected one.
                'action_required' => ($renewal === null || $renewal->status === DocumentStatus::Rejected)
                    && ($current === null || in_array($expiryStatus, ['expiring_soon', 'expired'], true)),
            ];
        }

        return [
            'can_drive' => ! $driver->isDocumentsBlocked(),
            'block_reason' => $driver->documents_block_reason,
            'action_required' => collect($entries)->contains('action_required', true),
            'documents' => $entries,
        ];
    }

    /**
     * Daily: remind drivers about papers coming up for renewal, at each step
     * (30, 14, 7 and 1 days before, the day itself, and once expired). A
     * paper approved late only gets the nearest step. A paper with a renewal
     * already uploaded, or replaced by a later one, gets nothing.
     *
     * @return int reminders sent
     */
    public function sendReminders(): int
    {
        $settings = AppSetting::driverDocuments();

        // Off: nothing is sent and nothing is marked, so drivers hear about
        // their papers once reminders are back on.
        if (! $settings['reminders_enabled'] || $settings['reminder_days'] === []) {
            return 0;
        }

        $horizon = $this->today()->addDays(max($settings['reminder_days']))->toDateString();
        $sent = 0;

        DriverDocument::query()
            ->where('status', DocumentStatus::Approved)
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $horizon)
            ->where(fn ($q) => $q->whereNull('expiry_reminder_stage')->orWhere('expiry_reminder_stage', '>', self::STAGE_EXPIRED))
            ->with(['driver.user', 'vehicle'])
            ->lazyById()
            ->each(function (DriverDocument $document) use ($settings, &$sent) {
                try {
                    if ($this->remind($document, $settings)) {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Driver document expiry reminder failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }

    /**
     * Drivers whose licence block may have started or ended since the last
     * check: those blocked now and those with an approved licence past its
     * expiry date.
     */
    public function refreshDue(): int
    {
        $today = $this->today()->toDateString();
        $count = 0;

        Driver::query()
            ->where(fn ($q) => $q->whereNotNull('documents_blocked_at')
                ->orWhereHas('documents', fn ($d) => $d->where('type', DocumentType::DrivingLicence)
                    ->where('status', DocumentStatus::Approved)
                    ->whereNull('revoked_at')
                    ->whereDate('expires_at', '<', $today)))
            ->lazyById()
            ->each(function (Driver $driver) use (&$count) {
                try {
                    $this->refresh($driver);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error('Driver document block check failed', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);
                }
            });

        return $count;
    }

    /**
     * The driver's approved paper of a type that counts now: not withdrawn,
     * and the one running longest if two are approved.
     */
    public function currentDocument(Driver $driver, DocumentType $type): ?DriverDocument
    {
        return $driver->documents()
            ->where('type', $type)
            ->where('status', DocumentStatus::Approved)
            ->whereNull('revoked_at')
            ->orderByRaw('expires_at desc nulls first')
            ->first();
    }

    /**
     * A renewal of this paper uploaded and not yet approved (pending), if any.
     */
    public function pendingRenewal(DriverDocument $document): ?DriverDocument
    {
        return $this->sameSlot($document)
            ->where('status', DocumentStatus::Pending)
            ->latest()
            ->first();
    }

    /**
     * no_expiry, valid, expiring_soon (within the first reminder step) or expired.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function expiryStatus(DriverDocument $document, ?array $settings = null): string
    {
        if ($document->expires_at === null) {
            return 'no_expiry';
        }

        $settings ??= AppSetting::driverDocuments();
        $days = $this->daysUntilExpiry($document);

        return match (true) {
            $days < 0 => 'expired',
            $days <= (max($settings['reminder_days'] ?: [0])) => 'expiring_soon',
            default => 'valid',
        };
    }

    /**
     * Calendar days from today (Lagos) to the expiry date: 0 on the day
     * itself, negative once expired.
     */
    public function daysUntilExpiry(DriverDocument $document): int
    {
        return (int) $this->today()->diffInDays($this->localDate($document->expires_at), false);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function blocksFrom(Carbon $expiresAt, array $settings): Carbon
    {
        return $this->localDate($expiresAt)->addDays($settings['licence_grace_days'] + 1);
    }

    public function today(): Carbon
    {
        return now()->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * The reminder step for a number of days left: the closest step at or
     * above it (so a paper approved with 5 days left gets the 7-day step
     * only), 0 on the day, STAGE_EXPIRED after, null when not due yet.
     *
     * @param  list<int>  $reminderDays
     */
    public function stageFor(int $daysLeft, array $reminderDays): ?int
    {
        if ($daysLeft < 0) {
            return self::STAGE_EXPIRED;
        }

        if ($daysLeft === 0) {
            return 0;
        }

        $steps = array_filter($reminderDays, fn (int $day) => $day >= $daysLeft);

        return $steps === [] ? null : min($steps);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function reminderLine(DriverDocument $document, array $settings): string
    {
        $label = $document->type->label();
        $date = $document->expires_at->format('j M Y');
        $days = $this->daysUntilExpiry($document);
        $blocks = $document->type->blocksDriverWhenExpired() && $settings['block_on_expired_licence'];
        $lastDay = $this->blocksFrom($document->expires_at, $settings)->subDay()->format('j M Y');

        if ($days < 0) {
            return $blocks
                ? "Your {$this->lower($label)} expired on {$date}. Upload the renewed one by {$lastDay} to keep driving."
                : "Your {$this->lower($label)} expired on {$date}. Upload the new one to keep your records up to date.";
        }

        $when = match ($days) {
            0 => 'today',
            1 => 'tomorrow',
            default => "on {$date} (in {$days} days)",
        };

        return $blocks
            ? "Your {$this->lower($label)} expires {$when}. Upload the renewed licence now so E-tiGo can check it in time; without it you can't go online after {$lastDay}."
            : "Your {$this->lower($label)} expires {$when}. Upload the renewed paper so E-tiGo can check it.";
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function remind(DriverDocument $document, array $settings): bool
    {
        $driver = $document->driver;

        if ($driver?->user === null || ! $this->isCurrent($document)) {
            return false;
        }

        // Vehicle papers: own cars only, while the car is still the driver's.
        if ($document->vehicle_id !== null && ($document->vehicle === null || $document->vehicle->is_fleet || $document->vehicle->driver_id !== $driver->id)) {
            return false;
        }

        $stage = $this->stageFor($this->daysUntilExpiry($document), $settings['reminder_days']);

        if ($stage === null || ($document->expiry_reminder_stage !== null && $stage >= $document->expiry_reminder_stage)) {
            return false;
        }

        $mark = fn () => $document->forceFill(['expiry_reminder_stage' => $stage, 'expiry_reminder_sent_at' => now()])->saveQuietly();

        // Replaced by a longer-running paper, or a renewal is already in:
        // the driver has done their part.
        $superseded = $this->sameSlot($document)
            ->where('status', DocumentStatus::Approved)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>', $document->expires_at->toDateString()))
            ->exists();

        // Blocked: the block message has told the driver already.
        $toldByBlock = $stage === self::STAGE_EXPIRED && $document->type->blocksDriverWhenExpired() && $driver->isDocumentsBlocked();

        if ($superseded || $toldByBlock || $this->pendingRenewal($document) !== null) {
            $mark();

            return false;
        }

        $line = $this->reminderLine($document, $settings);

        // Vehicle legal papers get their push from vehicle maintenance.
        if (! $document->type->isVehicleLegalPaper()) {
            $this->safely(fn () => $this->notifications->send(
                userId: $driver->user_id,
                type: NotificationType::DocumentExpiring,
                title: $stage === self::STAGE_EXPIRED ? "{$document->type->label()} Expired" : NotificationType::DocumentExpiring->label(),
                body: $line,
                data: [
                    'document_id' => (string) $document->id,
                    'document_type' => $document->type->value,
                    'expires_at' => $document->expires_at->toDateString(),
                ],
            ));
        }

        if ($driver->user->email) {
            $this->safely(fn () => $driver->user->notify(new DriverDocumentExpiringNotification($driver, $document, $line)));
        }

        $mark();

        return true;
    }

    private function blockStarted(Driver $driver): void
    {
        $this->safely(fn () => $this->notifications->sendCritical(
            userId: $driver->user_id,
            type: NotificationType::DriverDocumentsBlocked,
            title: NotificationType::DriverDocumentsBlocked->label(),
            body: "{$driver->documents_block_reason} Upload {$this->licenceToUpload($driver)}; you can go online again once E-tiGo approves it. Your account, ratings and earnings are unaffected.",
            data: ['driver_id' => (string) $driver->id],
        ));

        $this->safely(fn () => $this->takeOfflineUnlessOnTrip($driver));
        $this->safely(fn () => app(ScheduledRideReservationService::class)->releaseAllFor($driver->user_id, 'documents_blocked'));

        if ($driver->isFleetVehicle()) {
            $name = trim(($driver->user?->first_name ?? '').' '.($driver->user?->last_name ?? ''));

            $this->safely(fn () => $this->operations->send(
                label: 'Fleet driver off the road',
                headline: "{$name} can't drive",
                body: "{$driver->documents_block_reason} They can drive again once a renewed licence is approved.",
                lines: [],
                actionPath: "/admin/drivers/{$driver->id}",
                actionLabel: 'Open the driver',
            ));
        }
    }

    private function blockLifted(Driver $driver): void
    {
        $this->safely(fn () => $this->notifications->send(
            userId: $driver->user_id,
            type: NotificationType::DriverDocumentsUnblocked,
            title: NotificationType::DriverDocumentsUnblocked->label(),
            body: 'Your driving licence is approved. You can go online again.',
            data: ['driver_id' => (string) $driver->id],
        ));
    }

    private function takeOfflineUnlessOnTrip(Driver $driver): void
    {
        $setOffline = DB::transaction(function () use ($driver) {
            $driver = Driver::lockForUpdate()->find($driver->id);

            if ($driver === null || ! $driver->is_online) {
                return false;
            }

            if (Ride::where('driver_id', $driver->user_id)->whereIn('status', RideStatus::driverOnTripStatuses())->exists()) {
                return false;
            }

            $driver->update(['is_online' => false]);
            AuditLog::record($driver, 'driver_set_offline_documents_blocked');

            return true;
        });

        if ($setOffline) {
            app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
        }
    }

    /**
     * Papers that compete for the same slot: the driver's own papers of this
     * type, or the same car's papers of this type.
     *
     * @return Builder<DriverDocument>
     */
    private function sameSlot(DriverDocument $document)
    {
        return DriverDocument::query()
            ->where('type', $document->type)
            ->where('id', '!=', $document->id)
            ->when(
                $document->vehicle_id !== null,
                fn ($q) => $q->where('vehicle_id', $document->vehicle_id),
                fn ($q) => $q->where('driver_id', $document->driver_id)->whereNull('vehicle_id'),
            );
    }

    /**
     * An expired licence is renewed; a withdrawn one is replaced by a valid one.
     */
    private function licenceToUpload(Driver $driver): string
    {
        return str_starts_with((string) $driver->documents_block_reason, 'Your driving licence expired')
            ? 'your renewed licence'
            : 'a valid driving licence';
    }

    private function isCurrent(DriverDocument $document): bool
    {
        return $document->status === DocumentStatus::Approved && $document->revoked_at === null;
    }

    private function localDate(Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString(), self::TIMEZONE)->startOfDay();
    }

    private function lower(string $label): string
    {
        return mb_strtolower($label);
    }

    private function safely(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Driver document side effect failed', ['error' => $e->getMessage()]);
        }
    }
}
