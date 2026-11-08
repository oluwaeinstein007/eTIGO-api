<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleMakeResource;
use App\Http\Resources\VehicleModelResource;
use App\Models\VehicleMake;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleCatalogController extends Controller
{
    /**
     * List active vehicle makes with their active models.
     */
    public function index(Request $request): JsonResponse
    {
        $fuelType = $request->query('fuel_type');
        $search = $request->query('search');

        $query = VehicleMake::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name');

        $query->with(['models' => function ($modelQuery) use ($fuelType, $search) {
            $modelQuery->active()
                ->orderBy('sort_order')
                ->orderBy('name');

            if (! empty($fuelType)) {
                $modelQuery->where('fuel_type', $fuelType);
            }

            if (! empty($search)) {
                $modelQuery->whereLike('name', SearchTerm::like($search), false);
            }
        }]);

        if (! empty($search)) {
            $query->where(function (Builder $q) use ($search) {
                $q->whereLike('name', SearchTerm::like($search), false)
                    ->orWhereHas('models', function (Builder $mq) use ($search) {
                        $mq->active()->whereLike('name', SearchTerm::like($search), false);
                    });
            });
        }

        if (! empty($fuelType)) {
            $query->whereHas('models', function (Builder $mq) use ($fuelType) {
                $mq->active()->where('fuel_type', $fuelType);
            });
        }

        $makes = $query->get();

        return response()->json([
            'makes' => VehicleMakeResource::collection($makes),
        ]);
    }

    /**
     * List models for a specific vehicle make.
     */
    public function models(VehicleMake $vehicleMake, Request $request): JsonResponse
    {
        $fuelType = $request->query('fuel_type');
        $search = $request->query('search');

        $query = $vehicleMake->models()->active()->orderBy('sort_order')->orderBy('name');

        if (! empty($fuelType)) {
            $query->where('fuel_type', $fuelType);
        }

        if (! empty($search)) {
            $query->whereLike('name', SearchTerm::like($search), false);
        }

        $models = $query->get();

        return response()->json([
            'make' => new VehicleMakeResource($vehicleMake),
            'models' => VehicleModelResource::collection($models),
        ]);
    }
}
