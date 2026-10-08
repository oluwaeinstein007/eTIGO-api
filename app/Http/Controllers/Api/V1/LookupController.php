<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function states(): JsonResponse
    {
        return response()->json([
            'states' => self::NIGERIAN_STATES,
        ]);
    }

    public function regions(): JsonResponse
    {
        return response()->json([
            'regions' => self::GEOPOLITICAL_ZONES,
        ]);
    }

    private const NIGERIAN_STATES = [
        ['code' => 'AB', 'name' => 'Abia', 'region' => 'South-East'],
        ['code' => 'AD', 'name' => 'Adamawa', 'region' => 'North-East'],
        ['code' => 'AK', 'name' => 'Akwa Ibom', 'region' => 'South-South'],
        ['code' => 'AN', 'name' => 'Anambra', 'region' => 'South-East'],
        ['code' => 'BA', 'name' => 'Bauchi', 'region' => 'North-East'],
        ['code' => 'BY', 'name' => 'Bayelsa', 'region' => 'South-South'],
        ['code' => 'BE', 'name' => 'Benue', 'region' => 'North-Central'],
        ['code' => 'BO', 'name' => 'Borno', 'region' => 'North-East'],
        ['code' => 'CR', 'name' => 'Cross River', 'region' => 'South-South'],
        ['code' => 'DE', 'name' => 'Delta', 'region' => 'South-South'],
        ['code' => 'EB', 'name' => 'Ebonyi', 'region' => 'South-East'],
        ['code' => 'ED', 'name' => 'Edo', 'region' => 'South-South'],
        ['code' => 'EK', 'name' => 'Ekiti', 'region' => 'South-West'],
        ['code' => 'EN', 'name' => 'Enugu', 'region' => 'South-East'],
        ['code' => 'FC', 'name' => 'FCT', 'region' => 'North-Central'],
        ['code' => 'GO', 'name' => 'Gombe', 'region' => 'North-East'],
        ['code' => 'IM', 'name' => 'Imo', 'region' => 'South-East'],
        ['code' => 'JI', 'name' => 'Jigawa', 'region' => 'North-West'],
        ['code' => 'KD', 'name' => 'Kaduna', 'region' => 'North-West'],
        ['code' => 'KN', 'name' => 'Kano', 'region' => 'North-West'],
        ['code' => 'KT', 'name' => 'Katsina', 'region' => 'North-West'],
        ['code' => 'KE', 'name' => 'Kebbi', 'region' => 'North-West'],
        ['code' => 'KO', 'name' => 'Kogi', 'region' => 'North-Central'],
        ['code' => 'KW', 'name' => 'Kwara', 'region' => 'North-Central'],
        ['code' => 'LA', 'name' => 'Lagos', 'region' => 'South-West'],
        ['code' => 'NA', 'name' => 'Nasarawa', 'region' => 'North-Central'],
        ['code' => 'NI', 'name' => 'Niger', 'region' => 'North-Central'],
        ['code' => 'OG', 'name' => 'Ogun', 'region' => 'South-West'],
        ['code' => 'ON', 'name' => 'Ondo', 'region' => 'South-West'],
        ['code' => 'OS', 'name' => 'Osun', 'region' => 'South-West'],
        ['code' => 'OY', 'name' => 'Oyo', 'region' => 'South-West'],
        ['code' => 'PL', 'name' => 'Plateau', 'region' => 'North-Central'],
        ['code' => 'RI', 'name' => 'Rivers', 'region' => 'South-South'],
        ['code' => 'SO', 'name' => 'Sokoto', 'region' => 'North-West'],
        ['code' => 'TA', 'name' => 'Taraba', 'region' => 'North-East'],
        ['code' => 'YO', 'name' => 'Yobe', 'region' => 'North-East'],
        ['code' => 'ZA', 'name' => 'Zamfara', 'region' => 'North-West'],
    ];

    private const GEOPOLITICAL_ZONES = [
        ['name' => 'North-Central', 'states' => ['Benue', 'FCT', 'Kogi', 'Kwara', 'Nasarawa', 'Niger', 'Plateau']],
        ['name' => 'North-East', 'states' => ['Adamawa', 'Bauchi', 'Borno', 'Gombe', 'Taraba', 'Yobe']],
        ['name' => 'North-West', 'states' => ['Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Sokoto', 'Zamfara']],
        ['name' => 'South-East', 'states' => ['Abia', 'Anambra', 'Ebonyi', 'Enugu', 'Imo']],
        ['name' => 'South-South', 'states' => ['Akwa Ibom', 'Bayelsa', 'Cross River', 'Delta', 'Edo', 'Rivers']],
        ['name' => 'South-West', 'states' => ['Ekiti', 'Lagos', 'Ogun', 'Ondo', 'Osun', 'Oyo']],
    ];
}
