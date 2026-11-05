<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminLoginCodeNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Admin two-step sign-in. A correct password starts a challenge: a 6-digit
 * code is emailed, and the dashboard trades the challenge token and code for
 * a session token. Only hashes of the token and code are kept (in the cache,
 * for the code's lifetime).
 */
class AdminLoginChallengeService
{
    private const STATIC_CODE = '123456';

    public function enabled(): bool
    {
        return (bool) config('auth.admin_two_factor.enabled');
    }

    /**
     * @return array{challenge_token: string, expires_at: Carbon}
     */
    public function start(User $user): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes($this->expiryMinutes());

        Cache::put($this->key($token), [
            'user_id' => $user->id,
            'code' => null,
            'expires_at' => $expiresAt->getTimestamp(),
            'sent_at' => null,
            'resends' => 0,
        ], $expiresAt);

        $this->sendCode($token, $user);

        return ['challenge_token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @return array{user: ?User, error: ?string, status: int}
     */
    public function verify(string $token, string $code): array
    {
        $challenge = Cache::get($this->key($token));

        if (! $challenge) {
            return ['user' => null, 'error' => 'This sign-in code has expired. Sign in again.', 'status' => 422];
        }

        // add() first: some cache stores cannot increment a missing key.
        Cache::add($this->key($token).':attempts', 0, Carbon::createFromTimestamp($challenge['expires_at']));
        $attempts = (int) Cache::increment($this->key($token).':attempts');

        if ($attempts > (int) config('auth.admin_two_factor.max_attempts')) {
            Cache::forget($this->key($token));

            return ['user' => null, 'error' => 'Too many wrong codes. Sign in again.', 'status' => 429];
        }

        if (! hash_equals($challenge['code'], hash('sha256', $code))) {
            return ['user' => null, 'error' => 'That code is not right. Check the latest email and try again.', 'status' => 422];
        }

        Cache::forget($this->key($token));
        Cache::forget($this->key($token).':attempts');

        $user = User::find($challenge['user_id']);

        return $user
            ? ['user' => $user, 'error' => null, 'status' => 200]
            : ['user' => null, 'error' => 'This sign-in code has expired. Sign in again.', 'status' => 422];
    }

    /**
     * @return array{sent: bool, error: ?string, status: int, retry_after: ?int}
     */
    public function resend(string $token): array
    {
        $challenge = Cache::get($this->key($token));
        $user = $challenge ? User::find($challenge['user_id']) : null;

        if (! $challenge || ! $user) {
            return ['sent' => false, 'error' => 'This sign-in code has expired. Sign in again.', 'status' => 422, 'retry_after' => null];
        }

        $wait = $challenge['sent_at'] + (int) config('auth.admin_two_factor.resend_cooldown_seconds') - now()->getTimestamp();

        if ($wait > 0) {
            return ['sent' => false, 'error' => "Wait {$wait} seconds before asking for another code.", 'status' => 429, 'retry_after' => $wait];
        }

        if ($challenge['resends'] >= (int) config('auth.admin_two_factor.max_resends')) {
            return ['sent' => false, 'error' => 'No more codes can be sent for this sign-in. Sign in again.', 'status' => 429, 'retry_after' => null];
        }

        $this->sendCode($token, $user, resend: true);

        return ['sent' => true, 'error' => null, 'status' => 200, 'retry_after' => null];
    }

    /**
     * a***n@etigo.ng: enough for the admin to recognise the inbox.
     */
    public function emailHint(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $masked = mb_strlen($name) <= 2
            ? mb_substr($name, 0, 1).'*'
            : mb_substr($name, 0, 1).str_repeat('*', 3).mb_substr($name, -1);

        return $masked.'@'.$domain;
    }

    private function sendCode(string $token, User $user, bool $resend = false): void
    {
        $challenge = Cache::get($this->key($token));
        $code = app()->isProduction()
            ? str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT)
            : self::STATIC_CODE;

        $challenge['code'] = hash('sha256', $code);
        $challenge['sent_at'] = now()->getTimestamp();
        $challenge['resends'] += $resend ? 1 : 0;

        Cache::put($this->key($token), $challenge, Carbon::createFromTimestamp($challenge['expires_at']));

        $user->notify(new AdminLoginCodeNotification($code, $this->expiryMinutes()));
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth.admin_two_factor.code_expiry_minutes');
    }

    private function key(string $token): string
    {
        return 'admin_login_challenge:'.hash('sha256', $token);
    }
}
