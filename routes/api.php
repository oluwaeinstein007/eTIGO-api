<?php

use App\Http\Controllers\Api\V1\Admin\AdminAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\AdminPromoController;
use App\Http\Controllers\Api\V1\Admin\AdminCityController;
use App\Http\Controllers\Api\V1\Admin\AdminCityVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\AdminCommissionController;
use App\Http\Controllers\Api\V1\Admin\AdminDisputeController;
use App\Http\Controllers\Api\V1\Admin\AdminDriverLedgerController;
use App\Http\Controllers\Api\V1\Admin\AdminFleetAgreementController;
use App\Http\Controllers\Api\V1\Admin\AdminFleetVehicleController;
use App\Http\Controllers\Api\V1\Admin\AdminGamificationController;
use App\Http\Controllers\Api\V1\Admin\AdminLedgerExplorerController;
use App\Http\Controllers\Api\V1\Admin\AdminManagementController;
use App\Http\Controllers\Api\V1\Admin\AdminPassengerController;
use App\Http\Controllers\Api\V1\Admin\AdminPayoutController;
use App\Http\Controllers\Api\V1\Admin\AdminPricingController;
use App\Http\Controllers\Api\V1\Admin\AdminReconciliationController;
use App\Http\Controllers\Api\V1\Admin\AdminRefundController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminRideController;
use App\Http\Controllers\Api\V1\Admin\AdminSurgeRuleController;
use App\Http\Controllers\Api\V1\Admin\AdminVehicleClassController;
use App\Http\Controllers\Api\V1\Admin\AdminWalletController;
use App\Http\Controllers\Api\V1\Admin\AdminWalletSettingsController;
use App\Http\Controllers\Api\V1\Admin\DriverManagementController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\AdminInvitationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CityVehicleClassController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\Driver\BankAccountController;
use App\Http\Controllers\Api\V1\Driver\DriverActiveRideController;
use App\Http\Controllers\Api\V1\Driver\DriverController;
use App\Http\Controllers\Api\V1\Driver\DriverEarningsController;
use App\Http\Controllers\Api\V1\Driver\DriverLedgerController;
use App\Http\Controllers\Api\V1\Driver\DriverLocationController;
use App\Http\Controllers\Api\V1\Driver\DriverRemittanceController;
use App\Http\Controllers\Api\V1\Driver\DriverStatsController;
use App\Http\Controllers\Api\V1\Driver\KycController;
use App\Http\Controllers\Api\V1\Driver\NearbyRidesController;
use App\Http\Controllers\Api\V1\Driver\OnboardingController;
use App\Http\Controllers\Api\V1\Driver\PayoutController;
use App\Http\Controllers\Api\V1\GamificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Passenger\NearbyDriversController;
use App\Http\Controllers\Api\V1\Passenger\PassengerActiveRideController;
use App\Http\Controllers\Api\V1\Passenger\ProfileController;
use App\Http\Controllers\Api\V1\Passenger\WalletController;
use App\Http\Controllers\Api\V1\Passenger\WalletTopupController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PromoController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\RideCancellationReasonController;
use App\Http\Controllers\Api\V1\RideController;
use App\Http\Controllers\Api\V1\RideDisputeController;
use App\Http\Controllers\Api\V1\RideEstimateController;
use App\Http\Controllers\Api\V1\RideLocationController;
use App\Http\Controllers\Api\V1\RidePaymentController;
use App\Http\Controllers\Api\V1\RideRatingController;
use App\Http\Controllers\Api\V1\RideReceiptController;
use App\Http\Controllers\Api\V1\RideShareController;
use App\Http\Controllers\Api\V1\RideTipController;
use App\Http\Controllers\Api\V1\SosController;
use App\Http\Controllers\Api\V1\Admin\AdminOfflineFlagController;
use App\Http\Controllers\Api\V1\Admin\AdminSosController;
use App\Http\Controllers\Api\V1\Driver\DriverComplianceController;
use App\Http\Controllers\Api\V1\Driver\DriverFlagController;
use App\Http\Controllers\Api\V1\Driver\EvReservationController;
use App\Http\Controllers\Api\V1\EvStationController;
use App\Http\Controllers\Api\V1\Admin\AdminEvStationController;
use App\Http\Controllers\Api\V1\Webhook\PaystackWebhookController;
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
Route::get('/lookup/states', [LookupController::class, 'states']);
Route::get('/lookup/regions', [LookupController::class, 'regions']);
Route::get('/lookup/cancellation-reasons', [RideCancellationReasonController::class, 'index']);
Route::get('/cities', [CityController::class, 'index']);
Route::get('/cities/detect', [CityController::class, 'detect']);
Route::get('/cities/{city}/vehicle-classes', [CityVehicleClassController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Public — EV Charging Stations
|--------------------------------------------------------------------------
*/
Route::get('/ev-stations', [EvStationController::class, 'index']);
Route::get('/ev-stations/{station}', [EvStationController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Payment Webhooks (unauthenticated — verified by signature)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'handlePaystack']);
Route::match(['get', 'post'], '/webhooks/qoreid', [KycWebhookController::class, 'handle']);
Route::post('/webhooks/paystack-wallet', [PaystackWebhookController::class, 'handle']);

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
Route::prefix('admin/auth')->group(function () {
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/login', [AdminAuthController::class, 'login']);

        Route::get('/invite/verify/{token}', [AdminInvitationController::class, 'verifyToken']);
        Route::post('/invite/accept', [AdminInvitationController::class, 'accept']);

        Route::post('/forgot-password', [AdminInvitationController::class, 'forgotPassword']);
        Route::post('/reset-password', [AdminInvitationController::class, 'resetPassword']);
    });

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
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);

    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payments/initialize', [PaymentMethodController::class, 'initialize']);
    Route::get('/payments/{transactionId}/verify', [PaymentMethodController::class, 'verify']);
    Route::patch('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'setDefault']);
    Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Gamification Routes (Authenticated)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('gamification')->group(function () {
    Route::get('/', [GamificationController::class, 'show']);
    Route::get('/carbon-history', [GamificationController::class, 'carbonHistory']);
    Route::get('/tiers', [GamificationController::class, 'tiers']);
    Route::get('/leaderboard', [GamificationController::class, 'leaderboard']);
});

/*
|--------------------------------------------------------------------------
| Promo Routes (Authenticated — Passenger)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:passenger'])->prefix('promos')->group(function () {
    Route::post('/validate', [PromoController::class, 'validate']);
    Route::get('/available', [PromoController::class, 'available']);
});

/*
|--------------------------------------------------------------------------
| Ride Routes (authenticated — any role, scoped inside controller)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('rides')->group(function () {
    Route::post('/', [RideController::class, 'store'])->middleware('user.type:passenger');
    Route::get('/', [RideController::class, 'index']);
    Route::get('/cancellation-reasons', [RideCancellationReasonController::class, 'index']);
    Route::get('/{ride}', [RideController::class, 'show']);
    Route::post('/{ride}/rebroadcast', [RideController::class, 'rebroadcast'])
        ->middleware(['user.type:passenger', 'throttle:3,1']);
    Route::post('/{ride}/cancel', [RideController::class, 'cancel']);
    Route::get('/{ride}/location', [RideLocationController::class, 'show']);
    Route::post('/{ride}/driver-arrived', [RideController::class, 'driverArrived'])->middleware('user.type:driver');
    Route::post('/{ride}/verify-pin', [RideController::class, 'verifyPin'])->middleware('user.type:driver');
    Route::post('/{ride}/complete', [RideController::class, 'complete'])->middleware('user.type:driver');
    Route::post('/{ride}/accept', [RideController::class, 'accept'])->middleware('user.type:driver');
    Route::post('/{ride}/reject', [RideController::class, 'reject'])->middleware('user.type:driver');
    Route::post('/{ride}/confirm-cash', [RidePaymentController::class, 'confirmCash'])->middleware('user.type:driver');
    Route::post('/{ride}/tip', [RideTipController::class, 'store'])->middleware('user.type:passenger');
    Route::get('/{ride}/receipt', [RideReceiptController::class, 'show']);
    Route::post('/{ride}/rating', [RideRatingController::class, 'store']);
    Route::post('/{ride}/dispute', [RideDisputeController::class, 'store']);
    Route::post('/{ride}/sos', [SosController::class, 'trigger']);
});

/*
|--------------------------------------------------------------------------
| SOS Routes (Authenticated — ride participant)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('sos')->group(function () {
    Route::post('/{incident}/acknowledge', [SosController::class, 'acknowledge']);
    Route::post('/{incident}/cancel', [SosController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Passenger Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:passenger'])->prefix('passenger')->group(function () {
    Route::get('/active-ride', [PassengerActiveRideController::class, 'show']);
    Route::get('/nearby-drivers', NearbyDriversController::class);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);

    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'show']);
        Route::get('/transactions', [WalletController::class, 'transactions']);
        Route::post('/topup', [WalletTopupController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/topup/{transactionId}/verify', [WalletTopupController::class, 'verify']);
    });
});

/*
|--------------------------------------------------------------------------
| Driver Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:driver'])->prefix('driver')->group(function () {
    Route::get('/nearby-rides', NearbyRidesController::class);
    Route::get('/active-ride', [DriverActiveRideController::class, 'show']);
    Route::get('/stats', [DriverStatsController::class, 'show']);
    Route::get('/earnings', [DriverEarningsController::class, 'show']);
    Route::get('/earnings/rides/{ride}', [DriverEarningsController::class, 'rideBreakdown']);
    Route::post('/toggle-online', [DriverController::class, 'toggleOnline']);
    Route::get('/location', [DriverController::class, 'location']);
    Route::post('/location', [DriverLocationController::class, 'update']);

    Route::get('/onboarding/status', [OnboardingController::class, 'status']);
    Route::post('/onboarding/submit', [OnboardingController::class, 'submitForReview']);
    Route::put('/profile', [OnboardingController::class, 'updateProfile']);
    Route::post('/profile/photo', [OnboardingController::class, 'updateProfilePhoto']);
    Route::delete('/profile/photo', [OnboardingController::class, 'deleteProfilePhoto']);

    Route::post('/documents', [OnboardingController::class, 'uploadDocument']);
    Route::get('/documents', [OnboardingController::class, 'documents']);

    Route::post('/onboarding/vehicle-ownership', [OnboardingController::class, 'setVehicleOwnership']);

    Route::post('/vehicle', [OnboardingController::class, 'storeVehicle']);
    Route::put('/vehicle', [OnboardingController::class, 'updateVehicle']);
    Route::get('/vehicle', [OnboardingController::class, 'vehicle']);

    Route::prefix('remittance')->group(function () {
        Route::get('/today', [DriverRemittanceController::class, 'today']);
        Route::get('/history', [DriverRemittanceController::class, 'history']);
    });
    Route::get('/fleet-agreement', [DriverRemittanceController::class, 'agreement']);

    Route::prefix('ledger')->group(function () {
        Route::get('/summary', [DriverLedgerController::class, 'summary']);
        Route::get('/transactions', [DriverLedgerController::class, 'index']);
    });

    Route::prefix('bank-account')->group(function () {
        Route::get('/', [BankAccountController::class, 'show']);
        Route::post('/', [BankAccountController::class, 'store']);
    });

    Route::prefix('payouts')->group(function () {
        Route::get('/', [PayoutController::class, 'index']);
        Route::post('/', [PayoutController::class, 'store'])->middleware('throttle:3,1');
    });

    Route::prefix('kyc')->group(function () {
        Route::get('/status', [KycController::class, 'status']);
        Route::get('/verifications', [KycController::class, 'verifications']);
        Route::post('/verify-nin', [KycController::class, 'verifyNin']);
        Route::post('/verify-license', [KycController::class, 'verifyDriversLicense']);
        Route::post('/verify-vehicle', [KycController::class, 'verifyVehiclePlate']);
        Route::post('/liveness-session', [KycController::class, 'createLivenessSession']);
    });

    Route::get('/compliance', [DriverComplianceController::class, 'show']);
    Route::post('/flags/{flag}/dispute', [DriverFlagController::class, 'dispute']);

    Route::prefix('ev-reservations')->group(function () {
        Route::get('/', [EvReservationController::class, 'index']);
        Route::post('/stations/{station}/reserve', [EvReservationController::class, 'store']);
        Route::get('/{reservation}', [EvReservationController::class, 'show']);
        Route::post('/{reservation}/activate', [EvReservationController::class, 'activate']);
        Route::post('/{reservation}/complete', [EvReservationController::class, 'complete']);
        Route::post('/{reservation}/cancel', [EvReservationController::class, 'cancel']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'user.type:admin'])->prefix('admin')->group(function () {
    Route::middleware('admin.role:operations,safety_operator')->prefix('drivers')->group(function () {
        Route::get('/', [DriverManagementController::class, 'index']);
        Route::get('/pending', [DriverManagementController::class, 'pendingReview']);
        Route::get('/{driver}', [DriverManagementController::class, 'show']);
        Route::post('/{driver}/review', [DriverManagementController::class, 'review']);
        Route::post('/{driver}/documents/{document}/review', [DriverManagementController::class, 'reviewDocument']);
        Route::post('/{driver}/suspend', [DriverManagementController::class, 'suspend']);
        Route::post('/{driver}/reactivate', [DriverManagementController::class, 'reactivate']);
        Route::patch('/{driver}/vehicle/fleet', [DriverManagementController::class, 'toggleFleetVehicle']);
        // Testing/QA only: remove before production go-live
        Route::post('/{driver}/complete-onboarding', [DriverManagementController::class, 'completeOnboarding']);
    });

    Route::middleware('admin.role:operations')->prefix('cities')->group(function () {
        Route::get('/', [AdminCityController::class, 'index']);
        Route::post('/', [AdminCityController::class, 'store']);
        Route::get('/{city}', [AdminCityController::class, 'show']);
        Route::put('/{city}', [AdminCityController::class, 'update']);
        Route::patch('/{city}/status', [AdminCityController::class, 'toggleStatus']);
        Route::put('/{city}/vehicle-classes', [AdminCityVehicleClassController::class, 'update']);
    });

    Route::middleware('admin.role:operations')->prefix('vehicle-classes')->group(function () {
        Route::get('/', [AdminVehicleClassController::class, 'index']);
        Route::post('/', [AdminVehicleClassController::class, 'store']);
        Route::get('/{vehicleClass}', [AdminVehicleClassController::class, 'show']);
        Route::put('/{vehicleClass}', [AdminVehicleClassController::class, 'update']);
    });

    Route::middleware('admin.role:operations,finance')->prefix('pricing')->group(function () {
        Route::get('/', [AdminPricingController::class, 'index']);
        Route::post('/', [AdminPricingController::class, 'store']);
        Route::get('/current', [AdminPricingController::class, 'current']);
        Route::get('/{pricingConfig}', [AdminPricingController::class, 'show']);
    });

    Route::middleware('admin.role:operations')->prefix('surge-rules')->group(function () {
        Route::get('/', [AdminSurgeRuleController::class, 'index']);
        Route::post('/', [AdminSurgeRuleController::class, 'store']);
        Route::get('/current-multiplier', [AdminSurgeRuleController::class, 'currentMultiplier']);
        Route::get('/{surgeRule}', [AdminSurgeRuleController::class, 'show']);
        Route::put('/{surgeRule}', [AdminSurgeRuleController::class, 'update']);
        Route::patch('/{surgeRule}/status', [AdminSurgeRuleController::class, 'toggleStatus']);
    });

    Route::middleware('admin.role:operations')->prefix('rides')->group(function () {
        Route::post('/{ride}/assign', [AdminRideController::class, 'assign']);
    });

    Route::middleware('admin.role:operations,finance')->prefix('fleet-vehicles')->group(function () {
        Route::get('/', [AdminFleetVehicleController::class, 'index']);
        Route::post('/', [AdminFleetVehicleController::class, 'store']);
        Route::get('/drivers-awaiting', [AdminFleetVehicleController::class, 'awaitingAssignment']);
        Route::get('/{vehicle}', [AdminFleetVehicleController::class, 'show']);
        Route::put('/{vehicle}', [AdminFleetVehicleController::class, 'update']);
        Route::post('/{vehicle}/assign', [AdminFleetVehicleController::class, 'assign']);
        Route::post('/{vehicle}/unassign', [AdminFleetVehicleController::class, 'unassign']);
    });

    Route::middleware('admin.role:operations,finance')->prefix('fleet-agreements')->group(function () {
        Route::get('/', [AdminFleetAgreementController::class, 'index']);
        Route::post('/', [AdminFleetAgreementController::class, 'store']);
        Route::get('/{fleetAgreement}', [AdminFleetAgreementController::class, 'show']);
        Route::put('/{fleetAgreement}', [AdminFleetAgreementController::class, 'update']);
        Route::post('/{fleetAgreement}/terminate', [AdminFleetAgreementController::class, 'terminate']);
        Route::post('/{fleetAgreement}/terminate-settle', [AdminFleetAgreementController::class, 'terminateWithSettlement']);
        Route::post('/{fleetAgreement}/pause', [AdminFleetAgreementController::class, 'pause']);
        Route::post('/{fleetAgreement}/resume', [AdminFleetAgreementController::class, 'resume']);
        Route::post('/{fleetAgreement}/swap', [AdminFleetAgreementController::class, 'swap']);
        Route::post('/{fleetAgreement}/remittances/{remittance}/excuse', [AdminFleetAgreementController::class, 'excuseDay']);
    });

    Route::middleware('admin.role:operations')->prefix('gamification')->group(function () {
        Route::get('/users', [AdminGamificationController::class, 'indexUsers']);
        Route::get('/aggregate', [AdminGamificationController::class, 'aggregate']);
        Route::get('/tiers', [AdminGamificationController::class, 'tiers']);
        Route::put('/tiers', [AdminGamificationController::class, 'updateTiers']);
        Route::get('/multipliers', [AdminGamificationController::class, 'multipliers']);
        Route::put('/multipliers', [AdminGamificationController::class, 'updateMultipliers']);
    });

    Route::middleware('admin.role:operations,finance')->prefix('promos')->group(function () {
        Route::get('/', [AdminPromoController::class, 'index']);
        Route::post('/', [AdminPromoController::class, 'store']);
        Route::get('/{promo}', [AdminPromoController::class, 'show']);
        Route::put('/{promo}', [AdminPromoController::class, 'update']);
        Route::patch('/{promo}/status', [AdminPromoController::class, 'toggleStatus']);
        Route::get('/{promo}/performance', [AdminPromoController::class, 'performance']);
    });

    Route::middleware('admin.role:safety_operator')->prefix('sos')->group(function () {
        Route::get('/active', [AdminSosController::class, 'active']);
        Route::get('/history', [AdminSosController::class, 'history']);
        Route::get('/{incident}', [AdminSosController::class, 'show']);
        Route::post('/{incident}/assign', [AdminSosController::class, 'assign']);
        Route::post('/{incident}/dispatch', [AdminSosController::class, 'dispatch']);
        Route::post('/{incident}/resolve', [AdminSosController::class, 'resolve']);
    });

    Route::middleware('admin.role:operations')->prefix('ev-stations')->group(function () {
        Route::get('/', [AdminEvStationController::class, 'index']);
        Route::post('/', [AdminEvStationController::class, 'store']);
        Route::get('/utilisation', [AdminEvStationController::class, 'utilisation']);
        Route::get('/{station}', [AdminEvStationController::class, 'show']);
        Route::put('/{station}', [AdminEvStationController::class, 'update']);
        Route::patch('/{station}/status', [AdminEvStationController::class, 'toggleStatus']);
        Route::put('/{station}/stalls', [AdminEvStationController::class, 'manageStalls']);
    });

    Route::middleware('admin.role:operations,safety_operator')->prefix('offline-flags')->group(function () {
        Route::get('/', [AdminOfflineFlagController::class, 'index']);
        Route::get('/{flag}', [AdminOfflineFlagController::class, 'show']);
        Route::post('/{flag}/review', [AdminOfflineFlagController::class, 'review']);
        Route::post('/{flag}/escalate', [AdminOfflineFlagController::class, 'escalate']);
        Route::post('/{flag}/confirm-deactivation', [AdminOfflineFlagController::class, 'confirmDeactivation']);
    });

    Route::middleware('admin.role:support')->prefix('disputes')->group(function () {
        Route::get('/', [AdminDisputeController::class, 'index']);
        Route::get('/{dispute}', [AdminDisputeController::class, 'show']);
        Route::post('/{dispute}/resolve', [AdminDisputeController::class, 'resolve']);
    });

    Route::middleware('admin.role:support,safety_operator')->prefix('passengers')->group(function () {
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

    // ── Wallet & Ledger Administration ──

    Route::middleware('admin.role:finance,support')->prefix('wallets')->group(function () {
        Route::get('/', [AdminWalletController::class, 'index']);
        Route::get('/{account}', [AdminWalletController::class, 'show']);
        Route::post('/{account}/freeze', [AdminWalletController::class, 'freeze'])->middleware('admin.role:finance');
        Route::post('/{account}/unfreeze', [AdminWalletController::class, 'unfreeze'])->middleware('admin.role:finance');
    });

    Route::middleware('admin.role:finance,support')->prefix('driver-ledgers')->group(function () {
        Route::get('/', [AdminDriverLedgerController::class, 'index']);
        Route::get('/{account}', [AdminDriverLedgerController::class, 'show']);
    });

    Route::middleware('admin.role:finance')->prefix('ledger')->group(function () {
        Route::get('/', [AdminLedgerExplorerController::class, 'index']);
        Route::get('/export', [AdminLedgerExplorerController::class, 'export']);
    });

    Route::middleware('admin.role:finance')->prefix('adjustments')->group(function () {
        Route::get('/', [AdminAdjustmentController::class, 'index']);
        Route::post('/', [AdminAdjustmentController::class, 'store']);
        Route::post('/{adjustment}/approve', [AdminAdjustmentController::class, 'approve']);
        Route::post('/{adjustment}/reject', [AdminAdjustmentController::class, 'reject']);
    });

    Route::middleware('admin.role:finance')->prefix('payouts')->group(function () {
        Route::get('/', [AdminPayoutController::class, 'index']);
        Route::post('/{payout}/approve', [AdminPayoutController::class, 'approve']);
        Route::post('/{payout}/reject', [AdminPayoutController::class, 'reject']);
        Route::post('/{payout}/retry', [AdminPayoutController::class, 'retry']);
        Route::get('/export', [AdminPayoutController::class, 'export']);
    });

    Route::middleware('admin.role:finance')->prefix('rides')->group(function () {
        Route::post('/{ride}/refund', [AdminRefundController::class, 'refundToWallet']);
    });

    Route::middleware('admin.role:finance')->prefix('settings')->group(function () {
        Route::get('/commission', [AdminCommissionController::class, 'show']);
        Route::put('/commission', [AdminCommissionController::class, 'update']);
        Route::get('/wallet', [AdminWalletSettingsController::class, 'show']);
        Route::put('/wallet', [AdminWalletSettingsController::class, 'update']);
    });

    Route::middleware('admin.role:finance')->prefix('reports')->group(function () {
        Route::get('/reconciliation', [AdminReconciliationController::class, 'show']);
        Route::get('/reconciliation/history', [AdminReconciliationController::class, 'index']);
        Route::get('/reconciliation/wallet-liability', [AdminReconciliationController::class, 'walletLiability']);
    });

    Route::middleware('admin.role:operations,finance')->prefix('reports')->group(function () {
        Route::get('/ride-volume', [AdminReportController::class, 'rideVolume']);
        Route::get('/completion-rate', [AdminReportController::class, 'completionRate']);
        Route::get('/revenue', [AdminReportController::class, 'revenue']);
        Route::get('/driver-utilisation', [AdminReportController::class, 'driverUtilisation']);
        Route::get('/export', [AdminReportController::class, 'export']);
    });
});
