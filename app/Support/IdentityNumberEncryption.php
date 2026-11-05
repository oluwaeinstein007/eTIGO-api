<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts (or decrypts) driver ID numbers and KYC match details in place.
 * Used by the migration that introduced the encryption and by
 * `drivers:encrypt-identity-numbers`, which the deploy runs again once the
 * new code is live: until then the old code can still write plain values.
 * Values already in the target form are skipped, so a rerun is harmless.
 */
class IdentityNumberEncryption
{
    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'kyc_verifications' => ['id_number', 'match_data'],
        'drivers' => ['licence_number'],
    ];

    /**
     * @return int Rows changed
     */
    public static function encryptAll(): int
    {
        return self::transformAll(encrypt: true);
    }

    /**
     * @return int Rows changed
     */
    public static function decryptAll(): int
    {
        return self::transformAll(encrypt: false);
    }

    private static function transformAll(bool $encrypt): int
    {
        $changed = 0;

        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $encrypt, &$changed) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column};

                        if ($value === null) {
                            continue;
                        }

                        if ($encrypt && ! self::isPayload($value)) {
                            $changes[$column] = Crypt::encryptString($value);
                        } elseif (! $encrypt && self::isPayload($value)) {
                            // Throws when the payload was made with another
                            // APP_KEY, rather than leaving ciphertext behind.
                            $changes[$column] = Crypt::decryptString($value);
                        }
                    }

                    if ($changes !== []) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                        $changed++;
                    }
                }
            });
        }

        return $changed;
    }

    /**
     * Whether the value has the shape of a Laravel encrypted payload
     * (base64 JSON with iv, value and mac). A payload this key cannot open
     * is reported when it is read, never mistaken for plain text.
     */
    public static function isPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['iv'], $payload['value'], $payload['mac'])) {
            return false;
        }

        try {
            Crypt::decryptString($value);
        } catch (DecryptException $e) {
            throw new \RuntimeException('A value was encrypted with a different APP_KEY. Set APP_PREVIOUS_KEYS to the old key before running this.', previous: $e);
        }

        return true;
    }
}
