<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Enums\NotificationType;
use App\Enums\RiderNotificationStatus;
use App\Enums\RideStatus;
use App\Events\RiderNotificationUpdated;
use App\Jobs\SendThirdPartyRiderMessageJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Ride;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Routes the PIN and live tracking of a third-party booking (Phase 2,
 * BE2-3P-04) to the rider by WhatsApp, with SMS as the fallback, following
 * the recipients saved on the ride at booking. The booker sees the PIN and
 * tracking in the app when the policy includes them (Ride::bookerSeesPin).
 *
 * Delivery status of the driver-details message: `pending` until a channel
 * accepts it, then `sent_whatsapp` or `sent_sms`; `failed` once retries run
 * out, which shows the booker the PIN whatever the policy. A new PIN restarts
 * it at `pending`. Every change is broadcast to the booker.
 */
class ThirdPartyRoutingService
{
    public const DRIVER_DETAILS = 'driver_details';

    public const PIN_UPDATE = 'pin_update';

    public const DRIVER_ARRIVED = 'driver_arrived';

    public const CANCELLED = 'cancelled';

    public const REMINDER = 'reminder';

    /**
     * Messages that carry the PIN and so drive rider_notification_status.
     */
    private const TRACKED = [self::DRIVER_DETAILS, self::PIN_UPDATE];

    private const PICKUP_STATUSES = [RideStatus::Matched, RideStatus::DriverEnRoute, RideStatus::DriverArrived];

