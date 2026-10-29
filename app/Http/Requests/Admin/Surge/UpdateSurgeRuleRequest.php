<?php

namespace App\Http\Requests\Admin\Surge;

use App\Enums\SurgeType;
use App\Models\SurgeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSurgeRuleRequest extends FormRequest
{
    use ValidatesSurgeConditions;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var SurgeRule $rule */
        $rule = $this->route('surgeRule');
        $type = SurgeType::tryFrom((string) $this->input('type')) ?? $rule->type;

        // Sent conditions replace the stored ones, so they must be complete
        // for the rule's type, as must a change of type.
        $replacing = $this->has('conditions') || $type !== $rule->type;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(SurgeType::class)],
            'multiplier' => ['sometimes', 'numeric', 'min:1.00', 'max:5.00'],
            ...$this->conditionRules(
                timeBased: $replacing && $type === SurgeType::TimeBased,
                demandBased: $replacing && $type === SurgeType::DemandBased,
            ),
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            // Checked against the saved dates when only one of them is sent.
            'effective_from' => array_filter([
                'sometimes',
                'date',
                ! $this->has('effective_until') && $rule->effective_until
                    ? 'before:'.$rule->effective_until->toIso8601String()
                    : null,
            ]),
            'effective_until' => [
                'nullable',
                'date',
                $this->has('effective_from') ? 'after:effective_from' : 'after:'.$rule->effective_from->toIso8601String(),
            ],
        ];
    }
}
