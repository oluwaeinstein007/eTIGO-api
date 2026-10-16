<?php

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\BankAccountNameMatch;
use App\Enums\BankAccountReviewStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutPreference;
use App\Enums\PayoutStatus;
use App\Jobs\ProcessPayoutTransferJob;
use App\Jobs\ProcessWeeklyAutoPayoutJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Driver;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\PayoutAccountChangedNotification;
use App\Notifications\PayoutAccountReviewedNotification;
use App\Services\LedgerService;
use App\Services\PayoutAccountSafeguards;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
    $this->seed(SystemAccountSeeder::class);

    $this->driverUser = User::factory()->driver()->create(['first_name' => 'Ada', 'last_name' => 'Obi']);
    $this->driver = Driver::factory()->approved()->for($this->driverUser)->create();
    $this->finance = User::factory()->finance()->create();
});

/** Paystack resolves any account number to this name. */
function paystackResolvesTo(object $test, string $accountName): void
{
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')
        ->andReturnUsing(fn (string $number) => ['account_number' => $number, 'account_name' => $accountName]);
    $gateway->shouldReceive('listBanks')->andReturn([]);
    app()->instance(WalletGateway::class, $gateway);
}

function savePayoutAccount(object $test, string $accountNumber = '0987654321'): TestResponse
{
    $test->actingAs($test->driverUser)->postJson('/api/v1/driver/bank-account/otp')->assertOk();

    return $test->actingAs($test->driverUser)->postJson('/api/v1/driver/bank-account', [
        'bank_code' => '044',
        'account_number' => $accountNumber,
        'otp_code' => '123456',
    ]);
}

function fundSafeguardDriver(object $test, int $kobo): void
{
    $ledger = app(LedgerService::class);
    $account = $ledger->findOrCreateAccount('App\\Models\\Driver', $test->driver->id, AccountType::DriverEarningsAvailable);

    $ledger->postJournal([
        ['account_id' => $ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $kobo],
        ['account_id' => $account->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $kobo],
    ], ['description' => 'Test earnings']);
}

// === Prompts before saving ===

it('tells the driver before saving that the name matches and a first account is paid at once', function () {
    paystackResolvesTo($this, 'OBI ADA CHIOMA');

    $this->actingAs($this->driverUser)
        ->postJson('/api/v1/driver/bank-account/resolve', ['bank_code' => '044', 'account_number' => '0987654321'])
        ->assertOk()
        ->assertJsonPath('name_check.status', 'matched')
        ->assertJsonPath('name_check.outcome', 'ok')
        ->assertJsonPath('name_check.message', null)
        ->assertJsonPath('payout_hold.applies', false);
});

it('warns before saving that a mismatched account will wait for review', function () {
    paystackResolvesTo($this, 'JOHN DOE');

    $this->actingAs($this->driverUser)
        ->postJson('/api/v1/driver/bank-account/resolve', ['bank_code' => '044', 'account_number' => '0987654321'])
        ->assertOk()
        ->assertJsonPath('name_check.status', 'mismatch')
        ->assertJsonPath('name_check.outcome', 'review')
        ->assertJsonPath('name_check.verified_name', 'Ada Obi')
        ->assertJsonPath('name_check.message', 'This account is in the name JOHN DOE, which does not match your verified name Ada Obi. You can still save it, but payouts will be paused until E-tiGo checks it. Use an account in your own name to be paid without delay.');
});

it('warns before saving that a mismatched account cannot be saved in block mode', function () {
    AppSetting::setValue('wallet.payout_account_name_check', 'block', 'wallet');
    paystackResolvesTo($this, 'JOHN DOE');

    $this->actingAs($this->driverUser)
        ->postJson('/api/v1/driver/bank-account/resolve', ['bank_code' => '044', 'account_number' => '0987654321'])
        ->assertJsonPath('name_check.outcome', 'blocked');
});

it('warns before saving a replacement account that its payouts start after the hold', function () {
    BankAccount::factory()->create(['driver_id' => $this->driver->id]);
    paystackResolvesTo($this, 'ADA OBI');

    $this->actingAs($this->driverUser)
        ->postJson('/api/v1/driver/bank-account/resolve', ['bank_code' => '044', 'account_number' => '0987654321'])
        ->assertJsonPath('payout_hold.applies', true)
        ->assertJsonPath('payout_hold.hours', 24)
        ->assertJsonPath('payout_hold.message', 'For your security, payouts to this account will start 24 hours after you save it.');
});

// === Saving ===

it('pays a matching first account without a hold or review', function () {
    paystackResolvesTo($this, 'ADA OBI');

    savePayoutAccount($this)
        ->assertCreated()
        ->assertJsonPath('bank_account.name_match_status', 'matched')
        ->assertJsonPath('bank_account.review_status', null)
        ->assertJsonPath('bank_account.payouts_held_until', null)
        ->assertJsonPath('payout_eligibility.can_request_payouts', true);
});

