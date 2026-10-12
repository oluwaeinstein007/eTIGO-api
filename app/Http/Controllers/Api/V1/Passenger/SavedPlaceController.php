<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavedPlace\StoreSavedPlaceRequest;
use App\Http\Requests\SavedPlace\UpdateSavedPlaceRequest;
use App\Http\Resources\SavedPlaceResource;
use App\Models\SavedPlace;
use Illuminate\Http\JsonResponse;

class SavedPlaceController extends Controller
{
    public function index(): JsonResponse
    {
        $places = request()->user()
            ->savedPlaces()
            ->orderByRaw("CASE type WHEN 'home' THEN 0 WHEN 'work' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'saved_places' => SavedPlaceResource::collection($places),
        ]);
    }

    public function store(StoreSavedPlaceRequest $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->validated('type');

        if (in_array($type, ['home', 'work'])) {
            $place = $user->savedPlaces()->updateOrCreate(
                ['type' => $type],
                [
                    'name' => ucfirst($type),
                    'address' => $request->validated('address'),
                    'latitude' => $request->validated('latitude'),
                    'longitude' => $request->validated('longitude'),
                ],
            );

            return response()->json([
                'message' => ucfirst($type) . ' address saved.',
                'saved_place' => new SavedPlaceResource($place),
            ], $place->wasRecentlyCreated ? 201 : 200);
        }

        $place = $user->savedPlaces()->create($request->validated());

        return response()->json([
            'message' => 'Place saved.',
            'saved_place' => new SavedPlaceResource($place),
        ], 201);
    }

    public function show(SavedPlace $savedPlace): JsonResponse
    {
        if ($savedPlace->user_id !== request()->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'saved_place' => new SavedPlaceResource($savedPlace),
        ]);
    }

    public function update(UpdateSavedPlaceRequest $request, SavedPlace $savedPlace): JsonResponse
    {
        if ($savedPlace->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $savedPlace->update($request->validated());

        return response()->json([
            'message' => 'Place updated.',
            'saved_place' => new SavedPlaceResource($savedPlace),
        ]);
    }

    public function destroy(SavedPlace $savedPlace): JsonResponse
    {
        if ($savedPlace->user_id !== request()->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $savedPlace->delete();

        return response()->json([
            'message' => 'Place removed.',
        ]);
    }
}
