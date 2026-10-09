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
            'user' => new UserResource($this->whenLoaded('user')),
            'city' => new CityResource($this->whenLoaded('city')),
            'status' => $this->status,
            'vehicle_ownership_type' => $this->vehicle_ownership_type,
            'kyc_status' => $this->kyc_status,
            'kyc_verified_at' => $this->kyc_verified_at,
            'licence_number' => $this->licence_number,
            'rejection_reason' => $this->when($this->resource->status === DriverStatus::Rejected, $this->rejection_reason),
            'is_online' => $this->is_online,
            'approved_at' => $this->approved_at,
            'documents' => DriverDocumentResource::collection($this->whenLoaded('documents')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'kyc_verifications' => KycVerificationResource::collection($this->whenLoaded('kycVerifications')),
            'created_at' => $this->created_at,
        ];
    }
}
