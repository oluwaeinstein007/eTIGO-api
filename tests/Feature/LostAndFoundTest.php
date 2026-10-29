<?php

use App\Contracts\SmsGateway;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\BalanceDueReason;
use App\Enums\LostItemStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\ProcessLostItemsJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\LostItem;
use App\Models\LostItemMessage;
use App\Models\Notification;
use App\Models\PassengerBalanceDue;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\LostItemService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 10:00:00', 'UTC'));

    $this->city = City::factory()->create(['timezone' => 'Africa/Lagos']);

    $this->passenger = User::factory()->create([
        'type' => UserType::Passenger,
        'first_name' => 'Ada',
        'phone' => '+2348010000001',
    ]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create([
        'type' => UserType::Driver,
        'first_name' => 'Musa',
        'phone' => '+2348020000002',
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id, 'city_id' => $this->city->id]);
    Vehicle::factory()->create(['driver_id' => $this->driver->id, 'plate_number' => 'LAG-123-XY', 'colour' => 'Silver']);

    $this->support = User::factory()->admin(AdminRole::Support)->create();
    $this->supportToken = $this->support->createToken('auth', ['admin'])->plainTextToken;

    $this->ride = Ride::factory()->completed()->create([
        'city_id' => $this->city->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'completed_at' => now()->subHours(2),
    ]);

    $this->sms = new class implements SmsGateway
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): bool
        {
            $this->sent[] = ['phone' => $phone, 'message' => $message];

            return true;
        }

        public function deliver(string $phone, string $message): ?string
        {
            $this->send($phone, $message);

            return 'sms';
        }

        public function sendOtp(string $phone, string $code, string $message): bool
        {
            return $this->send($phone, $message);
        }
    };
    $this->app->instance(SmsGateway::class, $this->sms);

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->report = fn (array $overrides = [], ?Ride $ride = null, ?string $token = null) => ($this->as)($token ?? $this->passengerToken)
        ->postJson('/api/v1/rides/'.($ride ?? $this->ride)->id.'/lost-items', array_merge([
            'category' => 'phone',
            'description' => 'Black iPhone in a blue case, left on the back seat.',
        ], $overrides));

    $this->reportedItem = function (array $overrides = []) {
        ($this->report)($overrides)->assertCreated();

        return LostItem::latest()->firstOrFail();
    };

    $this->respond = fn (LostItem $item, bool $found, ?string $note = null) => ($this->as)($this->driverToken)
        ->postJson("/api/v1/lost-items/{$item->id}/respond", ['found' => $found, 'note' => $note]);

    $this->newRide = fn () => Ride::factory()->completed()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->ride->vehicle_class_id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
    ])->id;

    $this->notified = fn (User $user, string $type) => Notification::where('user_id', $user->id)->where('type', $type)->count();

    $this->setting = fn (string $key, mixed $value) => AppSetting::setValue(
        "lost_and_found.{$key}",
        is_bool($value) ? ($value ? 'true' : 'false') : $value,
        'lost_and_found',
    );

    $this->wallet = fn (int $kobo) => Account::factory()->passengerWallet()->withBalance($kobo)->create([
        'owner_type' => (new User)->getMorphClass(),
        'owner_id' => $this->passenger->id,
    ]);
});

// ── Reporting ──

it('lets the passenger report a lost item and notifies the driver', function () {
    $response = ($this->report)();

    $response->assertCreated()
        ->assertJsonPath('lost_item.status', 'driver_notified')
        ->assertJsonPath('lost_item.category', 'phone')
        ->assertJsonPath('lost_item.reported_by', 'passenger')
        ->assertJsonPath('lost_item.viewer_role', 'passenger')
        ->assertJsonPath('lost_item.ride.vehicle.plate_number', 'LAG-123-XY')
        ->assertJsonPath('lost_item.driver.first_name', 'Musa')
        ->assertJsonPath('lost_item.contact', null)
        ->assertJsonPath('lost_item.available_actions', ['cancel', 'escalate', 'message']);

    expect($response->json('lost_item.reference_number'))->toStartWith('LF-');

    $item = LostItem::sole();
    expect($item->driver_notified_at)->not->toBeNull()
        ->and(($this->notified)($this->driverUser, 'lost_item_report'))->toBe(1)
        ->and(AuditLog::where('auditable_id', $item->id)->where('event', 'lost_item.reported')->exists())->toBeTrue();
});

