<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\AdminInvitation;
use App\Notifications\AdminInvitationNotification;
use App\Notifications\AdminPasswordResetNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TestMailCommand extends Command
{
    protected $signature = 'mail:test {email} {--type=all : invitation|reset|all}';

    protected $description = 'Send test emails to verify mail delivery';

    public function handle(): int
    {
        $email = $this->argument('email');
        $type = $this->option('type');

        if (in_array($type, ['all', 'invitation'])) {
            $this->sendInvitation($email);
        }

        if (in_array($type, ['all', 'reset'])) {
            $this->sendReset($email);
        }

        return self::SUCCESS;
    }

    private function sendInvitation(string $email): void
    {
        $invitation = AdminInvitation::create([
            'email' => $email,
            'admin_role' => AdminRole::Operations,
            'token' => Str::random(64),
            'invited_by' => \App\Models\User::first()->id,
            'expires_at' => now()->addHours(48),
        ]);

        $notification = new AdminInvitationNotification($invitation);
        $notification->onConnection('sync');

        Notification::route('mail', $email)->notify($notification);

        $this->info("Invitation email sent to {$email}");
    }

    private function sendReset(string $email): void
    {
        $token = Str::random(64);

        $user = \App\Models\User::where('email', $email)->first();

        if (! $user) {
            $user = new \App\Models\User([
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'email' => $email,
            ]);
            $user->exists = false;
        }

        $notification = new AdminPasswordResetNotification($token);
        $notification->onConnection('sync');

        $user->notify($notification);

        $this->info("Password reset email sent to {$email}");
    }
}
