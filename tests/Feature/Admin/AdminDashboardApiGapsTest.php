<?php

use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\PayoutStatus;
use App\Jobs\DailyReconciliationJob;
use App\Models\Account;
use App\Models\Adjustment;
use App\Models\BankAccount;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\GamificationProfile;
use App\Models\Payout;
use App\Models\PromoCode;
use App\Models\TripCarbonScore;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\LedgerService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->finance = User::factory()->finance()->create();
    $this->token = $this->finance->createToken('auth', ['admin'])->plainTextToken;

    $this->driver = Driver::factory()->approved()->create();
    $this->driver->user->update(['first_name' => 'Emeka', 'last_name' => 'Nwosu', 'phone' => '+2348055550000']);
    $this->driverAccount = Account::factory()->driverEarningsAvailable()->create([
        'owner_type' => (new Driver)->getMorphClass(),
        'owner_id' => $this->driver->id,
    ]);
});

/*
| Payouts
*/

it('gives payouts a reference, a page of their own and who rejected them', function () {
    $payout = Payout::factory()->create(['driver_id' => $this->driver->id]);

    expect($payout->reference_number)->toMatch('/^PO-\d{6}$/');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/reject", ['reason' => 'Bank details do not match the driver'])
        ->assertOk()
        ->assertJsonPath('payout.status', 'rejected')
        ->assertJsonPath('payout.rejected_by.id', $this->finance->id);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/payouts/{$payout->id}")
        ->assertOk()
        ->assertJsonPath('payout.reference_number', $payout->reference_number)
        ->assertJsonPath('payout.failure_reason', 'Bank details do not match the driver')
        ->assertJsonPath('payout.rejected_by.id', $this->finance->id);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/payouts?search='.$payout->reference_number)
        ->assertJsonCount(1, 'payouts');
});

it('reads payout dates as Lagos days', function () {
    // 00:30 in Lagos on 21 October is 23:30 UTC on the 20th.
    $payout = Payout::factory()->create(['requested_at' => Carbon::parse('2026-10-20 23:30:00', 'UTC')]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/payouts?from=2026-10-21&to=2026-10-21')
        ->assertJsonCount(1, 'payouts')
        ->assertJsonPath('payouts.0.id', $payout->id);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/payouts?from=2026-10-20&to=2026-10-20')
        ->assertJsonCount(0, 'payouts');
});

it('reads ledger dates as Lagos days and names the account of every entry', function () {
    $passenger = User::factory()->passenger()->create(['first_name' => 'Ngozi', 'last_name' => 'Ade']);
    $wallet = Account::factory()->passengerWallet()->create(['owner_id' => $passenger->id]);
    $clearing = app(LedgerService::class)->systemAccount(AccountType::PspClearing);

    $this->travelTo(Carbon::parse('2026-10-20 23:30:00', 'UTC'));
    app(LedgerService::class)->postJournal([
        ['account_id' => $clearing->id, 'type' => 'debit', 'amount' => 5000],
        ['account_id' => $wallet->id, 'type' => 'credit', 'amount' => 5000],
    ], ['description' => 'Wallet top-up via Paystack: TOPUP-LAGOS', 'idempotency_key' => 'topup-lagos']);
    $this->travelBack();

    $entries = collect($this->withToken($this->token)
        ->getJson('/api/v1/admin/ledger?from=2026-10-21&to=2026-10-21')
        ->assertOk()
        ->assertJsonCount(1, 'journals')
        ->json('journals.0.entries'))->keyBy('account.id');

    expect($entries[$wallet->id]['account']['owner_type'])->toBe('passenger')
        ->and($entries[$wallet->id]['account']['owner']['name'])->toBe('Ngozi Ade')
        ->and($entries[$clearing->id]['account']['owner_type'])->toBe('system')
        ->and($entries[$clearing->id]['account']['owner'])->toBeNull();

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/ledger?from=2026-10-20&to=2026-10-20')
        ->assertJsonCount(0, 'journals');
});

/*
| Adjustments and the wallet audit trail
*/

it('names whose account an adjustment is on and who rejected it', function () {
    $adjustment = Adjustment::factory()->create(['account_id' => $this->driverAccount->id]);

    expect($adjustment->reference_number)->toMatch('/^AD-\d{6}$/');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/adjustments/{$adjustment->id}/reject", ['reason' => 'Duplicate of an earlier request'])
        ->assertOk()
        ->assertJsonPath('adjustment.rejection_reason', 'Duplicate of an earlier request')
        ->assertJsonPath('adjustment.rejected_by.id', $this->finance->id);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/adjustments/{$adjustment->id}")
        ->assertOk()
        ->assertJsonPath('adjustment.account.owner_type', 'driver')
        ->assertJsonPath('adjustment.account.owner.id', $this->driver->id)
        ->assertJsonPath('adjustment.account.owner.name', 'Emeka Nwosu');

    foreach (['Emeka', $adjustment->reference_number, '+2348055550000'] as $search) {
        $this->withToken($this->token)
            ->getJson('/api/v1/admin/adjustments?search='.urlencode($search))
            ->assertJsonCount(1, 'adjustments');
    }

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/adjustments?owner_type=passenger')
        ->assertJsonCount(0, 'adjustments');
});

it('lists an adjustment on one of E-tiGo\'s own accounts without failing', function () {
    $system = app(LedgerService::class)->systemAccount(AccountType::Refunds);
    Adjustment::factory()->create(['account_id' => $system->id]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/adjustments')
        ->assertOk()
        ->assertJsonPath('adjustments.0.account.owner_type', 'system')
        ->assertJsonPath('adjustments.0.account.owner', null);
});

it('creates adjustments on driver ledgers and passenger wallets only', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/adjustments', [
            'account_id' => $this->driverAccount->id,
            'type' => 'credit',
            'amount' => 150000,
            'reason' => 'Missed fare from a ride on Monday',
        ])
        ->assertCreated()
        ->assertJsonPath('adjustment.account.owner.name', 'Emeka Nwosu');

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/adjustments', [
            'account_id' => app(LedgerService::class)->systemAccount(AccountType::PlatformCommission)->id,
            'type' => 'credit',
            'amount' => 150000,
            'reason' => 'Should not be allowed here',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_id');
});