it('validates the category and description', function () {
    ($this->report)(['category' => 'spaceship', 'description' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'description']);
});

it('only accepts reports for completed trips', function () {
    $this->ride->update(['status' => RideStatus::InProgress, 'completed_at' => null]);

    ($this->report)()->assertUnprocessable()
        ->assertJsonPath('message', 'You can only report a lost item for a completed trip.');
});

it('only accepts reports within the report window', function () {
    $this->ride->update(['completed_at' => now()->subDays(31)]);

    ($this->report)()->assertUnprocessable();

    ($this->setting)('report_window_days', 60);

    ($this->report)()->assertCreated();
});

it('refuses users who were not on the trip', function () {
    $stranger = User::factory()->create(['type' => UserType::Passenger]);

    ($this->report)([], null, $stranger->createToken('auth')->plainTextToken)->assertForbidden();
});

it('allows one open report per trip', function () {
    $item = ($this->reportedItem)();

    ($this->report)()->assertUnprocessable()
        ->assertJsonPath('message', 'You already have an open lost item report for this trip.');

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/cancel")->assertOk();

    ($this->report)()->assertCreated();
});

it('limits the open reports one passenger can have', function () {
    ($this->setting)('max_open_reports_per_passenger', 1);
    ($this->reportedItem)();

    $other = Ride::factory()->completed()->create(['passenger_id' => $this->passenger->id, 'driver_id' => $this->driverUser->id]);

    ($this->report)([], $other)->assertUnprocessable();
});

it('refuses new reports while Lost & Found is switched off', function () {
    ($this->setting)('enabled', false);

    ($this->report)()->assertUnprocessable();
});

it('stores photos and returns links to them', function () {
    Storage::fake(config('filesystems.uploads'));

    $response = ($this->report)(['photos' => [UploadedFile::fake()->create('bag.jpg', 100, 'image/jpeg')]]);

    $response->assertCreated();
    $path = LostItem::sole()->photos[0];
    Storage::disk(config('filesystems.uploads'))->assertExists($path);
    expect($path)->toStartWith("lost-items/{$this->ride->id}/")
        ->and($response->json('lost_item.photos'))->toHaveCount(1);
});

it('texts the other contact number when the driver has the item', function () {
    $item = ($this->reportedItem)(['contact_phone' => '08031234567']);

    expect($item->contact_phone)->toBe('+2348031234567');

    ($this->respond)($item, true)->assertOk();

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe('+2348031234567')
        ->and($this->sms->sent[0]['message'])->toContain($item->reference_number);
});

it('hands the report to support when the driver cannot be reached', function () {
    $this->driverUser->update(['is_active' => false]);

    ($this->report)()->assertCreated()
        ->assertJsonPath('lost_item.status', 'reported')
        ->assertJsonPath('lost_item.escalated', true);

    expect(LostItem::needsAttention()->count())->toBe(1)
        ->and(($this->notified)($this->driverUser, 'lost_item_report'))->toBe(0);
});

it('lets the driver hand in an item they found', function () {
    ($this->setting)('return_fee', 1500);

    $response = ($this->report)(['category' => 'bag', 'description' => 'Brown leather bag under the seat.'], null, $this->driverToken);

    $response->assertCreated()
        ->assertJsonPath('lost_item.status', 'driver_confirmed')
        ->assertJsonPath('lost_item.reported_by', 'driver')
        ->assertJsonPath('lost_item.viewer_role', 'driver')
        ->assertJsonPath('lost_item.return_fee.amount', 1500)
        ->assertJsonPath('lost_item.passenger.first_name', 'Ada')
        ->assertJsonPath('lost_item.available_actions', ['mark_returned', 'message']);

    expect(($this->notified)($this->passenger, 'lost_item_update'))->toBe(1);
});

