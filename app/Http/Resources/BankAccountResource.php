<?php

namespace App\Http\Resources;

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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function resolveBankName(): ?string
    {
        if (! $this->bank_code) {
            return null;
        }

        $banks = Cache::get('banks_nigeria', []);

        foreach ($banks as $bank) {
            if (($bank['code'] ?? null) === $this->bank_code) {
                return $bank['name'];
            }
        }

        return null;
    }
}
