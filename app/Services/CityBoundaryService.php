<?php

namespace App\Services;

use App\Models\City;

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
    private const DEFAULT_RADIUS_KM = 30.0;

    private const EDGE_TOLERANCE_KM = 0.01;

    private const KM_PER_DEGREE_LAT = 110.574;

    private const KM_PER_DEGREE_LNG_AT_EQUATOR = 111.320;

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
