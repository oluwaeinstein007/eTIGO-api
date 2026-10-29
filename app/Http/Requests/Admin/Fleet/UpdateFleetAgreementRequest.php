<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFleetAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Limits that depend on the agreement (what the driver has paid, the first
     * day money was collected) are checked in FleetRemittanceService.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'daily_remittance_target' => ['sometimes', 'numeric', 'min:1000'],
            'total_vehicle_cost' => ['sometimes', 'numeric', 'min:100000'],
            'agreement_start_date' => ['sometimes', 'date_format:Y-m-d'],
            // Why the cost or start date changed; kept in the agreement's history.
            'reason' => ['required_with:total_vehicle_cost,agreement_start_date', 'nullable', 'string', 'max:500'],
        ];
    }
}