it('shows the driver the rider on a third-party trip', function () {
    $this->ride->update(['is_third_party' => true, 'rider_name' => 'Chidi Okafor', 'rider_phone' => '+2348050000005']);

    ($this->report)()->assertCreated();

    ($this->as)($this->driverToken)->getJson('/api/v1/lost-items/'.LostItem::sole()->id)
        ->assertOk()
        ->assertJsonPath('lost_item.passenger.first_name', 'Chidi');
});

// ── Driver response ──

it('shares contact details once the driver confirms they have the item', function () {
    $item = ($this->reportedItem)(['contact_phone' => '+2348031234567']);

    ($this->respond)($item, true, 'It is with me, I am in Ikeja until 6pm.')
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'driver_confirmed')
        ->assertJsonPath('lost_item.contact.first_name', 'Ada')
        ->assertJsonPath('lost_item.contact.phone', '+2348031234567');

    ($this->as)($this->passengerToken)->getJson("/api/v1/lost-items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('lost_item.driver_response', 'It is with me, I am in Ikeja until 6pm.')
        ->assertJsonPath('lost_item.contact.phone', '+2348020000002')
        ->assertJsonPath('lost_item.available_actions', ['confirm_received', 'escalate', 'message']);

    expect(($this->notified)($this->passenger, 'lost_item_update'))->toBe(1);
});

it('keeps phone numbers private when contact sharing is off', function () {
    ($this->setting)('share_contact_details', false);
    $item = ($this->reportedItem)();

    ($this->respond)($item, true)->assertOk()->assertJsonPath('lost_item.contact', null);
});

it('lets a driver who did not find the item come back and say they found it', function () {
    $item = ($this->reportedItem)();

    ($this->respond)($item, false)->assertOk()->assertJsonPath('lost_item.status', 'driver_denied');
    ($this->respond)($item, false)->assertUnprocessable();
    ($this->respond)($item, true)->assertOk()->assertJsonPath('lost_item.status', 'driver_confirmed');
});

it('only lets the trip driver respond', function () {
    $item = ($this->reportedItem)();
    $otherDriver = User::factory()->create(['type' => UserType::Driver]);

    ($this->as)($otherDriver->createToken('auth')->plainTextToken)
        ->postJson("/api/v1/lost-items/{$item->id}/respond", ['found' => true])
        ->assertForbidden();

    ($this->as)($this->passengerToken)
        ->postJson("/api/v1/lost-items/{$item->id}/respond", ['found' => true])
        ->assertForbidden();
});

// ── Return ──

it('resolves when the driver returns the item and the passenger confirms', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->as)($this->driverToken)->postJson("/api/v1/lost-items/{$item->id}/returned")
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'returned');

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/received")
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'resolved')
        ->assertJsonPath('lost_item.is_open', false)
        ->assertJsonPath('lost_item.available_actions', [])
        ->assertJsonPath('lost_item.contact', null);

    expect($item->fresh()->returned_at)->not->toBeNull();
});

it('lets the passenger confirm receipt before the driver marks it returned', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/received")
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'resolved');
});

it('does not let the driver mark an item returned they never confirmed', function () {
    $item = ($this->reportedItem)();

    ($this->as)($this->driverToken)->postJson("/api/v1/lost-items/{$item->id}/returned")->assertUnprocessable();
});

it('lets the passenger cancel only before the driver has the item', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/cancel")->assertUnprocessable();

    $other = Ride::factory()->completed()->create(['passenger_id' => $this->passenger->id, 'driver_id' => $this->driverUser->id]);
    ($this->report)([], $other)->assertCreated();
    $second = LostItem::where('ride_id', $other->id)->sole();

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$second->id}/cancel", ['reason' => 'Found it at home'])
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'cancelled');

    expect(($this->notified)($this->driverUser, 'lost_item_update'))->toBe(1);
});

