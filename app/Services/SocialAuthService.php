<?php

namespace App\Services;

use App\Enums\SocialProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialAuthService
{
    public function verifyToken(SocialProvider $provider, string $token): ?array
    {
        return match ($provider) {
            SocialProvider::Google => $this->verifyGoogleToken($token),
            SocialProvider::Apple => $this->verifyAppleToken($token),
            SocialProvider::Facebook => $this->verifyFacebookToken($token),
        };
    }

    private function verifyGoogleToken(string $token): ?array
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $token,
        ]);

        if ($response->failed()) {
            Log::warning('Google token verification failed', ['status' => $response->status()]);

            return null;
        }

        $data = $response->json();

        $clientId = config('services.google.client_id');
        if ($clientId && ($data['aud'] ?? '') !== $clientId) {
            Log::warning('Google token audience mismatch');

            return null;
        }

        return [
            'id' => $data['sub'],
            'email' => $data['email'] ?? null,
            'first_name' => $data['given_name'] ?? null,
            'last_name' => $data['family_name'] ?? null,
        ];
    }

    private function verifyAppleToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! $payload || empty($payload['sub'])) {
            return null;
        }

        return [
            'id' => $payload['sub'],
            'email' => $payload['email'] ?? null,
            'first_name' => null,
            'last_name' => null,
        ];
    }

    private function verifyFacebookToken(string $token): ?array
    {
        $appId = config('services.facebook.app_id');
        $appSecret = config('services.facebook.app_secret');

        $debugResponse = Http::get('https://graph.facebook.com/debug_token', [
            'input_token' => $token,
            'access_token' => $appId.'|'.$appSecret,
        ]);

        if ($debugResponse->failed()) {
            Log::warning('Facebook token debug failed');

            return null;
        }

        $debugData = $debugResponse->json('data');
        if (! ($debugData['is_valid'] ?? false)) {
            return null;
        }

        $profileResponse = Http::get('https://graph.facebook.com/me', [
            'fields' => 'id,first_name,last_name,email',
            'access_token' => $token,
        ]);

        if ($profileResponse->failed()) {
            return null;
        }

        $profile = $profileResponse->json();

        return [
            'id' => $profile['id'],
            'email' => $profile['email'] ?? null,
            'first_name' => $profile['first_name'] ?? null,
            'last_name' => $profile['last_name'] ?? null,
        ];
    }
}
