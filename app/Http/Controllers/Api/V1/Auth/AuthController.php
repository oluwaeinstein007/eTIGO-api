<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\DriverStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userType = UserType::from($validated['type']);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'type' => $userType,
        ]);

        if ($userType === UserType::Driver) {
            Driver::create([
                'user_id' => $user->id,
                'status' => DriverStatus::PendingReview,
            ]);
        }

        AuditLog::record($user, 'registered');

        $token = $user->createToken(
            $userType->value.'-auth',
            [$userType->value],
        )->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userType = UserType::from($validated['type']);

        $user = User::where('email', $validated['email'])
            ->where('type', $userType)
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Contact support.',
            ], 403);
        }

        AuditLog::record($user, 'logged_in');

        $token = $user->createToken(
            $userType->value.'-auth',
            [$userType->value],
        )->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = request()->user();
        $user->currentAccessToken()->delete();

        AuditLog::record($user, 'logged_out');

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => new UserResource(request()->user()),
        ]);
    }
}
