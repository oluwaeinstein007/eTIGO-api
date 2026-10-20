<?php

namespace App\Support;

/**
 * Google's encoded polyline format at precision 5, the format the maps
 * gateways return routes in and the admin dashboard draws.
 */
class Polyline
{
    /**
     * @param  list<array{0: float, 1: float}>  $points  [lat, lng] pairs
     */
    public static function encode(array $points): string
    {
        $encoded = '';
        $previousLat = 0;
        $previousLng = 0;

        foreach ($points as [$lat, $lng]) {
            $latE5 = (int) round($lat * 1e5);
            $lngE5 = (int) round($lng * 1e5);

            foreach ([$latE5 - $previousLat, $lngE5 - $previousLng] as $delta) {
                $value = $delta < 0 ? ~($delta << 1) : $delta << 1;

                while ($value >= 0x20) {
                    $encoded .= chr((0x20 | ($value & 0x1F)) + 63);
                    $value >>= 5;
                }

                $encoded .= chr($value + 63);
            }

            $previousLat = $latE5;
            $previousLng = $lngE5;
        }

        return $encoded;
    }

    /**
     * @return list<array{lat: float, lng: float}>
     */
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $deltas = [];

            for ($axis = 0; $axis < 2; $axis++) {
                $result = 0;
                $shift = 0;

                do {
                    if ($index >= $length) {
                        return $points;
                    }

                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);

                $deltas[] = ($result & 1) ? ~($result >> 1) : ($result >> 1);
            }

            $lat += $deltas[0];
            $lng += $deltas[1];
            $points[] = ['lat' => $lat / 1e5, 'lng' => $lng / 1e5];
        }

        return $points;
    }
}
