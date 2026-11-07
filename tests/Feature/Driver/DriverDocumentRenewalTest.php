<?php

use App\Enums\AdminRole;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\ProcessDriverDocumentExpiryJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverShift;
use App\Models\Notification;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Notifications\DriverDocumentExpiringNotification;
use App\Services\DriverDocumentComplianceService;
use App\Services\DriverLocationService;
use App\Services\NotificationService;
use App\Services\RideService;
use App\Services\RideStateMachine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    NotificationFacade::fake();
    Storage::fake(config('filesystems.uploads'));
    // Saturday 7 Nov 2026, 08:00 UTC (09:00 in Lagos).
    $this->travelTo(Carbon::parse('2026-11-07 08:00:00', 'UTC'));

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('removeDriver')->andReturnNull();
    $locations->shouldReceive('getDriverLocation')->andReturnNull();
    $this->app->instance(DriverLocationService::class, $locations);

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $this->city = City::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->user = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Bayo', 'email' => 'bayo@example.com']);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->user->id, 'city_id' => $this->city->id, 'licence_number' => 'LAG12345AA01']);
    $this->vehicle = Vehicle::factory()->create(['driver_id' => $this->driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);
    $this->token = $this->user->createToken('auth', ['driver'])->plainTextToken;

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->paper = fn (DocumentType $type, ?string $expiresAt, DocumentStatus $status = DocumentStatus::Approved, ?Driver $driver = null, ?Vehicle $vehicle = null) => DriverDocument::create([
        'driver_id' => ($driver ?? $this->driver)->id,
        'vehicle_id' => $vehicle?->id,
        'type' => $type,
        'file_path' => 'driver-documents/'.fake()->uuid().'.pdf',
        'original_filename' => 'paper.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1000,
        'expires_at' => $expiresAt,
        'status' => $status,
        'reviewed_at' => $status === DocumentStatus::Approved ? now() : null,
    ]);

    $this->runJob = fn () => app()->call([new ProcessDriverDocumentExpiryJob, 'handle']);
    $this->pushes = fn (NotificationType $type) => Notification::where('user_id', $this->user->id)->where('type', $type->value)->count();
});

// === RENEWAL UPLOAD ===

it('keeps the approved licence while its renewal waits for review', function () {
    $current = ($this->paper)(DocumentType::DrivingLicence, '2026-11-20');

    ($this->as)($this->token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('renewed.pdf', 100, 'application/pdf'),
        'expires_at' => '2031-11-20',
        'document_number' => 'LAG99999ZZ09',
    ])->assertCreated()
        ->assertJsonPath('document.status', 'pending')
        ->assertJsonPath('document.document_number', '•••• ZZ09');

    expect($current->fresh()->status)->toBe(DocumentStatus::Approved)
        ->and($this->driver->documents()->where('type', 'driving_licence')->count())->toBe(2);

    // The checklist shows the licence in force, with the renewal under it, and counts the type once.
    ($this->as)($this->token)->getJson('/api/v1/driver/documents')
        ->assertOk()
        ->assertJsonPath('documents.0.type', 'driving_licence')
        ->assertJsonPath('documents.0.status', 'approved')
        ->assertJsonPath('documents.0.expiry_status', 'expiring_soon')
        ->assertJsonPath('documents.0.days_until_expiry', 13)
        ->assertJsonPath('documents.0.renewal.status', 'pending')
        ->assertJsonPath('documents.0.renewal.expires_at', '2031-11-20')
        ->assertJsonPath('total_uploaded', 1)
        ->assertJsonPath('compliance.can_drive', true)
        ->assertJsonPath('compliance.action_required', false);
});

it('needs the expiry date on a renewed licence, and one later than the licence on file', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-20');

    $upload = fn (array $data) => ($this->as)($this->token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('renewed.pdf', 100, 'application/pdf'),
        ...$data,
    ]);

    $upload([])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $upload(['expires_at' => '2026-11-20'])->assertUnprocessable()
        ->assertJsonPath('errors.expires_at.0', 'Your licence on file is valid until 20 Nov 2026. Upload the renewed licence, which expires later.');
    $upload(['expires_at' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $upload(['expires_at' => '2029-11-20'])->assertCreated();
});

