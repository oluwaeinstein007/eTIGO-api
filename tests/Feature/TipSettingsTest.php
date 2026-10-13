<?php

use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Models\AppSetting;
use App\Models\User;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->admin = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::SuperAdmin,
    ]);
    $this->adminToken = $this->admin->createToken('auth', ['admin', 'super_admin'])->plainTextToken;
});

describe('GET /rides/tip-settings', function () {
    it('returns default tip presets for authenticated user', function () {
        $response = $this->getJson('/api/v1/rides/tip-settings', [
            'Authorization' => "Bearer {$this->passengerToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure(['presets', 'min_amount', 'max_amount', 'currency'])
            ->assertJsonPath('min_amount', 50)
            ->assertJsonPath('max_amount', 50000)
            ->assertJsonPath('currency', 'NGN');
    });

    it('returns custom presets when configured', function () {
        AppSetting::setValue('tip.presets', json_encode([200, 500, 1000, 2000]), 'tip');
        AppSetting::setValue('tip.min_amount', 100, 'tip');
        AppSetting::setValue('tip.max_amount', 10000, 'tip');

        $response = $this->getJson('/api/v1/rides/tip-settings', [
            'Authorization' => "Bearer {$this->passengerToken}",
        ]);

        $response->assertOk()
            ->assertJsonPath('presets', [200, 500, 1000, 2000])
            ->assertJsonPath('min_amount', 100)
            ->assertJsonPath('max_amount', 10000);
    });

    it('rejects unauthenticated request', function () {
        $this->getJson('/api/v1/rides/tip-settings')
            ->assertUnauthorized();
    });
});

describe('Admin Tip Settings', function () {
    it('shows current tip settings', function () {
        $response = $this->getJson('/api/v1/admin/settings/tips', [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure(['settings' => ['presets', 'min_amount', 'max_amount', 'currency']]);
    });

    it('updates tip presets', function () {
        $response = $this->putJson('/api/v1/admin/settings/tips', [
            'presets' => [100, 300, 500, 1000, 2000],
            'min_amount' => 100,
            'max_amount' => 25000,
        ], [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Tip settings updated.')
            ->assertJsonPath('settings.presets', [100, 300, 500, 1000, 2000])
            ->assertJsonPath('settings.min_amount', 100)
            ->assertJsonPath('settings.max_amount', 25000);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'tip.presets',
            'group' => 'tip',
        ]);
    });

    it('rejects invalid presets', function () {
        $this->putJson('/api/v1/admin/settings/tips', [
            'presets' => [],
        ], [
            'Authorization' => "Bearer {$this->adminToken}",
        ])->assertStatus(422);
    });

    it('rejects min_amount >= max_amount', function () {
        $this->putJson('/api/v1/admin/settings/tips', [
            'min_amount' => 5000,
            'max_amount' => 5000,
        ], [
            'Authorization' => "Bearer {$this->adminToken}",
        ])->assertStatus(422);
    });
});
