<?php

use App\Enums\AdminRole;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Notifications\AdminInvitationNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('allows super admin to invite a new admin', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/admins/invite', [
            'email' => 'new-ops@etigo.com',
            'admin_role' => 'operations',
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'invitation' => ['id', 'email', 'admin_role', 'expires_at']]);

    $this->assertDatabaseHas('admin_invitations', [
        'email' => 'new-ops@etigo.com',
        'admin_role' => 'operations',
        'invited_by' => $superAdmin->id,
    ]);

    Notification::assertSentOnDemand(AdminInvitationNotification::class);
});

it('prevents non-super admins from inviting', function () {
    $opsAdmin = User::factory()->admin(AdminRole::Operations)->create();
    $token = $opsAdmin->createToken('admin-auth', ['admin', 'operations'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/admins/invite', [
            'email' => 'new@etigo.com',
            'admin_role' => 'support',
        ]);

    $response->assertForbidden();
});

it('prevents inviting another super admin', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/admins/invite', [
            'email' => 'another-super@etigo.com',
            'admin_role' => 'super_admin',
        ]);

    $response->assertUnprocessable();
});

it('prevents duplicate pending invitations', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    AdminInvitation::create([
        'email' => 'existing@etigo.com',
        'admin_role' => AdminRole::Operations,
        'token' => str_repeat('a', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->addHours(48),
    ]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/admins/invite', [
            'email' => 'existing@etigo.com',
            'admin_role' => 'support',
        ]);

    $response->assertStatus(409);
});

it('allows verifying a valid invitation token', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $invitation = AdminInvitation::create([
        'email' => 'new@etigo.com',
        'admin_role' => AdminRole::Support,
        'token' => str_repeat('b', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->addHours(48),
    ]);

    $response = $this->getJson('/api/v1/admin/auth/invite/verify/'.$invitation->token);

    $response->assertOk()
        ->assertJson([
            'invitation' => [
                'email' => 'new@etigo.com',
                'admin_role' => 'support',
            ],
        ]);
});

it('rejects expired invitation token', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    AdminInvitation::create([
        'email' => 'expired@etigo.com',
        'admin_role' => AdminRole::Support,
        'token' => str_repeat('c', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->subHour(),
    ]);

    $response = $this->getJson('/api/v1/admin/auth/invite/verify/'.str_repeat('c', 64));

    $response->assertUnprocessable();
});

it('allows accepting a valid invitation', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $invitation = AdminInvitation::create([
        'email' => 'accept@etigo.com',
        'admin_role' => AdminRole::Operations,
        'token' => str_repeat('d', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->addHours(48),
    ]);

    $response = $this->postJson('/api/v1/admin/auth/invite/accept', [
        'token' => $invitation->token,
        'first_name' => 'New',
        'last_name' => 'Admin',
        'phone' => '+2341234567890',
        'password' => 'SecureP@ss1',
        'password_confirmation' => 'SecureP@ss1',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'user' => [
                'email' => 'accept@etigo.com',
                'type' => 'admin',
                'admin_role' => 'operations',
            ],
        ]);

    $this->assertDatabaseHas('admin_invitations', [
        'id' => $invitation->id,
        'accepted_at' => now(),
    ]);
});

it('rejects accepting an expired invitation', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    AdminInvitation::create([
        'email' => 'expired-accept@etigo.com',
        'admin_role' => AdminRole::Support,
        'token' => str_repeat('e', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->subHour(),
    ]);

    $response = $this->postJson('/api/v1/admin/auth/invite/accept', [
        'token' => str_repeat('e', 64),
        'first_name' => 'Late',
        'last_name' => 'Admin',
        'phone' => '+2349999999999',
        'password' => 'SecureP@ss1',
        'password_confirmation' => 'SecureP@ss1',
    ]);

    $response->assertUnprocessable();
});

it('allows super admin to list all admins', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    User::factory()->admin(AdminRole::Operations)->create();
    User::factory()->admin(AdminRole::Support)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/admins');

    $response->assertOk()
        ->assertJsonStructure(['admins', 'meta' => ['current_page', 'last_page', 'total']]);

    expect($response->json('meta.total'))->toBe(3);
});

it('allows super admin to deactivate another admin', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $opsAdmin = User::factory()->admin(AdminRole::Operations)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/admins/{$opsAdmin->id}/deactivate");

    $response->assertOk();

    expect($opsAdmin->fresh()->is_active)->toBeFalse();
});

it('prevents super admin from deactivating themselves', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/admins/{$superAdmin->id}/deactivate");

    $response->assertForbidden();
});

it('prevents deactivating another super admin', function () {
    $superAdmin1 = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $superAdmin2 = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin1->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/admins/{$superAdmin2->id}/deactivate");

    $response->assertForbidden();
});

it('allows super admin to reactivate an admin', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $opsAdmin = User::factory()->admin(AdminRole::Operations)->inactive()->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/admins/{$opsAdmin->id}/reactivate");

    $response->assertOk();

    expect($opsAdmin->fresh()->is_active)->toBeTrue();
});

it('allows super admin to update admin role', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $supportAdmin = User::factory()->admin(AdminRole::Support)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/admins/{$supportAdmin->id}", [
            'admin_role' => 'operations',
        ]);

    $response->assertOk();

    expect($supportAdmin->fresh()->admin_role)->toBe(AdminRole::Operations);
});

it('allows super admin to resend invitation', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $invitation = AdminInvitation::create([
        'email' => 'resend@etigo.com',
        'admin_role' => AdminRole::Support,
        'token' => str_repeat('f', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->addHours(12),
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/admins/invitations/{$invitation->id}/resend");

    $response->assertOk();

    $invitation->refresh();
    expect($invitation->token)->not->toBe(str_repeat('f', 64));
    expect($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(AdminInvitationNotification::class);
});

it('allows super admin to revoke invitation', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $invitation = AdminInvitation::create([
        'email' => 'revoke@etigo.com',
        'admin_role' => AdminRole::Support,
        'token' => str_repeat('g', 64),
        'invited_by' => $superAdmin->id,
        'expires_at' => now()->addHours(48),
    ]);

    $response = $this->withToken($token)
        ->deleteJson("/api/v1/admin/admins/invitations/{$invitation->id}");

    $response->assertOk();

    expect($invitation->fresh()->isExpired())->toBeTrue();
});

it('allows super admin to pass through role-gated routes', function () {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $token = $superAdmin->createToken('admin-auth', ['admin', 'super_admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/admins');

    $response->assertOk();
});