it('still lets a new driver upload a licence without an expiry date', function () {
    $newcomer = Driver::factory()->create(['status' => 'onboarding']);
    $token = $newcomer->user->createToken('auth', ['driver'])->plainTextToken;

    ($this->as)($token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
    ])->assertCreated();
});

it('only changes an approved driver licence number through a renewed licence', function () {
    ($this->as)($this->token)->putJson('/api/v1/driver/profile', ['licence_number' => 'NEW00001'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('licence_number');

    // Sending the same number back is fine.
    ($this->as)($this->token)->putJson('/api/v1/driver/profile', ['licence_number' => 'LAG12345AA01'])->assertOk();
});

// === REVIEW ===

it('replaces the old licence when the renewal is approved, taking its new number', function () {
    $old = ($this->paper)(DocumentType::DrivingLicence, '2026-11-20');
    $renewal = ($this->paper)(DocumentType::DrivingLicence, '2031-11-20', DocumentStatus::Pending);
    $renewal->update(['document_number' => 'LAG99999ZZ09']);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/review", ['action' => 'approve'])
        ->assertOk()
        ->assertJsonPath('document.status', 'approved')
        ->assertJsonPath('document.document_number', 'LAG99999ZZ09')
        ->assertJsonPath('compliance.documents.0.expires_at', '2031-11-20')
        ->assertJsonPath('compliance.documents.0.expiry_status', 'valid');

    expect(DriverDocument::find($old->id))->toBeNull()
        ->and($this->driver->fresh()->licence_number)->toBe('LAG99999ZZ09')
        ->and(AuditLog::where('event', 'document_replaced')->where('auditable_id', $renewal->id)->first()->old_values)
        ->toMatchArray(['document_id' => $old->id, 'expires_at' => '2026-11-20'])
        ->and(AuditLog::where('event', 'licence_number_changed')->first()->new_values['licence_number_last4'])->toBe('ZZ09')
        ->and(($this->pushes)(NotificationType::DocumentReviewed))->toBe(1);

    // The history timeline names both.
    $history = ($this->as)($this->adminToken)->getJson("/api/v1/admin/drivers/{$this->driver->id}")->assertOk()->json('history');
    expect(collect($history)->pluck('description'))->toContain('Driving licence renewed', 'Licence number changed');
});

it('will not approve a licence without its expiry date, or one already expired', function () {
    $pending = ($this->paper)(DocumentType::DrivingLicence, null, DocumentStatus::Pending);
    $review = fn (array $data) => ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$pending->id}/review", ['action' => 'approve', ...$data]);

    $review([])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $review(['expires_at' => '2026-11-06'])->assertUnprocessable()
        ->assertJsonPath('message', 'This driving licence has already expired. Reject it and ask the driver for the renewed one.');
    $review(['expires_at' => '2026-11-07'])->assertOk()->assertJsonPath('document.status', 'approved');
});

it('tells a working driver when their renewal is rejected', function () {
    $renewal = ($this->paper)(DocumentType::DrivingLicence, '2031-11-20', DocumentStatus::Pending);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/review", [
        'action' => 'reject',
        'rejection_reason' => 'The photo is blurry.',
    ])->assertOk();

    expect(Notification::where('user_id', $this->user->id)->where('type', 'document_reviewed')->first()->body)
        ->toBe("Your driving licence wasn't accepted: The photo is blurry. Upload it again in the app.");
});

// === REMINDERS ===

