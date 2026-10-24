<?php

namespace App\Notifications\Concerns;

use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Keeps queued email under the provider's rate limit (Resend: 10 a second)
 * when many go out at once, such as an alert to every admin. The "mail"
 * limiter (AppServiceProvider) holds a job back instead of letting the
 * provider refuse it, and a refused or failed send is retried for an hour.
 */
trait ThrottlesMail
{
    /**
     * @return list<object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RateLimited('mail')] : [];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
