<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\DriverStatus;
use App\Enums\SocialProvider;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteRegistrationRequest;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\SocialAuthRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\SocialAccount;
use App\Models\SocialAuthCode;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private OtpService $otpService,
        private SocialAuthService $socialAuthService,
    ) {}

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $result = $this->otpService->sendAuthOtp($request->validated('phone'));

        if (! $result['sent']) {
            return response()->json([
                'message' => $result['error'],
            ], 429);
        }

        return response()->json([
            'message' => 'Verification code sent.',
            'expires_at' => $result['expires_at'],
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->otpService->verifyAuthOtp($validated['phone'], $validated['code']);

        if (! $result['valid']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        $userType = UserType::from($validated['type']);

        $user = User::where('phone', $validated['phone'])
            ->where('type', $userType)
            ->first();

        if ($user) {
            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Your account has been deactivated. Contact support.',
                ], 403);
            }

            $user->update(['phone_verified_at' => now()]);

            AuditLog::record($user, 'logged_in');

            $token = $user->createToken(
                $user->type->value.'-auth',
                [$user->type->value],
            )->plainTextToken;

            return response()->json([
                'message' => 'Logged in successfully.',
                'is_new_user' => false,
                'user' => new UserResource($user),
                'token' => $token,
            ]);
        }

        return response()->json([
            'message' => 'Phone verified. Please complete registration.',
            'is_new_user' => true,
            'phone' => $validated['phone'],
            'type' => $userType->value,
        ]);
    }

    public function completeRegistration(CompleteRegistrationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $phone = $validated['phone'];
        $userType = UserType::from($validated['type']);

        if (User::where('phone', $phone)->where('type', $userType)->exists()) {
            return response()->json([
                'message' => 'A '.$userType->value.' account with this phone number already exists.',
            ], 409);
        }

        $recentlyVerified = OtpCode::forPhone($phone)
            ->where('purpose', 'login')
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', now()->subMinutes(10))
            ->exists();

        if (! $recentlyVerified) {
            return response()->json([
                'message' => 'Phone number not verified. Please verify your phone first.',
            ], 403);
        }

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $phone,
            'email' => $validated['email'] ?? null,
            'type' => $userType,
            'phone_verified_at' => now(),
        ]);

        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')
                ->store("profile-photos/{$user->id}", config('filesystems.uploads'));

            if (! $path) {
                $user->delete();

                return response()->json(['message' => 'File upload failed. Please try again.'], 503);
            }

            $user->update(['profile_photo_path' => $path]);
            $user->refresh();
        }

        if ($userType === UserType::Driver) {
            Driver::create([
                'user_id' => $user->id,
                'city_id' => $validated['city_id'] ?? null,
                'status' => DriverStatus::Onboarding,
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

    public function socialRedirect(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string', 'in:google,apple,facebook'],
            'type' => ['required', 'string', 'in:passenger,driver'],
            'state' => ['required', 'string', 'min:16', 'max:128'],
        ]);

        $provider = SocialProvider::from($request->input('provider'));

        $redirectUrl = $this->socialAuthService->getRedirectUrl(
            $provider,
            $request->input('type'),
            $request->input('state'),
        );

        return response()->json([
            'redirect_url' => $redirectUrl,
            'provider' => $provider->value,
        ]);
    }

    public function socialAuth(SocialAuthRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $provider = SocialProvider::from($validated['provider']);
        $userType = UserType::from($validated['type']);

        $socialUser = $this->socialAuthService->verifyToken($provider, $validated['token']);

        if (! $socialUser) {
            return response()->json([
                'message' => 'Invalid social login token.',
            ], 401);
        }

        $socialAccount = SocialAccount::where('provider', $provider->value)
            ->where('provider_id', $socialUser['id'])
            ->whereHas('user', fn ($q) => $q->where('type', $userType))
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;

            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Your account has been deactivated. Contact support.',
                ], 403);
            }

            AuditLog::record($user, 'logged_in');

            $token = $user->createToken(
                $user->type->value.'-auth',
                [$user->type->value],
            )->plainTextToken;

            return response()->json([
                'message' => 'Logged in successfully.',
                'is_new_user' => false,
                'user' => new UserResource($user),
                'token' => $token,
            ]);
        }

        if ($socialUser['email']) {
            $existingUser = User::where('email', $socialUser['email'])
                ->where('type', $userType)
                ->first();

            if ($existingUser) {
                if (! $existingUser->is_active) {
                    return response()->json([
                        'message' => 'Your account has been deactivated. Contact support.',
                    ], 403);
                }

                SocialAccount::create([
                    'user_id' => $existingUser->id,
                    'provider' => $provider->value,
                    'provider_id' => $socialUser['id'],
                ]);

                AuditLog::record($existingUser, 'logged_in');

                $token = $existingUser->createToken(
                    $existingUser->type->value.'-auth',
                    [$existingUser->type->value],
                )->plainTextToken;

                return response()->json([
                    'message' => 'Logged in successfully.',
                    'is_new_user' => false,
                    'user' => new UserResource($existingUser),
                    'token' => $token,
                ]);
            }
        }

        $user = User::create([
            'first_name' => $socialUser['first_name'] ?? '',
            'last_name' => $socialUser['last_name'] ?? '',
            'email' => $socialUser['email'],
            'type' => $userType,
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider->value,
            'provider_id' => $socialUser['id'],
        ]);

        if ($userType === UserType::Driver) {
            Driver::create([
                'user_id' => $user->id,
                'status' => DriverStatus::Onboarding,
            ]);
        }

        AuditLog::record($user, 'registered');

        $token = $user->createToken(
            $userType->value.'-auth',
            [$userType->value],
        )->plainTextToken;

        $needsProfileCompletion = empty($socialUser['first_name']) || empty($socialUser['last_name']);

        return response()->json([
            'message' => 'Account created successfully.',
            'is_new_user' => true,
            'needs_profile_completion' => $needsProfileCompletion,
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
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

    public function socialExchange(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:64'],
        ]);

        $authCode = SocialAuthCode::where('code', $request->input('code'))->first();

        if (! $authCode || $authCode->isUsed() || $authCode->isExpired()) {
            return response()->json([
                'message' => 'Invalid or expired authorization code.',
            ], 401);
        }

        $authCode->markUsed();

        $provider = SocialProvider::from($authCode->provider);
        $userType = UserType::from($authCode->user_type);

        $socialAccount = SocialAccount::where('provider', $provider->value)
            ->where('provider_id', $authCode->provider_id)
            ->whereHas('user', fn ($q) => $q->where('type', $userType))
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;

            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Your account has been deactivated. Contact support.',
                ], 403);
            }

            AuditLog::record($user, 'logged_in');

            $token = $user->createToken(
                $user->type->value.'-auth',
                [$user->type->value],
            )->plainTextToken;

            return response()->json([
                'message' => 'Logged in successfully.',
                'is_new_user' => false,
                'user' => new UserResource($user),
                'token' => $token,
            ]);
        }

        if ($authCode->email) {
            $existingUser = User::where('email', $authCode->email)
                ->where('type', $userType)
                ->first();

            if ($existingUser) {
                if (! $existingUser->is_active) {
                    return response()->json([
                        'message' => 'Your account has been deactivated. Contact support.',
                    ], 403);
                }

                SocialAccount::create([
                    'user_id' => $existingUser->id,
                    'provider' => $provider->value,
                    'provider_id' => $authCode->provider_id,
                ]);

                AuditLog::record($existingUser, 'logged_in');

                $token = $existingUser->createToken(
                    $existingUser->type->value.'-auth',
                    [$existingUser->type->value],
                )->plainTextToken;

                return response()->json([
                    'message' => 'Logged in successfully.',
                    'is_new_user' => false,
                    'user' => new UserResource($existingUser),
                    'token' => $token,
                ]);
            }
        }

        $user = User::create([
            'first_name' => $authCode->first_name ?? '',
            'last_name' => $authCode->last_name ?? '',
            'email' => $authCode->email,
            'type' => $userType,
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider->value,
            'provider_id' => $authCode->provider_id,
        ]);

        if ($userType === UserType::Driver) {
            Driver::create([
                'user_id' => $user->id,
                'status' => DriverStatus::Onboarding,
            ]);
        }

        AuditLog::record($user, 'registered');

        $token = $user->createToken(
            $userType->value.'-auth',
            [$userType->value],
        )->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'is_new_user' => true,
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => new UserResource(request()->user()),
        ]);
    }
}
