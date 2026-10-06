# E-tiGo — Cancellation Fee Policy Proposal

**Prepared for:** E-tiGo Business & Product Team  
**Date:** October 2026  
**Status:** Decision Required Before Phase 1 Launch  
**PRD Reference:** OQ-05 (§8.5, BE-RIDE-06)

---

## Executive Summary

Cancellation fees are a critical lever for ride-hailing platforms. They protect driver earnings, reduce operational waste, and improve matching efficiency. However, poorly designed cancellation policies create passenger friction and increase churn.

This document presents three recommended cancellation fee models, their trade-offs, edge cases, and the business decisions the E-tiGo team needs to make before launch. All models are compatible with the current ride state machine implementation.

---

## 1. Why Cancellation Fees Matter

### The Problem Without Fees

| Issue | Impact |
|-------|--------|
| Passenger cancels after driver is dispatched | Driver wastes fuel, time, loses earning opportunity |
| Serial cancellations ("fare shopping") | Inflates demand metrics; degrades driver trust |
| Cancel-after-arrival abuse | Driver drove to pickup for nothing; highest cost per event |
| Matching engine noise | Cancelled rides waste matching capacity for other passengers |

### Industry Benchmark Data

| Platform | Free Cancellation Window | Fee After Window | Fee After Arrival |
|----------|--------------------------|------------------|-------------------|
| Uber | 2 min after match or 5 min before ETA | $5–$10 flat | $5–$10 flat |
| Bolt | 3 min after match | 50% of estimated fare (capped) | Full estimated fare |
| Lyft | 2 min after match | $5–$10 flat | $5–$10 flat |
| InDrive | No cancellation fee | — | — |
| Grab (SE Asia) | 5 min grace period | Flat fee (~$1–2 USD) | Higher flat fee |

---

## 2. Recommended Models

### Model A: Time-Based Grace Period (Recommended for Launch)

**Best for:** Balanced passenger/driver experience; lowest implementation complexity.

**How it works:**
- **Free cancellation:** Within 2 minutes of driver being matched, or any time before match (searching state)
- **Fee after grace period:** Flat fee of ₦500 once the 2-minute window expires
- **Fee after driver arrival:** Higher flat fee of ₦1,000
- **In-progress rides:** Cannot be cancelled (must be completed)

| Cancellation Window | Status | Fee | Who Pays | Driver Receives |
|---------------------|--------|-----|----------|-----------------|
| Before match | Requested / Searching | ₦0 | — | — |
| Within 2 min of match | Matched / Driver En Route | ₦0 | — | — |
| After 2 min, before arrival | Driver En Route | ₦500 | Passenger | ₦500 |
| After driver arrival | Driver Arrived | ₦1,000 | Passenger | ₦1,000 |
| During ride | In Progress | Not allowed | — | — |

**Pros:**
- Simple for passengers to understand
- Clear, predictable driver compensation
- Easy to explain in the app UI ("Cancel free within 2 min")
- Lowest implementation complexity

**Cons:**
- Doesn't account for ride value (a ₦500 fee on a ₦20,000 ride is insignificant)
- Fixed fees may need adjustment per city as operations scale

---

### Model B: Percentage-of-Fare with Cap

**Best for:** Revenue-proportional fairness; scales with ride value.

**How it works:**
- **Free cancellation:** Within 2 minutes of match, or before match
- **Fee formula:** 10% of estimated fare, capped between ₦300 (minimum) and ₦2,000 (maximum)
- **After arrival multiplier:** 20% of estimated fare (same cap range)

| Cancellation Window | Fee Formula | Min | Max |
|---------------------|-------------|-----|-----|
| Before match / within 2 min | ₦0 | — | — |
| After 2 min, before arrival | 10% of estimated fare | ₦300 | ₦2,000 |
| After driver arrival | 20% of estimated fare | ₦300 | ₦2,000 |

**Example:**
- ₦3,000 ride → ₦300 fee (hits minimum)
- ₦8,000 ride → ₦800 fee
- ₦25,000 ride → ₦2,000 fee (hits cap)

**Pros:**
- Fair relative to ride value
- Discourages cancellation on high-value rides
- Cap protects passengers from excessive fees

**Cons:**
- More complex to explain in the app
- Passengers don't always know the estimated fare at cancellation time
- Requires showing the fee amount in the cancellation confirmation dialog

---

### Model C: Escalating Penalty (for Repeat Offenders)

**Best for:** Discouraging habitual cancellers; combines with Model A or B.

**How it works:**
- First 2 cancellations per day: Free (regardless of timing)
- 3rd cancellation: ₦500 fee
- 4th+ cancellation: ₦1,000 fee
- Reset counter daily at midnight (city timezone)
- Driver-side cancellations follow the same model (but reversed: driver pays penalty)

**Note:** This model is typically layered on top of Model A or B, not used alone.

---

## 3. Edge Cases & Business Decisions Required

### Decision 1: Driver-Side Cancellations

When a driver cancels after accepting a ride, should there be consequences?

| Option | Description | Recommendation |
|--------|-------------|----------------|
| **A. No fee, but track** | Log driver cancellations; flag drivers with high cancel rates for Admin review | Recommended for launch |
| **B. Acceptance rate penalty** | Drivers below 80% acceptance rate lose priority matching | Consider post-launch |
| **C. Financial penalty** | Driver is charged a fee (deducted from next payout) | Aggressive; not recommended for launch |

**Recommendation:** Option A for launch. Track driver cancellation rates in Admin dashboard. Layer in Option B once you have baseline data (Month 2–3).

---

### Decision 2: Waiting Time Before "No-Show"