it('lets the passenger ask support to step in once', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, false);

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/escalate", ['reason' => 'I am sure it fell behind the seat.'])
        ->assertOk()
        ->assertJsonPath('lost_item.escalated', true)
        ->assertJsonPath('lost_item.escalation_reason', 'I am sure it fell behind the seat.');

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/escalate", ['reason' => 'Please help again.'])
        ->assertUnprocessable();

    expect(LostItem::needsAttention()->pluck('id')->all())->toBe([$item->id]);
});

// ── Messages and visibility ──

it('lets passenger and driver message each other and notifies the other side', function () {
    $item = ($this->reportedItem)();

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/messages", ['body' => 'Please check under the front seat.'])
        ->assertCreated()
        ->assertJsonPath('lost_item_message.sender_role', 'passenger')
        ->assertJsonPath('lost_item_message.is_mine', true);

    ($this->as)($this->driverToken)->postJson("/api/v1/lost-items/{$item->id}/messages", ['body' => 'Found it, where should I bring it?'])
        ->assertCreated();

    expect(($this->notified)($this->driverUser, 'lost_item_message'))->toBe(1)
        ->and(($this->notified)($this->passenger, 'lost_item_message'))->toBe(1);

    ($this->as)($this->passengerToken)->getJson("/api/v1/lost-items/{$item->id}")
        ->assertOk()
        ->assertJsonCount(2, 'lost_item.messages')
        ->assertJsonPath('lost_item.messages.1.sender_name', 'Musa')
        ->assertJsonPath('lost_item.messages.1.is_mine', false);
});

it('closes the conversation once the report is closed', function () {
    $item = ($this->reportedItem)();
    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/cancel");

    ($this->as)($this->driverToken)->postJson("/api/v1/lost-items/{$item->id}/messages", ['body' => 'Hello?'])
        ->assertUnprocessable();
});

it('hides reports from users who were not on the trip', function () {
    $item = ($this->reportedItem)();
    $stranger = User::factory()->create(['type' => UserType::Passenger]);
    $token = $stranger->createToken('auth')->plainTextToken;

    ($this->as)($token)->getJson("/api/v1/lost-items/{$item->id}")->assertNotFound();
    ($this->as)($token)->postJson("/api/v1/lost-items/{$item->id}/messages", ['body' => 'Hi'])->assertNotFound();
    ($this->as)($token)->getJson('/api/v1/lost-items')->assertOk()->assertJsonCount(0, 'lost_items');
});

it('lists the reports on the user\'s own trips', function () {
    $item = ($this->reportedItem)();

    ($this->as)($this->passengerToken)->getJson('/api/v1/lost-items')
        ->assertOk()
        ->assertJsonCount(1, 'lost_items')
        ->assertJsonPath('lost_items.0.id', $item->id);

    ($this->as)($this->driverToken)->getJson('/api/v1/lost-items?open=1')
        ->assertOk()
        ->assertJsonPath('lost_items.0.id', $item->id)
        ->assertJsonPath('lost_items.0.available_actions', ['respond', 'message']);

    ($this->as)($this->passengerToken)->getJson('/api/v1/lost-items?open=0')
        ->assertOk()
        ->assertJsonCount(0, 'lost_items');

    ($this->as)($this->passengerToken)->getJson("/api/v1/lost-items?ride_id={$this->ride->id}")
        ->assertOk()
        ->assertJsonCount(1, 'lost_items');
});

// ── Admin queue ──

it('gives support a queue with the reports that need attention first', function () {
    $quiet = ($this->reportedItem)();
    $other = Ride::factory()->completed()->create(['passenger_id' => $this->passenger->id, 'driver_id' => $this->driverUser->id]);
    ($this->report)(['category' => 'keys'], $other)->assertCreated();
    $urgent = LostItem::where('ride_id', $other->id)->sole();
    ($this->respond)($urgent, false);
    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$urgent->id}/escalate", ['reason' => 'Driver is wrong, please check.']);

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items')
        ->assertOk()
        ->assertJsonPath('lost_items.0.id', $urgent->id)
        ->assertJsonPath('lost_items.1.id', $quiet->id)
        ->assertJsonPath('lost_items.0.viewer_role', 'support')
        ->assertJsonPath('summary.open', 2)
        ->assertJsonPath('summary.needs_attention', 1);

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items?needs_attention=1')
        ->assertOk()
        ->assertJsonCount(1, 'lost_items');

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items?status=driver_notified,driver_denied&category=keys')
        ->assertOk()
        ->assertJsonCount(1, 'lost_items')
        ->assertJsonPath('lost_items.0.id', $urgent->id);

    ($this->as)($this->supportToken)->getJson("/api/v1/admin/lost-items?search={$quiet->reference_number}")
        ->assertOk()
        ->assertJsonCount(1, 'lost_items');
});

