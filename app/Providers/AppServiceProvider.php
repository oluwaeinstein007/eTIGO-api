<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Services\LogSmsGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, LogSmsGateway::class);
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, AppleExtendSocialite::class);
    }
}
