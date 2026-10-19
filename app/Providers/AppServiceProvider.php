<?php

namespace App\Providers;

use App\Contracts\KycGateway;
use App\Contracts\MapsGateway;
use App\Contracts\PaymentGateway;
use App\Contracts\PlaceSearchGateway;
use App\Contracts\PushNotificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\WalletGateway;
use App\Events\TierUpgraded;
use App\Gateways\PaystackWalletGateway;
use App\Listeners\SendTierUpgradedNotification;
use App\Models\PersonalAccessToken;
use App\Services\FailoverPlaceSearchGateway;
use App\Services\FakeKycGateway;
use App\Services\FakePaymentGateway;
use App\Services\FallbackPaymentGateway;
use App\Services\FirebasePushGateway;
use App\Services\FlutterwavePaymentGateway;
use App\Services\GeoapifyGateway;
use App\Services\GooglePlacesGateway;
use App\Services\HaversineMapsGateway;
use App\Services\LocationIqGateway;
use App\Services\LogPushGateway;
use App\Services\LogSmsGateway;
use App\Services\MapboxGateway;
use App\Services\PaystackPaymentGateway;
use App\Services\PhotonGateway;
use App\Services\QoreIdKycGateway;
use App\Services\SendchampGateway;
use App\Support\CloudinaryAdapter;
use Cloudinary\Cloudinary;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\Filesystem;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, function () {
            if (config('services.sendchamp.access_key')) {
                return new SendchampGateway;
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
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && ! $token->hasOutlivedSession(),
        );
        Event::listen(SocialiteWasCalled::class, AppleExtendSocialite::class);
        Event::listen(TierUpgraded::class, SendTierUpgradedNotification::class);

        $this->registerCloudinaryDriver();
    }

    private function registerCloudinaryDriver(): void
    {
        Storage::extend('cloudinary', function ($app, $config) {
            $cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $config['cloud_name'],
                    'api_key' => $config['api_key'],
                    'api_secret' => $config['api_secret'],
                ],
                'url' => [
                    'secure' => true,
                ],
            ]);

            $adapter = new CloudinaryAdapter($cloudinary);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });
    }

    private function registerMapsGateway(): void
    {
        $this->app->bind(PlaceSearchGateway::class, function () {
            return $this->createPlaceSearchGateway();
        });

        $this->app->bind(MapsGateway::class, function () {
            $googleApiKey = config('services.google_maps.api_key')
                ?: config('services.google.places_api_key');

            if (! empty($googleApiKey)) {
                return new GooglePlacesGateway(
                    $googleApiKey,
                    config('services.places.country', 'ng'),
                );
            }

            $accessToken = config('services.mapbox.access_token');

            if ($accessToken) {
                return new MapboxGateway($accessToken);
            }

            return new HaversineMapsGateway;
        });
    }

    private function createPlaceSearchGateway(): PlaceSearchGateway
    {
        $driversConfig = config('services.places.drivers', 'google,mapbox,geoapify,locationiq,photon');
        $driverList = array_filter(array_map('trim', explode(',', (string) $driversConfig)));
        $country = config('services.places.country', 'ng');

        $drivers = [];
        foreach ($driverList as $driverName) {
            $gateway = match (strtolower($driverName)) {
                'google', 'google_places', 'google_maps' => ($apiKey = config('services.google_maps.api_key') ?: config('services.google.places_api_key'))
                    ? new GooglePlacesGateway($apiKey, $country)
                    : null,
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
            $credentials = $this->resolveFirebaseCredentials();

            if ($credentials) {
                $factory = (new Factory)->withServiceAccount($credentials);
                $messaging = $factory->createMessaging();

                return new FirebasePushGateway($messaging);
            }

            return new LogPushGateway;
        });
    }

    /**
     * @return string|array<string, mixed>|null
     */
    private function resolveFirebaseCredentials(): string|array|null
    {
        $raw = config('services.firebase.credentials_path');

        if (empty($raw)) {
            $default = storage_path('app/firebase/etogo-b08c2-firebase-adminsdk-fbsvc-8dc10c96a6.json');
            if (file_exists($default)) {
                return $default;
            }

            $genericDefault = storage_path('app/firebase/firebase-credentials.json');
            if (file_exists($genericDefault)) {
                return $genericDefault;
            }

            return null;
        }

        // Support passing the raw JSON string in environment variable
        if (str_starts_with(trim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Direct path (absolute or valid as-is)
        if (file_exists($raw)) {
            return $raw;
        }

        // Relative to Laravel project root
        if (file_exists(base_path($raw))) {
            return base_path($raw);
        }

        // Relative to storage path
        if (file_exists(storage_path($raw))) {
            return storage_path($raw);
        }

        return null;
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

            $paystackKey = config('services.paystack.secret_key');
            $flutterwaveKey = config('services.flutterwave.secret_key');

            if ($paystackKey && $flutterwaveKey) {
                return new FallbackPaymentGateway(
                    new PaystackPaymentGateway($paystackKey),
                    new FlutterwavePaymentGateway(
                        $flutterwaveKey,
                        config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'),
                    ),
                );
            }

            if ($paystackKey) {
                return new PaystackPaymentGateway($paystackKey);
            }

            if ($flutterwaveKey) {
                return new FlutterwavePaymentGateway(
                    $flutterwaveKey,
                    config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'),
                );
            }

            if (! $this->app->environment('local', 'testing', 'staging')) {
                throw new \RuntimeException('No payment gateway configured. Set PAYSTACK_SECRET_KEY or FLUTTERWAVE_SECRET_KEY in your environment.');
            }

            return new FakePaymentGateway;
        });
    }
}
