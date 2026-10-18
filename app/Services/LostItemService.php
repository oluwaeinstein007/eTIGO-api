<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Enums\LostItemCategory;
use App\Enums\LostItemClosureReason;
use App\Enums\LostItemStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\LostItem;
use App\Models\LostItemMessage;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lost & Found (Phase 2, section 25), modelled on Uber, Bolt and Lyft:
 *
 * 1. The passenger reports an item against a completed trip (within
 *    `report_window_days`), or the driver hands in one they found.
 * 2. The driver is notified and says whether they have it.
 * 3. If they do, passenger and driver arrange the return through the
 *    report's messages (and each other's phone numbers, when
 *    `share_contact_details` is on). The driver marks it returned and the
 *    passenger confirms; without an answer it resolves after
 *    `auto_resolve_hours`. A return fee, when set, goes to the driver.
 * 4. Support steps in when the passenger asks, when the driver does not
 *    answer within `driver_response_hours`, or when the return is overdue.
 *
 * Every change runs under a row lock and is audit-logged; notifications go
 * out after commit.
 */
class LostItemService
{
    public const ESCALATION_PASSENGER = 'passenger';

    public const ESCALATION_SYSTEM = 'system';

    public function __construct(
        private NotificationService $notifications,
        private SmsGateway $sms,
        private LostItemReturnFeeService $fees,
    ) {}

