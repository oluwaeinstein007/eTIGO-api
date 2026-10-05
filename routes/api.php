<?php

use App\Http\Controllers\Api\V1\Admin\AdminCityController;
use App\Http\Controllers\Api\V1\Admin\AdminCityVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\AdminManagementController;
use App\Http\Controllers\Api\V1\Admin\AdminPricingController;
use App\Http\Controllers\Api\V1\Admin\AdminSurgeRuleController;
use App\Http\Controllers\Api\V1\Admin\AdminVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\DriverManagementController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\AdminInvitationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CityVehicleClassController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\Driver\OnboardingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Passenger\ProfileController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\RideEstimateController;
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
        Route::post('/social', [AuthController::class, 'socialAuth']);
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
| Passenger Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:passenger'])->prefix('passenger')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
});

/*
|--------------------------------------------------------------------------
| Driver Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:driver'])->prefix('driver')->group(function () {
    Route::get('/onboarding/status', [OnboardingController::class, 'status']);
    Route::put('/profile', [OnboardingController::class, 'updateProfile']);

    Route::post('/documents', [OnboardingController::class, 'uploadDocument']);
    Route::get('/documents', [OnboardingController::class, 'documents']);

    Route::post('/vehicle', [OnboardingController::class, 'storeVehicle']);
    Route::put('/vehicle', [OnboardingController::class, 'updateVehicle']);
    Route::get('/vehicle', [OnboardingController::class, 'vehicle']);
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
        Route::post('/{driver}/suspend', [DriverManagementController::class, 'suspend']);
        Route::post('/{driver}/reactivate', [DriverManagementController::class, 'reactivate']);
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
    });
});