it('sorts the queue by open and escalated, then other open, then finished, newest first within each', function () {
    $make = fn (int $hoursAgo, array $attributes) => LostItem::factory()->create([
        ...$attributes,
        'ride_id' => ($this->newRide)(),
        'created_at' => now()->subHours($hoursAgo),
    ]);

    $oldEscalated = $make(50, ['status' => LostItemStatus::DriverDenied, 'escalated_at' => now()->subHours(40)]);
    $newEscalated = $make(30, ['status' => LostItemStatus::DriverConfirmed, 'escalated_at' => now()->subHours(20)]);
    $oldOpen = $make(40, ['status' => LostItemStatus::DriverNotified]);
    $newOpen = $make(20, ['status' => LostItemStatus::DriverConfirmed]);
    // Finished reports are newer, and some were escalated before they closed.
    $escalatedCancelled = $make(2, ['status' => LostItemStatus::Cancelled, 'cancelled_at' => now(), 'escalated_at' => now()->subHour()]);
    $cancelled = $make(1, ['status' => LostItemStatus::Cancelled, 'cancelled_at' => now()]);
    $resolved = $make(10, ['status' => LostItemStatus::Resolved, 'resolved_at' => now()]);

    $response = ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items')->assertOk();

    expect($response->json('lost_items.*.id'))->toBe([
        $newEscalated->id, $oldEscalated->id,
        $newOpen->id, $oldOpen->id,
        $cancelled->id, $escalatedCancelled->id, $resolved->id,
    ])->and($response->json('lost_items.*.needs_attention'))->toBe([true, true, false, false, false, false, false])
        ->and($response->json('summary.needs_attention'))->toBe(2);

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items?per_page=3&page=2')
        ->assertOk()
        ->assertJsonPath('lost_items.0.id', $oldOpen->id)
        ->assertJsonPath('lost_items.1.id', $cancelled->id);
});

it('breaks created_at ties in the queue by reference number, across pages', function () {
    $make = fn (string $reference) => LostItem::factory()->create([
        'ride_id' => ($this->newRide)(),
        'reference_number' => $reference,
        'created_at' => now()->subHour(),
    ]);

    $middle = $make('LF-900002');
    $last = $make('LF-900001');
    $first = $make('LF-900003');

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items?per_page=2')
        ->assertOk()
        ->assertJsonPath('lost_items.0.id', $first->id)
        ->assertJsonPath('lost_items.1.id', $middle->id);

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'lost_items')
        ->assertJsonPath('lost_items.0.id', $last->id);
});