it('reminds at each step by push and email, once per step, and never sends a backlog', function () {
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2026-12-07'); // 30 days

    ($this->runJob)();
    ($this->runJob)();

    expect($licence->fresh()->expiry_reminder_stage)->toBe(30)
        ->and(($this->pushes)(NotificationType::DocumentExpiring))->toBe(1)
        ->and(Notification::where('user_id', $this->user->id)->first()->body)
        ->toBe("Your driving licence expires on 7 Dec 2026 (in 30 days). Upload the renewed licence now so E-tiGo can check it in time; without it you can't go online after 7 Dec 2026.");
    NotificationFacade::assertSentToTimes($this->user, DriverDocumentExpiringNotification::class, 1);

    // 20 days left: still the 30-day step. 13 days left: the 14-day step.
    $this->travelTo(Carbon::parse('2026-11-17 08:00:00', 'UTC'));
    ($this->runJob)();
    expect(($this->pushes)(NotificationType::DocumentExpiring))->toBe(1);

    $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
    ($this->runJob)();
    expect($licence->fresh()->expiry_reminder_stage)->toBe(14)
        ->and(($this->pushes)(NotificationType::DocumentExpiring))->toBe(2);

    // Skipping ahead to 1 day left sends the 1-day step only, then the day itself.
    $this->travelTo(Carbon::parse('2026-12-06 08:00:00', 'UTC'));
    ($this->runJob)();
    $this->travelTo(Carbon::parse('2026-12-07 08:00:00', 'UTC'));
    ($this->runJob)();

    expect($licence->fresh()->expiry_reminder_stage)->toBe(0)
        ->and(($this->pushes)(NotificationType::DocumentExpiring))->toBe(4)
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'document_expiring')->latest('id')->first()->body)
        ->toStartWith('Your driving licence expires today.');
});

it('sends nothing while a renewal is waiting for review', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-10');
    ($this->paper)(DocumentType::DrivingLicence, '2031-11-10', DocumentStatus::Pending);

    ($this->runJob)();

    expect(($this->pushes)(NotificationType::DocumentExpiring))->toBe(0);
    NotificationFacade::assertNothingSentTo($this->user);
});

it('emails but does not push for vehicle legal papers, and skips fleet cars', function () {
    ($this->paper)(DocumentType::InsuranceCertificate, '2026-11-14', vehicle: $this->vehicle);

    $fleetUser = User::factory()->create(['type' => UserType::Driver, 'email' => 'fleet@example.com']);
    $fleetDriver = Driver::factory()->approved()->create(['user_id' => $fleetUser->id]);
    $fleetCar = Vehicle::factory()->create(['driver_id' => $fleetDriver->id, 'is_fleet' => true]);
    ($this->paper)(DocumentType::InsuranceCertificate, '2026-11-14', driver: $fleetDriver, vehicle: $fleetCar);

    ($this->runJob)();

    expect(($this->pushes)(NotificationType::DocumentExpiring))->toBe(0);
    NotificationFacade::assertSentToTimes($this->user, DriverDocumentExpiringNotification::class, 1);
    NotificationFacade::assertNothingSentTo($fleetUser);
});

it('reminds about an expired government ID but never blocks the driver for it', function () {
    ($this->paper)(DocumentType::GovernmentId, '2026-11-01');

    ($this->runJob)();

    expect($this->driver->fresh()->isDocumentsBlocked())->toBeFalse()
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'document_expiring')->first()->body)
        ->toBe('Your government id expired on 1 Nov 2026. Upload the new one to keep your records up to date.');
});

it('sends nothing and marks nothing while reminders are off', function () {
    AppSetting::setValue('driver_documents.reminders_enabled', 'false', 'driver_documents');
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2026-11-10');

    ($this->runJob)();

    expect($licence->fresh()->expiry_reminder_stage)->toBeNull()
        ->and(($this->pushes)(NotificationType::DocumentExpiring))->toBe(0);
    NotificationFacade::assertNothingSentTo($this->user);
});

it('keeps a reminder step open while a renewal waits, and sends it if the renewal is rejected', function () {
    $id = ($this->paper)(DocumentType::GovernmentId, '2026-11-05');
    $renewal = ($this->paper)(DocumentType::GovernmentId, '2031-11-05', DocumentStatus::Pending);

    ($this->runJob)();
    expect($id->fresh()->expiry_reminder_stage)->toBeNull();

    $renewal->update(['status' => DocumentStatus::Rejected, 'rejection_reason' => 'Blurry.']);
    ($this->runJob)();

    expect($id->fresh()->expiry_reminder_stage)->toBe(-1)
        ->and(($this->pushes)(NotificationType::DocumentExpiring))->toBe(1);
});