    public function __construct(
        private SmsGateway $gateway,
        private RidePinService $pinService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Queue a message to the rider, if this ride and the policy call for one.
     */
    public function queue(Ride $ride, string $type): void
    {
        if (! $ride->messagesRider()) {
            return;
        }

        $tripUpdates = AppSetting::thirdParty()['rider_trip_updates'];

        $wanted = match ($type) {
            self::DRIVER_DETAILS, self::REMINDER => true,
            self::PIN_UPDATE => (bool) $ride->pin_recipient?->includesRider(),
            self::DRIVER_ARRIVED => $tripUpdates,
            // Only a rider who was already told about the ride hears it is off.
            self::CANCELLED => $tripUpdates
                && ($ride->rider_notified_at !== null || $ride->scheduledRide?->reminder_sent_at !== null),
            default => false,
        };

        if (! $wanted) {
            return;
        }

        // A booker who was already given the PIN because the rider could not
        // be reached keeps seeing it until a new message gets through.
        if (in_array($type, self::TRACKED, true) && $ride->rider_notification_status !== RiderNotificationStatus::Failed) {
            $ride->forceFill(['rider_notification_status' => RiderNotificationStatus::Pending])->save();
            $this->broadcast($ride);
        }

        SendThirdPartyRiderMessageJob::dispatch($ride->id, $type)->afterCommit();
    }

    /**
     * Send one message now. Throws when neither WhatsApp nor SMS accepts it,
     * so the job retries.
     *
     * @throws \RuntimeException
     */
    public function send(string $rideId, string $type): void
    {
        $ride = Ride::with(['passenger', 'driver.driver.vehicle', 'city', 'scheduledRide'])->find($rideId);

        if ($ride === null || ! $ride->messagesRider() || ! $this->stillRelevant($ride, $type)) {
            return;
        }

        $message = $this->compose($ride, $type);

        if ($message === null) {
            return;
        }

        $channel = $this->gateway->deliver($ride->rider_phone, $message);

        if ($channel === null) {
            throw new \RuntimeException("Third-party rider message '{$type}' could not be delivered for ride {$ride->id}.");
        }

        if (in_array($type, self::TRACKED, true)) {
            $ride->forceFill([
                'rider_notification_status' => RiderNotificationStatus::forChannel($channel),
                'rider_notification_channel' => $channel,
                'rider_notified_at' => now(),
            ])->save();

            $this->broadcast($ride);
        }

        Log::info('Third-party rider message sent', ['ride_id' => $ride->id, 'type' => $type, 'channel' => $channel]);
    }

    /**
     * The driver-details message could not be delivered after every retry:
     * show the booker the PIN and tell them, so the ride can still start.
     */
    public function markFailed(string $rideId, string $type): void
    {
        if (! in_array($type, self::TRACKED, true)) {
            Log::warning('Third-party rider message not delivered', ['ride_id' => $rideId, 'type' => $type]);

            return;
        }

        $ride = Ride::find($rideId);

        if ($ride === null
            || $ride->rider_notification_status !== RiderNotificationStatus::Pending
            || ! in_array($ride->status, self::PICKUP_STATUSES, true)) {
            return;
        }

        $ride->forceFill(['rider_notification_status' => RiderNotificationStatus::Failed])->save();
        $this->broadcast($ride);

        AuditLog::record($ride, 'third_party_rider_notification_failed', null, null, ['type' => $type]);

        $riderName = $ride->riderFirstName() ?? 'the rider';

        try {
            $this->notificationService->sendCritical(
                userId: $ride->passenger_id,
                type: NotificationType::ThirdPartyRiderUnreachable,
                title: "We couldn't reach {$riderName}",
                body: "We couldn't send {$riderName} the driver's details. Open the ride to see the PIN and share it with them.",
                data: ['ride_id' => (string) $ride->id],
            );
        } catch (\Throwable $e) {
            Log::warning('Could not tell the booker the rider was not reached', ['ride_id' => $ride->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The no-app live tracking page for this ride.
     */
    public function trackingUrl(Ride $ride): string
    {
        return str_replace(
            ['{ride}', '{token}'],
            [(string) $ride->id, (string) $ride->share_token],
            (string) config('third_party.tracking_url'),
        );
    }

    private function stillRelevant(Ride $ride, string $type): bool
    {
        return match ($type) {
            self::DRIVER_DETAILS, self::PIN_UPDATE => $ride->driver_id !== null && in_array($ride->status, self::PICKUP_STATUSES, true),
            self::DRIVER_ARRIVED => $ride->status === RideStatus::DriverArrived,
            self::CANCELLED => $ride->status === RideStatus::Cancelled,
            self::REMINDER => $ride->status === RideStatus::Scheduled,
            default => false,
        };
    }

    private function compose(Ride $ride, string $type): ?string
    {
        $booker = $this->plain($ride->passenger?->first_name, 40) ?: 'Your contact';

        return match ($type) {
            self::DRIVER_DETAILS => $this->driverDetailsMessage($ride, $booker),
            self::PIN_UPDATE => ($pin = $this->pinService->getCachedPin($ride)) === null ? null
                : "E-tiGo: your new ride PIN is {$pin}. The old PIN no longer works. Only tell it to the driver once you are in the right car.",
            self::DRIVER_ARRIVED => 'E-tiGo: your driver '.($ride->driver?->first_name ?? '').' has arrived'
                .(($car = $this->carDescription($ride)) ? " in the {$car}" : '').'. Check the plate number before you get in.',
            self::CANCELLED => "E-tiGo: the ride {$booker} booked for you from {$this->plain($ride->pickup_address)} has been cancelled. Contact {$booker} for details.",
            self::REMINDER => $this->reminderMessage($ride, $booker),
            default => null,
        };
    }

    private function driverDetailsMessage(Ride $ride, string $booker): string
    {
        $lines = ["E-tiGo: {$booker} booked a ride for you from {$this->plain($ride->pickup_address)}."];

        $driver = 'Driver: '.($ride->driver?->first_name ?? 'assigned');
        if ($car = $this->carDescription($ride)) {
            $driver .= ", {$car}";
        }
        $lines[] = $driver.'.';

        if ($ride->pin_recipient?->includesRider() && ($pin = $this->pinService->getCachedPin($ride)) !== null) {
            $lines[] = "Your ride PIN: {$pin}. Only tell it to the driver once you are in the right car.";
        } else {
            $lines[] = "Ask {$booker} for your ride PIN.";
        }

        if ($ride->tracking_recipient?->includesRider()) {
            $lines[] = 'Track your driver: '.$this->trackingUrl($ride);
        }

        $lines[] = "For changes, contact {$booker}.".$this->safetyLine();

        return implode("\n", $lines);
    }

    private function reminderMessage(Ride $ride, string $booker): ?string
    {
        $pickupAt = $ride->scheduledRide?->scheduled_pickup_at;

        if ($pickupAt === null) {
            return null;
        }

        $time = $pickupAt->copy()->setTimezone($ride->city?->timezone ?? config('app.timezone'))->format('g:i A');

        return "E-tiGo: {$booker} booked you a ride at {$time} from {$this->plain($ride->pickup_address)} to {$this->plain($ride->destination_address)}. "
            ."You'll get the driver's details when a driver is on the way. For changes, contact {$booker}.";
    }

    /**
     * "silver Toyota Corolla (ABC-123-XY)", or null when there is no vehicle.
     */
    private function carDescription(Ride $ride): ?string
    {
        $vehicle = $ride->driver?->driver?->vehicle;

        if ($vehicle === null) {
            return null;
        }

        $car = trim(implode(' ', array_filter([$vehicle->colour ? strtolower($vehicle->colour) : null, $vehicle->make, $vehicle->model])));

        return trim($car.($vehicle->plate_number ? " ({$vehicle->plate_number})" : '')) ?: null;
    }

    /**
     * Text the booker typed (their name, the addresses), made safe to send
     * to someone else's phone: links are removed so the feature cannot be
     * used to send phishing messages, and it is kept short.
     */
    private function plain(?string $text, int $limit = 120): string
    {
        $text = preg_replace('~(https?://|www\.)\S+~i', '', (string) $text);
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        return Str::limit($text, $limit, '…');
    }

    private function safetyLine(): string
    {
        $line = ' Emergency: '.config('sos.emergency_number', '112').'.';

        if ($safety = config('sos.safety_line')) {
            $line .= " E-tiGo safety line: {$safety}.";
        }

        return $line;
    }

    private function broadcast(Ride $ride): void
    {
        try {
            event(new RiderNotificationUpdated(
                rideId: (string) $ride->id,
                bookerId: (string) $ride->passenger_id,
                status: $ride->rider_notification_status?->value ?? RiderNotificationStatus::Pending->value,
                channel: $ride->rider_notification_channel,
                notifiedAt: $ride->rider_notified_at?->toIso8601String(),
                bookerSeesPin: $ride->bookerSeesPin(),
                bookerSeesTracking: $ride->bookerSeesTracking(),
            ));
        } catch (\Throwable) {
            // Reverb failures must not stop the rider's message.
        }
    }
}
