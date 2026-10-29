<?php

namespace App\Http\Requests\Admin\Surge;

use App\Enums\SurgeType;
use Illuminate\Validation\Rule;

/**
 * The conditions rules shared by creating and updating a surge rule. Only
 * the keys surge pricing reads are accepted, so a rule cannot be saved in a
 * shape it would silently ignore.
 */
trait ValidatesSurgeConditions
{
    /**
     * @param  bool  $timeBased  The time keys are required
     * @param  bool  $demandBased  The demand ratio is required
     * @return array<string, mixed>
     */
    protected function conditionRules(bool $timeBased, bool $demandBased): array
    {
        return [
            'conditions' => ['sometimes', 'array:'.implode(',', SurgeType::allConditionKeys())],
            'conditions.days_of_week' => [Rule::requiredIf($timeBased), 'array', 'min:1'],
            'conditions.days_of_week.*' => ['integer', 'min:1', 'max:7', 'distinct'],
            'conditions.start_time' => [Rule::requiredIf($timeBased), 'date_format:H:i'],
            // An end before the start runs past midnight; equal would never match.
            'conditions.end_time' => [Rule::requiredIf($timeBased), 'date_format:H:i', 'different:conditions.start_time'],
            'conditions.min_demand_supply_ratio' => [Rule::requiredIf($demandBased), 'numeric', 'min:1.0'],
            'conditions.min_requests' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conditions.array' => 'Unknown condition. Time-based rules take days_of_week (1 = Monday … 7 = Sunday), start_time and end_time; demand-based rules take min_demand_supply_ratio and min_requests.',
            'conditions.end_time.different' => 'The end time must differ from the start time. An end before the start runs past midnight.',
        ];
    }
}
