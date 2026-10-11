<?php

namespace App\Providers;

use App\Contracts\KycGateway;
use App\Contracts\MapsGateway;
use App\Contracts\PaymentGateway;
use App\Contracts\PlaceSearchGateway;
use App\Contracts\PushNotificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\WalletGateway;
use App\Gateways\PaystackWalletGateway;
use App\Models\PersonalAccessToken;
use App\Services\FailoverPlaceSearchGateway;
use App\Services\FakeKycGateway;
use App\Services\FakePaymentGateway;
use App\Services\FirebasePushGateway;
use App\Services\GeoapifyGateway;
use App\Services\HaversineMapsGateway;
use App\Services\LocationIqGateway;
use App\Services\LogPushGateway;
use App\Services\LogSmsGateway;
use App\Services\MapboxGateway;
use App\Services\PaystackPaymentGateway;
use App\Services\PhotonGateway;
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
        $this->registerWalletGateway();
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Event::listen(SocialiteWasCalled::class, AppleExtendSocialite::class);
    }

    private function registerMapsGateway(): void
    {
        $this->app->bind(PlaceSearchGateway::class, function () {
            return $this->createPlaceSearchGateway();
        });

        $this->app->bind(MapsGateway::class, function () {
            $accessToken = config('services.mapbox.access_token');

            if ($accessToken) {
                return new MapboxGateway($accessToken);
            }

            return new HaversineMapsGateway;
        });
    }

    private function createPlaceSearchGateway(): PlaceSearchGateway
    {
        $driversConfig = config('services.places.drivers', 'mapbox,geoapify,locationiq,photon');
        $driverList = array_filter(array_map('trim', explode(',', (string) $driversConfig)));
        $country = config('services.places.country', 'ng');

        $drivers = [];
        foreach ($driverList as $driverName) {
            $gateway = match (strtolower($driverName)) {
                'mapbox' => config('services.mapbox.access_token')
                    ? new MapboxGateway(config('services.mapbox.access_token'))
                    : null,
                'geoapify' => config('services.geoapify.api_key')
                    ? new GeoapifyGateway(config('services.geoapify.api_key'), $country)
                    : null,
                'locationiq' => config('services.locationiq.api_key')
                    ? new LocationIqGateway(config('services.locationiq.api_key'), $country)
                    : null,
                'photon' => new PhotonGateway,
                default => null,
            };

            if ($gateway !== null) {
                $drivers[] = $gateway;
            }
        }

        if (empty($drivers)) {
            return new HaversineMapsGateway;
        }

        if (count($drivers) === 1) {
            return $drivers[0];
        }

        return new FailoverPlaceSearchGateway($drivers);
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

    private function registerWalletGateway(): void
    {
        $this->app->bind(WalletGateway::class, function () {
            $secretKey = config('wallet.paystack.secret_key');

            if (! $secretKey) {
                throw new \RuntimeException('Paystack secret key is not configured. Set PAYSTACK_SECRET_KEY in your environment.');
            }

            return new PaystackWalletGateway($secretKey);
        });
    }

    private function registerPaymentGateway(): void
    {
        $this->app->bind(PaymentGateway::class, function () {
            if (config('services.paystack.fake', false)) {
                return new FakePaymentGateway;
            }

            $secretKey = config('services.paystack.secret_key');

            if ($secretKey) {
                return new PaystackPaymentGateway($secretKey);
            }

            if (! $this->app->environment('local', 'testing', 'staging')) {
                throw new \RuntimeException('Paystack secret key is not configured. Set PAYSTACK_SECRET_KEY in your environment.');
            }

            return new FakePaymentGateway;
        });
    }
}
