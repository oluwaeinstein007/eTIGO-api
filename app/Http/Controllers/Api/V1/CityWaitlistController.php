<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityWaitlistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A passenger asks to be told when a city that is not open yet (onboarding)
 * or paused goes live (Phase 2 multi-city, OQ-22). They get one
 * notification on launch; joining again afterwards starts a new wait.
 */
class CityWaitlistController extends Controller
{
    public function show(Request $request, City $city): JsonResponse
    {
        return response()->json($this->state($city, $this->entry($request, $city)));
    }

    public function store(Request $request, City $city): JsonResponse
    {
        if (! $city->launch_status->acceptsWaitlist()) {
            return response()->json([
                'message' => "E-tiGo is already live in {$city->name}. You can book now.",
            ], 422);
        }

        $entry = CityWaitlistEntry::updateOrCreate(
            ['city_id' => $city->id, 'user_id' => $request->user()->id],
            ['notified_at' => null],
        );

        return response()->json([
            'message' => "You are on the waitlist. We will tell you when E-tiGo opens in {$city->name}.",
            ...$this->state($city, $entry),
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, City $city): JsonResponse
    {
        $this->entry($request, $city)?->delete();

        return response()->json([
            'message' => 'You have left the waitlist.',
            ...$this->state($city, null),
        ]);
    }

    private function entry(Request $request, City $city): ?CityWaitlistEntry
    {
        return CityWaitlistEntry::where('city_id', $city->id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(City $city, ?CityWaitlistEntry $entry): array
    {
        return [
            'city' => [
                'id' => $city->id,
                'name' => $city->name,
                'launch_status' => $city->launch_status->value,
            ],
            'on_waitlist' => $entry !== null && $entry->notified_at === null,
            'joined_at' => $entry?->created_at,
            'notified_at' => $entry?->notified_at,
        ];
    }
}
