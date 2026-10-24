<?php

namespace App\Http\Requests\Admin\City;

use App\Services\CityBoundaryService;

/**
 * Shape rules for a city boundary, shared by creating, updating and
 * checking one, so all three accept the same boundaries.
 */
trait ValidatesCityBoundary
{
    /**
     * A polygon is rings of [lng, lat] pairs; a point is one [lng, lat] pair.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function withBoundaryShapeRules(array $rules): array
    {
        if ($this->input('boundary.type') === 'Polygon') {
            $rules['boundary.coordinates.*'] = ['array', 'min:3', 'max:'.CityBoundaryService::MAX_BOUNDARY_POINTS];
            $rules['boundary.coordinates.*.*'] = ['array', 'size:2'];
            $rules['boundary.coordinates.*.*.*'] = ['numeric'];
        } elseif ($this->input('boundary.type') === 'Point') {
            $rules['boundary.coordinates'] = [...$rules['boundary.coordinates'], 'size:2'];
            $rules['boundary.coordinates.*'] = ['numeric'];
        }

        return $rules;
    }
}