    /**
     * File a report: a passenger who left something, or the driver who
     * found something.
     *
     * @param  array{category: string, description: string, contact_phone?: string|null}  $data
     * @param  list<string>  $photoPaths
     *
     * @throws \DomainException when the ride cannot take a report
     */
    public function report(Ride $ride, User $user, array $data, array $photoPaths = []): LostItem
    {
        $settings = AppSetting::lostAndFound();
        $byDriver = $ride->driver_id !== null && $ride->driver_id === $user->id;

        if (! $settings['enabled']) {
            throw new \DomainException('Lost & Found is not available at the moment. Please contact support.');
        }

        if ($ride->status !== RideStatus::Completed || $ride->completed_at === null) {
            throw new \DomainException('You can only report a lost item for a completed trip.');
        }

        if ($ride->completed_at->lt(now()->subDays($settings['report_window_days']))) {
            throw new \DomainException("Lost items can be reported up to {$settings['report_window_days']} days after the trip. Please contact support.");
        }

        try {
            $item = DB::transaction(function () use ($ride, $user, $data, $photoPaths, $settings, $byDriver) {
                // Serialises reports on the same ride and by the same passenger.
                Ride::whereKey($ride->id)->lockForUpdate()->first();
                User::whereKey($ride->passenger_id)->lockForUpdate()->first();

                if (LostItem::where('ride_id', $ride->id)->open()->exists()) {
                    throw new \DomainException($byDriver
                        ? 'There is already an open lost item report for this trip. Answer it from your lost items list.'
                        : 'You already have an open lost item report for this trip.');
                }

                if (! $byDriver) {
                    $open = LostItem::where('passenger_id', $ride->passenger_id)->open()->count();

                    if ($open >= $settings['max_open_reports_per_passenger']) {
                        throw new \DomainException("You can have up to {$settings['max_open_reports_per_passenger']} open lost item reports. Close one, or contact support.");
                    }
                }

                $driver = $ride->driver_id ? User::find($ride->driver_id) : null;
                $driverReachable = $driver !== null && $driver->is_active;
                $now = now();

                $attributes = [
                    'ride_id' => $ride->id,
                    'passenger_id' => $ride->passenger_id,
                    'driver_id' => $ride->driver_id,
                    'reported_by' => $byDriver ? LostItem::REPORTED_BY_DRIVER : LostItem::REPORTED_BY_PASSENGER,
                    'reported_by_user_id' => $user->id,
                    'category' => $data['category'],
                    'description' => $data['description'],
                    'photos' => $photoPaths ?: null,
                    'contact_phone' => $byDriver ? null : ($data['contact_phone'] ?? null),
                ];

                if ($byDriver) {
                    $attributes += [
                        'status' => LostItemStatus::DriverConfirmed,
                        'driver_responded_at' => $now,
                        'return_fee' => $settings['return_fee'] ?: null,
                    ];
                } elseif ($driverReachable) {
                    $attributes += [
                        'status' => LostItemStatus::DriverNotified,
                        'driver_notified_at' => $now,
                    ];
                } else {
                    // No driver to ask: support takes it from the start.
                    $attributes += [
                        'status' => LostItemStatus::Reported,
                        'escalated_at' => $now,
                        'escalation_reason' => 'The driver cannot be reached through the app.',
                        'escalation_source' => self::ESCALATION_SYSTEM,
                    ];
                }

                $item = LostItem::create($attributes);

                AuditLog::record($item, 'lost_item.reported', $user, null, [
                    'ride_id' => $ride->id,
                    'reported_by' => $item->reported_by,
                    'category' => $item->category->value,
                    'status' => $item->status->value,
                ]);

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('There is already an open lost item report for this trip.');
        }

        $item->setRelation('ride', $ride);
        $tripDate = $this->tripDate($item);
        $noun = $item->category->noun();

        if ($item->reported_by === LostItem::REPORTED_BY_DRIVER) {
            $driverName = $user->first_name ?: 'Your driver';
            $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
                'Your driver found something',
                "{$driverName} found {$this->article($item->category)} in the car after your trip on {$tripDate}. Open the app to arrange getting it back.");
        } elseif ($item->status === LostItemStatus::DriverNotified) {
            $name = $ride->riderFirstName() ?: 'A passenger';
            $this->notify($item->driver_id, NotificationType::LostItemReport, $item,
                'Lost item report',
                "{$name} may have left their {$noun} in your car after the trip on {$tripDate}. Please check your car and tell them in the app.");
        }

        return $item;
    }

    /**
     * The driver says whether they have the item. A driver who said "not
     * found" can still come back with "found".
     *
     * @throws \DomainException
     */
    public function respond(LostItem $item, User $driver, bool $found, ?string $note): LostItem
    {
        $from = $found
            ? [LostItemStatus::Reported, LostItemStatus::DriverNotified, LostItemStatus::DriverDenied]
            : [LostItemStatus::Reported, LostItemStatus::DriverNotified];

        $item = $this->transition($item, $from, function (LostItem $item) use ($found, $note) {
            $changes = [
                'status' => $found ? LostItemStatus::DriverConfirmed : LostItemStatus::DriverDenied,
                'driver_response' => $note,
                'driver_responded_at' => now(),
            ];

            if ($found) {
                $changes['return_fee'] = AppSetting::lostAndFound()['return_fee'] ?: null;
            }

            // An automatic hand-over for a silent driver ends when they answer.
            if ($item->escalation_source === self::ESCALATION_SYSTEM) {
                $changes += ['escalated_at' => null, 'escalation_reason' => null, 'escalation_source' => null];
            }

            return $changes;
        }, $driver, $found ? 'lost_item.driver_confirmed' : 'lost_item.driver_denied');

        $noun = $item->category->noun();

        if ($found) {
            $fee = $item->returnFeeAmount() > 0 ? ' A return fee of ₦'.number_format($item->returnFeeAmount(), 2).' applies.' : '';
            $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
                'Your driver has your item',
                "Good news: your driver has your {$noun}. Message them in the app to arrange the return.{$fee}");
            $this->textContactPhone($item, "E-tiGo: your driver has your {$noun} (report {$item->reference_number}). Open the E-tiGo app or call support to arrange the return.");
        } else {
            $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
                'Item not found',
                "Your driver did not find your {$noun} in the car. If you are sure you left it there, ask support to help from the report.");
        }