it('says whether a wallet adjustment added or took money', function () {
    $passenger = User::factory()->passenger()->create();
    $wallet = Account::factory()->passengerWallet()->create(['owner_id' => $passenger->id]);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/adjustments', [
            'account_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 150000,
            'reason' => 'Reverse a top-up credited twice',
        ])
        ->assertCreated();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$wallet->id}/audit-trail")
        ->assertOk()
        ->assertJsonPath('entries.0.amount', 150000)
        ->assertJsonPath('entries.0.direction', 'debit')
        ->assertJsonPath('entries.0.adjustment.status', 'pending');
});

/*
| Driver ledgers
*/

it('names the driver on each ledger and pages through older entries and payouts', function () {
    Payout::factory()->count(12)->create(['driver_id' => $this->driver->id]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/driver-ledgers')
        ->assertOk()
        ->assertJsonPath('driver_ledgers.0.owner_type', 'driver')
        ->assertJsonPath('driver_ledgers.0.owner.name', 'Emeka Nwosu')
        ->assertJsonPath('driver_ledgers.0.owner.user_id', $this->driver->user_id);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/driver-ledgers/{$this->driverAccount->id}")
        ->assertOk()
        ->assertJsonPath('driver_ledger.owner.phone', '+2348055550000')
        ->assertJsonCount(10, 'recent_payouts');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/driver-ledgers/{$this->driverAccount->id}/payouts?page=2&per_page=10")
        ->assertOk()
        ->assertJsonCount(2, 'payouts')
        ->assertJsonPath('meta.total', 12);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/driver-ledgers/{$this->driverAccount->id}/entries")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $wallet = Account::factory()->passengerWallet()->create();
    $this->withToken($this->token)
        ->getJson("/api/v1/admin/driver-ledgers/{$wallet->id}/entries")
        ->assertNotFound();
});

/*
| Reconciliation
*/

it('lists the transactions behind a reconciliation mismatch', function () {
    $day = Carbon::parse('2026-10-20 12:00:00', 'Africa/Lagos');

    WebhookEvent::create([
        'provider' => 'paystack',
        'event_id' => 'evt-missing',
        'event_type' => 'charge.success',
        'payload' => ['event' => 'charge.success', 'data' => ['reference' => 'TOPUP-NOCREDIT', 'amount' => 50000, 'status' => 'success']],
        'signature' => 'test',
        'processed_at' => $day,
        'created_at' => $day,
    ]);
    $payout = Payout::factory()->create([
        'status' => PayoutStatus::Paid,
        'amount' => 70000,
        'gateway_reference' => null,
        'paid_at' => $day,
    ]);

    (new DailyReconciliationJob('2026-10-20'))->handle();

    $mismatches = collect($this->withToken($this->token)
        ->getJson('/api/v1/admin/reports/reconciliation?date=2026-10-20')
        ->assertOk()
        ->json('report.mismatches'))->keyBy('type');

    expect($mismatches['charges']['items'][0])->toMatchArray([
        'reference' => 'TOPUP-NOCREDIT',
        'issue' => 'missing_in_ledger',
        'gateway_amount' => 50000,
        'ledger_amount' => null,
    ])->and($mismatches['transfers']['items'][0])->toMatchArray([
        'reference' => "PAYOUT-{$payout->id}",
        'issue' => 'missing_at_gateway',
        'payout_id' => $payout->id,
        'payout_reference' => $payout->reference_number,
    ]);
});

/*
| Payout accounts, commission and tips
*/

it('finds a payout account by its account number', function () {
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id, 'account_number' => '0123456789']);
    BankAccount::factory()->create(['account_number' => '9999999999']);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/payout-accounts?search=23456')
        ->assertOk()
        ->assertJsonCount(1, 'payout_accounts')
        ->assertJsonPath('payout_accounts.0.id', $account->id);
});

