<?php

use App\Models\City;
use App\Support\NigerianStates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        City::whereNotNull('boundary')->get()->each(function (City $city) {
            $updates = [];

            if ($city->area_sq_km === null && $city->boundary) {
                $type = $city->boundary['type'] ?? '';
                if ($type === 'Point') {
                    $radius = $city->boundary['radius_km'] ?? 30;
                    $updates['area_sq_km'] = City::calculateAreaFromPoint($radius);
                } elseif ($type === 'Polygon') {
                    $updates['area_sq_km'] = City::calculateAreaFromPolygon($city->boundary['coordinates']);
                }
            }

            if ($city->state === null) {
                $stateMap = [
                    'Lagos' => 'Lagos',
                    'Abuja' => 'FCT',
                    'Port Harcourt' => 'Rivers',
                    'Ibadan' => 'Oyo',
                ];

                if (isset($stateMap[$city->name])) {
                    $updates['state'] = $stateMap[$city->name];
                    $updates['region'] = NigerianStates::regionFor($updates['state']);
                }
            }

            if (! empty($updates)) {
                $city->updateQuietly($updates);
            }
        });
    }

    public function down(): void
    {
        // No rollback — data backfill only
    }
};
