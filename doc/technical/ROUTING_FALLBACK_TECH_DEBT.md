# Routing Fallback: Known Tech Debt

**Date:** November 8, 2026
**Context:** PR "Fare fallback transparency" made the Haversine routing fallback (used when Google and Mapbox both fail) visible on the API (`distance_source`, `is_approximate`), stopped surge from stacking on an approximate distance, and refused bookings when the only available distance is an approximate guess over 40 km (`pricing.max_approximate_distance_km`).

This document lists the deliberate scope cuts from that review, so the reasoning isn't lost. None of these are bugs — they're resilience and pricing-accuracy improvements that go beyond what an MVP needs today, deferred until real fallback-frequency data justifies the complexity.

---

## 1. Circuit breaker for the maps provider chain

**Problem:** `FailoverMapsGateway` (`app/Services/FailoverMapsGateway.php`) retries the full provider chain (Google → Mapbox → Haversine) on *every single request*. During a Google or Mapbox outage, every rider in that window pays the full HTTP timeout for each dead provider before falling through to Haversine — not just the ones who end up on an approximate quote.

**Fix:** Track provider failures in cache (e.g. "5 failures in 30s") and skip a tripped provider for a cooldown window instead of retrying it per-request.

**Why deferred:** Needs a failure-tracking store and a decision on cooldown/recovery timing; worth building once we have real outage-frequency data from the `distance_source` field this PR adds, rather than guessing at thresholds now.

---

## 2. Per-city (or per-zone) road-detour multiplier

**Problem:** `HaversineMapsGateway` (`app/Services/HaversineMapsGateway.php:21-24`) applies a single hardcoded 1.3× multiplier everywhere. Lagos's mainland–island crossings (Third Mainland Bridge, Eko Bridge, Lekki-Ikoyi Link) force detours a flat multiplier can't represent — a straight line across the Lagoon isn't a route. A grid-laid-out city and a bridge-constrained one shouldn't share one constant.

**Fix:** Move the multiplier onto `PricingConfig` (or a new per-city setting) so it's tunable without a deploy; calibrate it against real Mapbox routes per city once enough fallback events have been logged.

**Why deferred:** Needs real road-vs-straight-line data per city to calibrate against — building the knob before we have numbers to turn it to is guesswork. `distance_source`/`is_approximate` (this PR) plus the refusal log line are the prerequisite for collecting that data.

---

## 3. Zone-to-zone bridge-crossing penalty

**Problem:** Even a well-calibrated per-city multiplier is a single number; a mainland-to-island trip specifically needs a bigger correction than a same-side trip, because the detour is structural (one of two bridges), not proportional to distance.

**Fix:** A zone lookup (mainland/island/etc. per city) with a fixed additive penalty or distinct multiplier for cross-zone trips.

**Why deferred:** Refinement on top of #2 — ship the simpler per-city knob first, see whether cross-zone trips are a meaningful share of fallback events, then decide if the added complexity pays for itself.

---

## 4. Route caching with spatial binning (H3/S2)

**Problem:** There is no route cache today — every quote calls the live provider. Raw lat/lng caching wouldn't help anyway: GPS coordinates drift a few meters between app opens, so an exact-match cache key almost never hits.

**Fix:** Bin origin/destination into H3 or S2 cells (e.g. resolution 8-9) and cache route results per cell pair, with a short TTL.

**Why deferred:** A genuine latency/cost optimization, but there's no evidence yet that provider latency or cost is a problem worth the added indexing complexity. Revisit once #1 (circuit breaker) data shows how often and how long providers are actually down.

---

## 5. Road-snapping before distance calculation

**Problem:** Noisy GPS (tall buildings, poor satellite visibility) can place a pickup pin 20-30m off the actual road, which distorts distance for *any* source, not just Haversine.

**Fix:** Snap-to-road on pickup/destination coordinates before routing, using the provider's own snap feature where available.

**Why deferred:** This is a pickup-pin-accuracy problem across the whole app, not specific to the Haversine fallback this PR addresses — it belongs in a separate workstream scoped around location accuracy generally.

---

## 6. Booking can silently re-quote a different (and possibly approximate) fare

**Problem:** `POST /rides/estimate` and `POST /rides` each independently call `FareEstimationService::estimate()`, which calls the maps gateway fresh. If a routing provider goes down in the gap between the two calls — seconds to minutes, however long the passenger takes to confirm — the booking can silently commit a different fare than the one shown on the estimate screen, including flipping from a routed `distance_source` to an approximate one. This isn't new in this PR (fare, surge and `distance_km` could already drift between the two calls before `is_approximate` existed); this PR makes the drift visible via `distance_source`/`is_approximate` rather than introducing it.

**Fix:** Would need a "locked quote" concept — the estimate response carries an id/token, `POST /rides` accepts it, and the server either honors that exact quote (if still current) or returns the new quote for the passenger to re-confirm rather than booking silently. This is an API contract change across the estimate and booking endpoints, not a bug fix.

**Why deferred:** A real gap, but a genuine product/API design decision (hard block vs. soft re-confirm, how long a quote stays valid, what happens to in-flight promo validation) that shouldn't be improvised inside a routing-fallback-transparency PR. Flagging here for a deliberate follow-up.

---

## Already addressed in this PR (for reference)

- `distance_source` / `is_approximate` on every fare estimate and ride (`FareEstimationService`, `RideService`, `pricing_snapshot`)
- Surge forced to `1.0`/inactive whenever the distance is approximate (`FareEstimationService::capSurgeForApproximateRoute`)
- Bookings refused (`422 FARE_ESTIMATE_UNAVAILABLE`) when only an approximate distance is available and it exceeds `pricing.max_approximate_distance_km` (default 40km)
- Passenger-facing warning string added to `warnings` on `POST /rides/estimate` when the quote is approximate
