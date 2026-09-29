<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\SocialProvider;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\SocialAuthService;
use Illuminate\Http\JsonResponse;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {}

    public function login(SocialLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->socialAuthService->authenticateFromToken(
                SocialProvider::from($validated['provider']),
                $validated['access_token'],
                UserType::from($validated['type']),
            );
        } catch (\Exception) {
            return response()->json([
                'message' => 'Unable to authenticate with the social provider. Please try again.',
            ], 422);
        }

        if (! $result['success']) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        $status = $result['is_new_user'] ? 201 : 200;

        return response()->json([
            'message' => $result['is_new_user'] ? 'Account created successfully.' : 'Logged in successfully.',
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
        ], $status);
    }
}