        return $item;
    }

    /**
     * The driver says they have handed the item back.
     *
     * @throws \DomainException
     */
    public function markReturned(LostItem $item, User $driver): LostItem
    {
        $item = $this->transition($item, [LostItemStatus::DriverConfirmed], fn () => [
            'status' => LostItemStatus::Returned,
            'returned_at' => now(),
        ], $driver, 'lost_item.returned');

        $hours = AppSetting::lostAndFound()['auto_resolve_hours'];
        $noun = $item->category->noun();
        $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
            'Did you get your item back?',
            "Your driver says your {$noun} has been returned. Confirm in the app, or tell us within {$hours} hours if you do not have it.");
        $this->textContactPhone($item, "E-tiGo: your driver says your {$noun} has been returned (report {$item->reference_number}). If you do not have it, contact support within {$hours} hours.");

        return $item;
    }

    /**
     * The passenger confirms they have the item back.
     *
     * @throws \DomainException
     */
    public function confirmReceived(LostItem $item, User $passenger): LostItem
    {
        $item = $this->transition($item, [LostItemStatus::DriverConfirmed, LostItemStatus::Returned], fn (LostItem $item) => [
            'status' => LostItemStatus::Resolved,
            'returned_at' => $item->returned_at ?? now(),
            'resolved_at' => now(),
            'return_fee_status' => $item->returnFeeAmount() > 0 ? 'pending' : null,
        ], $passenger, 'lost_item.received');

        $this->notify($item->driver_id, NotificationType::LostItemUpdate, $item,
            'Item returned',
            "Thank you: the passenger confirmed they have their {$item->category->noun()} back.");
        $this->collectFee($item);

        return $item;
    }

    /**
     * The passenger withdraws the report (found it elsewhere). Only before
     * the driver has the item: after that support closes it.
     *
     * @throws \DomainException
     */
    public function cancel(LostItem $item, User $passenger, ?string $reason): LostItem
    {
        $wasWithDriver = $item->status !== LostItemStatus::Reported;

        if (in_array($item->status, [LostItemStatus::DriverConfirmed, LostItemStatus::Returned], true)) {
            throw new \DomainException('Your driver has the item, so this report cannot be cancelled. Confirm you got it back, or ask support to close it.');
        }

        $item = $this->transition($item, [LostItemStatus::Reported, LostItemStatus::DriverNotified, LostItemStatus::DriverDenied], fn () => [
            'status' => LostItemStatus::Cancelled,
            'cancelled_at' => now(),
            'resolution_notes' => $reason,
        ], $passenger, 'lost_item.cancelled');

        if ($wasWithDriver) {
            $this->notify($item->driver_id, NotificationType::LostItemUpdate, $item,
                'Lost item report closed',
                "The passenger no longer needs help finding their {$item->category->noun()}. Thank you for checking.");
        }

        return $item;
    }

    /**
     * The passenger asks support to step in.
     *
     * @throws \DomainException
     */
    public function escalate(LostItem $item, User $passenger, string $reason): LostItem
    {
        return DB::transaction(function () use ($item, $passenger, $reason) {
            $locked = LostItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new \DomainException('This report is closed.');
            }

            if ($locked->escalated_at !== null || $locked->status === LostItemStatus::Reported) {
                throw new \DomainException('Our support team is already looking into this report.');
            }

            $locked->update([
                'escalated_at' => now(),
                'escalation_reason' => $reason,
                'escalation_source' => self::ESCALATION_PASSENGER,
            ]);

            AuditLog::record($locked, 'lost_item.escalated', $passenger, null, [
                'status' => $locked->status->value,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * A message on the report. Support's messages reach both sides; an
     * internal note reaches nobody.
     *
     * @throws \DomainException
     */
    public function postMessage(LostItem $item, User $sender, string $body, bool $internal = false): LostItemMessage
    {
        $role = match (true) {
            $sender->isAdmin() => LostItemMessage::ROLE_SUPPORT,
            $item->isPassenger($sender) => LostItemMessage::ROLE_PASSENGER,
            $item->isDriver($sender) => LostItemMessage::ROLE_DRIVER,
            default => throw new \DomainException('You are not part of this report.'),
        };

        if ($role !== LostItemMessage::ROLE_SUPPORT && ! $item->isOpen()) {
            throw new \DomainException('This report is closed. Contact support if you still need help.');
        }

        if ($role === LostItemMessage::ROLE_PASSENGER && $item->driver_id === null) {
            throw new \DomainException('The driver cannot be reached through the app. Our support team will contact you.');
        }

        $message = $item->messages()->create([
            'sender_user_id' => $sender->id,
            'sender_role' => $role,
            'body' => $body,
            'is_internal' => $internal && $role === LostItemMessage::ROLE_SUPPORT,
        ]);

        if ($message->is_internal) {
            AuditLog::record($item, 'lost_item.note_added', $sender, null, ['message_id' => $message->id]);

            return $message;
        }

        $recipients = match ($role) {
            LostItemMessage::ROLE_PASSENGER => [$item->driver_id],
            LostItemMessage::ROLE_DRIVER => [$item->passenger_id],
            default => [$item->passenger_id, $item->driver_id],
        };
        $from = match ($role) {
            LostItemMessage::ROLE_PASSENGER => 'the passenger',
            LostItemMessage::ROLE_DRIVER => 'your driver',
            default => 'E-tiGo support',
        };

        foreach (array_filter($recipients) as $recipientId) {
            $this->notify($recipientId, NotificationType::LostItemMessage, $item,
                "New message from {$from}",
                Str::limit($body, 120),
                ['message_id' => (string) $message->id]);
        }

        return $message;
    }

    /**
     * Support confirms the driver (or the office) has the item, for example
     * after reaching the driver by phone.
     *
     * @throws \DomainException
     */
    public function adminMarkFound(LostItem $item, User $admin): LostItem
    {
        $item = $this->transition($item, [LostItemStatus::Reported, LostItemStatus::DriverNotified, LostItemStatus::DriverDenied], fn (LostItem $item) => [
            'status' => LostItemStatus::DriverConfirmed,
            'driver_responded_at' => $item->driver_responded_at ?? now(),
            'return_fee' => $item->driver_id !== null ? (AppSetting::lostAndFound()['return_fee'] ?: null) : null,
        ], $admin, 'lost_item.admin_marked_found');

        $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
            'We found your item',
            "Your {$item->category->noun()} has been found. Check the report in the app to arrange getting it back.");
        $this->textContactPhone($item, "E-tiGo: your {$item->category->noun()} has been found (report {$item->reference_number}). Open the E-tiGo app or call support to arrange the return.");

        return $item;
    }

    /**
     * Support closes the report as resolved: the item is back with the
     * passenger. The return fee applies unless support waives it.
     *
     * @throws \DomainException
     */
    public function adminResolve(LostItem $item, User $admin, ?string $notes, bool $waiveFee): LostItem
    {
        $item = $this->transition($item, LostItemStatus::open(), function (LostItem $item) use ($admin, $notes, $waiveFee) {
            // Only an item the driver had (and so brought back) carries the fee.
            $feeDue = $item->returnFeeAmount() > 0
                && in_array($item->status, [LostItemStatus::DriverConfirmed, LostItemStatus::Returned], true);

            return [
                'status' => LostItemStatus::Resolved,
                'returned_at' => $item->returned_at ?? now(),
                'resolved_at' => now(),
                'resolved_by_admin_id' => $admin->id,
                'resolution_notes' => $notes,
                'return_fee_status' => $feeDue ? ($waiveFee ? 'waived' : 'pending') : null,
            ];
        }, $admin, 'lost_item.admin_resolved');

        $noun = $item->category->noun();
        $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
            'Lost item report resolved',
            "Your report for your {$noun} has been marked resolved. Contact support if anything is not right.");
        $this->notify($item->driver_id, NotificationType::LostItemUpdate, $item,
            'Lost item report resolved',
            "The lost item report for the passenger's {$noun} has been resolved. Thank you for your help.");
        $this->collectFee($item);

        return $item;
    }

    /**
     * Support closes the report without the item being returned.
     *
     * @throws \DomainException
     */
    public function adminClose(LostItem $item, User $admin, LostItemClosureReason $reason, ?string $notes): LostItem
    {
        $driverInvolved = $item->status !== LostItemStatus::Reported;

        $item = $this->transition($item, LostItemStatus::open(), fn () => [
            'status' => LostItemStatus::Unresolved,
            'closed_at' => now(),
            'closure_reason' => $reason,
            'resolved_by_admin_id' => $admin->id,
            'resolution_notes' => $notes,
        ], $admin, 'lost_item.admin_closed');

        $noun = $item->category->noun();
        $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
            'Lost item report closed',
            "We have closed your report for your {$noun}: {$reason->label()}. We are sorry we could not get it back to you.");

        if ($driverInvolved) {
            $this->notify($item->driver_id, NotificationType::LostItemUpdate, $item,
                'Lost item report closed',
                "Support has closed the lost item report for the passenger's {$noun}.");
        }

        return $item;
    }

    /**
     * The hourly sweep: hand silent drivers and overdue returns to support,
     * resolve returns the passenger never disputed, close "not found"
     * reports nobody followed up and retry return fees left pending.
     *
     * @return array{driver_silent: int, return_overdue: int, auto_resolved: int, auto_closed: int, fees_retried: int}
     */
    public function processOverdue(): array
    {
        $settings = AppSetting::lostAndFound();
        $counts = ['driver_silent' => 0, 'return_overdue' => 0, 'auto_resolved' => 0, 'auto_closed' => 0, 'fees_retried' => 0];

        $silent = LostItem::where('status', LostItemStatus::DriverNotified)
            ->whereNull('escalated_at')
            ->where('driver_notified_at', '<=', now()->subHours($settings['driver_response_hours']))
            ->pluck('id');

        foreach ($silent as $id) {
            if ($this->autoEscalate($id, LostItemStatus::DriverNotified, 'The driver did not answer in time.')) {
                $item = LostItem::find($id);
                $noun = $item->category->noun();
                $this->notify($item->passenger_id, NotificationType::LostItemUpdate, $item,
                    'Support is following up',
                    "Your driver has not answered about your {$noun} yet, so our support team will follow up with them.");
                $this->notify($item->driver_id, NotificationType::LostItemReport, $item,
                    'Reminder: lost item report',
                    "A passenger is still waiting to hear whether their {$noun} is in your car. Please check and answer in the app.");
                $counts['driver_silent']++;
            }
        }

        $overdue = LostItem::where('status', LostItemStatus::DriverConfirmed)
            ->whereNull('escalated_at')
            ->where('driver_responded_at', '<=', now()->subDays($settings['return_deadline_days']))
            ->pluck('id');

        foreach ($overdue as $id) {
            if ($this->autoEscalate($id, LostItemStatus::DriverConfirmed, 'The item has not been returned in time.')) {
                $counts['return_overdue']++;
            }
        }

        $returned = LostItem::where('status', LostItemStatus::Returned)
            ->whereNull('escalated_at')
            ->where('returned_at', '<=', now()->subHours($settings['auto_resolve_hours']))
            ->pluck('id');

        foreach ($returned as $id) {
            try {
                $item = $this->transition(LostItem::findOrFail($id), [LostItemStatus::Returned], fn (LostItem $item) => [
                    'status' => LostItemStatus::Resolved,
                    'resolved_at' => now(),
                    'return_fee_status' => $item->returnFeeAmount() > 0 ? 'pending' : null,
                ], null, 'lost_item.auto_resolved', escalatedOk: false);
                $this->collectFee($item);
                $counts['auto_resolved']++;
            } catch (\DomainException) {
                // Changed since the query (confirmed, disputed): nothing to do.
            }
        }

        $denied = LostItem::where('status', LostItemStatus::DriverDenied)
            ->whereNull('escalated_at')
            ->where('driver_responded_at', '<=', now()->subDays($settings['denied_auto_close_days']))
            ->pluck('id');

        foreach ($denied as $id) {
            try {
                $this->transition(LostItem::findOrFail($id), [LostItemStatus::DriverDenied], fn () => [
                    'status' => LostItemStatus::Unresolved,
                    'closed_at' => now(),
                    'closure_reason' => LostItemClosureReason::NotFound,
                ], null, 'lost_item.auto_closed', escalatedOk: false);
                $counts['auto_closed']++;
            } catch (\DomainException) {
                // Escalated or answered since the query.
            }
        }

        $pendingFees = LostItem::where('return_fee_status', 'pending')
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->pluck('id');

        foreach ($pendingFees as $id) {
            $this->fees->collect($id);
            $counts['fees_retried']++;
        }

        return $counts;
    }

    private function autoEscalate(string $id, LostItemStatus $expected, string $reason): bool
    {
        return DB::transaction(function () use ($id, $expected, $reason) {
            $item = LostItem::whereKey($id)->lockForUpdate()->first();

            if ($item === null || $item->status !== $expected || $item->escalated_at !== null) {
                return false;
            }

            $item->update([
                'escalated_at' => now(),
                'escalation_reason' => $reason,
                'escalation_source' => self::ESCALATION_SYSTEM,
            ]);

            AuditLog::record($item, 'lost_item.auto_escalated', null, null, [
                'status' => $item->status->value,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Apply a status change under a row lock, if the report is still in one
     * of the expected states.
     *
     * @param  list<LostItemStatus>  $from
     * @param  callable(LostItem): array<string, mixed>  $changes
     *
     * @throws \DomainException when the report has moved on
     */
    private function transition(LostItem $item, array $from, callable $changes, ?User $actor, string $event, bool $escalatedOk = true): LostItem
    {
        return DB::transaction(function () use ($item, $from, $changes, $actor, $event, $escalatedOk) {
            $locked = LostItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true) || (! $escalatedOk && $locked->escalated_at !== null)) {
                throw new \DomainException($locked->isOpen()
                    ? "This report is now \"{$locked->status->label()}\" and cannot be changed that way. Refresh and try again."
                    : 'This report is closed.');
            }

            $oldStatus = $locked->status;
            $locked->update($changes($locked));

            AuditLog::record($locked, $event, $actor, ['status' => $oldStatus->value], [
                'status' => $locked->status->value,
            ] + array_filter([
                'driver_response' => $locked->wasChanged('driver_response') ? $locked->driver_response : null,
                'closure_reason' => $locked->closure_reason?->value,
                'return_fee_status' => $locked->return_fee_status,
            ]));

            return $locked->setRelations($item->getRelations());
        });
    }

    private function collectFee(LostItem $item): void
    {
        if ($item->return_fee_status === 'pending') {
            $this->fees->collect($item->id);
            $item->refresh();
        }
    }

    /**
     * Push and in-app notification, after commit, never failing the request.
     *
     * @param  array<string, string>  $extra
     */
    private function notify(?string $userId, NotificationType $type, LostItem $item, string $title, string $body, array $extra = []): void
    {
        if ($userId === null) {
            return;
        }

        DB::afterCommit(function () use ($userId, $type, $item, $title, $body, $extra) {
            try {
                $this->notifications->send($userId, $type, $title, $body, [
                    'lost_item_id' => (string) $item->id,
                    'reference_number' => (string) $item->reference_number,
                    'ride_id' => (string) $item->ride_id,
                    'status' => $item->status->value,
                ] + $extra);
            } catch (\Throwable $e) {
                Log::warning('Lost item notification failed', ['lost_item_id' => $item->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Text the other number the passenger gave, for when the lost item is
     * their phone and push notifications cannot reach them.
     */
    private function textContactPhone(LostItem $item, string $message): void
    {
        $phone = $item->contact_phone;

        if ($phone === null || $phone === $item->passenger?->phone) {
            return;
        }

        DB::afterCommit(function () use ($phone, $message, $item) {
            try {
                $this->sms->send($phone, $message);
            } catch (\Throwable $e) {
                Log::warning('Lost item contact SMS failed', ['lost_item_id' => $item->id, 'error' => $e->getMessage()]);
            }
        });
    }

    private function tripDate(LostItem $item): string
    {
        $ride = $item->ride;
        $timezone = $ride->city?->timezone ?: 'Africa/Lagos';

        return $ride->completed_at->copy()->timezone($timezone)->format('j M');
    }

    private function article(LostItemCategory $category): string
    {
        return match ($category) {
            LostItemCategory::Keys => 'some keys',
            LostItemCategory::Glasses => 'a pair of glasses',
            LostItemCategory::IdOrDocuments => 'an ID or documents',
            LostItemCategory::Electronics => 'some electronics',
            LostItemCategory::Clothing => 'some clothing',
            LostItemCategory::Jewellery => 'some jewellery',
            LostItemCategory::Other => 'an item',
            default => 'a '.$category->noun(),
        };
    }
}
