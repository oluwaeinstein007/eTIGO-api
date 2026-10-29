<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An early DemoSeeder wrote surge conditions in a shape surge pricing never
 * read: time-based days as names in `days`, and demand as
 * `min_pending_rides` / `min_utilization_pct`. Those rules (Lagos and Abuja
 * on staging) ignored their days and demand. This rewrites them in the
 * shape SurgePricingService reads and drops keys their type does not use.
 *
 * - `days` names (Monday or Mon, any case) become ISO `days_of_week` (1–7).
 * - `min_pending_rides` becomes `min_requests`.
 * - `min_utilization_pct` (busy drivers as a share of all) has no direct
 *   equivalent; it becomes `min_demand_supply_ratio` 2.0, i.e. twice as
 *   many requests as free drivers, the default the dashboard offers.
 *
 * Rules already in the current shape are left alone.
 */
return new class extends Migration
{
    private const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    private const KEYS = [
        'time_based' => ['days_of_week', 'start_time', 'end_time'],
        'demand_based' => ['min_demand_supply_ratio', 'min_requests'],
        'manual' => [],
    ];

    private const UTILIZATION_RATIO = 2.0;

    public function up(): void
    {
        DB::table('surge_rules')->orderBy('id')->select(['id', 'type', 'conditions'])->each(function ($rule) {
            $old = json_decode((string) $rule->conditions, true);

            if (! is_array($old) || $old === []) {
                return;
            }

            $new = $old;

            if (isset($new['days']) && ! isset($new['days_of_week'])) {
                $new['days_of_week'] = $this->isoDays((array) $new['days']);
            }

            if (isset($new['min_pending_rides']) && ! isset($new['min_requests'])) {
                $new['min_requests'] = max(1, (int) $new['min_pending_rides']);
            }

            if (isset($new['min_utilization_pct']) && ! isset($new['min_demand_supply_ratio'])) {
                $new['min_demand_supply_ratio'] = self::UTILIZATION_RATIO;
            }

            $new = array_intersect_key($new, array_flip(self::KEYS[$rule->type] ?? array_keys($new)));

            if ($new === $old) {
                return;
            }

            DB::table('surge_rules')->where('id', $rule->id)->update(['conditions' => json_encode((object) $new)]);

            Log::info('Surge rule conditions rewritten in the current shape', ['surge_rule_id' => $rule->id, 'old' => $old, 'new' => $new]);
        });
    }

    public function down(): void
    {
        // The old shape was never read; nothing to restore.
    }

    /**
     * @param  array<int, mixed>  $names
     * @return list<int>
     */
    private function isoDays(array $names): array
    {
        $days = [];

        foreach ($names as $name) {
            $day = is_numeric($name) ? (int) $name : (self::DAYS[substr(strtolower(trim((string) $name)), 0, 3)] ?? null);

            if ($day !== null && $day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }
};
