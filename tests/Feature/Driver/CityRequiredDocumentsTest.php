<?php

use App\Enums\AdminRole;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\UserType;
use App\Jobs\ProcessDriverDocumentExpiryJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Notification;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\NotificationService;
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

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('removeDriver')->andReturnNull();
    $locations->shouldReceive('getDriverLocation')->andReturnNull();
    $this->app->instance(DriverLocationService::class, $locations);

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $this->lagos = City::factory()->create(['name' => 'Lagos', 'slug' => 'lagos', 'required_driver_documents' => ['lasdri_card', 'lassra_card']]);
    $this->abuja = City::factory()->create(['name' => 'Abuja', 'slug' => 'abuja']);

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->paper = fn (Driver $driver, DocumentType $type, ?string $expiresAt = null, DocumentStatus $status = DocumentStatus::Approved) => DriverDocument::create([
        'driver_id' => $driver->id,
        'type' => $type,
        'file_path' => 'driver-documents/'.fake()->uuid().'.pdf',
        'original_filename' => 'paper.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1000,
        'expires_at' => $expiresAt,
        'status' => $status,
        'reviewed_at' => $status === DocumentStatus::Approved ? now() : null,
    ]);

    $this->workingDriver = function (City $city): Driver {
        $user = User::factory()->create(['type' => UserType::Driver]);

        return Driver::factory()->approved()->create(['user_id' => $user->id, 'city_id' => $city->id]);
    };

    $this->runJob = fn () => app()->call([new ProcessDriverDocumentExpiryJob, 'handle']);
    $this->required = fn (Driver $driver) => Notification::where('user_id', $driver->user_id)->where('type', 'document_required')->get();
});

it('asks Lagos applicants for the LASDRI and LASSRA cards, and nobody else', function () {
    $lagosDriver = Driver::factory()->create(['status' => 'onboarding', 'city_id' => $this->lagos->id, 'vehicle_ownership_type' => 'fleet_vehicle']);
    $abujaDriver = Driver::factory()->create(['status' => 'onboarding', 'city_id' => $this->abuja->id, 'vehicle_ownership_type' => 'fleet_vehicle']);
    $lagosToken = $lagosDriver->user->createToken('a', ['driver'])->plainTextToken;

    ($this->as)($lagosToken)->getJson('/api/v1/driver/documents')
        ->assertOk()
        ->assertJsonPath('total_required', 4)
        ->assertJsonPath('documents.2.type', 'lasdri_card')
        ->assertJsonPath('documents.2.name', 'LASDRI card')
        ->assertJsonPath('documents.2.requires_expiry', true)
        ->assertJsonPath('documents.3.type', 'lassra_card')
        ->assertJsonPath('documents.3.requires_expiry', false);

    ($this->as)($lagosToken)->getJson('/api/v1/driver/onboarding/status')
        ->assertJsonPath('required_documents', ['driving_licence', 'government_id', 'lasdri_card', 'lassra_card']);

    ($this->as)($abujaDriver->user->createToken('a', ['driver'])->plainTextToken)->getJson('/api/v1/driver/documents')
        ->assertJsonPath('total_required', 2);
});

it('will not let a Lagos applicant submit without the cards, and needs the LASDRI expiry date', function () {
    $driver = Driver::factory()->create(['status' => 'onboarding', 'city_id' => $this->lagos->id, 'vehicle_ownership_type' => 'fleet_vehicle', 'licence_number' => 'LAG1']);
    ($this->paper)($driver, DocumentType::DrivingLicence, '2030-01-01', DocumentStatus::Pending);
    ($this->paper)($driver, DocumentType::GovernmentId, null, DocumentStatus::Pending);
    $token = $driver->user->createToken('a', ['driver'])->plainTextToken;
    $upload = fn (string $type, array $extra = []) => ($this->as)($token)->postJson('/api/v1/driver/documents', [
        'type' => $type,
        'document' => UploadedFile::fake()->create("{$type}.pdf", 100, 'application/pdf'),
        ...$extra,
    ]);

    ($this->as)($token)->postJson('/api/v1/driver/onboarding/submit')
        ->assertUnprocessable()
        ->assertJsonFragment(['Missing documents: lasdri_card, lassra_card']);

    $upload('lasdri_card')->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $upload('lasdri_card', ['expires_at' => '2027-06-30'])->assertCreated();
    $upload('lassra_card')->assertCreated();

    ($this->as)($token)->postJson('/api/v1/driver/onboarding/submit')->assertOk();
});

