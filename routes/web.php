<?php

use App\Http\Controllers\SocialCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/{provider}/callback', SocialCallbackController::class)
    ->where('provider', 'google|apple|facebook');

// No-app live trip page (third-party riders and shared trips). It reads the
// public share endpoint, which checks the token and the link expiry.
Route::get('/track/{ride}/{token}', function (string $ride, string $token) {
    return response()
        ->view('track', ['endpoint' => url("/api/v1/rides/{$ride}/share/{$token}")])
        ->header('Cache-Control', 'no-store')
        ->header('X-Robots-Tag', 'noindex, nofollow');
})->whereUuid('ride')->where('token', '[A-Za-z0-9]{16,64}')->middleware('throttle:60,1');

if (app()->environment('local')) {
    Route::get('/mail-preview/{type}', function (string $type) {
        $data = match ($type) {
            'admin-invitation' => [
                'preheader' => "You're invited to the Etigo admin team",
                'preheaderLabel' => 'Admin invitation',
                'preheaderTitle' => "You're invited to the Etigo admin team",
                'preheaderSub' => 'Join Etigo. Your invitation is valid for 48 hours.',
                'inviteeFirstName' => 'Amara',
                'inviterName' => 'Naomi Adeyemi',
                'workspaceName' => 'Mainland Mobility',
                'roleName' => 'Administrator',
                'acceptUrl' => url('/admin/accept-invite?token=abc123'),
                'recipientEmail' => 'amara@example.com',
                'expiresAt' => '15 October 2026 at 10:00 WAT',
            ],
            'admin-password-reset' => [
                'preheader' => 'Reset your Etigo admin password',
                'preheaderLabel' => 'Admin password reset',
                'preheaderTitle' => 'Reset your Etigo admin password',
                'preheaderSub' => 'Your secure reset link expires in 30 minutes.',
                'userName' => 'Amara',
                'resetUrl' => url('/admin/reset-password?token=abc123'),
                'recipientEmail' => 'amara@example.com',
                'requestedAt' => '13 October 2026 · 10:00 WAT',
                'expiresAt' => '10:30 WAT on 13 October 2026',
            ],
            'admin-welcome' => [
                'preheader' => 'Welcome to your Etigo workspace',
                'preheaderLabel' => 'Admin welcome',
                'preheaderTitle' => 'Welcome to your Etigo workspace, Amara',
                'preheaderSub' => 'Your admin account is ready. Take a look around your dashboard.',
                'userName' => 'Amara',
                'workspaceName' => 'Mainland Mobility',
                'roleName' => 'Administrator',
                'dashboardUrl' => url('/admin/dashboard'),
                'recipientEmail' => 'amara@example.com',
            ],
            'ride-receipt' => [
                'preheader' => 'Your Etigo ride receipt',
                'preheaderLabel' => 'Ride receipt',
                'preheaderTitle' => "Your Etigo ride receipt · \u{20A6}4,500.00",
                'preheaderSub' => 'Yaba to Surulere on 13 October. Paid with Visa ending in 4242.',
                'userName' => 'Amara',
                'recipientEmail' => 'amara@example.com',
                'currency' => 'NGN',
                'currencySymbol' => "\u{20A6}",
                'totalPaid' => '4,500.00',
                'paymentMethodLabel' => 'Visa ---- 4242',
                'pickupArea' => 'Yaba',
                'dropoffArea' => 'Surulere',
                'pickupAddress' => 'Tejuosho Market, Yaba',
                'dropoffAddress' => 'Bode Thomas Street, Surulere',
                'pickupTime' => '09:12 WAT',
                'dropoffTime' => '09:30 WAT',
                'rideReference' => 'ETG-261013-0842',
                'rideDate' => '13 October 2026',
                'passengerName' => 'Amara Okafor',
                'driverName' => 'Tunde Bello',
                'vehicleType' => 'Etigo Comfort',
                'duration' => '18 min',
                'distance' => '7.2 km',
                'fareItems' => [
                    ['label' => 'Base fare', 'amount' => '4,200.00'],
                    ['label' => 'Distance & time', 'amount' => '500.00'],
                    ['label' => 'Booking fee', 'amount' => '300.00'],
                    ['label' => 'Subtotal', 'amount' => '5,000.00'],
                    ['label' => 'Ride credit', 'amount' => '500.00', 'negative' => true],
                ],
                'paymentNote' => 'Charged to Visa ending in 4242. No further payment is due.',
                'mapImageUrl' => 'https://res.cloudinary.com/mt0hsxma/image/upload/v1791939161/email-assets/route-map-yaba-surulere.jpg',
                'receiptUrl' => url('/rides/abc123'),
            ],
            'passenger-welcome' => [
                'preheader' => 'Welcome to Etigo',
                'preheaderLabel' => 'Passenger welcome',
                'preheaderTitle' => "Welcome to Etigo, Amara. Let's get moving.",
                'preheaderSub' => 'Your account is ready. Your next journey starts with Etigo.',
                'userName' => 'Amara',
                'recipientEmail' => 'amara@example.com',
                'bookRideUrl' => url('/rides/book'),
            ],
            'driver-onboarding-welcome' => [
                'preheader' => "We've received your Etigo driver registration",
                'preheaderLabel' => 'Driver onboarding welcome',
                'preheaderTitle' => "We've received your Etigo driver registration",
                'preheaderSub' => 'Your profile and documents are in review.',
                'userName' => 'Tunde',
                'recipientEmail' => 'tunde@example.com',
                'applicationId' => 'DRV-261013-0176',
                'submittedDate' => '13 October 2026',
                'documentsReceived' => 'Identity, driving licence & insurance',
                'applicationUrl' => url('/driver/application'),
            ],
            'driver-approved' => [
                'preheader' => "You're approved to drive with Etigo",
                'preheaderLabel' => 'Driver approved',
                'preheaderTitle' => "You're approved to drive with Etigo",
                'preheaderSub' => 'Your documents are approved. Open Etigo Driver to get started.',
                'userName' => 'Tunde',
                'recipientEmail' => 'tunde@example.com',
                'driverName' => 'Tunde Bello',
                'driverId' => 'DRV-261013-0176',
                'approvedDate' => '14 October 2026',
                'driverAppUrl' => url('/driver'),
            ],
            'driver-document-expiring' => [
                'preheader' => 'Your driving licence expires on 27 October',
                'preheaderLabel' => 'Driver document expiring',
                'preheaderTitle' => 'Your driving licence expires on 27 October',
                'preheaderSub' => '14 days left. Upload your renewed licence.',
                'userName' => 'Tunde',
                'recipientEmail' => 'tunde@example.com',
                'documentType' => 'Driving licence',
                'expiryDate' => '27 October 2026',
                'daysRemaining' => 14,
                'documentEnding' => '4821',
                'driverName' => 'Tunde Bello',
                'driverId' => 'DRV-261013-0176',
                'updateDocumentUrl' => url('/driver/documents'),
            ],
            'payment-receipt' => [
                'preheader' => "Payment successful · \u{20A6}4,500.00",
                'preheaderLabel' => 'Payment receipt',
                'preheaderTitle' => "Payment successful · \u{20A6}4,500.00",
                'preheaderSub' => 'Your Visa payment was successful. Keep this confirmation for your records.',
                'userName' => 'Amara',
                'recipientEmail' => 'amara@example.com',
                'currency' => 'NGN',
                'currencySymbol' => "\u{20A6}",
                'totalAmount' => '4,500.00',
                'paymentMethod' => 'Card',
                'paymentMethodDetail' => 'Visa ending in 4242',
                'paymentReference' => 'PAY-261013-0398',
                'paymentDate' => '13 October 2026 · 09:30 WAT',
                'paidBy' => 'Amara Okafor',
                'paymentFor' => 'Ride ETG-261013-0842',
                'receiptUrl' => url('/payments/PAY-261013-0398'),
            ],
            'invoice-activated' => [
                'preheader' => 'Your Etigo invoice access is now active',
                'preheaderLabel' => 'Invoice activated',
                'preheaderTitle' => 'Your Etigo invoice access is now active',
                'preheaderSub' => 'View and download invoices for Mainland Mobility in your account.',
                'userName' => 'Amara',
                'recipientEmail' => 'amara@example.com',
                'workspaceName' => 'Mainland Mobility',
                'activatedBy' => 'Naomi Adeyemi · Administrator',
                'activeFrom' => '13 October 2026',
                'invoicesUrl' => url('/admin/billing/invoices'),
            ],
            'account-deactivated' => [
                'preheader' => 'Your Etigo account has been deactivated',
                'preheaderLabel' => 'Account update',
                'preheaderTitle' => 'Your Etigo account has been deactivated',
                'preheaderSub' => 'You will no longer be able to sign in or use Etigo services.',
                'userName' => 'Amara',
                'recipientEmail' => 'amara@example.com',
                'deactivatedAt' => '14 October 2026',
                'reason' => 'Violation of community guidelines',
            ],
            default => abort(404),
        };

        return view("emails.{$type}", $data);
    });
}