it('names drivers on their own commission rate and moves them back to the global rate', function () {
    CommissionConfig::create(['rate' => 0.15, 'driver_id' => null, 'is_active' => true]);
    CommissionConfig::create(['rate' => 0.10, 'driver_id' => $this->driver->id, 'is_active' => true]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/settings/commission')
        ->assertOk()
        ->assertJsonPath('overrides.0.driver.name', 'Emeka Nwosu');

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/settings/commission/drivers/{$this->driver->id}")
        ->assertOk()
        ->assertJsonPath('global_rate', 0.15);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/settings/commission')
        ->assertJsonCount(0, 'overrides');

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/settings/commission/drivers/{$this->driver->id}")
        ->assertNotFound();
});

it('puts tip setting errors on the field that caused them', function () {
    $this->withToken($this->token)
        ->putJson('/api/v1/admin/settings/tips', ['min_amount' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('min_amount');

    $this->withToken($this->token)
        ->putJson('/api/v1/admin/settings/tips', ['min_amount' => 100, 'max_amount' => 1000, 'presets' => [200, 5000]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('presets.1')
        ->assertJsonMissingValidationErrors('presets.0');

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/settings/tips')
        ->assertJsonPath('limits.preset_min', 50);
});

/*
| Promos and gamification (Operations)
*/

it('includes promos for every city when filtering by city', function () {
    $ops = User::factory()->admin(AdminRole::Operations)->create();
    $lekki = City::factory()->create();
    $other = City::factory()->create();

    $everywhere = PromoCode::factory()->create();
    $lekkiOnly = PromoCode::factory()->inCities($lekki)->create();
    PromoCode::factory()->inCities($other)->create();

    $ids = collect($this->actingAs($ops)->getJson("/api/v1/admin/promos?city_id={$lekki->id}")->assertOk()->json('promos'))->pluck('id');
    expect($ids->sort()->values()->all())->toBe(collect([$everywhere->id, $lekkiOnly->id])->sort()->values()->all());

    $this->actingAs($ops)
        ->getJson("/api/v1/admin/promos?city_id={$lekki->id}&include_all_cities=0")
        ->assertJsonCount(1, 'promos')
        ->assertJsonPath('promos.0.id', $lekkiOnly->id);
});

it('ranks passengers by what they earned in the page\'s date range', function () {
    $ops = User::factory()->admin(AdminRole::Operations)->create();
    $allTimeLeader = GamificationProfile::factory()->create(['total_ranking_points' => 9000, 'user_id' => User::factory()->passenger()]);
    $weekLeader = GamificationProfile::factory()->create(['total_ranking_points' => 100, 'user_id' => User::factory()->passenger()]);

    TripCarbonScore::factory()->create(['user_id' => $allTimeLeader->user_id, 'final_points' => 500, 'created_at' => Carbon::parse('2026-09-01', 'UTC')]);
    TripCarbonScore::factory()->create(['user_id' => $allTimeLeader->user_id, 'final_points' => 10, 'created_at' => Carbon::parse('2026-10-22 12:00', 'UTC')]);
    TripCarbonScore::factory()->create(['user_id' => $weekLeader->user_id, 'final_points' => 80, 'created_at' => Carbon::parse('2026-10-23 12:00', 'UTC')]);

    $this->actingAs($ops)
        ->getJson('/api/v1/admin/gamification/users?from=2026-10-20&to=2026-10-26')
        ->assertOk()
        ->assertJsonPath('period', ['from' => '2026-10-20', 'to' => '2026-10-26'])
        ->assertJsonCount(2, 'profiles')
        ->assertJsonPath('profiles.0.user_id', $weekLeader->user_id)
        ->assertJsonPath('profiles.0.period.points', 80)
        ->assertJsonPath('profiles.1.period.points', 10);

    $this->actingAs($ops)
        ->getJson('/api/v1/admin/gamification/users')
        ->assertJsonPath('period', null)
        ->assertJsonPath('profiles.0.user_id', $allTimeLeader->user_id)
        ->assertJsonMissingPath('profiles.0.period');
});
