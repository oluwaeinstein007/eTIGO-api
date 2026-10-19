# Gamification Design Gaps: Backend Changes

**Date:** October 19, 2026  
**Backend:** PR #112 (`feature/gamification-design-alignment`); live on staging once merged  
**Prepared for:** Admin dashboard team and designer (also read §4 if you work on the passenger app)

This answers the three questions in "Gamification — Design vs Backend". One is a design change, one is a new API field, and for the multipliers both sides change so they match.

Full request and response details are in `API_REFERENCE.md` (sections **Gamification** and **Admin — Gamification Management**). The changed requests are in the "E-tiGo API v1" Postman collection. Base URL: `/api/v1`.

---

## Backend response for each item

Paste these into the gaps document.

| # | Item | Backend response |
|---|------|------------------|
| 1 | "Discounted booking fees" is a tick box; the API stores a percentage per tier | **Design changes to match the API.** Each tier has its own discount, so an on/off switch can't hold it. Show a number field per tier for `booking_fee_discount_pct`: 0–100, up to two decimals, where 0 means no discount. Label it **"Fare discount (%)"**: it comes off the ride's base fare, and there is no separate booking fee. Current values: Silver 2, Gold 5, Platinum 10, Diamond 15 (Bronze 0). These match the client's Pricing Guide. |
| 2 | "Exclusive promotions" is a perk on the design; the API has no field | **Kept, and added to the API.** New on/off field `exclusive_promotions_enabled` on each tier, in `GET` and `PUT /admin/gamification/tiers`. It works with the promo form's existing **Minimum tier** (`min_tier_level`). A promo with a minimum above Bronze is tier-only, and a passenger can use it only if their tier reaches the minimum **and** has this perk on. Defaults: off for Bronze, on for Silver to Diamond, which is how tier-only promos already worked. |
| 3 | Multipliers: design allows 1.0×–2.0× in 0.1× steps; API allowed up to 10× and off-peak is 1.25× | **Both change, and now match: 1.00× to 3.00× in steps of 0.05×.** The API used to accept anything from 1.00 to 10.00; it now refuses values above 3.00 or not on a 0.05 step (422). Please change the design to the same range and step. 0.05 steps keep the agreed off-peak bonus of 1.25×. The 3× ceiling leaves room above the current EV bonus (2×). 10× was too high, because points move passengers up tiers and tiers give fare discounts. |

---

## 1. Tier discount field

```
PUT /admin/gamification/tiers
```

No API change except validation: `booking_fee_discount_pct` now accepts at most two decimals (`2.5` is fine, `2.555` gets a 422). Show the error under the field it names, e.g. `tiers.1.booking_fee_discount_pct`.

On the passenger side the same number is `benefits.booking_fee_discount_pct` (`GET /gamification`) and `booking_fee_discount_pct` on each tier (`GET /gamification/tiers`). Show it as "5% off fares" and hide it at 0.

## 2. Exclusive promotions

Each tier in `GET /admin/gamification/tiers` and in the `PUT` response now has:

```json
{
  "tier_level": 3,
  "tier_name": "Gold",
  "booking_fee_discount_pct": 5,
  "ev_reservation_fee_waived": false,
  "priority_matching_enabled": true,
  "exclusive_promotions_enabled": true
}
```

Send `exclusive_promotions_enabled` with each tier in the `PUT`. It is optional, so the dashboard keeps working before you add it: a tier sent without it keeps its stored value.

Two things for the promo form:

- **Minimum tier** above Bronze is what makes a promo exclusive. Bronze (or empty) means everyone can use it.
- If no tier at or above the chosen minimum has exclusive promotions on, nobody can use the promo. A hint under the field ("Only tiers with Exclusive promotions on can use this code") will save support questions.

## 3. Multipliers

```
PUT /admin/gamification/multipliers
```

`multiplier_value`: 1.00 to 3.00, step 0.05. A value outside that gets a 422 on `multipliers.N.multiplier_value`. Current values all fit: EV 2.00, off-peak 1.25, shared journey 1.50.

Bonuses that are stackable add their extra on top of 1×: EV (2×) plus off-peak (1.25×) gives 2.25×, not 2.5×. If the design shows an example, use that rule.

## 4. Passenger app

- `GET /gamification`: `benefits` has a new `exclusive_promotions` (boolean). Show it as an unlocked perk when true.
- `GET /gamification/tiers`: each tier has `exclusive_promotions_enabled`, for the "what you get at each tier" list.
- A tier-only promo the passenger can't use is refused with the existing message, "Your tier level does not qualify for this promo code.", and left out of `GET /promos/available`. No new error to handle.

## 5. What isn't breaking

Nothing breaks. The new tier field is optional on input. The tighter limits only refuse values the design would not offer anyway.