it('still emails about an expired licence when the block push has told the driver', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-06');

    ($this->runJob)();

    expect($this->driver->fresh()->isDocumentsBlocked())->toBeTrue()
        ->and(($this->pushes)(NotificationType::DocumentExpiring))->toBe(0)
        ->and(($this->pushes)(NotificationType::DriverDocumentsBlocked))->toBe(1);
    NotificationFacade::assertSentTo($this->user, DriverDocumentExpiringNotification::class,
        fn ($n) => str_starts_with($n->line, 'Your driving licence expired on 6 Nov 2026.'));
});

it('says a government ID does not stop rides in its email', function () {
    $user = $this->user;
    $id = ($this->paper)(DocumentType::GovernmentId, '2026-11-01');
    $html = (string) (new DriverDocumentExpiringNotification($this->driver, $id))->toMail($user)->render();
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2026-11-01');
    $licenceHtml = (string) (new DriverDocumentExpiringNotification($this->driver, $licence))->toMail($user)->render();

    expect($html)->not->toContain("won't be able to accept rides")
        ->and($licenceHtml)->toContain("won't be able to accept rides");
});

// === BLOCK ===

it('takes the driver off the road the day after the licence expires, and back on when the renewal is approved', function () {
    $this->driver->update(['is_online' => true]);
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2026-11-07');

    // Valid through its expiry date.
    ($this->runJob)();
    expect($this->driver->fresh()->isDocumentsBlocked())->toBeFalse();

    $this->travelTo(Carbon::parse('2026-11-08 08:00:00', 'UTC'));
    ($this->runJob)();

    $driver = $this->driver->fresh();
    expect($driver->isDocumentsBlocked())->toBeTrue()
        ->and($driver->documents_block_reason)->toBe('Your driving licence expired on 7 Nov 2026.')
        ->and($driver->is_online)->toBeFalse()
        ->and(($this->pushes)(NotificationType::DriverDocumentsBlocked))->toBe(1)
        // The block is the expired message; no separate reminder.
        ->and($licence->fresh()->expiry_reminder_stage)->toBe(-1)
        ->and(Driver::whereKey($driver->id)->dispatchable()->exists())->toBeFalse();

    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online')
        ->assertUnprocessable()
        ->assertJsonFragment(['Your driving licence expired on 7 Nov 2026. Upload your renewed licence; you can go online once E-tiGo approves it.']);

    ($this->as)($this->token)->getJson('/api/v1/driver/documents')
        ->assertJsonPath('compliance.can_drive', false)
        ->assertJsonPath('compliance.action_required', true)
        ->assertJsonPath('documents.0.expiry_status', 'expired');

    // A fresh admin sign-in: admin tokens don't last a day.
    $adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $renewal = ($this->paper)(DocumentType::DrivingLicence, '2031-11-07', DocumentStatus::Pending);
    ($this->as)($adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/review", ['action' => 'approve'])->assertOk();

    expect($this->driver->fresh()->isDocumentsBlocked())->toBeFalse()
        ->and(($this->pushes)(NotificationType::DriverDocumentsUnblocked))->toBe(1)
        // Lifting the block is the answer; no second "approved" push.
        ->and(($this->pushes)(NotificationType::DocumentReviewed))->toBe(0);
});

it('gives the grace period operations set, and warns when going online during it', function () {
    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-documents', ['licence_grace_days' => 3])
        ->assertOk()
        ->assertJsonPath('settings.licence_grace_days', 3);

    ($this->paper)(DocumentType::DrivingLicence, '2026-11-05');

    ($this->runJob)();
    expect($this->driver->fresh()->isDocumentsBlocked())->toBeFalse()
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'document_expiring')->first()->body)
        ->toBe('Your driving licence expired on 5 Nov 2026. Upload the renewed one by 8 Nov 2026 to keep driving.');

    $this->vehicle->update(['vehicle_class_id' => $this->vehicleClass->id]);
    ($this->as)($this->token)->postJson('/api/v1/driver/toggle-online')
        ->assertOk()
        ->assertJsonPath('document_notice', 'Your driving licence expired on 5 Nov 2026. Upload the renewed one by 8 Nov 2026 to keep driving.');

    $this->travelTo(Carbon::parse('2026-11-09 08:00:00', 'UTC'));
    ($this->runJob)();
    expect($this->driver->fresh()->isDocumentsBlocked())->toBeTrue();
});

