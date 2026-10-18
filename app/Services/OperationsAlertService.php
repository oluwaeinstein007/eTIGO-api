<?php

namespace App\Services;

use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Models\User;
use App\Notifications\OperationsAlertNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the operations team (super admins when there is none) about fleet
 * work that needs a person. Never throws: an alert that fails is logged.
 */
class OperationsAlertService
{
    /**
     * @param  list<string>  $lines
     */
    public function send(string $label, string $headline, string $body, array $lines, string $actionPath, string $actionLabel): void
    {
        try {
            $find = fn (AdminRole $role) => User::where('type', UserType::Admin)
                ->where('admin_role', $role->value)
                ->where('is_active', true)
                ->get();

            $admins = $find(AdminRole::Operations);

            if ($admins->isEmpty()) {
                $admins = $find(AdminRole::SuperAdmin);
            }

            Notification::send($admins, new OperationsAlertNotification($label, $headline, $body, $lines, $actionPath, $actionLabel));
        } catch (\Throwable $e) {
            Log::warning('Operations alert failed', ['label' => $label, 'error' => $e->getMessage()]);
        }
    }
}
