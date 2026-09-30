<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceToken\StoreDeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

class DeviceTokenController extends Controller
{
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        DeviceToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $request->validated('platform'),
                'token' => $request->validated('token'),
            ],
            ['is_active' => true],
        );

        return response()->json(['message' => 'Device token registered.'], 201);
    }

    public function destroy(StoreDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        DeviceToken::where('user_id', $user->id)
            ->where('platform', $request->validated('platform'))
            ->where('token', $request->validated('token'))
            ->delete();

        return response()->json(['message' => 'Device token removed.']);
    }
}