it('holds payouts to a replacement account, then lets them through when the hold ends', function () {
    BankAccount::factory()->create(['driver_id' => $this->driver->id]);
    paystackResolvesTo($this, 'ADA OBI');
    fundSafeguardDriver($this, 500000);

    savePayoutAccount($this)
        ->assertCreated()
        ->assertJsonPath('payout_eligibility.can_request_payouts', false)
        ->assertJsonPath('payout_eligibility.code', 'payout_account_on_hold');

    $this->actingAs($this->driverUser)->postJson('/api/v1/driver/payouts', ['amount' => 200000])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'payout_account_on_hold')
        ->assertJsonPath('payouts_held_until', fn ($until) => $until !== null);

    $this->travel(25)->hours();

    $this->actingAs($this->driverUser)->postJson('/api/v1/driver/payouts', ['amount' => 200000])->assertCreated();
});

it('says when payouts start in the change alert', function () {
    BankAccount::factory()->create(['driver_id' => $this->driver->id]);
    paystackResolvesTo($this, 'ADA OBI');

    savePayoutAccount($this)->assertCreated();

    Notification::assertSentTo($this->driverUser, PayoutAccountChangedNotification::class,
        fn (PayoutAccountChangedNotification $n) => str_contains($n->toSms($this->driverUser), 'Payouts to it start ')
            && str_contains($n->toSms($this->driverUser), '(Lagos time)'));
});

it('holds a first account too when finance turns that on', function () {
    AppSetting::setValue('wallet.payout_account_hold_first_account', 1, 'wallet');
    paystackResolvesTo($this, 'ADA OBI');

    savePayoutAccount($this)->assertJsonPath('payout_eligibility.code', 'payout_account_on_hold');
});

it('does not hold a replacement account when the hold is set to zero hours', function () {
    AppSetting::setValue('wallet.payout_account_hold_hours', 0, 'wallet');
    BankAccount::factory()->create(['driver_id' => $this->driver->id]);
    paystackResolvesTo($this, 'ADA OBI');

    savePayoutAccount($this)->assertJsonPath('payout_eligibility.can_request_payouts', true);
});

it('saves a mismatched account for review and pauses its payouts', function () {
    paystackResolvesTo($this, 'JOHN DOE');
    fundSafeguardDriver($this, 500000);

    savePayoutAccount($this)
        ->assertCreated()
        ->assertJsonPath('bank_account.name_match_status', 'mismatch')
        ->assertJsonPath('bank_account.review_status', 'pending')
        ->assertJsonPath('payout_eligibility.code', 'payout_account_under_review');

    $this->actingAs($this->driverUser)->postJson('/api/v1/driver/payouts', ['amount' => 200000])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'payout_account_under_review');

    Notification::assertSentTo($this->driverUser, PayoutAccountChangedNotification::class,
        fn (PayoutAccountChangedNotification $n) => str_contains($n->toArray($this->driverUser)['body'], 'paused until E-tiGo checks the account name'));
});

it('refuses a mismatched account in block mode without using up the code', function () {
    AppSetting::setValue('wallet.payout_account_name_check', 'block', 'wallet');
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('resolveAccountNumber')->andReturnUsing(fn (string $number) => [
        'account_number' => $number,
        'account_name' => $number === '0987654321' ? 'JOHN DOE' : 'ADA OBI',
    ]);
    app()->instance(WalletGateway::class, $gateway);

    savePayoutAccount($this)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'payout_account_name_mismatch')
        ->assertJsonValidationErrors(['account_number']);

    expect($this->driver->bankAccounts()->exists())->toBeFalse();

    $this->actingAs($this->driverUser)->postJson('/api/v1/driver/bank-account', [
        'bank_code' => '044', 'account_number' => '0123456789', 'otp_code' => '123456',
    ])->assertCreated();
});

it('records a mismatch without pausing payouts when the name check is off', function () {
    AppSetting::setValue('wallet.payout_account_name_check', 'off', 'wallet');
    paystackResolvesTo($this, 'JOHN DOE');

    savePayoutAccount($this)
        ->assertJsonPath('bank_account.name_match_status', 'mismatch')
        ->assertJsonPath('bank_account.review_status', null)
        ->assertJsonPath('payout_eligibility.can_request_payouts', true);
});

it('accepts an account in the middle name from the driver\'s verified NIN', function () {
    $this->driver->kycVerifications()->create([
        'type' => KycVerificationType::Nin,
        'status' => KycVerificationStatus::Verified,
        'provider_response' => ['firstname' => 'Ada', 'middlename' => 'Chioma', 'lastname' => 'Obi'],
        'verified_at' => now(),
    ]);
    paystackResolvesTo($this, 'CHIOMA OBI');

    savePayoutAccount($this)
        ->assertJsonPath('bank_account.name_match_status', 'matched')
        ->assertJsonPath('payout_eligibility.can_request_payouts', true);
});

