<?php

namespace App\Http\Resources;

use App\Enums\DriverStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'user' => new UserResource($this->whenLoaded('user')),
            'city' => new CityResource($this->whenLoaded('city')),
            'status' => $this->status,
            'vehicle_ownership_type' => $this->vehicle_ownership_type,
            'kyc_status' => $this->kyc_status,
            'kyc_verified_at' => $this->kyc_verified_at,
            'licence_number' => $this->licence_number,
            'rejection_reason' => $this->rejection_reason,
            'suspension_reason' => $this->when($this->resource->status === DriverStatus::Suspended, $this->suspension_reason),
            'is_online' => $this->is_online,
            'is_profile_complete' => $this->resource->isProfileComplete(),
            'approved_at' => $this->approved_at,
            'suspended_at' => $this->suspended_at,
            'documents' => DriverDocumentResource::collection($this->whenLoaded('documents')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'bank_account' => new BankAccountResource($this->whenLoaded('primaryBankAccount')),
            'kyc_verifications' => KycVerificationResource::collection($this->whenLoaded('kycVerifications')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
