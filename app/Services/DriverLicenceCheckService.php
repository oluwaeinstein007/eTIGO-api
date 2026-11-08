<?php

namespace App\Services;

use App\Contracts\KycGateway;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\DriverDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Checks a driving licence against FRSC records (QoreID's licence lookup,
 * the same one onboarding KYC uses) so operations review renewals with the
 * record in front of them: found in the driver's name, and FRSC's expiry
 * date next to the one the driver entered.
 *
 * The check informs the review and never decides it: a mismatch is a flag,
 * and when the lookup fails (provider down, or no record) operations check
 * the photo by hand. It is not a new KYC: the result is kept on the
 * document and the driver's KYC status is untouched.
 */
class DriverLicenceCheckService
{
    public const MATCHED = 'matched';

    public const NAME_MISMATCH = 'name_mismatch';

    public const UNAVAILABLE = 'unavailable';

    /**
     * Statuses for a driver already on the road, whose licence uploads are
     * renewals (checked on upload).
     */
    private const WORKING = [DriverStatus::Approved, DriverStatus::Suspended];

    /**
     * Construct with the KYC provider used at onboarding.
     */
    public function __construct(private KycGateway $gateway) {}

    /**
     * Whether a just-uploaded paper should be looked up: a working driver's
     * driving licence, with the check switched on.
     */
    public function shouldCheck(DriverDocument $document): bool
    {
        return $document->type === DocumentType::DrivingLicence
            && $document->status === DocumentStatus::Pending
            && in_array($document->driver?->status, self::WORKING, true)
            && AppSetting::driverDocuments()['frsc_check_on_renewal'];
    }

    /**
     * Look the licence up and store the result on the document. The number
     * is the one on the renewed licence, else the driver's current number.
     *
     * @return array<string, mixed> the stored result
     */
    public function check(DriverDocument $document): array
    {
        $driver = $document->driver()->with('user')->first();
        $number = $document->document_number ?: $driver?->licence_number;

        if ($driver === null || $number === null) {
            return $this->store($document, [
                'status' => self::UNAVAILABLE,
                'note' => 'No licence number to look up. Check the photo by hand.',
            ]);
        }

        try {
            $result = $this->gateway->verifyDriversLicense($number, [
                'firstname' => (string) $driver->user?->first_name,
                'lastname' => (string) $driver->user?->last_name,
            ]);
        } catch (\Throwable $e) {
            // QoreID answers an unknown number and an outage the same way.
            Log::warning('FRSC licence check failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            return $this->store($document, [
                'status' => self::UNAVAILABLE,
                'note' => 'FRSC records could not confirm this licence (not found, or the service was unavailable). Check the photo by hand.',
            ]);
        }

        return $this->store($document, [
            'status' => $result['verified'] ? self::MATCHED : self::NAME_MISMATCH,
            'frsc_expires_at' => $this->date($result['match_data']['expiry_date'] ?? null),
            'number_last4' => mb_substr($number, -4),
            'provider_reference' => $result['provider_reference'] ?? null,
            'note' => $result['verified'] ? null : ($result['failure_reason'] ?? 'The name on FRSC records differs from the driver\'s.'),
        ]);
    }

    /**
     * What FRSC records say about this licence, for the reviewer: the
     * renewal check if one ran, else (for a licence uploaded at onboarding)
     * the driver's onboarding licence verification. Null when neither
     * exists. expiry_matches compares FRSC's date with the document's.
     *
     * @return array<string, mixed>|null
     */
    public function record(DriverDocument $document): ?array
    {
        if ($document->type !== DocumentType::DrivingLicence) {
            return null;
        }

        $record = $document->licence_check;

        if ($record === null) {
            $verification = $document->driver?->kycVerifications()
                ->where('type', KycVerificationType::DriversLicense)
                ->where('status', KycVerificationStatus::Verified)
                ->latest('verified_at')
                ->first();

            if ($verification === null) {
                return null;
            }

            $record = [
                'status' => self::MATCHED,
                'frsc_expires_at' => $this->date($verification->match_data['expiry_date'] ?? null),
                'number_last4' => $verification->id_number ? mb_substr($verification->id_number, -4) : null,
                'checked_at' => $verification->verified_at?->toIso8601String(),
                'source' => 'onboarding_kyc',
                'note' => null,
            ];
        }

        $frsc = $record['frsc_expires_at'] ?? null;
        $entered = $document->expires_at?->toDateString();

        return [
            ...$record,
            'expiry_matches' => $frsc !== null && $entered !== null ? $frsc === $entered : null,
        ];
    }

    /**
     * FRSC's expiry date for the licence, when the record has one.
     */
    public function frscExpiry(DriverDocument $document): ?string
    {
        return $this->record($document)['frsc_expires_at'] ?? null;
    }

    /**
     * Save the result on the document and in its audit trail.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function store(DriverDocument $document, array $result): array
    {
        $result = [
            'frsc_expires_at' => null,
            'number_last4' => null,
            'provider_reference' => null,
            ...$result,
            'checked_at' => now()->toIso8601String(),
            'source' => 'renewal_check',
        ];

        $document->forceFill(['licence_check' => $result])->saveQuietly();

        AuditLog::record($document, 'licence_frsc_checked', null, null, [
            'status' => $result['status'],
            'frsc_expires_at' => $result['frsc_expires_at'],
            'driver_id' => $document->driver_id,
        ]);

        return $result;
    }

    /**
     * A provider date as Y-m-d, or null when missing or unreadable.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