it('lists the actions support can take at each status', function () {
    $actions = fn (LostItem $item) => ($this->as)($this->supportToken)->getJson("/api/v1/admin/lost-items/{$item->id}")
        ->assertOk()
        ->json('lost_item.available_actions');

    $before = ['note', 'message', 'mark_found', 'resolve', 'close'];
    $withDriver = ['note', 'message', 'resolve', 'close'];
    $finished = ['note', 'message'];

    $item = ($this->reportedItem)();
    ($this->as)($this->supportToken)->getJson('/api/v1/admin/lost-items')
        ->assertJsonPath('lost_items.0.available_actions', $before);

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'mark_found'])
        ->assertOk()
        ->assertJsonPath('lost_item.available_actions', $withDriver);

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'mark_found'])
        ->assertUnprocessable();

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'close', 'closure_reason' => 'unclaimed'])
        ->assertOk()
        ->assertJsonPath('lost_item.available_actions', $finished);

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'note', 'body' => 'Passenger called after closing.'])
        ->assertCreated()
        ->assertJsonPath('lost_item.available_actions', $finished);

    ($this->as)($this->passengerToken)->getJson("/api/v1/lost-items/{$item->id}")
        ->assertJsonPath('lost_item.available_actions', [])
        ->assertJsonMissingPath('lost_item.needs_attention');

    foreach ([
        [LostItemStatus::Reported, $before],
        [LostItemStatus::DriverNotified, $before],
        [LostItemStatus::DriverDenied, $before],
        [LostItemStatus::DriverConfirmed, $withDriver],
        [LostItemStatus::Returned, $withDriver],
        [LostItemStatus::Resolved, $finished],
        [LostItemStatus::Unresolved, $finished],
        [LostItemStatus::Cancelled, $finished],
    ] as [$status, $expected]) {
        expect($actions(LostItem::factory()->create(['status' => $status, 'ride_id' => ($this->newRide)()])))->toBe($expected, $status->value);
    }
});

it('restricts the admin queue to support and operations', function () {
    $finance = User::factory()->admin(AdminRole::Finance)->create();

    ($this->as)($finance->createToken('auth')->plainTextToken)->getJson('/api/v1/admin/lost-items')->assertForbidden();

    $operations = User::factory()->admin(AdminRole::Operations)->create();
    ($this->as)($operations->createToken('auth')->plainTextToken)->getJson('/api/v1/admin/lost-items')->assertOk();
});

it('keeps support notes internal and sends support messages to both sides', function () {
    $item = ($this->reportedItem)();

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'note', 'body' => 'Called driver, no answer.'])
        ->assertCreated()
        ->assertJsonPath('lost_item_message.is_internal', true);

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'message', 'body' => 'We are looking into this.'])
        ->assertCreated();

    expect(($this->notified)($this->passenger, 'lost_item_message'))->toBe(1)
        ->and(($this->notified)($this->driverUser, 'lost_item_message'))->toBe(1);

    ($this->as)($this->passengerToken)->getJson("/api/v1/lost-items/{$item->id}")
        ->assertOk()
        ->assertJsonCount(1, 'lost_item.messages')
        ->assertJsonPath('lost_item.messages.0.sender_name', 'E-tiGo Support')
        ->assertJsonMissingPath('lost_item.messages.0.is_internal');

    ($this->as)($this->supportToken)->getJson("/api/v1/admin/lost-items/{$item->id}")
        ->assertOk()
        ->assertJsonCount(2, 'lost_item.messages');
});

it('lets support mark an item found, resolve or close a report', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, false);

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'mark_found', 'notes' => 'Driver found it in the boot.'])
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'driver_confirmed');

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'resolve', 'notes' => 'Collected at the hub.'])
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'resolved')
        ->assertJsonPath('lost_item.resolution_notes', 'Collected at the hub.');

    expect($item->fresh()->resolved_by_admin_id)->toBe($this->support->id)
        ->and(LostItemMessage::where('lost_item_id', $item->id)->where('is_internal', true)->count())->toBe(1);

    $other = Ride::factory()->completed()->create(['passenger_id' => $this->passenger->id, 'driver_id' => $this->driverUser->id]);
    ($this->report)([], $other)->assertCreated();
    $second = LostItem::where('ride_id', $other->id)->sole();

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$second->id}/intervene", ['action' => 'close'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('closure_reason');

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$second->id}/intervene", ['action' => 'close', 'closure_reason' => 'driver_unreachable'])
        ->assertOk()
        ->assertJsonPath('lost_item.status', 'unresolved')
        ->assertJsonPath('lost_item.closure_reason', 'driver_unreachable');

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$second->id}/intervene", ['action' => 'resolve'])
        ->assertUnprocessable();
});

// ── Return fee ──