it('shows payout eligibility with the saved account', function () {
    BankAccount::factory()->create(['driver_id' => $this->driver->id, 'review_status' => BankAccountReviewStatus::Rejected]);
    paystackResolvesTo($this, 'ADA OBI');

    $this->actingAs($this->driverUser)->getJson('/api/v1/driver/bank-account')
        ->assertOk()
        ->assertJsonPath('payout_eligibility.can_request_payouts', false)
        ->assertJsonPath('payout_eligibility.code', 'payout_account_rejected');
});

// === Every payout path respects the safeguards ===

it('skips held accounts in the weekly auto-payout and pays the rest', function () {
    Queue::fake([ProcessPayoutTransferJob::class]);
    $this->driver->update(['payout_preference' => PayoutPreference::WeeklyAuto]);
    BankAccount::factory()->create(['driver_id' => $this->driver->id, 'payouts_held_until' => now()->addHours(5)]);
    fundSafeguardDriver($this, 500000);

    $otherUser = User::factory()->driver()->create();
    $other = Driver::factory()->approved()->for($otherUser)->create(['payout_preference' => PayoutPreference::WeeklyAuto]);
    BankAccount::factory()->create(['driver_id' => $other->id]);
    $ledger = app(LedgerService::class);
    $ledger->postJournal([
        ['account_id' => $ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 500000],
        ['account_id' => $ledger->findOrCreateAccount('App\\Models\\Driver', $other->id, AccountType::DriverEarningsAvailable)->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 500000],
    ], ['description' => 'Test earnings']);

    (new ProcessWeeklyAutoPayoutJob)->handle(app(PayoutAccountSafeguards::class));

    expect(Payout::where('driver_id', $this->driver->id)->exists())->toBeFalse()
        ->and(Payout::where('driver_id', $other->id)->exists())->toBeTrue();
});

it('refuses to approve a payout to a held account until finance releases the hold', function () {
    Queue::fake([ProcessPayoutTransferJob::class]);
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id, 'payouts_held_until' => now()->addHours(5)]);
    $payout = Payout::factory()->create(['driver_id' => $this->driver->id, 'bank_account_id' => $account->id, 'amount' => 200000]);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'payout_account_on_hold');

    $this->actingAs($this->finance)->getJson('/api/v1/admin/payouts')
        ->assertJsonPath('payouts.0.bank_account_block.code', 'payout_account_on_hold');

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payout-accounts/{$account->id}/release-hold", ['reason' => 'Driver confirmed the change by phone'])
        ->assertOk()
        ->assertJsonPath('payout_account.payouts_held_until', null);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/approve")->assertOk();
    expect(AuditLog::where('event', 'bank_account.hold_released')->exists())->toBeTrue();
});

it('refuses to retry a failed payout to an account under review', function () {
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id, 'review_status' => BankAccountReviewStatus::Pending]);
    $payout = Payout::factory()->failed()->create(['driver_id' => $this->driver->id, 'bank_account_id' => $account->id]);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/retry")
        ->assertConflict()
        ->assertJsonPath('code', 'payout_account_under_review');

    expect($payout->fresh()->status)->toBe(PayoutStatus::Failed);
});

// === Finance review queue ===

it('lists accounts waiting for review first, with counts', function () {
    BankAccount::factory()->create(['driver_id' => $this->driver->id, 'is_primary' => false]);
    $pending = BankAccount::factory()->create([
        'driver_id' => $this->driver->id,
        'account_name' => 'JOHN DOE',
        'verified_name' => 'Ada Obi',
        'name_match_status' => BankAccountNameMatch::Mismatch,
        'review_status' => BankAccountReviewStatus::Pending,
        'payouts_held_until' => now()->addHours(3),
    ]);

    $this->actingAs($this->finance)->getJson('/api/v1/admin/payout-accounts')
        ->assertOk()
        ->assertJsonPath('payout_accounts.0.id', $pending->id)
        ->assertJsonPath('payout_accounts.0.verified_name', 'Ada Obi')
        ->assertJsonPath('payout_accounts.0.driver.name', 'Ada Obi')
        ->assertJsonPath('payout_accounts.0.payout_block.code', 'payout_account_under_review')
        ->assertJsonPath('summary.pending_review', 1)
        ->assertJsonPath('summary.on_hold', 1);

    $this->actingAs($this->finance)->getJson('/api/v1/admin/payout-accounts?review_status=pending&on_hold=1')
        ->assertJsonCount(1, 'payout_accounts');
});