When the driver has arrived and the passenger doesn't appear:

| Option | Description |
|--------|-------------|
| **A. 5-minute wait timer** | After 5 minutes at pickup, driver can mark "Passenger No-Show" → auto-cancel with fee |
| **B. 3-minute wait timer** | Shorter wait; more aggressive but better for driver utilisation |
| **C. No timer** | Driver must call passenger and manually cancel; no automatic no-show |

**Recommendation:** Option A (5 minutes). This aligns with the existing free waiting time policy (5 minutes free in the pricing config). The driver app should show a countdown timer after arrival is confirmed.

**Important:** The "no-show" cancellation should always apply the "after arrival" fee tier, not the free-cancellation tier.

---

### Decision 3: Emergency / Safety Cancellations

Should there be exceptions to cancellation fees?

| Scenario | Recommended Policy |
|----------|--------------------|
| Passenger triggers SOS | No fee; auto-waive |
| Vehicle mismatch (wrong car/plate) | No fee; waive + flag driver for review |
| Driver requests cancellation | No fee to passenger; driver's cancel rate tracked |
| System timeout (no driver found) | No fee (system-initiated) |
| Payment failure | No fee; retry or switch to cash |

**Recommendation:** Build a fee-waiver system that auto-exempts certain cancellation reasons. The `CancellationReason` enum already supports categorization. Reasons like `safety_concern`, `vehicle_mismatch`, and `emergency` should be auto-exempt.

---

### Decision 4: Fee Collection Method

How should cancellation fees be collected?

| Payment Method | Collection Approach |
|----------------|---------------------|
| Card user | Auto-charge the fee to the card on file |
| Cash user | Add fee as a "balance due" — deduct from next ride's fare, or block new ride requests until settled |

**Recommendation:** For card users, auto-charge. For cash users, block new ride creation until the outstanding fee is settled (show a "Pay Outstanding Fee" screen). This is the industry standard approach used by Uber and Bolt in cash-heavy markets.

---

### Decision 5: Refund & Dispute Process

If a passenger disputes a cancellation fee:

| Option | Description |
|--------|-------------|
| **A. Admin manual review** | Passenger submits dispute; Admin reviews GPS/timeline data; issues refund if warranted |
| **B. Auto-refund first offense** | First disputed fee is auto-refunded as a goodwill gesture; subsequent disputes require manual review |
| **C. No refunds** | All cancellation fees are final |

**Recommendation:** Option B. Auto-refund the first disputed cancellation fee per passenger per month. This reduces support load and builds goodwill. Flag accounts that dispute fees more than twice per month for Admin review.

---

### Decision 6: Nigerian Market Considerations

| Factor | Consideration |
|--------|---------------|
| **Cash-heavy market** | ~70% of rides in Lagos/Abuja are cash. Fee collection for cash users is harder. |
| **Price sensitivity** | ₦500–₦1,000 is significant for economy rides (~30–50% of minimum fare). Ensure fees don't exceed the ride value. |
| **Traffic / delays** | Lagos traffic can cause genuine delays. Consider that "driver too far" cancellations may be legitimate. |
| **Network issues** | Poor connectivity may cause accidental cancellations. Consider a 30-second "undo cancel" window. |

---

## 4. Recommended Launch Configuration

For Phase 1 launch, we recommend **Model A (Time-Based Grace Period)** with the following configuration:

```
cancellation_fee:
  enabled: true
  grace_period_minutes: 2
  fee_after_grace: 500.00          # NGN
  fee_after_arrival: 1000.00       # NGN
  currency: NGN
  no_show_wait_minutes: 5
  auto_exempt_reasons:
    - safety_concern
    - vehicle_mismatch
    - emergency
    - system_timeout
  cash_user_policy: block_new_rides
  card_user_policy: auto_charge
  dispute_auto_refund_first: true
  dispute_auto_refund_limit_per_month: 1
```

These values should be configurable per city via Admin dashboard (future requirement).

---

## 5. Implementation Impact

The current ride state machine already supports this. The cancellation fee logic hooks into `RideService::cancelRide()`:

1. Check if the ride is in a fee-eligible state
2. Calculate elapsed time since match (for grace period check)
3. Determine fee tier (free / after-grace / after-arrival)
4. Check auto-exempt reasons
5. If card: charge fee via payment gateway
6. If cash: create outstanding balance record; block future ride creation until settled
7. Record fee in `payments` table with a new type `cancellation_fee`

**Estimated implementation effort:** 2–3 days once the business decisions above are made.

---

## 6. Summary of Decisions Needed

| # | Decision | Options | Our Recommendation |
|---|----------|---------|-------------------|
| 1 | Cancellation fee model | A (Time-Based), B (Percentage), C (Escalating) | **Model A** for launch |
| 2 | Grace period duration | 2 min, 3 min, 5 min | **2 minutes** |
| 3 | Fee amounts | Flat ₦300–₦2,000 | **₦500 after grace, ₦1,000 after arrival** |
| 4 | Driver-side cancellations | No fee / rate tracking / financial penalty | **Track only** (no fee at launch) |
| 5 | No-show wait timer | 3 min / 5 min / no timer | **5 minutes** |
| 6 | Safety/emergency exemptions | Auto-exempt specific reasons | **Yes** |
| 7 | Cash user fee collection | Block new rides / deduct from next ride / ignore | **Block new ride creation** |
| 8 | Dispute handling | Manual / auto-refund first / no refunds | **Auto-refund first per month** |

**Action required:** Please review and confirm decisions 1–8 so we can implement cancellation fees before launch.

---

*Prepared by E-tiGo Engineering — October 2026*
