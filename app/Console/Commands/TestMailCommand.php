<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Enums\PaymentMethod;
use App\Models\AdminInvitation;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\AdminInvitationNotification;
use App\Notifications\AdminPasswordResetNotification;
use App\Notifications\AdminWelcomeNotification;
use App\Notifications\RideCompletedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TestMailCommand extends Command
{
    protected $signature = 'mail:test {email} {--type=all : invitation|reset|welcome|receipt|all}';

    protected $description = 'Send test emails to verify mail delivery';

    public function handle(): int
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        $types = $type === 'all'
            ? ['invitation', 'reset', 'welcome', 'receipt']
            : [$type];

        foreach ($types as $t) {
            match ($t) {
                'invitation' => $this->sendInvitation($email),
                'reset' => $this->sendReset($email),
                'welcome' => $this->sendWelcome($email),
                'receipt' => $this->sendReceipt($email),
                default => $this->error("Unknown type: {$t}"),
            };
        }

        return self::SUCCESS;
    }

    private function getOrMakeUser(string $email): User
    {
        $user = User::where('email', $email)->first()
            ?? tap(new User([
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => $email,
            ]), fn ($u) => $u->exists = false);

        if (! $user->admin_role) {
            $user->admin_role = AdminRole::Operations;
        }

        return $user;
    }

    private function sendInvitation(string $email): void
    {
        $invitation = AdminInvitation::create([
            'email' => $email,
            'admin_role' => AdminRole::Operations,
            'token' => Str::random(64),
            'invited_by' => User::first()->id,
            'expires_at' => now()->addHours(48),
        ]);

        $notification = new AdminInvitationNotification($invitation);
        $notification->onConnection('sync');

        Notification::route('mail', $email)->notify($notification);

        $this->info("Invitation email sent to {$email}");
    }

    private function sendReset(string $email): void
    {
        $notification = new AdminPasswordResetNotification(Str::random(64));
        $notification->onConnection('sync');

        $this->getOrMakeUser($email)->notify($notification);

        $this->info("Password reset email sent to {$email}");
    }

    private function sendWelcome(string $email): void
    {
        $notification = new AdminWelcomeNotification;
        $notification->onConnection('sync');

        $this->getOrMakeUser($email)->notify($notification);

        $this->info("Welcome email sent to {$email}");
    }

    private function sendReceipt(string $email): void
    {
        $ride = Ride::latest()->first();

        if (! $ride) {
            $this->warn('No rides in DB — sending receipt with mock data.');
            $fareDetails = [
                'final_fare' => 3500.00,
                'distance_km' => 12.4,
                'duration_minutes' => 25,
                'waiting_charge' => 200.00,
                'fare_breakdown' => [
                    'base_fare' => 500.00,
                    'distance_charge' => 1860.00,
                    'time_charge' => 750.00,
                    'waiting_charge' => 200.00,
                    'minimum_fare' => 800.00,
                ],
            ];

            $ride = new Ride([
                'pickup_address' => '123 Lekki Phase 1, Lagos',
                'destination_address' => '45 Victoria Island, Lagos',
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
                    'minimum_fare' => 0,
                ],
            ];
        }

        $notification = new RideCompletedNotification($ride, $fareDetails);
        $notification->onConnection('sync');

        $this->getOrMakeUser($email)->notify($notification);

        $this->info("Ride receipt email sent to {$email}");
    }
}
