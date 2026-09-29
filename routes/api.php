<?php

use App\Http\Controllers\Api\V1\Admin\AdminCityController;
use App\Http\Controllers\Api\V1\Admin\AdminCityVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\AdminVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\DriverManagementController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CityVehicleClassController;
use App\Http\Controllers\Api\V1\Driver\OnboardingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Passenger\ProfileController;
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
Route::get('/cities/{city}/vehicle-classes', [CityVehicleClassController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Auth — Passenger & Driver (email/password)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/social-login', [SocialAuthController::class, 'login']);

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

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
    });
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
});
