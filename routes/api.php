<?php

use App\Http\Controllers\Api\V1\Admin\AdminCityController;
use App\Http\Controllers\Api\V1\Admin\AdminCityVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\AdminManagementController;
use App\Http\Controllers\Api\V1\Admin\AdminPassengerController;
use App\Http\Controllers\Api\V1\Admin\AdminPricingController;
use App\Http\Controllers\Api\V1\Admin\AdminRideController;
use App\Http\Controllers\Api\V1\Admin\AdminSurgeRuleController;
use App\Http\Controllers\Api\V1\Admin\AdminVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\DriverManagementController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\AdminInvitationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CityVehicleClassController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\Driver\DriverController;
use App\Http\Controllers\Api\V1\Driver\DriverLocationController;
use App\Http\Controllers\Api\V1\Driver\KycController;
use App\Http\Controllers\Api\V1\Driver\OnboardingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Passenger\ProfileController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\RideController;
use App\Http\Controllers\Api\V1\RideEstimateController;
use App\Http\Controllers\Api\V1\RideLocationController;
use App\Http\Controllers\Api\V1\RideShareController;
use App\Http\Controllers\Api\V1\Webhook\KycWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/
Route::get('/health', HealthController::class);

/*
|--------------------------------------------------------------------------
| Public — Cities & Vehicle Classes
|--------------------------------------------------------------------------
*/
Route::get('/cities', [CityController::class, 'index']);
Route::get('/cities/detect', [CityController::class, 'detect']);
Route::get('/cities/{city}/vehicle-classes', [CityVehicleClassController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Payment Webhooks (unauthenticated — verified by signature)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/flutterwave', [PaymentWebhookController::class, 'handleFlutterwave']);
Route::post('/webhooks/qoreid', [KycWebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Ride Share (public — no auth, validated by share token)
|--------------------------------------------------------------------------
*/
Route::get('/rides/{ride}/share/{token}', [RideShareController::class, 'show'])
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Auth — Passenger & Driver (phone OTP + social OAuth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/otp/send', [AuthController::class, 'sendOtp']);
        Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
        Route::post('/register/complete', [AuthController::class, 'completeRegistration']);
        Route::post('/social/redirect', [AuthController::class, 'socialRedirect']);
        Route::post('/social', [AuthController::class, 'socialAuth']);
        Route::post('/social/exchange', [AuthController::class, 'socialExchange']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Auth — Admin (email/password)
|--------------------------------------------------------------------------
*/
Route::prefix('admin/auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::get('/invite/verify/{token}', [AdminInvitationController::class, 'verifyToken']);
    Route::post('/invite/accept', [AdminInvitationController::class, 'accept']);

    Route::post('/forgot-password', [AdminInvitationController::class, 'forgotPassword']);
    Route::post('/reset-password', [AdminInvitationController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Ride Estimates (Authenticated — any user type)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/rides/estimate', RideEstimateController::class);

    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payments/initialize', [PaymentMethodController::class, 'initialize']);
    Route::get('/payments/{transactionId}/verify', [PaymentMethodController::class, 'verify']);
    Route::patch('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'setDefault']);
    Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Ride Routes (authenticated — any role, scoped inside controller)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('rides')->group(function () {
    Route::post('/', [RideController::class, 'store'])->middleware('user.type:passenger');
    Route::get('/', [RideController::class, 'index']);
    Route::get('/{ride}', [RideController::class, 'show']);
    Route::post('/{ride}/cancel', [RideController::class, 'cancel']);
    Route::get('/{ride}/location', [RideLocationController::class, 'show']);
    Route::post('/{ride}/driver-arrived', [RideController::class, 'driverArrived'])->middleware('user.type:driver');
    Route::post('/{ride}/verify-pin', [RideController::class, 'verifyPin'])->middleware('user.type:driver');
    Route::post('/{ride}/complete', [RideController::class, 'complete'])->middleware('user.type:driver');
    Route::post('/{ride}/accept', [RideController::class, 'accept'])->middleware('user.type:driver');
    Route::post('/{ride}/reject', [RideController::class, 'reject'])->middleware('user.type:driver');
});

/*
|--------------------------------------------------------------------------
| Passenger Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:passenger'])->prefix('passenger')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);
});

/*
|--------------------------------------------------------------------------
| Driver Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:driver'])->prefix('driver')->group(function () {
    Route::post('/toggle-online', [DriverController::class, 'toggleOnline']);
    Route::post('/location', [DriverLocationController::class, 'update']);

    Route::get('/onboarding/status', [OnboardingController::class, 'status']);
    Route::post('/onboarding/submit', [OnboardingController::class, 'submitForReview']);
    Route::put('/profile', [OnboardingController::class, 'updateProfile']);
    Route::post('/profile/photo', [OnboardingController::class, 'updateProfilePhoto']);
    Route::delete('/profile/photo', [OnboardingController::class, 'deleteProfilePhoto']);

    Route::post('/documents', [OnboardingController::class, 'uploadDocument']);
    Route::get('/documents', [OnboardingController::class, 'documents']);

    Route::post('/vehicle', [OnboardingController::class, 'storeVehicle']);
    Route::put('/vehicle', [OnboardingController::class, 'updateVehicle']);
    Route::get('/vehicle', [OnboardingController::class, 'vehicle']);

    Route::prefix('kyc')->group(function () {
        Route::get('/status', [KycController::class, 'status']);
        Route::get('/verifications', [KycController::class, 'verifications']);
        Route::post('/verify-nin', [KycController::class, 'verifyNin']);
        Route::post('/verify-license', [KycController::class, 'verifyDriversLicense']);
        Route::post('/verify-vehicle', [KycController::class, 'verifyVehiclePlate']);
        Route::post('/liveness-session', [KycController::class, 'createLivenessSession']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:admin'])->prefix('admin')->group(function () {
    Route::prefix('drivers')->group(function () {
        Route::get('/', [DriverManagementController::class, 'index']);
        Route::get('/pending', [DriverManagementController::class, 'pendingReview']);
        Route::get('/{driver}', [DriverManagementController::class, 'show']);
        Route::post('/{driver}/review', [DriverManagementController::class, 'review']);
        Route::post('/{driver}/documents/{document}/review', [DriverManagementController::class, 'reviewDocument']);
        Route::post('/{driver}/suspend', [DriverManagementController::class, 'suspend']);
        Route::post('/{driver}/reactivate', [DriverManagementController::class, 'reactivate']);
        Route::patch('/{driver}/vehicle/fleet', [DriverManagementController::class, 'toggleFleetVehicle']);
    });

    Route::prefix('cities')->group(function () {
        Route::get('/', [AdminCityController::class, 'index']);
        Route::post('/', [AdminCityController::class, 'store']);
        Route::get('/{city}', [AdminCityController::class, 'show']);
        Route::put('/{city}', [AdminCityController::class, 'update']);
        Route::patch('/{city}/status', [AdminCityController::class, 'toggleStatus']);
        Route::put('/{city}/vehicle-classes', [AdminCityVehicleClassController::class, 'update']);
    });

    Route::prefix('vehicle-classes')->group(function () {
        Route::get('/', [AdminVehicleClassController::class, 'index']);
        Route::post('/', [AdminVehicleClassController::class, 'store']);
        Route::get('/{vehicleClass}', [AdminVehicleClassController::class, 'show']);
        Route::put('/{vehicleClass}', [AdminVehicleClassController::class, 'update']);
    });

    Route::prefix('pricing')->group(function () {
        Route::get('/', [AdminPricingController::class, 'index']);
        Route::post('/', [AdminPricingController::class, 'store']);
        Route::get('/current', [AdminPricingController::class, 'current']);
        Route::get('/{pricingConfig}', [AdminPricingController::class, 'show']);
    });

    Route::prefix('surge-rules')->group(function () {
        Route::get('/', [AdminSurgeRuleController::class, 'index']);
        Route::post('/', [AdminSurgeRuleController::class, 'store']);
        Route::get('/current-multiplier', [AdminSurgeRuleController::class, 'currentMultiplier']);
        Route::get('/{surgeRule}', [AdminSurgeRuleController::class, 'show']);
        Route::put('/{surgeRule}', [AdminSurgeRuleController::class, 'update']);
        Route::patch('/{surgeRule}/status', [AdminSurgeRuleController::class, 'toggleStatus']);
    });

    Route::prefix('rides')->group(function () {
        Route::post('/{ride}/assign', [AdminRideController::class, 'assign']);
    });

    Route::prefix('passengers')->group(function () {
        Route::get('/', [AdminPassengerController::class, 'index']);
        Route::get('/{passenger}', [AdminPassengerController::class, 'show']);
        Route::post('/{passenger}/suspend', [AdminPassengerController::class, 'suspend']);
        Route::post('/{passenger}/reactivate', [AdminPassengerController::class, 'reactivate']);
    });

    Route::middleware('admin.role:super_admin')->prefix('admins')->group(function () {
        Route::get('/', [AdminManagementController::class, 'index']);
        Route::get('/invitations', [AdminManagementController::class, 'invitations']);
        Route::post('/invite', [AdminManagementController::class, 'invite']);
        Route::post('/invitations/{invitation}/resend', [AdminManagementController::class, 'resendInvite']);
        Route::delete('/invitations/{invitation}', [AdminManagementController::class, 'revokeInvite']);
        Route::get('/{admin}', [AdminManagementController::class, 'show']);
        Route::put('/{admin}', [AdminManagementController::class, 'update']);
        Route::post('/{admin}/deactivate', [AdminManagementController::class, 'deactivate']);
        Route::post('/{admin}/reactivate', [AdminManagementController::class, 'reactivate']);
        Route::delete('/{admin}', [AdminManagementController::class, 'destroy']);
    });
});
