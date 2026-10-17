<?php

use App\Models\City;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
});

function promoPayload(array $overrides = []): array
{
    return [
        'code' => 'DASH'.fake()->unique()->numberBetween(100, 999),
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'max_discount_cap' => 1000,
        'starts_at' => now()->subHour()->toIso8601String(),
        'expires_at' => now()->addMonth()->toIso8601String(),
        ...$overrides,
    ];
}

it('returns a status for every stage of a promo', function (string $state, string $status) {
    $promo = match ($state) {
        'active' => PromoCode::factory()->create(),
        'scheduled' => PromoCode::factory()->future()->create(),
        'paused' => PromoCode::factory()->inactive()->create(),
        'expired' => PromoCode::factory()->expired()->create(),
        'paused_then_expired' => PromoCode::factory()->expired()->inactive()->create(),
        'cancelled' => PromoCode::factory()->create(['is_active' => false, 'cancelled_at' => now()]),
    };

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}")
        ->assertOk()
        ->assertJsonPath('promo.status.value', $status);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos?status={$status}")
        ->assertOk()
        ->assertJsonPath('promos.0.id', $promo->id)
        ->assertJsonCount(1, 'promos');
})->with([
    ['active', 'active'],
    ['scheduled', 'scheduled'],
    ['paused', 'paused'],
    ['expired', 'expired'],
    ['paused_then_expired', 'expired'],
    ['cancelled', 'cancelled'],
]);

it('still accepts the old inactive status filter as paused', function () {
    $paused = PromoCode::factory()->inactive()->create();
    PromoCode::factory()->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/promos?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'promos')
        ->assertJsonPath('promos.0.id', $paused->id);
});

it('runs a promo in several cities', function () {
    [$lagos, $abuja, $kano] = City::factory()->count(3)->create();

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', promoPayload(['city_ids' => [$lagos->id, $abuja->id]]))
        ->assertCreated();

    expect($response->json('promo.city_ids'))->toEqualCanonicalizing([$lagos->id, $abuja->id])
        ->and($response->json('promo.cities'))->toHaveCount(2);

    $promo = PromoCode::findOrFail($response->json('promo.id'));
    expect($promo->isAvailableInCity($lagos->id))->toBeTrue()
        ->and($promo->isAvailableInCity($kano->id))->toBeFalse();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos?city_id={$abuja->id}")
        ->assertJsonPath('promos.0.id', $promo->id);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/promos/{$promo->id}", ['city_ids' => []])
        ->assertOk()
        ->assertJsonPath('promo.city_ids', []);

    expect($promo->fresh()->isAvailableInCity($kano->id))->toBeTrue();
});

it('keeps the cities when an update leaves them out', function () {
    $city = City::factory()->create();
    $promo = PromoCode::factory()->inCities($city)->create();

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/promos/{$promo->id}", ['description' => 'New copy'])
        ->assertOk()
        ->assertJsonPath('promo.city_ids', [$city->id]);
});

it('accepts the single city_id the API took before', function () {
    $city = City::factory()->create();

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', promoPayload(['city_id' => $city->id]))
        ->assertCreated()
        ->assertJsonPath('promo.city_ids', [$city->id]);
});

it('rejects an unknown city', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', promoPayload(['city_ids' => ['9a1b2c3d-4e5f-6789-0abc-def123456789']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('city_ids.0');
});

it('maps rider eligibility onto completed-ride counts', function (string $eligibility, ?int $min, ?int $max) {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', promoPayload(['rider_eligibility' => $eligibility]))
        ->assertCreated()
        ->assertJsonPath('promo.rider_eligibility.value', $eligibility)
        ->assertJsonPath('promo.min_order_count', $min)
        ->assertJsonPath('promo.max_order_count', $max);

    expect(PromoCode::find($response->json('promo.id'))->riderEligibility()->value)->toBe($eligibility);
})->with([
    ['all', null, null],
    ['first_ride', null, 0],
    ['returning', 1, null],
]);

it('reports other ride counts as custom eligibility', function () {
    $promo = PromoCode::factory()->create(['min_order_count' => 3, 'max_order_count' => 10]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}")
        ->assertJsonPath('promo.rider_eligibility.value', 'custom');
});

it('does not take custom as an eligibility input', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', promoPayload(['rider_eligibility' => 'custom']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rider_eligibility');
});

it('maps timing onto the peak flags', function (string $timing, bool $peak, bool $offPeak) {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', promoPayload(['timing' => $timing]))
        ->assertCreated()
        ->assertJsonPath('promo.timing.value', $timing)
        ->assertJsonPath('promo.peak_only', $peak)
        ->assertJsonPath('promo.off_peak_only', $offPeak);
})->with([
    ['any_time', false, false],
    ['peak_only', true, false],
    ['off_peak_only', false, true],
]);

