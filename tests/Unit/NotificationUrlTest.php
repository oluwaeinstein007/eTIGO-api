<?php

namespace Tests\Unit;

use App\Enums\DisputeStatus;
use App\Enums\DocumentType;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\AdminPasswordChangedNotification;
use App\Notifications\AdminPasswordResetNotification;
use App\Notifications\AdminWelcomeNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\DriverApprovedNotification;
use App\Notifications\DriverDocumentExpiringNotification;
use App\Notifications\DriverOnboardingWelcomeNotification;
use App\Notifications\InvoiceActivatedNotification;
use App\Notifications\OperationsAlertNotification;
use App\Notifications\PassengerWelcomeNotification;
use App\Notifications\PaymentReceiptNotification;
use App\Notifications\RideCompletedNotification;
use App\Notifications\TierUpgradedNotification;
use Tests\TestCase;

class NotificationUrlTest extends TestCase
{
    public function test_admin_notifications_use_admin_frontend_url(): void
    {
        config([
            'app.admin_frontend_url' => 'https://admin-dev.etigorides.com',
            'app.landing_page_url' => 'https://www.etigorides.com',
        ]);

        $user = new User(['first_name' => 'Admin', 'email' => 'admin@etigo.ng']);

        $welcome = new AdminWelcomeNotification;
        $mail = $welcome->toMail($user);
        $this->assertStringStartsWith('https://admin-dev.etigorides.com/admin/dashboard', $mail->viewData['dashboardUrl']);

        $pwReset = new AdminPasswordResetNotification('test-token');
        $mail = $pwReset->toMail($user);
        $this->assertStringStartsWith('https://admin-dev.etigorides.com/auth/reset-password', $mail->viewData['resetUrl']);

        $pwChanged = new AdminPasswordChangedNotification;
        $mail = $pwChanged->toMail($user);
        $this->assertSame('https://admin-dev.etigorides.com/auth/forgot-password', $mail->viewData['resetUrl']);

        $invoice = new InvoiceActivatedNotification('Ops Team');
        $mail = $invoice->toMail($user);
        $this->assertSame('https://admin-dev.etigorides.com/admin/billing/invoices', $mail->viewData['invoicesUrl']);

        $opsAlert = new OperationsAlertNotification('ops', 'Critical Issue', 'Something happened', ['line 1'], '/admin/ops/status', 'View status');
        $mail = $opsAlert->toMail($user);
        $this->assertSame('https://admin-dev.etigorides.com/admin/ops/status', $mail->viewData['actionUrl']);
    }

    public function test_customer_and_driver_notifications_use_landing_page_url(): void
    {
        config([
            'app.admin_frontend_url' => 'https://admin-dev.etigorides.com',
            'app.landing_page_url' => 'https://www.etigorides.com',
        ]);

        $passenger = new User(['first_name' => 'John', 'email' => 'john@example.com']);

        // Passenger welcome
        $passengerWelcome = new PassengerWelcomeNotification;
        $mail = $passengerWelcome->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/rides/book', $mail->viewData['bookRideUrl']);

        // Ride receipt
        $ride = new Ride([
            'distance_km' => 12.5,
            'duration_minutes' => 20,
            'pickup_address' => 'Victoria Island, Lagos',
            'destination_address' => 'Ikeja, Lagos',
        ]);
        $ride->id = 'ride-123';
        $rideReceipt = new RideCompletedNotification($ride, [
            'final_fare' => 3000,
            'duration_minutes' => 20,
            'distance_km' => 12.5,
            'fare_breakdown' => [
                'base_fare' => 500,
                'distance_fare' => 2000,
                'time_fare' => 500,
            ],
        ]);
        $mail = $rideReceipt->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/rides/ride-123', $mail->viewData['receiptUrl']);

        // Payment receipt
        $payment = new PaymentReceiptNotification(
            ride: $ride,
            amountKobo: 350000,
            paymentMethod: 'wallet',
            paymentReference: 'TXN-999',
            paidAt: now()
        );
        $mail = $payment->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/payments/TXN-999', $mail->viewData['receiptUrl']);

        // Tier upgraded
        $tier = new TierUpgradedNotification(tierName: 'Silver', previousTier: 1, newTier: 2);
        $mail = $tier->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/rewards', $mail->viewData['rewardsUrl']);

        // Dispute resolved
        $dispute = new Dispute([
            'status' => DisputeStatus::Resolved,
            'reference_number' => 'DSP-101',
            'resolution_notes' => 'Resolved in your favor',
        ]);
        $disputeResolved = new DisputeResolvedNotification($dispute);
        $mail = $disputeResolved->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/rides', $mail->viewData['supportUrl']);

        // Driver notifications
        $driver = new Driver(['id' => 'drv-uuid-1234', 'reference_number' => 'DRV-001']);
        $driverApproved = new DriverApprovedNotification($driver);
        $mail = $driverApproved->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/driver', $mail->viewData['driverAppUrl']);

        $driverOnboarding = new DriverOnboardingWelcomeNotification($driver);
        $mail = $driverOnboarding->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/driver/application', $mail->viewData['applicationUrl']);

        $doc = new DriverDocument([
            'type' => DocumentType::DrivingLicence,
            'expires_at' => now()->addDays(5),
            'document_number' => 'LIC-123',
        ]);
        $driverExpiring = new DriverDocumentExpiringNotification($driver, $doc);
        $mail = $driverExpiring->toMail($passenger);
        $this->assertSame('https://www.etigorides.com/driver/documents', $mail->viewData['updateDocumentUrl']);
    }
}
