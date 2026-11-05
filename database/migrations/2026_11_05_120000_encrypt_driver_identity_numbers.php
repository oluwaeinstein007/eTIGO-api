<?php

use App\Support\IdentityNumberEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Driver ID numbers (NIN, licence, plate) and the personal details a KYC
 * provider matched are encrypted at rest. match_data becomes text because
 * an encrypted value is not JSON; licence_number becomes text because an
 * encrypted value is longer than 255 characters. The deploy runs the same
 * pass again (drivers:encrypt-identity-numbers) once the new code is live,
 * for values the old code wrote in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->text('match_data')->nullable()->change();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->text('licence_number')->nullable()->change();
        });

        IdentityNumberEncryption::encryptAll();
    }

    public function down(): void
    {
        IdentityNumberEncryption::decryptAll();

        Schema::table('drivers', function (Blueprint $table) {
            $table->string('licence_number')->nullable()->change();
        });

        DB::statement('ALTER TABLE kyc_verifications ALTER COLUMN match_data TYPE json USING match_data::json');
    }
};
