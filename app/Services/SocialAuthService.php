<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\SocialProvider;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function authenticateFromToken(SocialProvider $provider, string $accessToken, UserType $userType): array
    {
        $socialUser = Socialite::driver($provider->value)->stateless()->userFromToken($accessToken);

        return DB::transaction(function () use ($provider, $socialUser, $userType) {
            $socialAccount = SocialAccount::where('provider', $provider->value)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if ($socialAccount) {
                $user = $socialAccount->user;

                if ($user->type !== $userType) {
                    return [
                        'success' => false,
                        'error' => 'This social account is linked to a different account type.',
                        'status' => 409,
                    ];
                }

                if (! $user->is_active) {
                    return [
                        'success' => false,
                        'error' => 'Your account has been deactivated. Contact support.',
                        'status' => 403,
                    ];
                }

                $socialAccount->update([
                    'provider_token' => $socialUser->token,
                    'provider_refresh_token' => $socialUser->refreshToken,
                ]);

                AuditLog::record($user, 'social_login', $user, [], ['provider' => $provider->value]);

                return $this->issueToken($user, $userType, false);
            }

            if ($socialUser->getEmail()) {
                $existingUser = User::where('email', $socialUser->getEmail())
                    ->where('type', $userType)
                    ->first();

                if ($existingUser) {
                    $existingUser->socialAccounts()->create([
                        'provider' => $provider->value,
                        'provider_id' => $socialUser->getId(),
                        'provider_token' => $socialUser->token,
                        'provider_refresh_token' => $socialUser->refreshToken,
                    ]);

                    AuditLog::record($existingUser, 'social_account_linked', $existingUser, [], ['provider' => $provider->value]);

                    return $this->issueToken($existingUser, $userType, false);
                }
            }

            $nameParts = $this->parseName($socialUser->getName());

            $user = User::create([
                'first_name' => $nameParts['first_name'],
                'last_name' => $nameParts['last_name'],
                'email' => $socialUser->getEmail(),
                'phone' => null,
                'type' => $userType,
                'profile_photo_path' => $socialUser->getAvatar(),
            ]);

            if ($userType === UserType::Driver) {
                Driver::create([
                    'user_id' => $user->id,
                    'status' => DriverStatus::PendingReview,
                ]);
            }

            $user->socialAccounts()->create([
                'provider' => $provider->value,
                'provider_id' => $socialUser->getId(),
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken,
            ]);

            AuditLog::record($user, 'registered_via_social', null, [], ['provider' => $provider->value]);

            return $this->issueToken($user, $userType, true);
        });
    }

    private function issueToken(User $user, UserType $userType, bool $isNewUser): array
    {
        $token = $user->createToken(
            $userType->value.'-social-auth',
            [$userType->value],
        )->plainTextToken;

        return [
            'success' => true,
            'user' => $user,
            'token' => $token,
            'is_new_user' => $isNewUser,
        ];
    }

    private function parseName(?string $fullName): array
    {
        if (! $fullName) {
            return ['first_name' => 'User', 'last_name' => ''];
        }

        $parts = explode(' ', trim($fullName), 2);

        return [
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
        ];
    }
}
