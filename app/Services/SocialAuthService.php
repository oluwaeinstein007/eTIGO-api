<?php

namespace App\Services;

use App\Enums\SocialProvider;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function getRedirectUrl(SocialProvider $provider): string
    {
        return Socialite::driver($provider->value)
            ->stateless()
            ->redirect()
            ->getTargetUrl();
    }

    public function verifyToken(SocialProvider $provider, string $token): ?array
    {
        try {
            $socialUser = Socialite::driver($provider->value)
                ->stateless()
                ->userFromToken($token);
        } catch (\Exception $e) {
            Log::warning("Social token verification failed for {$provider->value}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $nameParts = $this->parseName($socialUser->getName());

        return [
            'id' => $socialUser->getId(),
            'email' => $socialUser->getEmail(),
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
        ];
    }

    public function handleCallback(SocialProvider $provider, string $code): ?array
    {
        try {
            $socialUser = Socialite::driver($provider->value)
                ->stateless()
                ->user();
        } catch (\Exception $e) {
            Log::warning("Social callback failed for {$provider->value}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $nameParts = $this->parseName($socialUser->getName());

        return [
            'id' => $socialUser->getId(),
            'email' => $socialUser->getEmail(),
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'token' => $socialUser->token,
        ];
    }

    private function parseName(?string $fullName): array
    {
        if (! $fullName) {
            return ['first_name' => null, 'last_name' => null];
        }

        $parts = explode(' ', trim($fullName), 2);

        return [
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
        ];
    }
}
