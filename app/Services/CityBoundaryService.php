<?php

namespace App\Services;

use App\Models\City;
use App\Support\NigerianStates;

/**
 * Overlap checks between city boundaries (Phase 2 multi-city, OQ-22). A
 * pickup must map to one city, so a boundary that overlaps another city's
 * is refused when saved. Boundaries are GeoJSON-like: a Polygon (exterior
 * ring of [lng, lat] points; holes are ignored) or a Point with radius_km
 * (30 km when missing, as in city detection).
 *
 * Shapes are compared on a flat local projection in kilometres, which is
 * accurate enough at city scale. Borders that touch are allowed: points
 * within 10 m of the other shape's edge count as on the edge, so two
 * cities can share a border drawn with the same points.
 */
class CityBoundaryService
{
    /**
     * Most points in one ring of a polygon (create, update and check
     * enforce it): keeps the overlap and self-crossing checks fast; a
     * detailed city outline needs far fewer.
     */
    public const MAX_BOUNDARY_POINTS = 500;

    private const DEFAULT_RADIUS_KM = 30.0;

    private const EDGE_TOLERANCE_KM = 0.01;

    private const KM_PER_DEGREE_LAT = 110.574;

    private const KM_PER_DEGREE_LNG_AT_EQUATOR = 111.320;

    /**
     * Nigeria's extent, with a small margin.
     */
    private const NIGERIA = ['min_lat' => 4.0, 'max_lat' => 14.0, 'min_lng' => 2.6, 'max_lng' => 14.7];

    /**
     * Areas outside this range (km²) are almost always a drawing mistake:
     * the Lagos metropolis outline is about 1,400 km².
     */
    private const MIN_AREA_KM2 = 10.0;

    private const MAX_AREA_KM2 = 2500.0;

    /**
     * A city further than this from its state's capital is probably drawn
     * in the wrong place (or the state is wrong).
     */
    private const MAX_KM_FROM_STATE_CAPITAL = 250.0;

    private const SWAPPED = 'The coordinates look swapped. GeoJSON lists longitude first, then latitude ([lng, lat]); swapped back, this boundary is where the city should be.';

    /**
     * The first other city whose boundary overlaps this one, if any.
     *
     * @param  array<string, mixed>  $boundary
     */
    public function findOverlappingCity(array $boundary, ?string $exceptCityId = null): ?City
    {
        if ($this->shape($boundary) === null) {
            return null;
        }

        return City::query()
            ->whereNotNull('boundary')
            ->when($exceptCityId, fn ($q) => $q->whereKeyNot($exceptCityId))
            ->orderBy('name')
            ->get()
            ->first(fn (City $city) => is_array($city->boundary) && $this->overlaps($boundary, $city->boundary));
    }

    /**
     * For each city, the other cities whose saved boundary overlaps it.
     * Saving refuses a new overlap, so these are boundaries saved before
     * that check; the admin city screens show them so they get redrawn.
     *
     * @param  iterable<City>  $cities
     * @return array<string, list<City>>
     */
    public function overlapsFor(iterable $cities): array
    {
        $drawn = City::query()->whereNotNull('boundary')->orderBy('name')->get()
            ->filter(fn (City $city) => is_array($city->boundary));

        $overlaps = [];

        foreach ($cities as $city) {
            $overlaps[$city->id] = is_array($city->boundary)
                ? $drawn->filter(fn (City $other) => $other->id !== $city->id && $this->overlaps($city->boundary, $other->boundary))->values()->all()
                : [];
        }

        return $overlaps;
    }

    /**
     * Plain-English warnings for the admin city screens, one per city whose
     * boundary overlaps this one.
     *
     * @return list<string>
     */
    public function warningsForCity(City $city): array
    {
        $overlaps = array_map(
            fn (City $other) => "{$city->name}'s boundary overlaps {$other->name}. Pickups in the overlap are booked in the smaller of the two cities. Redraw one boundary so they only share a border.",
            $this->overlapsFor([$city])[$city->id],
        );

        if (! is_array($city->boundary) || $city->boundary === []) {
            return $city->isLive()
                ? [...$overlaps, "{$city->name} has no map boundary, so pickups are not placed in it by location. Draw its outline."]
                : $overlaps;
        }

        return [...$overlaps, ...$this->sanityWarnings($city->boundary, $city->state)];
    }

