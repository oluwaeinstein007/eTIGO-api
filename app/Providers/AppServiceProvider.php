<?php

namespace App\Providers;

use App\Contracts\MapsGateway;
use App\Contracts\SmsGateway;
use App\Services\HaversineMapsGateway;
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
        $this->app->bind(MapsGateway::class, HaversineMapsGateway::class);
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, AppleExtendSocialite::class);
    }
}