it('takes the return fee from the wallet and pays the driver in full', function () {
    ($this->setting)('return_fee', 2000);
    ($this->wallet)(500_000);
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->setting)('return_fee', 9000); // a later change does not touch this report

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/received")
        ->assertOk()
        ->assertJsonPath('lost_item.return_fee.amount', 2000)
        ->assertJsonPath('lost_item.return_fee.status', 'charged');

    $wallet = Account::where('owner_id', $this->passenger->id)->where('type', AccountType::PassengerWallet)->sole();
    $earnings = Account::where('owner_id', $this->driver->id)->where('type', AccountType::DriverEarningsAvailable)->sole();

    expect($wallet->fresh()->balance)->toBe(300_000)
        ->and($earnings->balance)->toBe(200_000);
});

it('records the return fee as a balance due when the wallet cannot pay it', function () {
    ($this->setting)('return_fee', 2000);
    ($this->wallet)(100);
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/received")
        ->assertOk()
        ->assertJsonPath('lost_item.return_fee.status', 'balance_due');

    $balance = PassengerBalanceDue::sole();
    expect($balance->reason)->toBe(BalanceDueReason::LostItemReturnFee)
        ->and((float) $balance->amount)->toBe(2000.0)
        ->and($balance->payee_driver_id)->toBe($this->driverUser->id);

    // Paying the balance passes the fee on and closes it on the report.
    Account::where('owner_id', $this->passenger->id)->where('type', AccountType::PassengerWallet)->update(['balance' => 300_000]);

    ($this->as)($this->passengerToken)->postJson('/api/v1/passenger/balance-due/pay', ['payment_method' => 'wallet'])->assertOk();

    expect($item->fresh()->return_fee_status)->toBe('charged');
});

it('lets support waive the return fee when resolving', function () {
    ($this->setting)('return_fee', 2000);
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->as)($this->supportToken)->postJson("/api/v1/admin/lost-items/{$item->id}/intervene", ['action' => 'resolve', 'waive_return_fee' => true])
        ->assertOk()
        ->assertJsonPath('lost_item.return_fee.status', 'waived');

    expect(PassengerBalanceDue::count())->toBe(0);
});

it('charges no fee when the return fee is off', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/received")
        ->assertOk()
        ->assertJsonPath('lost_item.return_fee.amount', 0)
        ->assertJsonPath('lost_item.return_fee.status', null);
});

// ── Hourly sweep ──

it('charges one return fee per trip, however the first was collected', function (string $firstVia, string $secondVia) {
    ($this->setting)('return_fee', 2000);
    $wallet = ($this->wallet)(0);
    $fund = fn (string $via) => $wallet->update(['balance' => $via === 'wallet' ? 500_000 : 0]);

    $first = ($this->reportedItem)();
    ($this->respond)($first, true);
    $fund($firstVia);
    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$first->id}/received")
        ->assertJsonPath('lost_item.return_fee.status', $firstVia === 'wallet' ? 'charged' : 'balance_due');

    ($this->report)(['category' => 'keys'])->assertCreated();
    $second = LostItem::where('category', 'keys')->sole();
    ($this->respond)($second, true);
    $fund($secondVia);
    $balanceBefore = $wallet->fresh()->balance;

    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$second->id}/received")
        ->assertOk()
        ->assertJsonPath('lost_item.return_fee.status', 'waived');

    expect($wallet->fresh()->balance)->toBe($balanceBefore)
        ->and(PassengerBalanceDue::count())->toBe($firstVia === 'wallet' ? 0 : 1);
})->with([
    'balance due, then balance due' => ['balance_due', 'balance_due'],
    'wallet, then wallet' => ['wallet', 'wallet'],
    'wallet, then balance due' => ['wallet', 'balance_due'],
    'balance due, then wallet' => ['balance_due', 'wallet'],
]);