it('blocks on a withdrawn licence, keeps it through a re-upload, and lifts on the new approval', function () {
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2029-01-01');

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$licence->id}/revoke", ['reason' => 'FRSC reports this licence as suspended.'])
        ->assertOk()
        ->assertJsonPath('document.status', 'rejected')
        ->assertJsonPath('compliance.can_drive', false);

    expect($this->driver->fresh()->documents_block_reason)->toBe('Your driving licence was withdrawn by E-tiGo: FRSC reports this licence as suspended.')
        ->and(Notification::where('user_id', $this->user->id)->where('type', 'document_reviewed')->first()->body)
        ->toBe('E-tiGo withdrew your driving licence: FRSC reports this licence as suspended. Upload a valid one in the app.');

    // A new upload does not wipe the withdrawn paper, so the block holds.
    ($this->as)($this->token)->postJson('/api/v1/driver/documents', [
        'type' => 'driving_licence',
        'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        'expires_at' => '2031-01-01',
    ])->assertCreated();

    expect(DriverDocument::find($licence->id))->not->toBeNull()
        ->and(app(DriverDocumentComplianceService::class)->refresh($this->driver->fresh())->isDocumentsBlocked())->toBeTrue();

    $new = $this->driver->documents()->where('status', 'pending')->first();
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$new->id}/review", ['action' => 'approve'])->assertOk();

    expect($this->driver->fresh()->isDocumentsBlocked())->toBeFalse();
});

it('keeps a withdrawn licence blocking when expiry blocking is off, and re-blocks when it is turned back on', function () {
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2029-01-01');
    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-documents', ['block_on_expired_licence' => false])->assertOk();

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$licence->id}/revoke", ['reason' => str_repeat('Suspended by FRSC. ', 26)])
        ->assertOk()
        ->assertJsonPath('compliance.can_drive', false);

    // The full reason fits (500 characters plus the wording).
    expect(mb_strlen($this->driver->fresh()->documents_block_reason))->toBeGreaterThan(500);

    // Lifted by hand, then a settings change rechecks it (revoked licences included).
    $this->driver->forceFill(['documents_blocked_at' => null, 'documents_block_reason' => null])->save();
    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-documents', ['block_on_expired_licence' => true])->assertOk();

    expect($this->driver->fresh()->isDocumentsBlocked())->toBeTrue();
});

it('does not withdraw vehicle papers', function () {
    $insurance = ($this->paper)(DocumentType::InsuranceCertificate, '2026-11-01', vehicle: $this->vehicle);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$insurance->id}/revoke", ['reason' => 'Forged.'])
        ->assertUnprocessable();

    expect($insurance->fresh()->status)->toBe(DocumentStatus::Approved);
});

it('frees the upcoming shifts of a driver whose licence block starts', function () {
    $shift = DriverShift::factory()->create(['driver_id' => $this->driver->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(8)]);
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-01');

    ($this->runJob)();

    expect($shift->fresh()->status->value)->toBe('cancelled');
});

it('takes a blocked driver offline on the next check once their trip is over', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-01');
    $this->driver->update(['is_online' => true]);
    $ride = Ride::factory()->inProgress()->create(['driver_id' => $this->user->id]);

    ($this->runJob)();
    expect($this->driver->fresh()->isDocumentsBlocked())->toBeTrue()
        ->and($this->driver->fresh()->is_online)->toBeTrue();

    $ride->update(['status' => 'completed']);
    ($this->runJob)();

    expect($this->driver->fresh()->is_online)->toBeFalse();
});

