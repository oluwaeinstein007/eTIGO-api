<?php

namespace App\Http\Requests\Promo;

use App\Enums\PromoRiderEligibility;
use App\Enums\PromoTiming;
use Illuminate\Validation\Rule;

/**
 * The dashboard's form fields (several cities, rider eligibility, timing) and
 * how they map onto the promo's stored columns.
 */
trait MapsPromoFormFields
{
    /**
     * @return array<string, mixed>
     */
    protected function formFieldRules(): array
    {
        return [
            'city_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'city_ids.*' => ['uuid', 'distinct', 'exists:cities,id'],
            'rider_eligibility' => ['sometimes', Rule::enum(PromoRiderEligibility::class)->except(PromoRiderEligibility::Custom)],
            'timing' => ['sometimes', Rule::enum(PromoTiming::class)],
        ];
    }

    /**
     * Accept the single city_id the API took before promos could run in
     * several cities.
     */
    protected function mergeLegacyCityId(): void
    {
        if ($this->has('city_id') && ! $this->has('city_ids')) {
            $this->merge(['city_ids' => $this->input('city_id') ? [$this->input('city_id')] : []]);
        }
    }

    /**
     * The validated promo columns, with rider_eligibility and timing turned into
     * the order-count and peak flags they stand for (they take precedence over
     * those fields when both are sent).
     *
     * @return array<string, mixed>
     */
    public function promoAttributes(): array
    {
        $attributes = collect($this->validated())
            ->except(['city_ids', 'rider_eligibility', 'timing'])
            ->all();

        if ($this->filled('rider_eligibility')) {
            $attributes = [...$attributes, ...PromoRiderEligibility::from($this->validated('rider_eligibility'))->orderCounts()];
        }

        if ($this->filled('timing')) {
            $attributes = [...$attributes, ...PromoTiming::from($this->validated('timing'))->flags()];
        }

        return $attributes;
    }

    /**
     * The cities to run the promo in, or null when the request leaves them unchanged.
     *
     * @return list<string>|null
     */
    public function cityIds(): ?array
    {
        return $this->has('city_ids') ? array_values($this->validated('city_ids') ?? []) : null;
    }

    /**
     * Peak and off-peak flags as they will be saved, for the "not both" check.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: bool, 1: bool}
     */
    protected function resolvedTimingFlags(array $data, bool $currentPeak = false, bool $currentOffPeak = false): array
    {
        if (isset($data['timing']) && PromoTiming::tryFrom((string) $data['timing'])) {
            $flags = PromoTiming::from($data['timing'])->flags();

            return [$flags['peak_only'], $flags['off_peak_only']];
        }

        return [
            (bool) ($data['peak_only'] ?? $currentPeak),
            (bool) ($data['off_peak_only'] ?? $currentOffPeak),
        ];
    }
}
