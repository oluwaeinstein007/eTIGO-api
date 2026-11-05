<?php

use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Models\Driver;
use App\Models\KycVerification;
use App\Support\IdentityNumberEncryption;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('stores KYC ID numbers and matched details encrypted', function () {
    $verification = KycVerification::create([
        'driver_id' => Driver::factory()->create()->id,
        'type' => KycVerificationType::Nin,
        'id_number' => '12345678901',
        'status' => KycVerificationStatus::Verified,
        'match_data' => ['firstname' => 'Ada', 'nin' => '12345678901'],
    ]);

    $row = DB::table('kyc_verifications')->find($verification->id);

    expect($row->id_number)->not->toContain('12345678901')
        ->and(Crypt::decryptString($row->id_number))->toBe('12345678901')
        ->and($row->match_data)->not->toContain('Ada')
        ->and($verification->fresh()->id_number)->toBe('12345678901')
        ->and($verification->fresh()->match_data)->toBe(['firstname' => 'Ada', 'nin' => '12345678901']);
});

it('stores driver licence numbers encrypted', function () {
    $driver = Driver::factory()->create(['licence_number' => 'LAG-12345AB']);

    $row = DB::table('drivers')->find($driver->id);

    expect($row->licence_number)->not->toContain('LAG-12345AB')
        ->and($driver->fresh()->licence_number)->toBe('LAG-12345AB');
});

it('encrypts identity numbers saved before encryption existed', function () {
    $migration = require database_path('migrations/2026_11_05_120000_encrypt_driver_identity_numbers.php');
    $driver = Driver::factory()->create();
    $verification = KycVerification::create([
        'driver_id' => $driver->id,
        'type' => KycVerificationType::Nin,
        'status' => KycVerificationStatus::Verified,
    ]);

    DB::table('drivers')->where('id', $driver->id)->update(['licence_number' => 'DL-55555']);
    DB::table('kyc_verifications')->where('id', $verification->id)->update([
        'id_number' => '00012345678',
        'match_data' => json_encode(['firstname' => 'Ada']),
    ]);

    $migration->up();
    $migration->up();

    expect($driver->fresh()->licence_number)->toBe('DL-55555')
        ->and($verification->fresh()->id_number)->toBe('00012345678')
        ->and($verification->fresh()->match_data)->toBe(['firstname' => 'Ada']);
});

it('encrypts plain values the old release wrote during the deploy', function () {
    $driver = Driver::factory()->create(['licence_number' => 'LAG-1']);
    $plain = Driver::factory()->create();
    DB::table('drivers')->where('id', $plain->id)->update(['licence_number' => 'LAG-2']);

    $this->artisan('drivers:encrypt-identity-numbers')
        ->expectsOutput('1 row(s) encrypted.')
        ->assertSuccessful();

    expect($plain->fresh()->licence_number)->toBe('LAG-2')
        ->and($driver->fresh()->licence_number)->toBe('LAG-1');
});

it('refuses to treat a value encrypted with another key as plain text', function () {
    $driver = Driver::factory()->create();
    $otherKey = new Encrypter(random_bytes(32), 'AES-256-CBC');
    DB::table('drivers')->where('id', $driver->id)->update(['licence_number' => $otherKey->encryptString('LAG-3')]);

    expect(fn () => IdentityNumberEncryption::encryptAll())
        ->toThrow(RuntimeException::class, 'different APP_KEY');
});
