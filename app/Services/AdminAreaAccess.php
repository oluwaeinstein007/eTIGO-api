<?php

namespace App\Services;

use App\Enums\AdminRole;
use App\Models\User;

/**
 * Which admin roles can open which part of the console, for screens that
 * gather data from several (the overview, global search). Mirrors the
 * admin.role middleware in routes/api.php; keep the two in step. Super
 * admins can open everything.
 */
class AdminAreaAccess
{
    public const MONITORING = 'monitoring';

    public const DISPUTES = 'disputes';

    public const REPORTS = 'reports';

    public const SOS = 'sos';

    public const OFFLINE_FLAGS = 'offline_flags';

    public const DRIVERS = 'drivers';

    public const PASSENGERS = 'passengers';

    public const PRICING = 'pricing';

    public const SURGE = 'surge';

    public const PROMOS = 'promos';

    public const CITIES = 'cities';

    public const EV_STATIONS = 'ev_stations';

    public const FINANCE = 'finance';

    public const ADMINS = 'admins';

    /**
     * @var array<string, list<AdminRole>>
     */
    private const AREAS = [
        self::MONITORING => [AdminRole::Operations],
        self::DISPUTES => [AdminRole::Support, AdminRole::SupportLead, AdminRole::SafetyOperator],
        self::REPORTS => [AdminRole::Operations, AdminRole::Finance],
        self::SOS => [AdminRole::SafetyOperator],
        self::OFFLINE_FLAGS => [AdminRole::Operations, AdminRole::SafetyOperator],
        self::DRIVERS => [AdminRole::Operations, AdminRole::SafetyOperator],
        self::PASSENGERS => [AdminRole::Support, AdminRole::SupportLead, AdminRole::SafetyOperator],
        self::PRICING => [AdminRole::Operations, AdminRole::Finance],
        self::SURGE => [AdminRole::Operations],
        self::PROMOS => [AdminRole::Operations, AdminRole::Finance],
        self::CITIES => [AdminRole::Operations],
        self::EV_STATIONS => [AdminRole::Operations],
        self::FINANCE => [AdminRole::Finance],
        self::ADMINS => [],
    ];

    public function can(User $admin, string $area): bool
    {
        if (! $admin->isAdmin()) {
            return false;
        }

        if ($admin->admin_role === AdminRole::SuperAdmin) {
            return true;
        }

        return in_array($admin->admin_role, self::AREAS[$area] ?? [], true);
    }
}
