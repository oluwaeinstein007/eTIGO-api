<?php

namespace App\Providers;

use App\Contracts\MapsGateway;
use App\Contracts\PaymentGateway;
use App\Contracts\PushNotificationGateway;
use App\Contracts\SmsGateway;
use App\Services\FakePaymentGateway;
use App\Services\FirebasePushGateway;
use App\Services\FlutterwavePaymentGateway;
use App\Services\GoogleMapsGateway;
use App\Services\HaversineMapsGateway;
use App\Services\LogPushGateway;
use App\Services\LogSmsGateway;
use App\Services\WhatsAppGateway;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, function () {
            if (config('services.whatsapp.phone_number_id') && config('services.whatsapp.access_token')) {
                return new WhatsAppGateway;
            }

            return new LogSmsGateway;
        });

        $this->registerMapsGateway();
        $this->registerPushNotificationGateway();
        $this->registerPaymentGateway();
    }

    public function boot(): void
    {
        //
    }

    private function registerMapsGateway(): void
    {
        $this->app->bind(MapsGateway::class, function () {
            $apiKey = config('services.google_maps.api_key');

            if ($apiKey) {
                return new GoogleMapsGateway($apiKey);
            }

            return new HaversineMapsGateway;
        });
    }

    private function registerPushNotificationGateway(): void
    {
        $this->app->bind(PushNotificationGateway::class, function () {
            $credentialsPath = config('services.firebase.credentials_path');

            if ($credentialsPath && file_exists($credentialsPath)) {
                $factory = (new Factory)->withServiceAccount($credentialsPath);
                $messaging = $factory->createMessaging();

                return new FirebasePushGateway($messaging);
            }

            return new LogPushGateway;
        });
    }

    private function registerPaymentGateway(): void
    {
        $this->app->bind(PaymentGateway::class, function () {
            $secretKey = config('services.flutterwave.secret_key');

            if ($secretKey) {
                return new FlutterwavePaymentGateway($secretKey);
            }

            if (! $this->app->environment('local', 'testing')) {
                throw new \RuntimeException('Flutterwave secret key is not configured. Set FLUTTERWAVE_SECRET_KEY in your environment.');
            }

            return new FakePaymentGateway;
        });
    }
}
