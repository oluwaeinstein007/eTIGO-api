<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\DriverStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

class OtpAuthController extends Controller
{
    public function __construct(
        private OtpService $otpService,
    ) {}

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $this->otpService->requestOtp(
            $request->validated('phone'),
            'login',
        );

        return response()->json([
            'message' => 'Verification code sent successfully.',
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->otpService->verifyOtp(
            $validated['phone'],
            $validated['code'],
            'login',
        );

        if (! $result['valid']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        $userType = UserType::from($validated['type']);
        $user = User::where('phone', $validated['phone'])
            ->where('type', $userType)
            ->first();

        $isNewUser = false;

        if (! $user) {
            $isNewUser = true;
            $user = User::create([
                'first_name' => $validated['first_name'] ?? '',
                'last_name' => $validated['last_name'] ?? '',
                'phone' => $validated['phone'],
                'type' => $userType,
            ]);

            $user->update(['phone_verified_at' => now()]);

            if ($userType === UserType::Driver) {
                Driver::create([
                    'user_id' => $user->id,
                    'status' => DriverStatus::PendingReview,
                ]);
            }

            AuditLog::record($user, 'registered');
        } else {
            if (! $user->hasPhoneVerified()) {
                $user->update(['phone_verified_at' => now()]);
            }

            AuditLog::record($user, 'logged_in');
        }

        $token = $user->createToken(
            $userType->value.'-auth',
            [$userType->value],
        )->plainTextToken;

        return response()->json([
            'message' => $isNewUser ? 'Account created successfully.' : 'Logged in successfully.',
            'user' => new UserResource($user),
            'token' => $token,
            'is_new_user' => $isNewUser,
        ], $isNewUser ? 201 : 200);
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
}