it('lets timing switch a peak-only promo to off-peak in one update', function () {
    $promo = PromoCode::factory()->create(['peak_only' => true]);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/promos/{$promo->id}", ['timing' => 'off_peak_only'])
        ->assertOk()
        ->assertJsonPath('promo.peak_only', false)
        ->assertJsonPath('promo.off_peak_only', true);
});

it('pauses and resumes a promo', function () {
    $promo = PromoCode::factory()->create();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/promos/{$promo->id}/pause")
        ->assertOk()
        ->assertJsonPath('message', 'Promo code paused.')
        ->assertJsonPath('promo.status.value', 'paused');

    // Pausing twice is harmless.
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/promos/{$promo->id}/pause")
        ->assertOk()
        ->assertJsonPath('promo.status.value', 'paused');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/promos/{$promo->id}/resume")
        ->assertOk()
        ->assertJsonPath('promo.status.value', 'active');

    $this->assertDatabaseHas('audit_logs', ['event' => 'promo_code_paused', 'auditable_id' => $promo->id]);
    $this->assertDatabaseHas('audit_logs', ['event' => 'promo_code_resumed', 'auditable_id' => $promo->id]);
});

it('will not resume a cancelled or expired promo', function () {
    $cancelled = PromoCode::factory()->create(['is_active' => false, 'cancelled_at' => now()]);
    $expired = PromoCode::factory()->expired()->inactive()->create();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/promos/{$cancelled->id}/resume")
        ->assertUnprocessable();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/promos/{$expired->id}/resume")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This promo code has expired. Move its end date later to run it again.');
});

it('reports performance with a bar for every day and lifetime usage', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));

    $promo = PromoCode::factory()->create([
        'total_redemption_limit' => 10,
        'starts_at' => Carbon::parse('2026-10-01 00:00:00', 'UTC'),
        'expires_at' => Carbon::parse('2026-12-01 00:00:00', 'UTC'),
    ]);

    // 23:30 UTC on the 2nd is 00:30 on the 3rd in Lagos.
    PromoRedemption::factory()->create(['promo_code_id' => $promo->id, 'discount_amount' => 300, 'redeemed_at' => Carbon::parse('2026-10-02 23:30:00', 'UTC')]);
    PromoRedemption::factory()->create(['promo_code_id' => $promo->id, 'discount_amount' => 200, 'redeemed_at' => Carbon::parse('2026-10-05 10:00:00', 'UTC')]);
    PromoRedemption::factory()->create(['promo_code_id' => $promo->id, 'discount_amount' => 100, 'redeemed_at' => Carbon::parse('2026-09-30 10:00:00', 'UTC')]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}/performance?start_date=2026-10-01&end_date=2026-10-07")
        ->assertOk()
        ->assertJsonPath('performance.period.start_date', '2026-10-01')
        ->assertJsonPath('performance.period.end_date', '2026-10-07')
        ->assertJsonPath('performance.total_redemptions', 2)
        ->assertJsonPath('performance.lifetime_redemptions', 3)
        ->assertJsonPath('performance.remaining_redemptions', 7)
        ->assertJsonPath('performance.usage_percentage', 30);

    expect((float) $response->json('performance.total_discount_given'))->toBe(500.0);

    $days = collect($response->json('performance.redemptions_by_day'));
    expect($days)->toHaveCount(7)
        ->and($days->pluck('date')->first())->toBe('2026-10-01')
        ->and($days->firstWhere('date', '2026-10-03')['count'])->toBe(1)
        ->and($days->firstWhere('date', '2026-10-02')['count'])->toBe(0)
        ->and($days->firstWhere('date', '2026-10-05')['count'])->toBe(1);

    Carbon::setTestNow();
});

it('defaults the performance range to the promo start until today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));

    $promo = PromoCode::factory()->create([
        'starts_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC'),
        'expires_at' => Carbon::parse('2026-12-01 00:00:00', 'UTC'),
    ]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}/performance")
        ->assertOk()
        ->assertJsonPath('performance.period.start_date', '2026-10-08')
        ->assertJsonPath('performance.period.end_date', '2026-10-10')
        ->assertJsonCount(3, 'performance.redemptions_by_day');

    Carbon::setTestNow();
});

it('caps the performance range at a year', function () {
    $promo = PromoCode::factory()->create();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}/performance?start_date=2024-01-01&end_date=2026-01-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');
});
