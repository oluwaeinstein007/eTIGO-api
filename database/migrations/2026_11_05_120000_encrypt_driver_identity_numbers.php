<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Driver ID numbers (NIN, licence, plate) and the personal details a KYC
 * provider matched are encrypted at rest. match_data becomes text because
 * an encrypted value is not JSON; licence_number becomes text because an
 * encrypted value is longer than 255 characters.
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

        $this->transform('kyc_verifications', ['id_number', 'match_data'], encrypt: true);
        $this->transform('drivers', ['licence_number'], encrypt: true);
    }

    public function down(): void
    {
        $this->transform('kyc_verifications', ['id_number', 'match_data'], encrypt: false);
        $this->transform('drivers', ['licence_number'], encrypt: false);

        Schema::table('drivers', function (Blueprint $table) {
            $table->string('licence_number')->nullable()->change();
        });

        DB::statement('ALTER TABLE kyc_verifications ALTER COLUMN match_data TYPE json USING match_data::json');
    }

    /**
     * Encrypts (or decrypts) each non-null value, skipping values that are
     * already in the target form so a rerun is harmless.
     *
     * @param  list<string>  $columns
     */
    private function transform(string $table, array $columns, bool $encrypt): void
    {
        DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $encrypt) {
            foreach ($rows as $row) {
                $changes = [];

                foreach ($columns as $column) {
                    $value = $row->{$column};

                    if ($value === null) {
                        continue;
                    }

                    $isEncrypted = $this->isEncrypted($value);

                    if ($encrypt && ! $isEncrypted) {
                        $changes[$column] = Crypt::encryptString($value);
                    } elseif (! $encrypt && $isEncrypted) {
                        $changes[$column] = Crypt::decryptString($value);
                    }
                }

                if ($changes !== []) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                }
            }
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
