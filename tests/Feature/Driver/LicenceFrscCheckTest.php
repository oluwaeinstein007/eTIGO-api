<?php

use App\Contracts\KycGateway;
use App\Enums\AdminRole;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Enums\UserType;
use App\Jobs\CheckRenewedLicenceJob;
use App\Models\AppSetting;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    NotificationFacade::fake();
    Storage::fake(config('filesystems.uploads'));
    $this->travelTo(Carbon::parse('2026-11-08 09:00:00', 'UTC'));

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $this->user = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Bayo', 'last_name' => 'Akinola']);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->user->id, 'licence_number' => 'LAG12345AA01']);
    $this->token = $this->user->createToken('auth', ['driver'])->plainTextToken;

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->paper = fn (?string $expiresAt, DocumentStatus $status = DocumentStatus::Approved, ?Driver $driver = null) => DriverDocument::create([
        'driver_id' => ($driver ?? $this->driver)->id,
        'type' => DocumentType::DrivingLicence,
        'file_path' => 'driver-documents/'.fake()->uuid().'.pdf',
        'original_filename' => 'licence.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1000,
        'expires_at' => $expiresAt,
        'status' => $status,
        'reviewed_at' => $status === DocumentStatus::Approved ? now() : null,
    ]);

    // FRSC (QoreID) answers: found in the driver's name, expiring 20 Nov 2031.
    $this->frsc = function (?string $expiry = '2031-11-20', bool $verified = true, ?Throwable $error = null) {
        $gateway = Mockery::mock(KycGateway::class);
        $expectation = $gateway->shouldReceive('verifyDriversLicense');
        $error !== null
            ? $expectation->andThrow($error)
            : $expectation->andReturn([
                'verified' => $verified,
                'provider_reference' => 'qid_123',
                'match_data' => ['first_name_match' => $verified, 'last_name_match' => $verified, 'expiry_date' => $expiry],
                'raw_response' => [],
                'failure_reason' => $verified ? null : 'Name mismatch against license records',
            ]);
        $this->app->instance(KycGateway::class, $gateway);

        return $gateway;
    };

    $this->runCheck = fn (DriverDocument $document) => app()->call([new CheckRenewedLicenceJob($document), 'handle']);
});

it('looks a working driver\'s renewed licence up in FRSC records after upload', function () {
    ($this->paper)('2026-11-20');

    ($this->as)($this->token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('renewed.pdf', 100, 'application/pdf'),
        'expires_at' => '2031-11-20',
        'document_number' => 'LAG99999ZZ09',
    ])->assertCreated();

    Queue::assertPushed(CheckRenewedLicenceJob::class, 1);
});

it('does not look up onboarding uploads, or renewals with the check switched off', function () {
    $newcomer = Driver::factory()->create(['status' => 'onboarding']);
    $token = $newcomer->user->createToken('auth', ['driver'])->plainTextToken;
    ($this->as)($token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
    ])->assertCreated();

    AppSetting::setValue('driver_documents.frsc_check_on_renewal', 'false', 'driver_documents');
    ($this->paper)('2026-11-20');
    ($this->as)($this->token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('renewed.pdf', 100, 'application/pdf'),
        'expires_at' => '2031-11-20',
    ])->assertCreated();

    Queue::assertNotPushed(CheckRenewedLicenceJob::class);
});

it('shows operations FRSC\'s date and flags an expiry that differs, without touching KYC', function () {
    // number_last4 below shows the renewed licence's own number was looked up.
    ($this->frsc)('2031-11-20');
    $renewal = ($this->paper)('2031-12-20', DocumentStatus::Pending);
    $renewal->update(['document_number' => 'LAG99999ZZ09']);
    $kycStatus = $this->driver->kyc_status;

    ($this->runCheck)($renewal);

    ($this->as)($this->adminToken)->getJson("/api/v1/admin/drivers/{$this->driver->id}")
        ->assertOk()
        ->assertJsonPath('driver.documents.0.frsc_check.status', 'matched')
        ->assertJsonPath('driver.documents.0.frsc_check.frsc_expires_at', '2031-11-20')
        ->assertJsonPath('driver.documents.0.frsc_check.expiry_matches', false)
        ->assertJsonPath('driver.documents.0.frsc_check.number_last4', 'ZZ09');

    expect(KycVerification::count())->toBe(0)
        ->and($this->driver->fresh()->kyc_status)->toBe($kycStatus);

    // The driver never sees it.
    ($this->as)($this->token)->getJson('/api/v1/driver/onboarding/status')
        ->assertJsonMissingPath('driver.documents.0.frsc_check');
});

it('records a name mismatch and an unavailable lookup as flags for the reviewer', function () {
    ($this->frsc)(null, verified: false);
    $mismatch = ($this->paper)('2031-11-20', DocumentStatus::Pending);
    ($this->runCheck)($mismatch);
    expect($mismatch->fresh()->licence_check['status'])->toBe('name_mismatch');

    ($this->frsc)(error: new RuntimeException('QoreID API error: not found'));
    $unknown = ($this->paper)('2031-11-20', DocumentStatus::Pending);
    ($this->runCheck)($unknown);
    expect($unknown->fresh()->licence_check['status'])->toBe('unavailable');

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/driver-documents?status=pending&frsc_flagged=true')
        ->assertOk()
        ->assertJsonCount(2, 'documents')
        ->assertJsonPath('summary.frsc_flagged', 2);
});

it('skips a renewal already reviewed by the time the job runs', function () {
    $gateway = ($this->frsc)();
    $gateway->shouldNotReceive('verifyDriversLicense');
    $renewal = ($this->paper)('2031-11-20', DocumentStatus::Pending);
    $renewal->update(['status' => DocumentStatus::Rejected, 'rejection_reason' => 'x']);

    ($this->runCheck)($renewal);

    expect($renewal->fresh()->licence_check)->toBeNull();
});

it('approves with FRSC\'s date when the reviewer sends none', function () {
    ($this->frsc)('2031-11-20');
    $renewal = ($this->paper)(null, DocumentStatus::Pending);
    ($this->runCheck)($renewal);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/review", ['action' => 'approve'])
        ->assertOk()
        ->assertJsonPath('document.expires_at', '2031-11-20T00:00:00.000000Z');
});

it('dates an onboarding licence from the onboarding FRSC check when approving the application', function () {
    $applicant = Driver::factory()->create(['kyc_status' => KycStatus::Verified, 'kyc_verified_at' => now()]);
    $licence = ($this->paper)(null, DocumentStatus::Pending, $applicant);
    $applicant->kycVerifications()->create([
        'type' => 'drivers_license',
        'id_number' => 'ABJ55555BB02',
        'status' => 'verified',
        'match_data' => ['expiry_date' => '2029-03-31'],
        'verified_at' => now(),
    ]);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$applicant->id}/review", ['action' => 'approve'])->assertOk();

    expect($licence->fresh()->expires_at->toDateString())->toBe('2029-03-31')
        ->and($licence->fresh()->status)->toBe(DocumentStatus::Approved);
});

it('lets operations run the lookup again', function () {
    ($this->frsc)('2031-11-20');
    $renewal = ($this->paper)('2031-11-20', DocumentStatus::Pending);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/frsc-check")
        ->assertOk()
        ->assertJsonPath('document.frsc_check.status', 'matched')
        ->assertJsonPath('document.frsc_check.expiry_matches', true);

    $approved = ($this->paper)('2030-01-01');
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$approved->id}/frsc-check")
        ->assertUnprocessable();
});