    /**
     * Warnings about a boundary that can be saved but is probably wrong:
     * outside Nigeria (often latitude and longitude swapped), a circle
     * instead of an outline, an implausible size, an outline that crosses
     * itself, or one far from the city's state. They never block a save.
     *
     * @param  array<string, mixed>  $boundary
     * @return list<string>
     */
    public function sanityWarnings(array $boundary, ?string $state = null): array
    {
        $shape = $this->shape($boundary);

        if ($shape === null) {
            return [];
        }

        // [lng, lat] points: the circle's centre or the outline's corners.
        $points = $shape['type'] === 'circle' ? [$shape['center']] : $shape['ring'];

        if (array_filter($points, fn (array $p) => ! $this->inNigeria($p[1], $p[0])) !== []) {
            $swapped = array_filter($points, fn (array $p) => $this->inNigeria($p[0], $p[1]));

            // Nothing else can be judged on a boundary in the wrong place.
            return [count($swapped) === count($points)
                ? self::SWAPPED
                : 'Part of this boundary is outside Nigeria. Check each point is [longitude, latitude].'];
        }

        $warnings = [];

        if ($shape['type'] === 'circle') {
            $warnings[] = 'This boundary is a circle. Draw an outline around the built-up area instead: a circle takes in water, farmland and neighbouring towns, and is hard to keep clear of other cities.';
        }

        ['area_sq_km' => $area, 'centre' => $centre] = $this->describe($boundary);

        // A crossing outline's area is meaningless (its parts cancel out).
        if ($shape['type'] === 'polygon' && $this->crossesItself($shape)) {
            $warnings[] = 'The outline crosses itself. Redraw it so its edges do not cross.';
        } elseif ($area < self::MIN_AREA_KM2) {
            $warnings[] = 'This boundary covers only '.number_format($area, 1).' km², so most pickups in the city would fall outside it.';
        } elseif ($area > self::MAX_AREA_KM2) {
            $warnings[] = 'This boundary covers '.number_format($area).' km², more than a city needs (the Lagos metropolis is about 1,400 km²). It will take in neighbouring towns.';
        }

        $capital = $state !== null ? NigerianStates::capitalOf($state) : null;

        if ($capital !== null) {
            $km = $this->distanceKm($centre['lat'], $centre['lng'], $capital['lat'], $capital['lng']);

            // Nigeria's latitudes and longitudes overlap, so swapped points
            // can still land in Nigeria: swapped back, they are near the state.
            if ($km > self::MAX_KM_FROM_STATE_CAPITAL
                && $this->distanceKm($centre['lng'], $centre['lat'], $capital['lat'], $capital['lng']) <= self::MAX_KM_FROM_STATE_CAPITAL) {
                return [self::SWAPPED];
            }

            if ($km > self::MAX_KM_FROM_STATE_CAPITAL) {
                $warnings[] = 'This boundary is about '.number_format($km).' km from '.$capital['name'].", {$state}'s capital. Check the state and where the boundary is drawn.";
            }
        }

        return $warnings;
    }

    /**
     * Area and centre of a boundary (the centre is the average of the
     * outline's corners, or the circle's centre).
     *
     * @param  array<string, mixed>  $boundary
     * @return array{area_sq_km: float, centre: array{lat: float, lng: float}}|null
     */
    public function describe(array $boundary): ?array
    {
        $shape = $this->shape($boundary);

        if ($shape === null) {
            return null;
        }

        if ($shape['type'] === 'circle') {
            return [
                'area_sq_km' => round(M_PI * $shape['radius'] ** 2, 2),
                'centre' => ['lat' => $shape['center'][1], 'lng' => $shape['center'][0]],
            ];
        }

        return [
            // All rings, so holes count as when the city is saved.
            'area_sq_km' => round(City::calculateAreaFromPolygon($boundary['coordinates']), 2),
            'centre' => [
                'lat' => round(array_sum(array_column($shape['ring'], 1)) / count($shape['ring']), 6),
                'lng' => round(array_sum(array_column($shape['ring'], 0)) / count($shape['ring']), 6),
            ],
        ];
    }