it('stops a blocked driver accepting an offer', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-01');
    ($this->runJob)();
    $ride = Ride::factory()->create(['status' => 'searching']);

    $result = app(RideService::class)->acceptRide($ride, $this->user);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe("You can't take rides until your renewed driving licence is approved.");
});

it('keeps withdrawals and blocks through a rollback of the migration', function () {
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2029-01-01');
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$licence->id}/revoke", ['reason' => 'Forged.'])->assertOk();
    $migration = require database_path('migrations/2026_11_07_180000_add_document_renewals_to_driver_documents_and_drivers.php');

    $migration->down();

    // The release without the feature keeps the driver off the road by suspension.
    $row = DB::table('drivers')->where('id', $this->driver->id)->first();
    expect($row->status)->toBe('suspended')
        ->and($row->suspension_reason)->toBe('Driving licence not valid on E-tiGo (held during a rollback).')
        ->and(Schema::hasColumn('drivers', 'documents_blocked_at'))->toBeFalse();

    $migration->up();

    $driver = $this->driver->fresh();
    expect($driver->status->value)->toBe('approved')
        ->and($driver->documents_block_reason)->toBe('Your driving licence was withdrawn by E-tiGo: Forged.')
        ->and($licence->fresh()->revoked_at)->not->toBeNull()
        ->and(Schema::hasTable('driver_document_renewals_rollback'))->toBeFalse();
});

it('stops offers the moment a licence lapses, before any block check runs', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-07');
    expect(Driver::whereKey($this->driver->id)->dispatchable()->exists())->toBeTrue();

    // Past midnight in Lagos, no job has run yet.
    $this->travelTo(Carbon::parse('2026-11-07 23:30:00', 'UTC'));

    expect($this->driver->fresh()->isDocumentsBlocked())->toBeFalse()
        ->and(Driver::whereKey($this->driver->id)->dispatchable()->exists())->toBeFalse();
});

it('takes a blocked driver offline as soon as their ride ends', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-01');
    $this->driver->update(['is_online' => true]);
    $ride = Ride::factory()->inProgress()->create(['driver_id' => $this->user->id]);
    ($this->runJob)();
    expect($this->driver->fresh()->is_online)->toBeTrue();

    app(RideStateMachine::class)->transitionTo($ride, RideStatus::Completed);

    expect($this->driver->fresh()->is_online)->toBeFalse();
});

it('retries only the reminder channel that failed', function () {
    $licence = ($this->paper)(DocumentType::DrivingLicence, '2026-11-10');
    $pushes = 0;
    $service = Mockery::mock(NotificationService::class);
    $service->shouldReceive('send')->andReturnUsing(function () use (&$pushes) {
        if (++$pushes === 1) {
            throw new RuntimeException('push store down');
        }

        return null;
    });
    $this->app->instance(NotificationService::class, $service);

    // First run: the push fails, the email goes.
    ($this->runJob)();
    expect($licence->fresh()->expiry_reminder_stage)->toBe(7)
        ->and($licence->fresh()->expiry_reminder_retry)->toBe(['push']);
    NotificationFacade::assertSentToTimes($this->user, DriverDocumentExpiringNotification::class, 1);

    // Next run: only the push again.
    ($this->runJob)();
    expect($licence->fresh()->expiry_reminder_retry)->toBeNull()
        ->and($pushes)->toBe(2);
    NotificationFacade::assertSentToTimes($this->user, DriverDocumentExpiringNotification::class, 1);
});

it('does not block drivers with no licence on file', function () {
    ($this->runJob)();

    ($this->as)($this->token)->getJson('/api/v1/driver/documents')->assertJsonPath('compliance.can_drive', true);
    expect(app(DriverDocumentComplianceService::class)->blockReason($this->driver))->toBeNull();
});

it('keeps blocked drivers out of the shift booking list', function () {
    // A driver with no car of their own, so only the licence decides.
    $user = User::factory()->create(['type' => UserType::Driver]);
    $shiftDriver = Driver::factory()->approved()->create(['user_id' => $user->id]);
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-01', driver: $shiftDriver);

    expect(Driver::whereKey($shiftDriver->id)->shiftEligible()->exists())->toBeTrue();

    ($this->runJob)();

    expect(Driver::whereKey($shiftDriver->id)->shiftEligible()->exists())->toBeFalse();
});

