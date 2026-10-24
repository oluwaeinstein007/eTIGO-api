<?php

namespace App\Support;

class NigerianStates
{
    /**
     * Code, name and region of every state (the public lookup).
     *
     * @return list<array{code: string, name: string, region: string}>
     */
    public static function all(): array
    {
        return array_map(fn (array $state) => ['code' => $state['code'], 'name' => $state['name'], 'region' => $state['region']], self::STATES);
    }

    public static function names(): array
    {
        return array_column(self::STATES, 'name');
    }

    public static function regionFor(string $state): ?string
    {
        foreach (self::STATES as $entry) {
            if (strcasecmp($entry['name'], $state) === 0) {
                return $entry['region'];
            }
        }

        return null;
    }

    /**
     * The state's capital and its coordinates, or null for an unknown state.
     *
     * @return array{name: string, lat: float, lng: float}|null
     */
    public static function capitalOf(string $state): ?array
    {
        foreach (self::STATES as $entry) {
            if (strcasecmp($entry['name'], $state) === 0) {
                return ['name' => $entry['capital'], 'lat' => $entry['capital_lat'], 'lng' => $entry['capital_lng']];
            }
        }

        return null;
    }

    public static function regions(): array
    {
        return self::GEOPOLITICAL_ZONES;
    }