    private function inNigeria(float $lat, float $lng): bool
    {
        return $lat >= self::NIGERIA['min_lat'] && $lat <= self::NIGERIA['max_lat']
            && $lng >= self::NIGERIA['min_lng'] && $lng <= self::NIGERIA['max_lng'];
    }

    /**
     * Two edges that are not neighbours cross.
     *
     * @param  array<string, mixed>  $shape
     */
    private function crossesItself(array $shape): bool
    {
        // Quadratic in the number of edges: requests cap a ring at
        // MAX_BOUNDARY_POINTS; an older, larger boundary is not checked.
        if (count($shape['ring']) > self::MAX_BOUNDARY_POINTS) {
            return false;
        }

        $edges = $this->edges($this->project($shape, $this->meanLat($shape))['ring']);
        $n = count($edges);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;
                }

                if ($this->segmentsCross($edges[$i][0], $edges[$i][1], $edges[$j][0], $edges[$j][1])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function overlaps(array $a, array $b): bool
    {
        $shapeA = $this->shape($a);
        $shapeB = $this->shape($b);

        if ($shapeA === null || $shapeB === null) {
            return false;
        }

        $originLat = ($this->meanLat($shapeA) + $this->meanLat($shapeB)) / 2;
        $a = $this->project($shapeA, $originLat);
        $b = $this->project($shapeB, $originLat);

        return match (true) {
            $a['type'] === 'circle' && $b['type'] === 'circle' => $this->circlesOverlap($a, $b),
            $a['type'] === 'circle' => $this->circleOverlapsPolygon($a, $b['ring']),
            $b['type'] === 'circle' => $this->circleOverlapsPolygon($b, $a['ring']),
            default => $this->polygonsOverlap($a['ring'], $b['ring']),
        };
    }

    /**
     * @param  array<string, mixed>  $boundary
     * @return array{type: 'circle', center: array{0: float, 1: float}, radius: float}|array{type: 'polygon', ring: list<array{0: float, 1: float}>}|null
     */
    private function shape(array $boundary): ?array
    {
        $coordinates = $boundary['coordinates'] ?? null;

        if (! is_array($coordinates)) {
            return null;
        }

        if (($boundary['type'] ?? null) === 'Point') {
            if (! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
                return null;
            }

            return [
                'type' => 'circle',
                'center' => [(float) $coordinates[0], (float) $coordinates[1]],
                'radius' => (float) ($boundary['radius_km'] ?? self::DEFAULT_RADIUS_KM),
            ];
        }

        if (($boundary['type'] ?? null) === 'Polygon') {
            $ring = [];
            foreach ($coordinates[0] ?? [] as $point) {
                if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) {
                    $ring[] = [(float) $point[0], (float) $point[1]];
                }
            }

            // A closed ring repeats its first point at the end.
            if (count($ring) > 1 && $ring[0] === $ring[count($ring) - 1]) {
                array_pop($ring);
            }

            return count($ring) >= 3 ? ['type' => 'polygon', 'ring' => $ring] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    private function meanLat(array $shape): float
    {
        if ($shape['type'] === 'circle') {
            return $shape['center'][1];
        }

        return array_sum(array_column($shape['ring'], 1)) / count($shape['ring']);
    }

    /**
     * Degrees to kilometres around the origin latitude: x east, y north.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private function project(array $shape, float $originLat): array
    {
        $kmPerLng = self::KM_PER_DEGREE_LNG_AT_EQUATOR * cos(deg2rad($originLat));
        $toKm = fn (array $p) => [$p[0] * $kmPerLng, $p[1] * self::KM_PER_DEGREE_LAT];

        if ($shape['type'] === 'circle') {
            return [...$shape, 'center' => $toKm($shape['center'])];
        }

        return [...$shape, 'ring' => array_map($toKm, $shape['ring'])];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function circlesOverlap(array $a, array $b): bool
    {
        $distance = hypot($a['center'][0] - $b['center'][0], $a['center'][1] - $b['center'][1]);

        return $distance < $a['radius'] + $b['radius'] - self::EDGE_TOLERANCE_KM;
    }

    /**
     * @param  array<string, mixed>  $circle
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function circleOverlapsPolygon(array $circle, array $ring): bool
    {
        [$x, $y] = $circle['center'];

        if (self::pointInRing($x, $y, $ring)) {
            return true;
        }

        return $this->distanceToRing($x, $y, $ring) < $circle['radius'] - self::EDGE_TOLERANCE_KM;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $a
     * @param  list<array{0: float, 1: float}>  $b
     */
    private function polygonsOverlap(array $a, array $b): bool
    {
        // Edges that cross.
        foreach ($this->edges($a) as [$p1, $p2]) {
            foreach ($this->edges($b) as [$q1, $q2]) {
                if ($this->segmentsCross($p1, $p2, $q1, $q2)) {
                    return true;
                }
            }
        }

        // No crossing: one is inside the other, or they only touch. Test
        // vertices, edge midpoints and the centroid of each against the other.
        foreach ([[$a, $b], [$b, $a]] as [$ring, $other]) {
            foreach ($this->samplePoints($ring) as [$x, $y]) {
                if ($this->strictlyInside($x, $y, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     * @return list<array{0: float, 1: float}>
     */
    private function samplePoints(array $ring): array
    {
        $points = $ring;

        foreach ($this->edges($ring) as [$p1, $p2]) {
            $points[] = [($p1[0] + $p2[0]) / 2, ($p1[1] + $p2[1]) / 2];
        }

        $centroid = [array_sum(array_column($ring, 0)) / count($ring), array_sum(array_column($ring, 1)) / count($ring)];
        if (self::pointInRing($centroid[0], $centroid[1], $ring)) {
            $points[] = $centroid;
        }

        return $points;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function strictlyInside(float $x, float $y, array $ring): bool
    {
        return self::pointInRing($x, $y, $ring)
            && $this->distanceToRing($x, $y, $ring) > self::EDGE_TOLERANCE_KM;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>
     */
    private function edges(array $ring): array
    {
        $edges = [];
        $n = count($ring);

        for ($i = 0; $i < $n; $i++) {
            $edges[] = [$ring[$i], $ring[($i + 1) % $n]];
        }

        return $edges;
    }

    /**
     * True when the segments cross at a point inside both (not at an end,
     * and not when they lie along each other).
     *
     * @param  array{0: float, 1: float}  $p1
     * @param  array{0: float, 1: float}  $p2
     * @param  array{0: float, 1: float}  $q1
     * @param  array{0: float, 1: float}  $q2
     */
    private function segmentsCross(array $p1, array $p2, array $q1, array $q2): bool
    {
        $d1 = $this->cross($q1, $q2, $p1);
        $d2 = $this->cross($q1, $q2, $p2);
        $d3 = $this->cross($p1, $p2, $q1);
        $d4 = $this->cross($p1, $p2, $q2);

        $eps = 1e-9;

        return (($d1 > $eps && $d2 < -$eps) || ($d1 < -$eps && $d2 > $eps))
            && (($d3 > $eps && $d4 < -$eps) || ($d3 < -$eps && $d4 > $eps))
            && $this->distanceToSegment($p1[0], $p1[1], $q1, $q2) > self::EDGE_TOLERANCE_KM
            && $this->distanceToSegment($p2[0], $p2[1], $q1, $q2) > self::EDGE_TOLERANCE_KM;
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     * @param  array{0: float, 1: float}  $c
     */
    private function cross(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function distanceToRing(float $x, float $y, array $ring): float
    {
        $min = INF;

        foreach ($this->edges($ring) as [$a, $b]) {
            $min = min($min, $this->distanceToSegment($x, $y, $a, $b));
        }

        return $min;
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private function distanceToSegment(float $x, float $y, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lengthSquared = $dx * $dx + $dy * $dy;

        $t = $lengthSquared > 0
            ? max(0.0, min(1.0, (($x - $a[0]) * $dx + ($y - $a[1]) * $dy) / $lengthSquared))
            : 0.0;

        return hypot($x - ($a[0] + $t * $dx), $y - ($a[1] + $t * $dy));
    }

    /**
     * Ray casting.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring  [[x, y], ...]
     */
    private static function pointInRing(float $x, float $y, array $ring): bool
    {
        $n = count($ring);
        $inside = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if (($yi > $y) !== ($yj > $y)
                && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
