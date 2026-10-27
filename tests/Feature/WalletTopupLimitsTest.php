<?php

use App\Contracts\WalletGateway;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->passenger = User::factory()->passenger()->create();
});

function runNairaWalletSettingsFix(): void
{
    (require database_path('migrations/2026_10_27_100000_fix_naira_wallet_settings.php'))->up();
}

function seedOldNairaWalletSettings(): void
{
    foreach ([
        'wallet.min_topup' => '500',
        'wallet.max_balance' => '500000',
        'wallet.daily_topup_cap' => '100000',
        'wallet.min_payout' => '1000',
        'wallet.settlement_delay' => '24',
        'wallet.max_negative_balance' => '0',
        'wallet.hold_expiry_hours' => '72',
        'wallet.abandoned_topup_minutes' => '30',
    ] as $key => $value) {
        AppSetting::setValue($key, $value, 'wallet');
    }
}

it('corrects wallet settings the old seeder wrote in naira', function () {
    seedOldNairaWalletSettings();

    runNairaWalletSettingsFix();

    expect(AppSetting::getWalletSettings())->toMatchArray([
        'min_topup' => 50000,
        'max_balance' => 50000000,
        'daily_topup_cap' => 10000000,
        'min_payout' => 100000,
        'settlement_delay' => 'instant',
        'max_negative_balance' => -500000,
        'hold_expiry_hours' => 4,
        'abandoned_topup_minutes' => 30,
    ]);
});

it('keeps wallet settings an admin changed after the old seed', function () {
    seedOldNairaWalletSettings();
    AppSetting::setValue('wallet.daily_topup_cap', '2000000', 'wallet');
    AppSetting::setValue('wallet.hold_expiry_hours', '12', 'wallet');

    runNairaWalletSettingsFix();

    expect(AppSetting::getWalletSettings())->toMatchArray([
        'max_balance' => 50000000,
        'daily_topup_cap' => 2000000,
        'hold_expiry_hours' => 12,
    ]);
});

it('leaves a database without the old seed alone', function () {
    AppSetting::setValue('wallet.max_balance', '500000', 'wallet');

    runNairaWalletSettingsFix();

    expect(DB::table('app_settings')->where('key', 'wallet.max_balance')->value('value'))->toBe('500000');
});

it('lets a passenger top up more than ₦1,000 once the settings are fixed', function () {
    seedOldNairaWalletSettings();
    runNairaWalletSettingsFix();

    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('initializePayment')->once()->andReturn(['link' => 'https://checkout.paystack.test/abc']);
    app()->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/passenger/wallet/topup', ['amount' => 1000000, 'callback_url' => 'https://app.etigo.test/wallet'])
        ->assertOk();
});

it('says how much more fits in the wallet when the balance cap is hit', function () {
    AppSetting::setValue('wallet.max_balance', '500000', 'wallet');

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/passenger/wallet/topup', ['amount' => 600000, 'callback_url' => 'https://app.etigo.test/wallet'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Top-up would exceed maximum wallet balance of ₦5,000. You can add up to ₦5,000 more.');
});

it('says how much is left today when the daily cap is hit', function () {
    Cache::put("topup_daily:{$this->passenger->id}:".now()->toDateString(), 9000000, now()->endOfDay());

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/passenger/wallet/topup', ['amount' => 2000000, 'callback_url' => 'https://app.etigo.test/wallet'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Daily top-up limit exceeded. The limit is ₦100,000 a day; you can top up ₦10,000 more today.');
});