it('hands silent drivers to support and reminds them', function () {
    $item = ($this->reportedItem)();

    $this->travel(23)->hours();
    (new ProcessLostItemsJob)->handle(app(LostItemService::class));
    expect($item->fresh()->escalated_at)->toBeNull();

    $this->travel(2)->hours();
    (new ProcessLostItemsJob)->handle(app(LostItemService::class));

    expect($item->fresh()->escalated_at)->not->toBeNull()
        ->and($item->fresh()->escalation_source)->toBe('system')
        ->and(($this->notified)($this->driverUser, 'lost_item_report'))->toBe(2)
        ->and(($this->notified)($this->passenger, 'lost_item_update'))->toBe(1);

    // The driver answering takes it back off support's list.
    ($this->respond)($item, true)->assertOk();
    expect($item->fresh()->escalated_at)->toBeNull();
});

it('resolves an undisputed return after the auto-resolve period', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);
    ($this->as)($this->driverToken)->postJson("/api/v1/lost-items/{$item->id}/returned");

    $this->travel(73)->hours();
    app(LostItemService::class)->processOverdue();

    expect($item->fresh()->status)->toBe(LostItemStatus::Resolved)
        ->and(Notification::where('user_id', $this->passenger->id)->where('title', 'Lost item report resolved')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->driverUser->id)->where('title', 'Item returned')->exists())->toBeTrue();
});

it('does not auto-resolve a return the passenger disputes', function () {
    $item = ($this->reportedItem)();
    ($this->respond)($item, true);
    ($this->as)($this->driverToken)->postJson("/api/v1/lost-items/{$item->id}/returned");
    ($this->as)($this->passengerToken)->postJson("/api/v1/lost-items/{$item->id}/escalate", ['reason' => 'I never got it back.'])->assertOk();

    $this->travel(100)->hours();
    app(LostItemService::class)->processOverdue();

    expect($item->fresh()->status)->toBe(LostItemStatus::Returned);
});

it('closes "not found" reports nobody followed up and flags overdue returns', function () {
    $denied = ($this->reportedItem)();
    ($this->respond)($denied, false);

    $other = Ride::factory()->completed()->create(['passenger_id' => $this->passenger->id, 'driver_id' => $this->driverUser->id]);
    ($this->report)([], $other)->assertCreated();
    $confirmed = LostItem::where('ride_id', $other->id)->sole();
    ($this->respond)($confirmed, true);

    $this->travel(8)->days();
    $counts = app(LostItemService::class)->processOverdue();

    expect($counts['auto_closed'])->toBe(1)
        ->and($counts['return_overdue'])->toBe(1)
        ->and($denied->fresh()->status)->toBe(LostItemStatus::Unresolved)
        ->and($denied->fresh()->closure_reason?->value)->toBe('not_found')
        ->and($confirmed->fresh()->escalated_at)->not->toBeNull()
        ->and(Notification::where('user_id', $this->passenger->id)->where('title', 'Lost item report closed')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->driverUser->id)->where('title', 'Lost item still to return')->exists())->toBeTrue();
});

// ── Settings and config ──

it('lets operations change the Lost & Found settings', function () {
    $operations = User::factory()->admin(AdminRole::Operations)->create();
    $token = $operations->createToken('auth')->plainTextToken;

    ($this->as)($token)->getJson('/api/v1/admin/settings/lost-and-found')
        ->assertOk()
        ->assertJsonPath('settings.report_window_days', 30)
        ->assertJsonPath('settings.return_fee', 0);

    ($this->as)($token)->putJson('/api/v1/admin/settings/lost-and-found', ['return_fee' => 1500, 'share_contact_details' => false])
        ->assertOk()
        ->assertJsonPath('settings.return_fee', 1500)
        ->assertJsonPath('settings.share_contact_details', false);

    ($this->as)($token)->putJson('/api/v1/admin/settings/lost-and-found', ['report_window_days' => 0])
        ->assertUnprocessable();

    expect(AuditLog::where('event', 'lost_and_found_settings.updated')->count())->toBe(1);

    ($this->as)($this->supportToken)->getJson('/api/v1/admin/settings/lost-and-found')->assertForbidden();
});

it('tells the apps the Lost & Found rules in /config', function () {
    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('lost_and_found.enabled', true)
        ->assertJsonPath('lost_and_found.report_window_days', 30)
        ->assertJsonPath('lost_and_found.categories.0.value', 'phone');
});
