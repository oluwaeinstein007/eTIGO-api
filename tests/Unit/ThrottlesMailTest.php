<?php

use App\Notifications\Concerns\ThrottlesMail;
use App\Notifications\OperationsAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

it('holds queued email to the provider rate and keeps retrying for a day', function () {
    $notification = new OperationsAlertNotification('Fleet', 'Headline', 'Body', [], '/admin', 'Open');
    $admin = new stdClass;

    expect($notification->middleware($admin, 'mail'))->toHaveCount(1)
        ->and($notification->middleware($admin, 'mail')[0])->toBeInstanceOf(RateLimited::class)
        ->and($notification->middleware($admin, 'database'))->toBe([])
        ->and($notification->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(23)->getTimestamp());

    $limit = RateLimiter::limiter('mail')(new stdClass);
    expect($limit->maxAttempts)->toBe(8)->and($limit->decaySeconds)->toBe(1);
});

it('throttles every queued notification that sends email', function () {
    foreach (glob(app_path('Notifications/*.php')) as $file) {
        $class = 'App\\Notifications\\'.basename($file, '.php');

        if (method_exists($class, 'toMail') && is_subclass_of($class, ShouldQueue::class)) {
            expect(in_array(ThrottlesMail::class, class_uses_recursive($class), true))->toBeTrue("{$class} sends queued email without ThrottlesMail");
        }
    }
});