    private const STATES = [
        ['code' => 'AB', 'name' => 'Abia', 'region' => 'South-East', 'capital' => 'Umuahia', 'capital_lat' => 5.5250, 'capital_lng' => 7.4922],
        ['code' => 'AD', 'name' => 'Adamawa', 'region' => 'North-East', 'capital' => 'Yola', 'capital_lat' => 9.2035, 'capital_lng' => 12.4954],
        ['code' => 'AK', 'name' => 'Akwa Ibom', 'region' => 'South-South', 'capital' => 'Uyo', 'capital_lat' => 5.0377, 'capital_lng' => 7.9128],
        ['code' => 'AN', 'name' => 'Anambra', 'region' => 'South-East', 'capital' => 'Awka', 'capital_lat' => 6.2104, 'capital_lng' => 7.0741],
        ['code' => 'BA', 'name' => 'Bauchi', 'region' => 'North-East', 'capital' => 'Bauchi', 'capital_lat' => 10.3158, 'capital_lng' => 9.8442],
        ['code' => 'BY', 'name' => 'Bayelsa', 'region' => 'South-South', 'capital' => 'Yenagoa', 'capital_lat' => 4.9267, 'capital_lng' => 6.2676],
        ['code' => 'BE', 'name' => 'Benue', 'region' => 'North-Central', 'capital' => 'Makurdi', 'capital_lat' => 7.7322, 'capital_lng' => 8.5391],
        ['code' => 'BO', 'name' => 'Borno', 'region' => 'North-East', 'capital' => 'Maiduguri', 'capital_lat' => 11.8311, 'capital_lng' => 13.1510],
        ['code' => 'CR', 'name' => 'Cross River', 'region' => 'South-South', 'capital' => 'Calabar', 'capital_lat' => 4.9757, 'capital_lng' => 8.3417],
        ['code' => 'DE', 'name' => 'Delta', 'region' => 'South-South', 'capital' => 'Asaba', 'capital_lat' => 6.1980, 'capital_lng' => 6.7330],
        ['code' => 'EB', 'name' => 'Ebonyi', 'region' => 'South-East', 'capital' => 'Abakaliki', 'capital_lat' => 6.3249, 'capital_lng' => 8.1137],
        ['code' => 'ED', 'name' => 'Edo', 'region' => 'South-South', 'capital' => 'Benin City', 'capital_lat' => 6.3350, 'capital_lng' => 5.6037],
        ['code' => 'EK', 'name' => 'Ekiti', 'region' => 'South-West', 'capital' => 'Ado-Ekiti', 'capital_lat' => 7.6233, 'capital_lng' => 5.2209],
        ['code' => 'EN', 'name' => 'Enugu', 'region' => 'South-East', 'capital' => 'Enugu', 'capital_lat' => 6.4584, 'capital_lng' => 7.5464],
        ['code' => 'FC', 'name' => 'FCT', 'region' => 'North-Central', 'capital' => 'Abuja', 'capital_lat' => 9.0579, 'capital_lng' => 7.4951],
        ['code' => 'GO', 'name' => 'Gombe', 'region' => 'North-East', 'capital' => 'Gombe', 'capital_lat' => 10.2897, 'capital_lng' => 11.1673],
        ['code' => 'IM', 'name' => 'Imo', 'region' => 'South-East', 'capital' => 'Owerri', 'capital_lat' => 5.4836, 'capital_lng' => 7.0333],
        ['code' => 'JI', 'name' => 'Jigawa', 'region' => 'North-West', 'capital' => 'Dutse', 'capital_lat' => 11.7562, 'capital_lng' => 9.3389],
        ['code' => 'KD', 'name' => 'Kaduna', 'region' => 'North-West', 'capital' => 'Kaduna', 'capital_lat' => 10.5105, 'capital_lng' => 7.4165],
        ['code' => 'KN', 'name' => 'Kano', 'region' => 'North-West', 'capital' => 'Kano', 'capital_lat' => 12.0022, 'capital_lng' => 8.5920],
        ['code' => 'KT', 'name' => 'Katsina', 'region' => 'North-West', 'capital' => 'Katsina', 'capital_lat' => 12.9908, 'capital_lng' => 7.6017],
        ['code' => 'KE', 'name' => 'Kebbi', 'region' => 'North-West', 'capital' => 'Birnin Kebbi', 'capital_lat' => 12.4539, 'capital_lng' => 4.1975],
        ['code' => 'KO', 'name' => 'Kogi', 'region' => 'North-Central', 'capital' => 'Lokoja', 'capital_lat' => 7.8023, 'capital_lng' => 6.7333],
        ['code' => 'KW', 'name' => 'Kwara', 'region' => 'North-Central', 'capital' => 'Ilorin', 'capital_lat' => 8.4966, 'capital_lng' => 4.5421],
        ['code' => 'LA', 'name' => 'Lagos', 'region' => 'South-West', 'capital' => 'Ikeja', 'capital_lat' => 6.6018, 'capital_lng' => 3.3515],
        ['code' => 'NA', 'name' => 'Nasarawa', 'region' => 'North-Central', 'capital' => 'Lafia', 'capital_lat' => 8.4939, 'capital_lng' => 8.5153],
        ['code' => 'NI', 'name' => 'Niger', 'region' => 'North-Central', 'capital' => 'Minna', 'capital_lat' => 9.6139, 'capital_lng' => 6.5569],
        ['code' => 'OG', 'name' => 'Ogun', 'region' => 'South-West', 'capital' => 'Abeokuta', 'capital_lat' => 7.1475, 'capital_lng' => 3.3619],
        ['code' => 'ON', 'name' => 'Ondo', 'region' => 'South-West', 'capital' => 'Akure', 'capital_lat' => 7.2571, 'capital_lng' => 5.2058],
        ['code' => 'OS', 'name' => 'Osun', 'region' => 'South-West', 'capital' => 'Osogbo', 'capital_lat' => 7.7827, 'capital_lng' => 4.5418],
        ['code' => 'OY', 'name' => 'Oyo', 'region' => 'South-West', 'capital' => 'Ibadan', 'capital_lat' => 7.3775, 'capital_lng' => 3.9470],
        ['code' => 'PL', 'name' => 'Plateau', 'region' => 'North-Central', 'capital' => 'Jos', 'capital_lat' => 9.8965, 'capital_lng' => 8.8583],
        ['code' => 'RI', 'name' => 'Rivers', 'region' => 'South-South', 'capital' => 'Port Harcourt', 'capital_lat' => 4.8156, 'capital_lng' => 7.0498],
        ['code' => 'SO', 'name' => 'Sokoto', 'region' => 'North-West', 'capital' => 'Sokoto', 'capital_lat' => 13.0059, 'capital_lng' => 5.2476],
        ['code' => 'TA', 'name' => 'Taraba', 'region' => 'North-East', 'capital' => 'Jalingo', 'capital_lat' => 8.8937, 'capital_lng' => 11.3596],
        ['code' => 'YO', 'name' => 'Yobe', 'region' => 'North-East', 'capital' => 'Damaturu', 'capital_lat' => 11.7470, 'capital_lng' => 11.9608],
        ['code' => 'ZA', 'name' => 'Zamfara', 'region' => 'North-West', 'capital' => 'Gusau', 'capital_lat' => 12.1628, 'capital_lng' => 6.6614],
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
