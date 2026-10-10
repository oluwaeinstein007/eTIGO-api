<?php

namespace App\Providers;

use App\Contracts\FlutterwaveWalletGateway;
use App\Contracts\KycGateway;
use App\Contracts\MapsGateway;
use App\Contracts\PaymentGateway;
use App\Contracts\PushNotificationGateway;
use App\Contracts\SmsGateway;
use App\Gateways\FlutterwaveWalletPaymentGateway;
use App\Models\PersonalAccessToken;
use App\Services\FakeKycGateway;
use App\Services\FakePaymentGateway;
use App\Services\FirebasePushGateway;
use App\Services\FlutterwavePaymentGateway;
use App\Services\HaversineMapsGateway;
use App\Services\LogPushGateway;
use App\Services\LogSmsGateway;
use App\Services\MapboxGateway;
use App\Services\QoreIdKycGateway;
use App\Services\WhatsAppGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;
use Laravel\Sanctum\Sanctum;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        $this->registerKycGateway();
        $this->registerFlutterwaveWalletGateway();
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Event::listen(SocialiteWasCalled::class, AppleExtendSocialite::class);
    }

    private function registerMapsGateway(): void
    {
        $this->app->bind(MapsGateway::class, function () {
            $accessToken = config('services.mapbox.access_token');

            if ($accessToken) {
                return new MapboxGateway($accessToken);
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

    private function registerKycGateway(): void
    {
        $this->app->bind(KycGateway::class, function () {
            if (config('services.qoreid.skip_verification', false)) {
                return new FakeKycGateway;
            }

            $clientId = config('services.qoreid.client_id', '');
            $secretKey = config('services.qoreid.secret_key', '');

            if ($clientId && $secretKey) {
                return new QoreIdKycGateway($clientId, $secretKey);
            }

            if (! $this->app->environment('local', 'testing')) {
                throw new \RuntimeException('QoreID credentials are not configured. Set QOREID_CLIENT_ID and QOREID_SECRET_KEY in your environment.');
            }

            return new FakeKycGateway;
        });
    }

    private function registerFlutterwaveWalletGateway(): void
    {
        $this->app->bind(FlutterwaveWalletGateway::class, function () {
            $secretKey = config('wallet.flutterwave.secret_key');

            if (! $secretKey) {
                throw new \RuntimeException('Flutterwave secret key is not configured. Set FLUTTERWAVE_SECRET_KEY in your environment.');
            }

            return new FlutterwaveWalletPaymentGateway($secretKey);
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