it('reminds working Lagos drivers about missing cards every 14 days, without blocking them', function () {
    $driver = ($this->workingDriver)($this->lagos);
    ($this->paper)($driver, DocumentType::LassraCard);

    ($this->runJob)();
    ($this->runJob)();

    expect(($this->required)($driver))->toHaveCount(1)
        ->and(($this->required)($driver)->first()->body)->toBe('Drivers in Lagos need their LASDRI card on E-tiGo. Upload it in the app under Documents.')
        ->and($driver->fresh()->isDocumentsBlocked())->toBeFalse();

    ($this->as)($driver->user->createToken('a', ['driver'])->plainTextToken)->getJson('/api/v1/driver/documents')
        ->assertJsonPath('compliance.can_drive', true)
        ->assertJsonPath('compliance.action_required', true)
        ->assertJsonFragment(['type' => 'lasdri_card', 'missing' => true, 'required_by_city' => true]);

    $this->travelTo(now()->addDays(14));
    ($this->runJob)();
    expect(($this->required)($driver))->toHaveCount(2);

    // Uploaded, even while pending: no more reminders.
    ($this->paper)($driver, DocumentType::LasdriCard, '2027-06-30', DocumentStatus::Pending);
    $this->travelTo(now()->addDays(14));
    ($this->runJob)();
    expect(($this->required)($driver))->toHaveCount(2);
});

it('does not remind drivers in cities that require no extra papers', function () {
    $driver = ($this->workingDriver)($this->abuja);

    ($this->runJob)();

    expect(($this->required)($driver))->toBeEmpty();
});

it('reminds about an expiring LASDRI card like any dated paper, without blocking', function () {
    $driver = ($this->workingDriver)($this->lagos);
    ($this->paper)($driver, DocumentType::LasdriCard, '2026-11-01');
    ($this->paper)($driver, DocumentType::LassraCard);

    ($this->runJob)();

    expect(Notification::where('user_id', $driver->user_id)->where('type', 'document_expiring')->first()->body)
        ->toBe('Your LASDRI card expired on 1 Nov 2026. Upload the new one to keep your records up to date.')
        ->and($driver->fresh()->isDocumentsBlocked())->toBeFalse();
});

it('lets operations set a city\'s required papers, LASDRI and LASSRA only', function () {
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/cities/{$this->abuja->id}", ['required_driver_documents' => ['lassra_card']])
        ->assertOk();
    expect($this->abuja->fresh()->required_driver_documents)->toBe(['lassra_card']);

    ($this->as)($this->adminToken)->putJson("/api/v1/admin/cities/{$this->abuja->id}", ['required_driver_documents' => ['driving_licence']])
        ->assertUnprocessable();

    // A list only, not an object keyed by the values.
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/cities/{$this->abuja->id}", ['required_driver_documents' => ['a' => 'lasdri_card']])
        ->assertUnprocessable();

    ($this->as)($this->adminToken)->putJson("/api/v1/admin/cities/{$this->abuja->id}", ['required_driver_documents' => []])
        ->assertOk();
    expect($this->abuja->fresh()->required_driver_documents)->toBe([]);
});

it('reminds a driver moved to another city at the next run', function () {
    $driver = ($this->workingDriver)($this->abuja);
    $driver->forceFill(['city_documents_reminded_at' => now()])->save();

    ($this->as)($this->adminToken)->putJson("/api/v1/admin/drivers/{$driver->id}/city", ['city_id' => $this->lagos->id, 'reason' => 'Moved to Lagos'])->assertOk();

    expect($driver->fresh()->city_documents_reminded_at)->toBeNull();
    ($this->runJob)();
    expect(($this->required)($driver))->toHaveCount(1);
});

it('shows a city card as missing when its only upload was rejected', function () {
    $driver = ($this->workingDriver)($this->lagos);
    ($this->paper)($driver, DocumentType::LassraCard);
    $rejected = ($this->paper)($driver, DocumentType::LasdriCard, '2027-06-30', DocumentStatus::Rejected);
    $rejected->update(['rejection_reason' => 'Blurry.']);

    ($this->as)($driver->user->createToken('a', ['driver'])->plainTextToken)->getJson('/api/v1/driver/documents')
        ->assertJsonFragment(['type' => 'lasdri_card', 'missing' => true, 'required_by_city' => true]);
});

it('retries a city document reminder that failed at the next run', function () {
    $driver = ($this->workingDriver)($this->lagos);
    $calls = 0;
    $service = Mockery::mock(NotificationService::class);
    $service->shouldReceive('send')->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('notifications table down');
        }

        return null;
    });
    $this->app->instance(NotificationService::class, $service);

    ($this->runJob)();
    expect($driver->fresh()->city_documents_reminded_at)->toBeNull();

    ($this->runJob)();
    expect($driver->fresh()->city_documents_reminded_at)->not->toBeNull()
        ->and($calls)->toBe(2);
});
