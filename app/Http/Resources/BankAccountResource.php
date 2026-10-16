<?php

namespace App\Http\Resources;

use App\Contracts\WalletGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->resolveBankName(),
            'account_number' => $this->maskedAccountNumber(),
            'account_name' => $this->account_name,
            'is_verified' => $this->is_verified,
            'is_primary' => $this->is_primary,
            // Payout safeguards: see payout_eligibility for what the driver can do now.
            'payouts_held_until' => $this->payouts_held_until?->toIso8601String(),
            'name_match_status' => $this->name_match_status?->value,
            'review_status' => $this->review_status?->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function resolveBankName(): ?string
    {
        if (! $this->bank_code) {
            return null;
        }

        try {
            $banks = Cache::remember('banks_nigeria', 86400, function () {
                return app(WalletGateway::class)->listBanks();
            });
        } catch (\Throwable) {
            $banks = [];
        }

        foreach ($banks as $bank) {
            if (($bank['code'] ?? null) === $this->bank_code) {
                return $bank['name'];
            }
        }

        return null;
    }
}