it('lets finance approve a mismatched account and tells the driver', function () {
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id, 'review_status' => BankAccountReviewStatus::Pending]);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payout-accounts/{$account->id}/approve", ['note' => 'Joint account, confirmed by phone'])
        ->assertOk()
        ->assertJsonPath('payout_account.review_status', 'approved')
        ->assertJsonPath('payout_account.reviewed_by.id', $this->finance->id)
        ->assertJsonPath('payout_account.payout_block', null);

    Notification::assertSentTo($this->driverUser, PayoutAccountReviewedNotification::class,
        fn (PayoutAccountReviewedNotification $n) => $n->toArray($this->driverUser)['body'] === 'Your bank account ending '.substr($account->account_number, -4).' has been approved for payouts.');
});

it('lets finance reject any account and tells the driver why', function () {
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id]);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payout-accounts/{$account->id}/reject", ['reason' => 'The account belongs to someone else.'])
        ->assertOk()
        ->assertJsonPath('payout_account.payout_block.code', 'payout_account_rejected');

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payout-accounts/{$account->id}/reject", ['reason' => 'The account belongs to someone else.'])
        ->assertUnprocessable();

    Notification::assertSentTo($this->driverUser, PayoutAccountReviewedNotification::class,
        fn (PayoutAccountReviewedNotification $n) => str_contains($n->toArray($this->driverUser)['body'], 'for payouts: The account belongs to someone else. Add a bank account'));
});

it('only approves accounts waiting for review or rejected', function () {
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id]);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payout-accounts/{$account->id}/approve")
        ->assertUnprocessable();
});

it('refuses to release a hold that is not active', function () {
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id]);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payout-accounts/{$account->id}/release-hold", ['reason' => 'Driver confirmed the change'])
        ->assertUnprocessable();
});

it('lets support see the queue but not act on it', function () {
    $support = User::factory()->admin(AdminRole::Support)->create();
    $account = BankAccount::factory()->create(['driver_id' => $this->driver->id, 'review_status' => BankAccountReviewStatus::Pending]);

    $this->actingAs($support)->getJson('/api/v1/admin/payout-accounts')->assertOk();
    $this->actingAs($support)->postJson("/api/v1/admin/payout-accounts/{$account->id}/approve")->assertForbidden();
});

// === Settings ===

it('lets finance configure the payout account safeguards', function () {
    $this->actingAs($this->finance)->putJson('/api/v1/admin/settings/wallet', [
        'payout_account_hold_hours' => 48,
        'payout_account_hold_first_account' => true,
        'payout_account_name_check' => 'block',
    ])->assertOk();

    $this->actingAs($this->finance)->getJson('/api/v1/admin/settings/wallet')
        ->assertJsonPath('settings.payout_account_hold_hours', 48)
        ->assertJsonPath('settings.payout_account_hold_first_account', true)
        ->assertJsonPath('settings.payout_account_name_check', 'block');

    $this->actingAs($this->finance)->putJson('/api/v1/admin/settings/wallet', ['payout_account_hold_first_account' => false])->assertOk();
    $this->actingAs($this->finance)->getJson('/api/v1/admin/settings/wallet')
        ->assertJsonPath('settings.payout_account_hold_first_account', false);
});

it('rejects invalid payout account safeguard settings', function () {
    $this->actingAs($this->finance)->putJson('/api/v1/admin/settings/wallet', [
        'payout_account_hold_hours' => 200,
        'payout_account_name_check' => 'sometimes',
    ])->assertUnprocessable()->assertJsonValidationErrors(['payout_account_hold_hours', 'payout_account_name_check']);
});

// === Existing accounts ===

it('checks existing accounts and sends mismatches for review only with --apply', function () {
    $matching = BankAccount::factory()->create(['driver_id' => $this->driver->id, 'account_name' => 'ADA OBI', 'is_primary' => true]);
    $otherUser = User::factory()->driver()->create(['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $other = Driver::factory()->approved()->for($otherUser)->create();
    $mismatched = BankAccount::factory()->create(['driver_id' => $other->id, 'account_name' => 'JOHN DOE', 'is_primary' => true]);

    $this->artisan('payout-accounts:check-names')
        ->expectsOutputToContain('Checked 2 account(s): 1 mismatch(es).')
        ->expectsOutputToContain('Nothing was changed')
        ->assertSuccessful();
    expect($mismatched->fresh()->name_match_status)->toBeNull();

    $this->artisan('payout-accounts:check-names --apply')->assertSuccessful();

    expect($matching->fresh()->name_match_status)->toBe(BankAccountNameMatch::Matched)
        ->and($matching->fresh()->review_status)->toBeNull()
        ->and($mismatched->fresh()->name_match_status)->toBe(BankAccountNameMatch::Mismatch)
        ->and($mismatched->fresh()->review_status)->toBe(BankAccountReviewStatus::Pending);
});
