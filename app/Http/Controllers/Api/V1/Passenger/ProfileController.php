<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passenger\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'user' => new UserResource(request()->user()),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $oldValues = $user->only(['first_name', 'last_name', 'email']);

        $data = $request->safe()->except(['profile_photo']);

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('s3')->delete($user->profile_photo_path);
            }

            $data['profile_photo_path'] = $request->file('profile_photo')
                ->store("profile-photos/{$user->id}", 's3');
        }

        $user->update($data);

        AuditLog::record($user, 'profile_updated', $user, $oldValues, $data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function deletePhoto(): JsonResponse
    {
        $user = request()->user();

        if (! $user->profile_photo_path) {
            return response()->json([
                'message' => 'No profile photo to remove.',
            ], 422);
        }

        Storage::disk('s3')->delete($user->profile_photo_path);

        $user->update(['profile_photo_path' => null]);

        AuditLog::record($user, 'profile_photo_removed', $user);

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