// === ADMIN ===

it('lets operations add a missing expiry date to an approved licence, restarting its reminders', function () {
    $licence = ($this->paper)(DocumentType::DrivingLicence, null);
    $licence->update(['expiry_reminder_stage' => 7]);

    ($this->as)($this->adminToken)->patchJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$licence->id}", ['expires_at' => '2026-11-01'])
        ->assertOk()
        ->assertJsonPath('document.expires_at', '2026-11-01T00:00:00.000000Z')
        ->assertJsonPath('compliance.can_drive', false);

    expect($licence->fresh()->expiry_reminder_stage)->toBeNull()
        ->and(AuditLog::where('event', 'document_details_updated')->count())->toBe(1);
});

it('refuses a renewal that does not outlast the licence on file', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2028-05-01');
    $renewal = ($this->paper)(DocumentType::DrivingLicence, '2031-05-01', DocumentStatus::Pending);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$this->driver->id}/documents/{$renewal->id}/review", ['action' => 'approve', 'expires_at' => '2027-05-01'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The licence on file is valid until 1 May 2028. Check the date: a renewed licence expires later.');
});

it('will not approve an application whose pending licence has no expiry date', function () {
    $applicant = Driver::factory()->create(['kyc_status' => 'verified', 'kyc_verified_at' => now()]);
    ($this->paper)(DocumentType::DrivingLicence, null, DocumentStatus::Pending, $applicant);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/drivers/{$applicant->id}/review", ['action' => 'approve'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documents.0', 'Driving licence: enter its expiry date (approve the document with expires_at).');

    expect($applicant->fresh()->status->value)->toBe('pending_review');
});

it('accepts true and false for the drivers list block filter', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-01');
    ($this->runJob)();

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/drivers?documents_blocked=true')
        ->assertOk()
        ->assertJsonPath('drivers.0.id', $this->driver->id);
    ($this->as)($this->adminToken)->getJson('/api/v1/admin/drivers?documents_blocked=false')->assertOk();
    ($this->as)($this->adminToken)->getJson('/api/v1/admin/driver-documents?renewals_only=true')->assertOk();
});

it('saves nothing when an approved driver sends a new licence number with other changes', function () {
    ($this->as)($this->token)->putJson('/api/v1/driver/profile', ['first_name' => 'Changed', 'licence_number' => 'NEW00001'])
        ->assertUnprocessable();

    expect($this->user->fresh()->first_name)->toBe('Bayo');
});

it('lists the renewals queue with expiry filters and a summary', function () {
    ($this->paper)(DocumentType::DrivingLicence, '2026-11-20');
    ($this->paper)(DocumentType::DrivingLicence, '2031-11-20', DocumentStatus::Pending);
    ($this->paper)(DocumentType::GovernmentId, '2026-10-01');

    $newcomer = Driver::factory()->create();
    ($this->paper)(DocumentType::DrivingLicence, null, DocumentStatus::Pending, $newcomer);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/driver-documents?status=pending&renewals_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.is_renewal', true)
        ->assertJsonPath('documents.0.driver.id', $this->driver->id)
        ->assertJsonPath('summary.pending_renewals', 1)
        ->assertJsonPath('summary.expiring_soon', 1)
        ->assertJsonPath('summary.expired', 1);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/driver-documents?expiry=expired')
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.type', 'government_id')
        ->assertJsonPath('documents.0.days_until_expiry', -37);
});

it('validates the driver document settings', function () {
    ($this->as)($this->adminToken)->getJson('/api/v1/admin/settings/driver-documents')
        ->assertOk()
        ->assertJsonPath('settings.reminder_days', [30, 14, 7, 1])
        ->assertJsonPath('settings.licence_grace_days', 0);

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-documents', ['reminder_days' => [7, 0]])
        ->assertUnprocessable();

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-documents', ['reminder_days' => [3, 21]])
        ->assertOk()
        ->assertJsonPath('settings.reminder_days', [21, 3]);
});
