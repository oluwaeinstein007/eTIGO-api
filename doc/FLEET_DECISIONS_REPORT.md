# E-tiGo Fleet Management — Decision Response Report

**Prepared by:** Engineering Team  
**Date:** 9 October 2026  
**Status:** Core implementation complete (Phase 1)  
**In response to:** Fleet Management Proposal v1.0 (5 Oct 2026)

---

## Table of Contents

1. [Decision Summary](#1-decision-summary)
2. [Key Model Shift: Remittance-First, Not End-of-Day Deduction](#2-key-model-shift-remittance-first-not-end-of-day-deduction)
3. [Decision-by-Decision Analysis](#3-decision-by-decision-analysis)
4. [Items That Are NOT Codebase-Enforceable](#4-items-that-are-not-codebase-enforceable)
5. [Pushback & Risk Flags](#5-pushback--risk-flags)
6. [Outstanding Items](#6-outstanding-items)
7. [Updated Earnings Model](#7-updated-earnings-model)
8. [Revised Technical Scope](#8-revised-technical-scope)

---

## 1. Decision Summary

| Question | Decision | Confidence |
|----------|----------|------------|
| Q1: Payment model | Fixed daily remittance target (implied by Q5 — not directly answered) | Needs confirmation |
| Q2: Shortfall handling | Partial deduction + shortfall tracking + grace period (duration TBD) | Decided — grace period length pending |
| Q3: Vehicle swapping | Admin-initiated only, payment history transfers | Decided |
| Q4: Early termination | Vehicle returned, contract governs send-off; HR/admin determines refund | Decided (policy-level) |
| Q5: Deduction timing | **Remittance-first per ride** — not end-of-day batch | Decided — major model shift |
| Q6: Commission rate | Zero commission for fleet drivers; all revenue from remittance | Decided |
| Q7: Price transparency | Percentage-only; hide total vehicle price | Decided — risk flagged below |
| Q8: Ownership transfer | Deferred — meeting with Chairman required | **Blocked** |
| Q9: Multi-vehicle | One vehicle per driver (Phase 1); external drivers need certifications | Decided |
| Q10: Insurance & maintenance | E-tiGo covers everything; costs bundled into daily remittance | Decided |

---

## 2. Key Model Shift: Remittance-First, Not End-of-Day Deduction

The client's Q5 answer fundamentally changes the financial model from what we proposed. This needs to be clearly understood before building anything.

### What We Proposed (End-of-Day Batch)

```
Driver earns all day → At midnight, system deducts ₦X from balance → Remainder is driver's
```

### What the Client Wants (Remittance-First)

```
Driver completes rides → First ₦40,000 of the day goes to E-tiGo →
Once ₦40,000 target is met → All subsequent ride earnings are the driver's
```

This is the **traditional Nigerian fleet/taxi remittance model** — the same model used by Bolt fleet partners and traditional taxi operators. It is an industry standard approach in this market.

### What This Means Technically

| Aspect | End-of-Day (proposed) | Remittance-First (decided) |
|--------|----------------------|---------------------------|
| When deduction happens | Once, at day's end | Accumulated per ride throughout the day |
| Driver sees earnings during day | Full gross, deduction happens later | Running tracker: "₦28,000 of ₦40,000 remitted" |
| What driver "keeps" | Gross minus deduction at night | Only earnings AFTER target is met |
| Complexity | Simple batch job | Per-ride wallet logic, real-time tracking |
| Driver psychology | "I earned ₦25K but ₦3.5K was taken" | "I need to hit ₦40K, then I earn for myself" |
| Cash ride handling | Deducted from wallet balance | Driver collects cash but it counts toward remittance target |

### Cash Ride Handling (New Requirement from Q5)

The client described a specific cash flow for cash-paid rides:

1. Rider pays cash to driver
2. Driver confirms the amount in-app
3. If driver cannot make change, the overpayment is credited to the rider's in-app wallet
4. The cash amount still counts toward the driver's daily remittance target

This creates a new flow: **cash rides still count toward remittance**, but the physical cash stays with the driver. The system tracks it as credited toward the daily target. This means at day's end, the driver may owe E-tiGo money (from cash rides counted toward remittance that the driver physically holds), or E-tiGo may owe the driver (from card rides after the target was met).

**This is a net settlement model** and requires careful accounting design.

---

## 3. Decision-by-Decision Analysis

### Q1: Daily Payment Amount — Fixed or Variable?

**Status:** Not directly answered, but Q5 implies a **fixed daily remittance target of ₦40,000**.

**Action needed:** Confirm this is the correct amount and whether it varies by vehicle type/model.

**Codebase note:** The ₦40,000 must be configurable per hire-to-own agreement, not hardcoded. Different vehicle models will have different daily targets.

---

### Q2: Shortfall Handling

**Decision:** Deduct whatever is available from the daily earnings, record the shortfall separately. Grace period before consequences.

**Grace period options mentioned:** 3 days, 7 days, or 14 days — client has not selected one.

**Action needed:** Pick the grace period duration, or confirm the tiered escalation (3 → warning, 7 → pause, 14 → review).

**Codebase-enforceable:** Yes — shortfall tracking, grace period countdown, admin alerts, auto-pause.

---

### Q3: Vehicle Swapping

**Decision:** Admin-initiated only. Happens during disrupted workflows (maintenance, driver suspension/leave). Payment history transfers. Admin board determines whether agreement changes or a temporary driver gets a fresh start.

**Codebase-enforceable:** Partially.
- Vehicle reassignment: **Yes** — admin endpoint to swap vehicles between drivers.
- Payment history transfer: **Yes** — reassign agreement to new vehicle, adjust terms.
- "Admin board determines outcome": **No** — this is a human decision. The system can provide the data (payment history, shortfalls, agreement status) but the decision is manual.

---

### Q4: Early Termination

**Decision:**
- Vehicle returned; payments during work hours must be completed per contract
- Quitting during clock-in is acceptable but only after HR/admin assessment
- Send-off money: admin/HR decides case-by-case
- Vehicle accident: driver suspended or fired depending on circumstances

**Codebase-enforceable:**
- Agreement termination status tracking: **Yes**
- Blocking account deletion while agreement is active: **Yes**
- Determining send-off amount: **No** — human/HR decision
- Determining fault in accidents: **No** — human/legal decision

**What the system can do:** Provide an admin workflow for termination (terminate agreement → record reason → record financial outcome → release vehicle for reassignment). The dollar amounts and decisions are entered by admins, not computed by the system.

---

### Q5: Deduction Timing — Remittance-First Per Ride

**Decision:** After each ride, the fare counts toward the daily remittance target (₦40,000). Once the target is met, subsequent ride earnings go to the driver.

**Codebase-enforceable:** Yes — this is core financial logic.

**New requirements this creates:**
1. Daily remittance tracker (per driver, resets daily)
2. Per-ride allocation logic: "Does this ride count toward remittance or driver earnings?"
3. Real-time progress indicator for the driver app
4. Cash ride confirmation flow
5. Cash change → rider wallet credit mechanism
6. End-of-day net settlement calculation (cash collected vs remittance owed)
7. Configurable daily reset time

---

### Q6: Commission Rate for Fleet Drivers

**Decision:** Zero commission. All platform revenue from the daily remittance. Extra charges only for penalties.

**Codebase-enforceable:** Yes — commission rate of 0% for fleet vehicles. The remittance target IS the platform's revenue from fleet drivers.

**Note:** This means the ₦40,000 daily target must cover: vehicle cost recovery + insurance + maintenance + charging/fuel + E-tiGo's operating margin. The client's Q10 answer confirms this is the intent.

---

### Q7: Vehicle Price Transparency

**Decision:** Show percentage progress only. Do not show the total vehicle price to the driver.

**Codebase-enforceable:** Yes — the API can return only `progress_percentage` without `total_price` or `remaining_balance`.

**Risk flagged:** See [Pushback Section](#5-pushback--risk-flags) below.

---

### Q8: Ownership Transfer

**Decision:** Deferred. Meeting required with Chairman (Engr. Sylvester Unokesan).

**Impact:** This blocks the ownership transfer workflow from being built. Phase 2 core (remittance tracking, daily deductions, shortfall handling) can proceed independently. Ownership transfer is Phase 3.

---

### Q9: Multi-Vehicle / External Drivers

**Decision:** One vehicle per driver. Fleet-only for Phase 1. External (driver-owned) vehicles will be introduced later with requirements:
- Vehicle inspections
- Certificate of Directorate of Road Traffic Services
- E-tiGo driver ID
- Hackney permit
- Driver certification
- E-hailing operator registration license

**Codebase-enforceable:**
- One vehicle per driver: **Yes** (already enforced)
- Inspection/certification tracking: **Future** — will need a certification/compliance model when external drivers are introduced
- The permit/license requirements are **regulatory** — the system can track whether documents are uploaded and approved, but the actual inspection is physical

---

### Q10: Insurance & Maintenance

**Decision:** Comprehensive structure provided:
- E-tiGo insures all fleet vehicles (cost built into daily remittance)
- E-tiGo covers routine maintenance (built into remittance)
- Accident excess/deductible system for at-fault accidents
- Driver financially responsible for negligence, deliberate damage, DUI, unauthorized use
- Driver-owned vehicle model deferred to a later phase

**Codebase-enforceable:**
- Tracking maintenance records per vehicle: **Yes** (future feature)
- Deducting accident excess from driver balance: **Yes**
- Determining fault/negligence: **No** — human/legal decision
- Insurance policy management: **No** — external system
- The daily remittance amount already includes insurance and maintenance — no separate tracking needed in the platform unless itemised breakdowns are requested

---

## 4. Items That Are NOT Codebase-Enforceable

These decisions require human processes, legal agreements, or physical-world actions. The platform can **support** them with data, workflows, and admin tools, but cannot **enforce** them.

| Item | Why It's Not Code-Enforceable | What the System CAN Do |
|------|------------------------------|----------------------|
| Send-off money determination (Q4) | HR/admin judgment call | Provide termination workflow with financial summary; admin enters the send-off amount |
| Accident fault determination (Q4, Q10) | Legal/insurance assessment | Record incident, link to driver/vehicle, track outcome entered by admin |
| Vehicle inspection and physical certification (Q9) | Physical process | Track certification documents (uploaded + admin-approved), expiry dates, block going online if expired |
| Insurance claims processing (Q10) | External insurer process | Record claims, link to incidents, track status |
| Contract terms and hire-purchase agreement (Q4) | Legal document | Store agreement reference and key dates; cannot enforce contract clauses |
| Driver negligence assessment (Q10) | Human judgment + legal | Provide incident data; admin records the outcome |
| Determining if a swap is warranted (Q3) | Admin board decision | Provide driver history, vehicle status, agreement details; admin initiates the swap |
| Vehicle DVLA re-registration (Q8, Q9) | Government process | Track registration status and expiry |
| Hackney permits, e-hailing licenses (Q9) | Regulatory bodies | Track document uploads and approval status |

**Recommendation:** For items the system can't enforce but needs to support, we should build an **admin incident/case management** workflow — a place where admins record decisions, link them to drivers/vehicles/agreements, and the system tracks the financial consequences.

---

## 5. Pushback & Risk Flags

### FLAG 1: Hiding Vehicle Price (Q7) — Legal Risk

**Client's decision:** Show only percentage progress, hide total vehicle price.

**Our concern:** The Federal Competition and Consumer Protection Act (FCCPA) 2018, Section 123-127, requires disclosure of material terms in hire-purchase and credit agreements. Specifically:
- Total amount payable
- Number and amount of instalments
- The cash price of the goods

The CBN Consumer Protection Framework and the Hire Purchase Act (Laws of the Federation) also require price disclosure in instalment purchase agreements.

**Risk:** A driver who has paid 80% of an undisclosed price could argue the agreement is voidable for non-disclosure of material terms. This creates legal exposure if any driver disputes the arrangement.

**Our recommendation:** Show the total vehicle price in the agreement/contract (which the driver signs), even if the in-app UI emphasises the percentage view. The signed agreement provides legal protection. The app can default to showing percentage with an option to view the full breakdown — this respects the client's UX preference while maintaining legal compliance.

**Severity:** Medium-high. Not a blocker for building the feature, but the legal team should review before launch.

---

### FLAG 2: Cash Change → Rider Wallet Credit (Q5) — Financial Regulatory Risk

**Client's decision:** When a driver can't make change on a cash ride, the overpayment is credited to the rider's in-app wallet.

**Our concern:** This creates **stored value** in the rider's wallet — essentially an e-money/prepaid instrument. Under CBN regulations (Guidelines on Electronic Money, 2015):
- Stored value instruments require a licence or partnership with a licensed institution
- There are consumer protection requirements around stored value (expiry, refund rights)

**This is not a blocker** — Flutterwave (E-tiGo's payment partner) handles the wallet infrastructure, and their licence likely covers this. But it needs to be verified.

**Our recommendation:** Confirm with Flutterwave that rider wallet credits from cash overpayment are covered under their existing regulatory setup. If so, proceed. If not, an alternative is to simply require drivers to make exact change or round down (rider gets a small discount).

**Severity:** Low-medium. Verify with Flutterwave before implementing.

---

### FLAG 3: Zero Commission Model (Q6) — Unit Economics

**Not a pushback** — this is a valid business model. But a note for awareness:

If the ₦40,000 daily remittance is E-tiGo's only revenue from fleet drivers, the unit economics must account for:
- Vehicle purchase cost amortised over the hire-to-own period
- Comprehensive insurance premiums
- Routine maintenance (scheduled servicing, tyres, brakes, EV drivetrain)
- Vehicle registration and permits
- Charging/fuel costs (if included)
- E-tiGo's operating margin (staff, tech, support)
- Vehicle depreciation
- Accident reserve

If a driver works 26 days/month at ₦40,000/day = ₦1,040,000/month revenue per vehicle. The client should validate that this covers all the above costs with acceptable margin.

**This is a business modelling exercise, not a code decision.** No action needed from engineering.

---

### FLAG 4: Remittance-First Model — Driver Experience Trade-off

**Not a pushback** — the remittance-first model is industry standard in Nigeria (Bolt fleet partners, traditional taxi operators). However, there is a well-documented trade-off:

**Pro:** Drivers are highly motivated to complete rides early in the day to "clear" their remittance and start earning for themselves. This drives utilisation.

**Con:** On slow days, a driver may work a full shift and earn less than ₦40,000, meaning they earned zero take-home pay for the day. This is the primary source of driver churn in remittance-based models.

**The grace period (Q2) mitigates this.** No action needed — just awareness for the client's driver retention strategy.

---

## 6. Outstanding Items

### Must Resolve Before Building

| Item | Status | Who Decides |
|------|--------|------------|
| Q1: Confirm daily remittance is ₦40,000 and whether it varies by vehicle type | Unanswered | Client |
| Q2: Grace period duration (3, 7, or 14 days — or tiered?) | Options given, not selected | Client |
| Q8: Post-ownership transfer process | Deferred to Chairman meeting | Engr. Sylvester Unokesan |
| Daily reset time | Not discussed | Client (suggested: 4:00 AM WAT) |
| Cash overpayment → wallet: verify Flutterwave regulatory coverage | Not started | Engineering + Flutterwave |

### Built (Phase 1 Complete)

| Feature | Status |
|---------|--------|
| Remittance agreement model (configurable target per agreement) | Done |
| Per-ride remittance accumulation and daily tracking | Done |
| Admin: create/edit/terminate/pause/resume agreements | Done |
| Shortfall tracking and recording | Done |
| Zero commission for fleet drivers | Done |
| Percentage-only progress display (driver view) | Done |
| Driver: today's remittance, history, agreement endpoints | Done |
| Daily settlement job | Done |
| 23 tests covering all flows | Done |

### Blocked

| Feature | Blocked By |
|---------|-----------|
| Ownership transfer workflow | Q8 — Chairman meeting |
| Cash overpayment → rider wallet credit | Flutterwave regulatory check |
| Grace period auto-escalation | Q2 — duration not selected |
| Admin shortfall alert notifications | Q2 — thresholds depend on grace period |

---

## 7. Updated Earnings Model

Based on the client's decisions, the fleet driver's daily earnings model is:

```
Daily Flow:
  ┌─────────────────────────────────────────────┐
  │  Ride 1: ₦3,200 → remittance (₦3,200/40,000)  │
  │  Ride 2: ₦5,800 → remittance (₦9,000/40,000)  │
  │  Ride 3: ₦4,500 → remittance (₦13,500/40,000) │
  │  ...                                            │
  │  Ride 9: ₦6,000 → remittance (₦38,000/40,000) │
  │  Ride 10: ₦5,500 → ₦2,000 to remittance        │
  │                     ₦3,500 to DRIVER             │
  │  ═══════════════════════════════════════════     │
  │  Remittance target: ₦40,000 ✅ MET              │
  │  ─────────────────────────────────────────      │
  │  Ride 11: ₦4,200 → ALL to driver               │
  │  Ride 12: ₦3,800 → ALL to driver               │
  │  ...                                            │
  └─────────────────────────────────────────────┘

  Day Summary:
    Total rides:           14
    Total fares:           ₦62,000
    Remittance (E-tiGo):   ₦40,000
    Driver take-home:      ₦22,000
    Platform commission:   ₦0 (zero for fleet)
    Shortfall:             ₦0
```

**Slow day scenario:**

```
  Day Summary:
    Total rides:           6
    Total fares:           ₦28,000
    Remittance (E-tiGo):   ₦28,000 (partial — target was ₦40,000)
    Driver take-home:      ₦0
    Platform commission:   ₦0
    Shortfall recorded:    ₦12,000
```

---

## 8. Revised Technical Scope

### Implementation Status: Phase 1 Complete

The following features have been built and tested (23 tests, all passing):

### Data Model (Implemented)

**`fleet_agreements`** — hire-to-own agreement per driver-vehicle pair

| Column | Type | Description |
|--------|------|-------------|
| id | UUID PK | |
| driver_id | FK → drivers | Driver on this agreement |
| vehicle_id | FK → vehicles | Fleet vehicle assigned |
| daily_remittance_target | decimal(12,2) | E.g., 40000.00 (configurable per agreement) |
| total_vehicle_cost | decimal(14,2) | Full purchase price (stored but not shown to driver per Q7) |
| total_remitted | decimal(14,2) | Running sum of all remittance collected |
| agreement_start_date | date | When the agreement started |
| status | string | active, paused, completed, terminated |
| shortfall_streak_days | integer | Consecutive days with shortfall (for grace period) |
| terminated_reason | text nullable | If terminated: why |
| terminated_at | timestamp nullable | |
| completed_at | timestamp nullable | When fully paid off |
| paused_at | timestamp nullable | When paused |
| created_by_admin_id | FK → users | Admin who created the agreement |
| timestamps | | |

Indexes: `(driver_id, status)`, `(vehicle_id, status)`

`progress_percentage` is computed at runtime: `(total_remitted / total_vehicle_cost) * 100`

**`daily_remittances`** — daily record per driver

| Column | Type | Description |
|--------|------|-------------|
| id | UUID PK | |
| agreement_id | FK → fleet_agreements | |
| driver_id | FK → drivers | |
| date | date | The remittance day |
| target_amount | decimal(12,2) | Daily target (snapshot from agreement) |
| remitted_amount | decimal(12,2) | Total collected toward target this day |
| shortfall_amount | decimal(12,2) | target - remitted (0 if target met) |
| target_met_at | timestamp nullable | When the target was met (null if not met) |
| ride_count | integer | Number of rides that day |
| total_fares | decimal(12,2) | Total fare value of all rides |
| driver_earnings | decimal(12,2) | What the driver kept (fares after target met) |
| settled | boolean | Whether end-of-day settlement is complete |
| timestamps | | |

Indexes: `(agreement_id, date)` UNIQUE, `(driver_id, date)`, `(settled, date)`

### API Endpoints (Implemented)

**Admin (7 endpoints):**
- `GET /admin/fleet-agreements` — list all agreements (filter by `status`, `driver_id`)
- `POST /admin/fleet-agreements` — create agreement (validates fleet vehicle + no duplicate active)
- `GET /admin/fleet-agreements/{id}` — agreement detail with 30 most recent remittance records
- `PUT /admin/fleet-agreements/{id}` — update daily target (active agreements only)
- `POST /admin/fleet-agreements/{id}/terminate` — terminate with reason
- `POST /admin/fleet-agreements/{id}/pause` — pause active agreement
- `POST /admin/fleet-agreements/{id}/resume` — resume paused agreement

**Driver (3 endpoints):**
- `GET /driver/remittance/today` — today's progress (amount remitted, target, rides, whether target met)
- `GET /driver/remittance/history` — paginated daily remittance history
- `GET /driver/fleet-agreement` — agreement summary (progress %, status — no total price per Q7)

### Service Layer (Implemented)

**`FleetRemittanceService`** handles:
- Agreement lifecycle (create, update, terminate, pause, resume)
- Per-ride remittance recording (first ₦X goes to E-tiGo, remainder to driver)
- Auto-completion when `total_remitted >= total_vehicle_cost`
- Daily settlement with shortfall streak tracking
- All operations wrapped in `DB::transaction()` with audit logging

### Background Jobs (Implemented)

- `DailyRemittanceSettlementJob` — settles unsettled remittance records for a given date, updates shortfall streak counter

### Not Yet Built (Blocked or Deferred)

| Feature | Status | Blocked By |
|---------|--------|-----------|
| Vehicle swap workflow (`POST /admin/drivers/{id}/vehicle/swap`) | Deferred | Not urgent — admin can terminate + recreate |
| Ownership transfer workflow | Blocked | Q8 — Chairman meeting |
| Cash overpayment → rider wallet credit | Blocked | Flutterwave regulatory check |
| Grace period auto-escalation thresholds | Blocked | Q2 — duration not selected |
| `RemittanceShortfallAlertJob` (admin alerts) | Deferred | Q2 — alert thresholds depend on grace period decision |
| Daily reset time scheduling (cron) | Deferred | Not discussed — suggested 4:00 AM WAT |

---

## Next Steps

### Client Action Required

1. **Confirm Q1:** Daily remittance amount (₦40,000 is currently configurable per agreement) and whether it varies by vehicle type
2. **Decide Q2:** Grace period duration (3, 7, or 14 days — or tiered?) — this unblocks auto-escalation alerts
3. **Schedule Q8:** Meeting with Chairman on ownership transfer process

### Engineering Next

1. **Verify with Flutterwave:** Cash overpayment → wallet credit regulatory status
2. **Schedule daily settlement cron:** `DailyRemittanceSettlementJob` needs a reset time (suggested: 4:00 AM WAT)
3. **Build shortfall alerts:** Once Q2 is answered, add `RemittanceShortfallAlertJob` with configured thresholds
4. **Vehicle swap endpoint:** Low priority — admin can currently terminate + recreate, but a dedicated swap endpoint preserves payment history

### Legal Review

- Hire-purchase price disclosure (Q7 risk flag) — recommended before launch

---

*This report was last updated on 9 October 2026. Phase 1 (core fleet agreement + remittance tracking) is complete and tested. Items marked "Blocked" will be unblocked once the corresponding decisions are made.*
