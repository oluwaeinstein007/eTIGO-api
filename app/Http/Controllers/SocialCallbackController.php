<?php

namespace App\Http\Controllers;

use App\Enums\SocialProvider;
use App\Models\SocialAuthCode;
use App\Services\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialCallbackController extends Controller
{
    public function __construct(
        private SocialAuthService $socialAuthService,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        if (! SocialProvider::tryFrom($provider)) {
            abort(404, 'Unknown provider.');
        }

        $oauthState = json_decode(base64_decode($request->input('state', '')), true);
        $userType = $oauthState['type'] ?? 'passenger';
        $clientState = $oauthState['state'] ?? '';

        $socialProvider = SocialProvider::from($provider);
        $socialUser = $this->socialAuthService->handleCallback($socialProvider, $request->input('code', ''));

        if (! $socialUser) {
            $scheme = $userType === 'driver' ? 'etigo-driver' : 'etigo-passenger';

            return redirect()->away("{$scheme}:///auth/callback?error=social_auth_failed&state={$clientState}");
        }

        $code = Str::random(64);

        SocialAuthCode::create([
            'code' => $code,
            'provider' => $provider,
            'provider_id' => $socialUser['id'],
            'email' => $socialUser['email'],
            'first_name' => $socialUser['first_name'],
            'last_name' => $socialUser['last_name'],
            'user_type' => $userType,
            'state' => $clientState,
            'expires_at' => now()->addMinutes(5),
        ]);

        $scheme = $userType === 'driver' ? 'etigo-driver' : 'etigo-passenger';

        return redirect()->away(
            "{$scheme}:///auth/callback?code={$code}&state={$clientState}",
        );
    }
}
