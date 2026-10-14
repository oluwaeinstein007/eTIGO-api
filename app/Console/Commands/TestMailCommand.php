<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\AdminInvitation;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\AdminInvitationNotification;
use App\Notifications\AdminPasswordResetNotification;
use App\Notifications\AdminWelcomeNotification;
use App\Notifications\DriverApprovedNotification;
use App\Notifications\DriverDocumentExpiringNotification;
use App\Notifications\DriverOnboardingWelcomeNotification;
use App\Notifications\AccountDeactivatedNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\InvoiceActivatedNotification;
use App\Notifications\PassengerWelcomeNotification;
use App\Notifications\PaymentReceiptNotification;
use App\Notifications\RideCompletedNotification;
use App\Notifications\TierUpgradedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TestMailCommand extends Command
{
    protected $signature = 'mail:test {email} {--type=all : invitation|reset|welcome|receipt|passenger|driver-onboarding|driver-approved|driver-document|payment|invoice|deactivated|dispute|tier|all}';

    protected $description = 'Send test emails to verify mail delivery';

    public function handle(): int
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        $allTypes = [
            'invitation', 'reset', 'welcome', 'receipt',
            'passenger', 'driver-onboarding', 'driver-approved',
            'driver-document', 'payment', 'invoice', 'deactivated',
            'dispute', 'tier',
        ];

        $types = $type === 'all' ? $allTypes : [$type];

        foreach ($types as $t) {
            match ($t) {
                'invitation' => $this->sendInvitation($email),
                'reset' => $this->sendReset($email),
                'welcome' => $this->sendWelcome($email),
                'receipt' => $this->sendReceipt($email),
                'passenger' => $this->sendPassengerWelcome($email),
                'driver-onboarding' => $this->sendDriverOnboarding($email),
                'driver-approved' => $this->sendDriverApproved($email),
                'driver-document' => $this->sendDriverDocumentExpiring($email),
                'payment' => $this->sendPaymentReceipt($email),
                'invoice' => $this->sendInvoiceActivated($email),
                'deactivated' => $this->sendAccountDeactivated($email),
                'dispute' => $this->sendDisputeResolved($email),
                'tier' => $this->sendTierUpgraded($email),
                default => $this->error("Unknown type: {$t}"),
            };
        }

        return self::SUCCESS;
    }

    private function getOrMakeUser(string $email): User
    {
        $user = User::where('email', $email)->first()
            ?? tap(new User([
                'first_name' => 'Amara',
                'last_name' => 'Okafor',
                'email' => $email,
            ]), fn ($u) => $u->exists = false);

        if (! $user->admin_role) {
            $user->admin_role = AdminRole::Operations;
        }

        return $user;
    }

    private function sendInvitation(string $email): void
    {
        $inviter = User::first();

        if (! $inviter) {
            $this->warn('No users in DB — creating mock inviter.');
            $inviter = User::create([
                'first_name' => 'Naomi',
                'last_name' => 'Adeyemi',
                'email' => 'mock-inviter-'.Str::random(6).'@etigo.test',
                'password' => bcrypt(Str::random(16)),
            ]);
        }

        $invitation = AdminInvitation::create([
            'email' => $email,
            'admin_role' => AdminRole::Operations,
            'token' => Str::random(64),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addHours(48),
        ]);

        $notification = new AdminInvitationNotification($invitation);

        Notification::route('mail', $email)->notifyNow($notification);

        $this->info("Admin invitation email sent to {$email}");
    }

    private function sendReset(string $email): void
    {
        $notification = new AdminPasswordResetNotification(Str::random(64));

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Password reset email sent to {$email}");
    }

    private function sendWelcome(string $email): void
    {
        $notification = new AdminWelcomeNotification;

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Admin welcome email sent to {$email}");
    }

    private function sendReceipt(string $email): void
    {
        $ride = Ride::latest()->first();

        if (! $ride) {
            $fareDetails = [
                'final_fare' => 4500.00,
                'distance_km' => 7.2,
                'duration_minutes' => 18,
                'waiting_charge' => 0,
                'fare_breakdown' => [
                    'base_fare' => 3500.00,
                    'distance_charge' => 500.00,
                    'time_charge' => 200.00,
                    'waiting_charge' => 0,
                    'booking_fee' => 300.00,
                ],
            ];

            $ride = new Ride([
                'pickup_address' => 'Tejuosho Market, Yaba',
                'destination_address' => 'Bode Thomas Street, Surulere',
                'pickup_lat' => 6.5095,
                'pickup_lng' => 3.3711,
                'destination_lat' => 6.4920,
                'destination_lng' => 3.3567,
                'fare_currency' => 'NGN',
                'payment_method' => PaymentMethod::Card,
            ]);
        } else {
            $fareDetails = [
                'final_fare' => (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount ?? 0),
                'distance_km' => 0,
                'duration_minutes' => 0,
                'waiting_charge' => 0,
                'fare_breakdown' => [
                    'base_fare' => 0,
                    'distance_charge' => 0,
                    'time_charge' => 0,
                    'waiting_charge' => 0,
                ],
            ];
        }

        $notification = new RideCompletedNotification($ride, $fareDetails);

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Ride receipt email sent to {$email}");
    }

    private function sendPassengerWelcome(string $email): void
    {
        $notification = new PassengerWelcomeNotification;

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Passenger welcome email sent to {$email}");
    }

    private function sendDriverOnboarding(string $email): void
    {
        $driver = Driver::first();

        if (! $driver) {
            $driver = new Driver([
                'reference_number' => 'DRV-261013-0176',
            ]);
            $driver->id = Str::uuid()->toString();
            $driver->created_at = now();
            $driver->exists = false;
        }

        $notification = new DriverOnboardingWelcomeNotification(
            $driver,
            ['driving_licence', 'insurance_certificate'],
        );

        $user = $this->getOrMakeUser($email);
        $user->first_name = 'Tunde';
        $user->last_name = 'Bello';
        $user->notifyNow($notification);

        $this->info("Driver onboarding email sent to {$email}");
    }

    private function sendDriverApproved(string $email): void
    {
        $driver = Driver::first();

        if (! $driver) {
            $driver = new Driver([
                'reference_number' => 'DRV-261013-0176',
                'approved_at' => now(),
            ]);
            $driver->id = Str::uuid()->toString();
            $driver->exists = false;
        }

        $notification = new DriverApprovedNotification($driver);

        $user = $this->getOrMakeUser($email);
        $user->first_name = 'Tunde';
        $user->last_name = 'Bello';
        $user->notifyNow($notification);

        $this->info("Driver approved email sent to {$email}");
    }

    private function sendDriverDocumentExpiring(string $email): void
    {
        $driver = Driver::first();

        if (! $driver) {
            $driver = new Driver([
                'reference_number' => 'DRV-261013-0176',
            ]);
            $driver->id = Str::uuid()->toString();
            $driver->exists = false;
        }

        $document = new DriverDocument([
            'type' => DocumentType::DrivingLicence,
            'expires_at' => now()->addDays(14),
            'original_filename' => 'licence_4821.pdf',
        ]);
        $document->id = Str::uuid()->toString();
        $document->exists = false;

        $notification = new DriverDocumentExpiringNotification($driver, $document);

        $user = $this->getOrMakeUser($email);
        $user->first_name = 'Tunde';
        $user->last_name = 'Bello';
        $user->notifyNow($notification);

        $this->info("Driver document expiring email sent to {$email}");
    }

    private function sendPaymentReceipt(string $email): void
    {
        $ride = new Ride([
            'pickup_address' => 'Tejuosho Market, Yaba',
            'destination_address' => 'Bode Thomas Street, Surulere',
            'fare_currency' => 'NGN',
            'payment_method' => PaymentMethod::Card,
        ]);
        $ride->id = Str::uuid()->toString();
        $ride->reference_number = 'ETG-261013-0842';

        $notification = new PaymentReceiptNotification(
            ride: $ride,
            amountKobo: 450000,
            paymentMethod: 'card',
            paymentReference: 'PAY-261013-0398',
            cardLast4: '4242',
        );

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Payment receipt email sent to {$email}");
    }

    private function sendInvoiceActivated(string $email): void
    {
        $activator = User::first();
        $activatorName = $activator
            ? trim($activator->first_name.' '.$activator->last_name).' · Administrator'
            : 'Naomi Adeyemi · Administrator';

        $notification = new InvoiceActivatedNotification(
            activatedByName: $activatorName,
            workspaceName: 'Mainland Mobility',
        );

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Invoice activated email sent to {$email}");
    }

    private function sendAccountDeactivated(string $email): void
    {
        $notification = new AccountDeactivatedNotification(
            reason: 'Violation of community guidelines',
        );

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Account deactivated email sent to {$email}");
    }

    private function sendDisputeResolved(string $email): void
    {
        $dispute = Dispute::latest()->first();

        if (! $dispute) {
            $dispute = new Dispute([
                'reference_number' => 'DSP-261013-0042',
                'status' => \App\Enums\DisputeStatus::Resolved,
                'resolution_notes' => 'After reviewing the trip details, a partial refund has been issued for the fare overcharge.',
                'refund_amount' => 1500.00,
                'refund_currency' => 'NGN',
            ]);
            $dispute->id = Str::uuid()->toString();
            $dispute->exists = false;
        }

        $notification = new DisputeResolvedNotification($dispute);

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Dispute resolved email sent to {$email}");
    }

    private function sendTierUpgraded(string $email): void
    {
        $notification = new TierUpgradedNotification(
            tierName: 'Silver',
            previousTier: 1,
            newTier: 2,
        );

        $this->getOrMakeUser($email)->notifyNow($notification);

        $this->info("Tier upgraded email sent to {$email}");
    }
}
