# E-tiGo Fleet Management — Complete Reference

**Prepared for:** Engr. Sylvester Unokesan (Chairman), Engineering Team, Designer, Frontend & Mobile Teams  
**Prepared by:** Engineering Team  
**Date:** 10 October 2026  
**Status:** Phase 1.5 Complete — API ready for integration (611 tests passing, 37 fleet-specific)  
**Context:** Following the Chairman's directive to align with industry standard practices, this document consolidates all fleet management decisions, industry justifications, technical implementation, and developer handoff into a single reference.

---

## Table of Contents

**Part I — Decisions & Industry Standard Practices**
1. [Executive Summary](#1-executive-summary)
2. [Remittance-First Per-Ride Model](#2-remittance-first-per-ride-model)
3. [Zero Commission for Fleet Drivers](#3-zero-commission-for-fleet-drivers)
4. [Tiered Shortfall Escalation (3-7-14 Days)](#4-tiered-shortfall-escalation-3-7-14-days)
5. [Price Transparency for Drivers](#5-price-transparency-for-drivers)
6. [Agreement Completion vs. Ownership Transfer](#6-agreement-completion-vs-ownership-transfer)
7. [Vehicle Swap Workflow](#7-vehicle-swap-workflow)
8. [Termination with Settlement](#8-termination-with-settlement)
9. [Daily Settlement at 4:00 AM WAT](#9-daily-settlement-at-400-am-wat)
10. [Audit Trail for All Financial Operations](#10-audit-trail-for-all-financial-operations)
11. [What Is Deferred and Why](#11-what-is-deferred-and-why)

**Part II — Decision Analysis & Risk Flags**
12. [Decision Summary (Q1–Q10)](#12-decision-summary-q1q10)
13. [Decision-by-Decision Analysis](#13-decision-by-decision-analysis)
14. [Items That Are NOT Codebase-Enforceable](#14-items-that-are-not-codebase-enforceable)
15. [Risk Flags](#15-risk-flags)
16. [Earnings Model](#16-earnings-model)

**Part III — Technical Implementation**
17. [Data Model](#17-data-model)
18. [Service Layer](#18-service-layer)
19. [Background Jobs](#19-background-jobs)
20. [Configuration](#20-configuration)

**Part IV — Developer Handoff (API & UI/UX)**
21. [API Endpoints — Driver (3)](#21-api-endpoints--driver-3)
22. [API Endpoints — Admin (11)](#22-api-endpoints--admin-11)
23. [UI/UX Guidance — Driver App](#23-uiux-guidance--driver-app)
24. [UI/UX Guidance — Admin Dashboard](#24-uiux-guidance--admin-dashboard)
25. [Enum Values](#25-enum-values)
26. [Key Business Rules for UI](#26-key-business-rules-for-ui)

**Part V — Status & Next Steps**
27. [Implementation Status](#27-implementation-status)
28. [Testing & Postman](#28-testing--postman)
29. [Outstanding Items & Next Steps](#29-outstanding-items--next-steps)

---

# Part I — Decisions & Industry Standard Practices

---

## 1. Executive Summary

E-tiGo's fleet management module enables the company to own vehicles and assign them to drivers under a hire-to-own (remittance) model. This is the dominant fleet model in Nigeria's ride-hailing and taxi industries — the same approach used by Bolt fleet partners, Uber fleet operators, InDrive fleet managers, and traditional taxi operators nationwide.

Every technical and business decision documented below follows industry-proven patterns. Where the client's original direction diverged from standard practice, we have adjusted per the Chairman's instruction to follow industry norms while clearly explaining why.

### Decision Summary Table

| # | Decision | Standard Practice Alignment |
|---|----------|---------------------------|
| 1 | Remittance-first per-ride model | Standard Nigerian fleet/taxi model (Bolt, Uber, InDrive, traditional) |
| 2 | Zero commission for fleet drivers | Standard fleet structure (commission is embedded in remittance) |
| 3 | Tiered 3-7-14 day shortfall escalation | Standard graduated response (fleet, finance, HR) |
| 4 | Full price transparency for drivers | Required by Hire Purchase Act and FCCPA |
| 5 | Financial completion ≠ ownership transfer | Standard hire-purchase practice; legally required separation |
| 6 | Vehicle swap as terminate + recreate | Standard fleet operation with audit trail preservation |
| 7 | Settlement tracking on termination | Required by hire-purchase law; standard in vehicle finance |
| 8 | 4:00 AM daily settlement | Standard fintech/ride-hailing settlement window |
| 9 | Full audit trail | Regulatory requirement (CBN, FCCPA) |
| 10 | Build when ready, not "code and deactivate" | Standard software engineering practice |

---

## 2. Remittance-First Per-Ride Model

### Decision
Each ride fare is allocated in real-time: the first portion goes toward the driver's daily remittance target (e.g., ₦40,000). Once the target is met, all subsequent earnings go directly to the driver. No commission is charged to fleet drivers.

### How It Works

```
┌────────────────────────────────────────────────────────┐
│  Daily Target: ₦40,000                                 │
│                                                        │
│  Ride 1: ₦3,200 fare → ALL to E-tiGo (₦3,200/40,000) │
│  Ride 2: ₦5,800 fare → ALL to E-tiGo (₦9,000/40,000) │
│  ...                                                   │
│  Ride 9: ₦6,000 fare → ALL to E-tiGo (₦38,000/40,000)│
│  Ride 10: ₦5,500 fare →                               │
│     ₦2,000 to E-tiGo (target met)                     │
│     ₦3,500 to DRIVER                                   │
│  ──────────────────────────────────────────────        │
│  After target met:                                     │
│  Ride 11: ₦4,200 → ALL to driver                      │
│  Ride 12: ₦3,800 → ALL to driver                      │
└────────────────────────────────────────────────────────┘
```

**Key concept:** The driver earns ₦0 until the daily target is met. After that, every fare is 100% theirs.

### Industry Standard
This is the **standard Nigerian fleet/taxi remittance model**. It is used by:

- **Bolt fleet partners** — fleet owners set a daily/weekly target; drivers remit first, keep the rest
- **Traditional taxi operators** — daily "owo oga" (owner's fee) paid before driver earns
- **Uber fleet partners** — similar remittance structure with daily targets
- **InDrive fleet managers** — daily target-based deductions

The model is universally understood by Nigerian commercial drivers and aligns with their expectations when joining a fleet.

### Why This Over End-of-Day Deduction

| Aspect | End-of-Day (proposed) | Remittance-First (decided) |
|--------|----------------------|---------------------------|
| When deduction happens | Once, at day's end | Accumulated per ride throughout the day |
| Driver sees earnings during day | Full gross, deduction happens later | Running tracker: "₦28,000 of ₦40,000 remitted" |
| What driver "keeps" | Gross minus deduction at night | Only earnings AFTER target is met |
| Driver psychology | "I earned ₦25K but ₦3.5K was taken" | "I need to hit ₦40K, then I earn for myself" |

End-of-day batch deduction creates a different psychology: drivers feel "money was taken from them." The remittance-first model is transparent — the driver knows exactly where they stand throughout the day and experiences the moment they "start earning for themselves" as a motivating milestone. This is why every major Nigerian fleet operator uses remittance-first.

### Implementation Status: Done
- Per-ride fare allocation: remittance portion vs. driver earnings
- Real-time progress tracking (driver sees "₦28,000 of ₦40,000 remitted")
- Daily target configurable per agreement (different vehicles can have different targets)
- Automatic agreement completion when total vehicle cost is fully remitted
- Fleet remittance wired into ride completion flow (`ProcessPaymentJob`)

---

## 3. Zero Commission for Fleet Drivers

### Decision
Fleet drivers pay 0% platform commission. E-tiGo's revenue comes entirely from the daily remittance.

### Industry Standard
This is the **standard practice for fleet-owned vehicles in ride-hailing**:

- **Bolt Fleet** — fleet partners (vehicle owners) pay Bolt a platform commission; their drivers pay the fleet partner a remittance. The driver does not also pay the platform a commission.
- **Uber Fleet** — same structure. The platform's cut comes from the fleet partner, not the individual fleet driver.
- **Traditional taxi** — the driver pays the owner's daily fee (remittance). There is no additional "commission" on top.

Charging both a remittance AND a commission would be non-standard and would make fleet driving economically unviable. The daily remittance target already incorporates vehicle cost, insurance, maintenance, and E-tiGo's margin.

### Implementation Status: Done
- Commission rate automatically returns 0% for any driver with an active fleet agreement
- Enforced at the service layer (`CommissionService`) — cannot be bypassed by configuration

---

## 4. Tiered Shortfall Escalation (3-7-14 Days)

### Decision
When a driver fails to meet their daily remittance target, the system tracks consecutive shortfall days and escalates through three tiers:
- **3 consecutive days** → Warning flag
- **7 consecutive days** → Review flag (admin attention required)
- **14 consecutive days** → Escalated flag (action required)

### Industry Standard
Tiered escalation is **standard practice in fleet and vehicle finance**:

- **Bolt fleet partners** — fleet owners typically have their own 3-5 day warning followed by driver reassignment or vehicle recall
- **Vehicle hire-purchase (Nigeria)** — missed payments trigger escalating notices before repossession (typically 7-14 day windows per the Hire Purchase Act)
- **Microfinance vehicle loans** — tiered default tracking with graduated response

A single fixed threshold (e.g., "action after 7 days") does not account for the difference between a temporary slow period and a persistent pattern. The tiered approach gives E-tiGo graduated data to make proportionate decisions.

### Excused Days
Not all shortfall days are the driver's fault. Industry standard practice is to account for:
- **Approved leave** — driver requested and received approval for time off
- **Vehicle downtime** — vehicle in maintenance or repair
- **Low demand** — genuinely low ride demand (admin discretion)
- **Payment system failures** — technical issues preventing ride completions

Excused days do not count toward the shortfall streak. This prevents unfair escalation and is standard in any performance-based fleet or employment system.

### Implementation Status: Done
- Automatic shortfall streak tracking per agreement
- Configurable escalation thresholds (3/7/14 days, adjustable via `config/fleet.php`)
- Excused-day support with reason categories
- Admin endpoint to mark a day as excused (decrements streak by 1)
- `RemittanceShortfallAlertJob` runs daily at 4:00 AM WAT to flag agreements at each tier
- Audit log for all escalation changes

---

## 5. Price Transparency for Drivers

### Decision
Drivers now see the full financial terms of their agreement: total vehicle cost, total remitted, remaining amount, and progress percentage. The UI should emphasise percentage progress but the financial details must be accessible.

### Industry Standard
**Full financial disclosure is a legal requirement for hire-purchase agreements in Nigeria:**

- **Hire Purchase Act (Laws of the Federation)** — requires disclosure of the cash price, total hire-purchase price, and all payment terms
- **Federal Competition and Consumer Protection Act (FCCPA) 2018, Sections 123-127** — requires disclosure of material terms in credit and instalment purchase agreements
- **CBN Consumer Protection Framework** — requires price disclosure in consumer financial products

Every reputable hire-purchase provider in Nigeria — from vehicle finance companies to mobile phone instalment plans (e.g., MTN Device Financing, 9Mobile handset plans) — discloses the total cost to the buyer. Hiding the total price would:

1. Violate the Hire Purchase Act
2. Create legal exposure if any driver disputes the arrangement
3. Undermine trust with drivers

### What Changed
The original decision (Q7) was to hide the vehicle price and show only percentage progress. This was **reversed** per the Chairman's directive to follow standard practice. The engineering team had previously flagged this as a legal risk — the reversal aligns E-tiGo with industry norms.

### Implementation Status: Done
- All financial terms (`total_vehicle_cost`, `remaining_amount`, `total_remitted`, `progress_percentage`) visible to drivers via API
- Admin sees additional operational fields (shortfall flags, settlement details) that are not relevant to drivers

---

## 6. Agreement Completion vs. Ownership Transfer

### Decision
When a driver's total remittance reaches the vehicle's total cost, the agreement status changes to "Financially Complete." This does **not** automatically transfer legal ownership of the vehicle.

### Industry Standard
**Separating financial completion from ownership transfer is standard in hire-purchase:**

- **Hire Purchase Act** — ownership transfers only when "the property in the goods shall pass to the hirer" upon completion of ALL terms, not just payment
- **Vehicle finance companies** — final payment triggers a process: title transfer, re-registration with DVLA, insurance update. This is never automatic.
- **Bolt/Uber fleet** — vehicle transfer after full payment requires physical handover, documentation, and re-registration

Automatic ownership transfer would be dangerous — there may be outstanding obligations (vehicle condition, contract terms, documentation) beyond just the payment amount.

### Implementation Status: Done
- "Completed" status renamed to "Financially Complete" to avoid confusion
- Ownership transfer is a separate manual/legal process (blocked pending Chairman meeting for the specific workflow)
- `isFinished()` helper distinguishes between "payment done" and "agreement closed"

---

## 7. Vehicle Swap Workflow

### Decision
Admin can swap a fleet driver's vehicle. This terminates the old agreement and creates a new one with the replacement vehicle. The admin chooses whether to carry over the amount already remitted.

### Industry Standard
Vehicle swaps happen in fleet operations for:
- Vehicle maintenance or breakdown
- Vehicle upgrade or downgrade
- Driver performance (graduating to a better vehicle)

**The terminate-and-recreate approach is standard:**
- **Bolt fleet partners** — when a vehicle is swapped, a new arrangement is created. The fleet partner decides whether to credit previous payments.
- **Vehicle leasing companies** — lease termination + new lease agreement, with or without transfer of paid equity
- **Commercial fleet operators** — new assignment, new contract

The alternative (modifying the existing agreement to point to a new vehicle) would break the audit trail and make financial reconciliation difficult. A clean termination + new agreement preserves full history.

### Implementation Status: Done
- Admin swap endpoint: terminates old agreement, unassigns old vehicle, assigns new, creates new agreement
- Optional carry-over of total remitted amount (admin decision)
- Optional new daily target and total cost (for different vehicle model)
- Full audit log recording both old and new agreement IDs

---

## 8. Termination with Settlement

### Decision
When an agreement is terminated early, the admin records:
- Vehicle return status (returned, pending return, not returned, damaged)
- Outstanding amount owed
- Settlement amount agreed
- Settlement notes

### Industry Standard
**Structured settlement recording is standard in vehicle finance and fleet operations:**

- **Hire-purchase termination** — the Hire Purchase Act requires accounting for: amount paid, amount outstanding, value of goods returned, and any settlement agreed
- **Vehicle leasing** — early termination agreements always document the financial reconciliation
- **Fleet operators** — vehicle return condition affects settlement (damage deductions, outstanding fees)

Recording this data digitally rather than on paper/spreadsheet is a maturity marker that financial auditors and regulators expect from technology platforms.

### Implementation Status: Done
- Termination with settlement endpoint capturing all financial details
- Vehicle return status tracking (returned, pending, not returned, damaged)
- Outstanding and settlement amount recording
- Free-text settlement notes for context
- Audit log for every termination with settlement

---

## 9. Daily Settlement at 4:00 AM WAT

### Decision
The system settles each day's remittance records at 4:00 AM West Africa Time. This time is configurable via environment variable.

### Industry Standard
- **Bolt** — daily settlement window in the early morning hours
- **Banking/fintech** — end-of-day reconciliation typically runs between 2-5 AM
- **Ride-hailing platforms** — daily earnings calculated in the early morning for the previous day

4:00 AM WAT is after the latest reasonable ride in most Nigerian cities and before the earliest morning commuters, making it an appropriate cut-off.

### Implementation Status: Done
- Configurable reset time via `config/fleet.php` and environment variable (`FLEET_DAILY_RESET_TIME`)
- Two scheduled jobs at reset time: `DailyRemittanceSettlementJob` and `RemittanceShortfallAlertJob`
- Timezone-aware scheduling (`Africa/Lagos`)

---

## 10. Audit Trail for All Financial Operations

### Decision
Every financial operation — agreement creation, updates, terminations, settlements, vehicle swaps, excused days, shortfall escalations — creates an audit log entry.

### Industry Standard
**Comprehensive audit logging is required by Nigerian financial regulations:**

- **CBN Consumer Protection Framework** — requires records of all customer financial transactions
- **FCCPA** — requires businesses to maintain records of consumer transactions
- **ISO 27001 / SOC 2** — security and compliance frameworks require audit trails for financial operations

For a platform handling daily financial transactions between the company and drivers, a complete audit trail is not optional — it is a regulatory and operational necessity.

### Implementation Status: Done
- Audit log entries for: agreement creation, updates, pauses, resumes, terminations, settlements, vehicle swaps, excused days, shortfall flag changes
- Each entry records: who performed the action, when, and the relevant details

---

## 11. What Is Deferred and Why

| Feature | Reason for Deferral | Industry Precedent |
|---------|--------------------|--------------------|
| Ownership transfer workflow | Awaiting Chairman meeting (Q8) | Standard: separate legal/admin process; not automated |
| Cash overpayment → wallet credit | Ready to build — Paystack confirmed (PSSP licence covers stored value) | Standard: requires licensed stored-value provider |
| Fleet maintenance module | Phase 2 — separate product domain | Standard: fleet management platforms (Fleetio, Samsara) treat this as a separate module |
| Independent driver onboarding | Client said later phase | Standard: fleet and independent drivers are different compliance tracks |
| EV integration | No EV fleet yet | Standard: EV telemetry is a separate integration layer (API partnerships with vehicle OEMs) |

### What We Are NOT Building (Per Chairman's Directive)

The client originally asked to "code everything but leave it inactive." Per the Chairman's instruction to follow standard practice:

- **We do not build inactive features.** Industry practice is to design for extensibility (our database schema and service interfaces support future features) but not build, test, and maintain code that has no user. Inactive code creates maintenance burden, misleading test coverage, and technical debt.
- **We build when needed.** Each feature listed above can be built when its prerequisites are met and the business is ready to use it. The architecture supports this without rework.

---

# Part II — Decision Analysis & Risk Flags

---

## 12. Decision Summary (Q1–Q10)

| Question | Decision | Status |
|----------|----------|--------|
| Q1: Payment model | Fixed daily remittance target (₦40,000 default, configurable per agreement) | Needs confirmation on per-vehicle-type variance |
| Q2: Shortfall handling | Tiered 3-7-14 day escalation: warning → review → escalated | Decided and implemented |
| Q3: Vehicle swapping | Admin-initiated, terminate + recreate, optional carry-over | Decided and implemented |
| Q4: Early termination | Vehicle returned, settlement recorded, HR/admin determines details | Decided and implemented |
| Q5: Deduction timing | **Remittance-first per ride** — not end-of-day batch | Decided and implemented |
| Q6: Commission rate | Zero commission for fleet drivers; all revenue from remittance | Decided and implemented |
| Q7: Price transparency | **Reversed** — drivers see full financial terms; UI emphasises percentage | Decided and implemented |
| Q8: Ownership transfer | Deferred — meeting with Chairman required | **Blocked** |
| Q9: Multi-vehicle | One vehicle per driver (Phase 1); external drivers later | Decided |
| Q10: Insurance & maintenance | E-tiGo covers everything; costs bundled into daily remittance | Decided (module deferred to Phase 2) |

---

## 13. Decision-by-Decision Analysis

### Q1: Daily Payment Amount — Fixed or Variable?

**Status:** Not directly answered, but Q5 implies a **fixed daily remittance target of ₦40,000**.

**Action needed:** Confirm this is the correct amount and whether it varies by vehicle type/model.

**Implementation note:** The ₦40,000 is configurable per agreement — different vehicle models can have different daily targets. Default is set via `config/fleet.php`.

---

### Q2: Shortfall Handling

**Decision:** Tiered 3-7-14 day escalation with excused-day support.

**Implementation:** Complete — automatic streak tracking, configurable thresholds, excused-day admin endpoint, `RemittanceShortfallAlertJob` runs daily.

---

### Q3: Vehicle Swapping

**Decision:** Admin-initiated only. Payment history optionally transfers via carry-over.

**Implementation:** Complete — `POST /admin/fleet-agreements/{id}/swap` with carry-over option. Terminates old, creates new, full audit log.

---

### Q4: Early Termination

**Decision:** Vehicle returned, settlement recorded, HR/admin determines financial details.

**Implementation:** Complete — `POST /admin/fleet-agreements/{id}/terminate-settle` with vehicle return status, outstanding amount, settlement amount, and notes.

**What the system cannot do:** Determining send-off amounts or fault in accidents — these are human/HR/legal decisions. The system provides the data and records the outcome.

---

### Q5: Deduction Timing — Remittance-First Per Ride

**Decision:** After each ride, the fare counts toward the daily remittance target. Once met, subsequent earnings go to the driver.

**Implementation:** Complete — wired into `ProcessPaymentJob`, automatic per-ride recording.

**Cash ride handling:** Cash rides count toward remittance target. The cash overpayment → rider wallet credit feature is now **unblocked** — Paystack has been selected as the payment provider, and their PSSP licence covers stored-value wallet operations. Implementation can proceed when prioritised.

---

### Q6: Commission Rate for Fleet Drivers

**Decision:** Zero commission. All platform revenue from the daily remittance.

**Implementation:** Complete — enforced in `CommissionService@getRate()`.

**Unit economics note:** If a driver works 26 days/month at ₦40,000/day = ₦1,040,000/month revenue per vehicle. The client should validate this covers: vehicle cost amortisation, insurance, maintenance, permits, E-tiGo's margin, depreciation, and accident reserve.

---

### Q7: Vehicle Price Transparency

**Original decision:** Hide total vehicle price from drivers.  
**Reversed to:** Show full financial terms, emphasise percentage in UI.

**Implementation:** Complete — `total_vehicle_cost` and `remaining_amount` visible to all users. See [Section 5](#5-price-transparency-for-drivers) for industry standard justification.

---

### Q8: Ownership Transfer

**Decision:** Deferred. Meeting required with Chairman (Engr. Sylvester Unokesan).

**Impact:** Ownership transfer workflow cannot be built until the specific process is defined. The "Financially Complete" status is in place — this is the handoff point.

---

### Q9: Multi-Vehicle / External Drivers

**Decision:** One vehicle per driver. Fleet-only for Phase 1. External (driver-owned) vehicles later with requirements: vehicle inspections, Certificate of Directorate of Road Traffic Services, E-tiGo driver ID, hackney permit, driver certification, e-hailing operator registration license.

**Implementation:** One vehicle per driver is already enforced. Certification tracking will be built when external drivers are introduced.

---

### Q10: Insurance & Maintenance

**Decision:** E-tiGo insures all fleet vehicles (cost built into daily remittance). Routine maintenance included. Accident excess/deductible system for at-fault accidents.

**Implementation:** The daily remittance amount already includes insurance and maintenance — no separate tracking needed unless itemised breakdowns are requested. Fleet maintenance module deferred to Phase 2.

---

## 14. Items That Are NOT Codebase-Enforceable

These decisions require human processes, legal agreements, or physical-world actions. The platform **supports** them with data, workflows, and admin tools, but cannot **enforce** them.

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

---

## 15. Risk Flags

### FLAG 1: Hiding Vehicle Price (Q7) — RESOLVED

**Original risk:** Hiding the total vehicle price would violate the Hire Purchase Act and FCCPA disclosure requirements.

**Resolution:** Q7 reversed. Drivers now see full financial terms. **This risk is eliminated.**

---

### FLAG 2: Cash Change → Rider Wallet Credit (Q5) — RESOLVED

**Risk:** Creating stored value in the rider's wallet may require a licence under CBN Guidelines on Electronic Money (2015).

**Resolution:** Paystack selected as payment provider (October 2026). Paystack holds a PSSP licence from CBN which covers stored-value wallet operations, including OPay transfer support for driver payouts. **This risk is eliminated.** The wallet credit feature can now be built when prioritised.

---

### FLAG 3: Zero Commission Model (Q6) — AWARENESS ONLY

Not a risk — this is a valid business model. But the ₦40,000 daily target must cover all costs (vehicle, insurance, maintenance, margin, depreciation, accident reserve). This is a business modelling exercise, not a code decision.

---

### FLAG 4: Remittance-First Model — Driver Experience

Not a risk — the model is industry standard. However, on slow days a driver may earn zero take-home pay. The tiered escalation with excused days mitigates this. Awareness for driver retention strategy.

---

## 16. Earnings Model

### Good Day Scenario

```
Day Summary:
  Total rides:           14
  Total fares:           ₦62,000
  Remittance (E-tiGo):   ₦40,000
  Driver take-home:      ₦22,000
  Platform commission:   ₦0 (zero for fleet)
  Shortfall:             ₦0
```

### Slow Day Scenario

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

# Part III — Technical Implementation

---

## 17. Data Model

### `fleet_agreements` — hire-to-own agreement per driver-vehicle pair

| Column | Type | Description |
|--------|------|-------------|
| id | UUID PK | |
| driver_id | FK → drivers | Driver on this agreement |
| vehicle_id | FK → vehicles | Fleet vehicle assigned |
| daily_remittance_target | decimal(12,2) | E.g., 40000.00 (configurable per agreement) |
| total_vehicle_cost | decimal(14,2) | Full purchase price (visible to driver per Q7 reversal) |
| total_remitted | decimal(14,2) | Running sum of all remittance collected |
| agreement_start_date | date | When the agreement started |
| status | string | active, paused, completed, terminated |
| shortfall_streak_days | integer | Consecutive days with shortfall |
| shortfall_flag | string nullable | warning, review, or escalated |
| shortfall_flagged_at | timestamp nullable | When the flag was last set |
| vehicle_return_status | string nullable | returned, pending_return, not_returned, damaged |
| outstanding_amount | decimal(14,2) nullable | Outstanding amount on termination |
| settlement_amount | decimal(14,2) nullable | Settlement amount agreed |
| settlement_notes | text nullable | Free-text settlement details |
| settled_at | timestamp nullable | When settlement was recorded |
| terminated_reason | text nullable | If terminated: why |
| terminated_at | timestamp nullable | |
| completed_at | timestamp nullable | When fully paid off |
| paused_at | timestamp nullable | When paused |
| created_by_admin_id | FK → users | Admin who created the agreement |
| timestamps | | |

Indexes: `(driver_id, status)`, `(vehicle_id, status)`

`progress_percentage` is computed at runtime: `(total_remitted / total_vehicle_cost) * 100`

### `daily_remittances` — daily record per driver

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
| excused_reason | text nullable | Why this day was excused (e.g., "vehicle_downtime: in maintenance") |
| timestamps | | |

Indexes: `(agreement_id, date)` UNIQUE, `(driver_id, date)`, `(settled, date)`

---

## 18. Service Layer

### `FleetRemittanceService`

| Method | Description |
|--------|-------------|
| `createAgreement()` | Create new agreement with validation (no duplicate active, fleet vehicle required) |
| `updateAgreement()` | Update daily target (active only), audit log with old/new values |
| `terminateAgreement()` | Basic termination with reason |
| `terminateWithSettlement()` | Termination with vehicle return status and financial reconciliation |
| `pauseAgreement()` / `resumeAgreement()` | Toggle active/paused with audit log |
| `recordRideRemittance()` | Per-ride allocation with row-level locking; auto-completes when total reached |
| `settleDay()` | End-of-day settlement; skips excused days; updates shortfall streak |
| `excuseDay()` | Mark remittance day as excused; decrements streak by 1; audit log |
| `swapVehicle()` | Terminate old agreement, unassign old vehicle, assign new, create new agreement |
| `getOrCreateTodayRemittance()` | Gets or creates today's daily remittance record |

### `CommissionService`

- `getRate()` returns 0.0 for drivers with active fleet agreements (early return before override/global/default logic)

### `ProcessPaymentJob`

- After payment processing, calls `FleetRemittanceService@recordRideRemittance()` for fleet drivers

---

## 19. Background Jobs

| Job | Schedule | Description |
|-----|----------|-------------|
| `DailyRemittanceSettlementJob` | Daily at 4:00 AM WAT | Ensures every active agreement has a remittance row for the date, then settles all unsettled records |
| `RemittanceShortfallAlertJob` | Daily at 4:00 AM WAT | Checks active agreements against thresholds, assigns shortfall flags, creates audit logs |

---

## 20. Configuration

**File:** `config/fleet.php`

| Key | Default | Env Variable | Description |
|-----|---------|-------------|-------------|
| `daily_reset_time` | `04:00` | `FLEET_DAILY_RESET_TIME` | When daily settlement runs |
| `shortfall_warning_days` | `3` | `FLEET_SHORTFALL_WARNING_DAYS` | Days before warning flag |
| `shortfall_review_days` | `7` | `FLEET_SHORTFALL_REVIEW_DAYS` | Days before review flag |
| `shortfall_escalation_days` | `14` | `FLEET_SHORTFALL_ESCALATION_DAYS` | Days before escalated flag |
| `default_daily_target` | `40000` | `FLEET_DEFAULT_DAILY_TARGET` | Default daily remittance target |

---

# Part IV — Developer Handoff (API & UI/UX)

**For:** Designer, Frontend, and Mobile Teams  
**Base URL:** `{APP_URL}/api/v1`

---

## 21. API Endpoints — Driver (3)

These are what the **mobile app** consumes.

### 1. Today's Remittance Progress
```
GET /driver/remittance/today
Authorization: Bearer {driver_token}
```

Returns the real-time remittance progress for today. Primary data source for the driver's daily dashboard.

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `target_amount` | string (decimal) | Daily target, e.g. `"40000.00"` |
| `remitted_amount` | string (decimal) | Amount collected toward target so far |
| `shortfall_amount` | string (decimal) | How much is still needed (`target - remitted`, 0 if met) |
| `target_met` | boolean | `true` once daily target is fully met |
| `target_met_at` | datetime / null | Timestamp when target was met |
| `ride_count` | integer | Number of rides completed today |
| `total_fares` | string (decimal) | Sum of all ride fares today |
| `driver_earnings` | string (decimal) | What the driver has earned AFTER target was met |
| `excused_reason` | string / null | If day is excused, shows the reason |

Returns `{"remittance": null}` if the driver has no active fleet agreement.

### 2. Remittance History
```
GET /driver/remittance/history
Authorization: Bearer {driver_token}
```

Paginated list of daily remittance records, most recent first. Same fields as today's endpoint, plus `settled` (boolean) and `date`.

Standard pagination via `meta` block (`current_page`, `last_page`, `per_page`, `total`).

### 3. Fleet Agreement
```
GET /driver/fleet-agreement
Authorization: Bearer {driver_token}
```

Returns the driver's active fleet agreement summary.

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `daily_remittance_target` | string (decimal) | The daily amount they need to remit |
| `total_vehicle_cost` | string (decimal) | Full vehicle purchase price |
| `total_remitted` | string (decimal) | Total amount remitted across all days |
| `remaining_amount` | string (decimal) | How much is left to pay |
| `progress_percentage` | float | 0–100, progress toward vehicle ownership |
| `agreement_start_date` | date string | When the agreement started |
| `status` | string | `active`, `paused`, `completed`, `terminated` |
| `shortfall_streak_days` | integer | Consecutive days where target was not met |
| `vehicle` | object | Vehicle details (make, model, plate, etc.) |

**Design note:** Drivers see `total_vehicle_cost` and `remaining_amount` alongside `progress_percentage`. The dashboard should emphasise percentage progress but the driver must have access to the full financial terms per hire-purchase disclosure requirements.

Returns `{"agreement": null}` if no active fleet agreement.

---

## 22. API Endpoints — Admin (11)

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,finance`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/admin/fleet-agreements` | List all agreements (filter by `status`, `driver_id`) |
| `POST` | `/admin/fleet-agreements` | Create new agreement |
| `GET` | `/admin/fleet-agreements/{id}` | Agreement detail + last 30 remittance records |
| `PUT` | `/admin/fleet-agreements/{id}` | Update daily target (active only) |
| `POST` | `/admin/fleet-agreements/{id}/terminate` | Terminate with reason |
| `POST` | `/admin/fleet-agreements/{id}/terminate-settle` | Terminate with full settlement details |
| `POST` | `/admin/fleet-agreements/{id}/pause` | Pause (e.g., vehicle maintenance) |
| `POST` | `/admin/fleet-agreements/{id}/resume` | Resume paused agreement |
| `POST` | `/admin/fleet-agreements/{id}/swap` | Swap vehicle (terminates old, creates new agreement) |
| `POST` | `/admin/fleet-agreements/{id}/remittances/{remittance_id}/excuse` | Mark a day as excused |

Admin responses include additional fields: `shortfall_flag`, `shortfall_flagged_at`, `vehicle_return_status`, `outstanding_amount`, `settlement_amount`, `settlement_notes`.

### Creating an Agreement (Admin Flow)

1. Mark a vehicle as fleet: `PATCH /admin/drivers/{driver_id}/vehicle/fleet`
2. Create the agreement: `POST /admin/fleet-agreements` with:
   - `driver_id` (UUID)
   - `vehicle_id` (UUID, must be `is_fleet: true`)
   - `daily_remittance_target` (number, min 1000)
   - `total_vehicle_cost` (number, min 100000)
   - `agreement_start_date` (date, today or future)

A driver can only have **one active agreement** at a time.

### Swap Fleet Vehicle

```
POST /admin/fleet-agreements/{id}/swap
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `new_vehicle_id` | UUID | Yes | Replacement fleet vehicle (must be unassigned) |
| `reason` | string | Yes | Reason for the swap (max 1000) |
| `carry_over_remitted` | boolean | Yes | Whether to carry over total_remitted |
| `daily_remittance_target` | number | No | New daily target (defaults to previous) |
| `total_vehicle_cost` | number | No | New total cost (defaults to previous) |

### Terminate with Settlement

```
POST /admin/fleet-agreements/{id}/terminate-settle
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reason` | string | Yes | Reason for termination (max 1000) |
| `vehicle_return_status` | string | No | `returned`, `pending_return`, `not_returned`, `damaged` |
| `outstanding_amount` | number | No | Outstanding amount owed |
| `settlement_amount` | number | No | Settlement amount agreed |
| `settlement_notes` | string | No | Free-text notes (max 2000) |

### Excuse Remittance Day

```
POST /admin/fleet-agreements/{id}/remittances/{remittance_id}/excuse
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reason` | string | Yes | `approved_leave`, `vehicle_downtime`, `low_demand`, `payment_failure`, `other` |
| `notes` | string | No | Additional context (max 1000) |

---

## 23. UI/UX Guidance — Driver App

### 1. Daily Remittance Dashboard (Primary Screen for Fleet Drivers)

This should be prominently visible when the driver is online. Think of it like a fuel gauge.

**States to handle:**

| State | Visual Suggestion |
|-------|-------------------|
| Target not yet met | Progress bar/circle showing `remitted_amount / target_amount`. Show "₦28,500 of ₦40,000" |
| Target met | Celebration moment. Switch to "You're earning! ₦X earned so far" |
| No agreement | Hide the remittance widget entirely — this is a regular (non-fleet) driver |

**Data to display:**
- Progress toward daily target (bar, circle, or percentage)
- Number of rides completed today
- Amount still needed: `shortfall_amount`
- Driver earnings (only meaningful after target met)
- Time target was met (`target_met_at`) — e.g., "Target met at 2:22 PM"

### 2. Remittance History

Calendar or list view showing daily records. Each day shows:
- Date
- Target met (yes/no — use checkmark/cross)
- Amount remitted vs target
- Number of rides
- Driver earnings for that day
- Shortfall (if any, highlighted)
- Excused days marked distinctly (grey/neutral, not red)

Consider colour-coding: green for target met, red/amber for shortfall days, grey for excused days.

### 3. Fleet Agreement Summary

A card or screen showing:
- Vehicle info (make, model, plate)
- Daily remittance target
- Progress toward ownership (percentage — primary display)
- Total vehicle cost, total remitted, remaining amount (accessible via "View details" or expandable section)
- Agreement status
- Shortfall streak (if > 0, show as a warning)
- Agreement start date

**Design note:** Lead with the percentage progress bar. The full financial details should be accessible but not the default view. This satisfies both the client's UX preference (percentage emphasis) and legal disclosure requirements.

---

## 24. UI/UX Guidance — Admin Dashboard

### 1. Fleet Agreements List

Table/list view with columns:
- Driver name
- Vehicle (make + plate)
- Daily target
- Total remitted / Total cost (admin sees both)
- Progress %
- Status (with colour badges: green=active, yellow=paused, grey=completed, red=terminated)
- Shortfall streak (flag if > 0)
- Shortfall flag (warning/review/escalated — colour-coded)

Filters: status dropdown, driver search

### 2. Fleet Agreement Detail

- All financial data (admin sees everything including settlement details)
- Recent remittance records (last 30 days shown by default)
- Action buttons: Pause, Resume, Terminate, Swap, Terminate with Settlement (context-dependent — only show valid actions for current status)
- Excuse day action on individual remittance records
- Audit trail (agreement history)

### 3. Create Agreement Form

Fields:
- Driver selector (searchable dropdown)
- Vehicle selector (filtered to fleet vehicles only)
- Daily remittance target (number input, default 40000)
- Total vehicle cost (number input)
- Agreement start date (date picker, today or future)

Validation messages to handle:
- "Driver already has an active fleet agreement"
- "Vehicle must be marked as fleet before creating an agreement"

---

## 25. Enum Values

### Fleet Agreement Status

| Value | Label | Description |
|-------|-------|-------------|
| `active` | Active | Agreement is in force, remittance is being tracked |
| `paused` | Paused | Temporarily suspended (e.g., vehicle maintenance, driver leave) |
| `completed` | Financially Complete | Total vehicle cost fully remitted — ownership transfer is a separate manual process |
| `terminated` | Terminated | Agreement ended early by admin (with reason) |

### Shortfall Flag

| Value | Trigger | Description |
|-------|---------|-------------|
| `warning` | 3+ consecutive shortfall days | Initial alert to admin |
| `review` | 7+ consecutive shortfall days | Admin review required |
| `escalated` | 14+ consecutive shortfall days | Escalation — action required |

### Vehicle Return Status

| Value | Description |
|-------|-------------|
| `returned` | Vehicle physically returned to E-tiGo |
| `pending_return` | Return agreed but not yet completed |
| `not_returned` | Vehicle not returned |
| `damaged` | Vehicle returned in damaged condition |

### Excused Day Reasons

| Value | Description |
|-------|-------------|
| `approved_leave` | Driver on approved leave |
| `vehicle_downtime` | Vehicle under maintenance or repair |
| `low_demand` | Low ride demand (admin discretion) |
| `payment_failure` | Payment system issue |
| `other` | Other reason (requires notes) |

---

## 26. Key Business Rules for UI

1. **Fleet drivers earn ₦0 until daily target is met.** The UI should make this crystal clear — show it as "Working toward today's target" not "Earnings: ₦0".

2. **Emphasise percentage progress but show full financial terms.** The UI should lead with percentage (e.g., "25% toward ownership") but the driver must be able to view total vehicle cost, remaining amount, and total remitted. This is a legal requirement for hire-purchase agreements.

3. **One active agreement per driver.** The create agreement form should validate this and show a clear error if violated.

4. **Fleet vehicles skip plate KYC verification.** When a vehicle is marked as fleet, the KYC flow changes — NIN + Driver's License is sufficient (no plate verification needed since E-tiGo owns the vehicle).

5. **Zero commission for fleet drivers.** The earnings display should NOT show any commission deduction line. Fleet drivers see only: remittance vs. personal earnings.

6. **Agreement auto-completes financially.** When `total_remitted >= total_vehicle_cost`, the agreement status changes to `completed` (financially complete). This does NOT mean ownership has transferred — that is a separate administrative and legal process. The driver should see a "Payment obligations fulfilled" state, not an ownership transfer celebration.

---

# Part V — Status & Next Steps

---

## 27. Implementation Status

### Phase 1 (Complete)

| Feature | Status |
|---------|--------|
| Remittance agreement model (configurable target per agreement) | Done |
| Per-ride remittance accumulation and daily tracking | Done |
| Admin: create/edit/terminate/pause/resume agreements | Done |
| Shortfall tracking and recording | Done |
| Zero commission for fleet drivers | Done |
| Driver: today's remittance, history, agreement endpoints | Done |
| Daily settlement job | Done |

### Phase 1.5 (Complete — October 2026)

| Feature | Status |
|---------|--------|
| Fleet remittance wired into ride completion flow | Done — automatic per-ride recording |
| Daily settlement cron at 4:00 AM WAT (configurable) | Done |
| 3-7-14 day shortfall escalation with auto-flagging | Done |
| Excused-day support (leave, downtime, low demand) | Done |
| Vehicle swap endpoint with carry-over option | Done |
| Termination with settlement workflow | Done |
| Zero commission enforcement in CommissionService | Done |
| Full financial terms visible to drivers (Q7 reversal) | Done |
| Fleet configuration file (reset time, thresholds) | Done |

### Not Yet Built

| Feature | Status | Blocked By | Impact on UI |
|---------|--------|-----------|-------------|
| Ownership transfer workflow | Blocked | Q8 — Chairman meeting | Don't build a "Transfer ownership" button yet |
| Cash overpayment → rider wallet credit | Unblocked | Paystack confirmed — ready to build when prioritised | No cash change → wallet feature yet |
| Fleet maintenance module | Deferred | Phase 2 | No vehicle servicing/maintenance screens yet |
| Independent driver onboarding | Deferred | Client said later phase | No own-vehicle driver compliance workflow yet |
| EV integration | Deferred | Architecture supports future extension | No EV-specific features yet |

---

## 28. Testing & Postman

**Test suite:** 611 tests passing, 37 fleet-specific (9 pre-existing skips unrelated to fleet).

### Postman Collection

Import `doc/postman_collection.json` — folders:
- **Admin — Fleet Agreement Management** (11 requests with example responses)
- **Driver — Fleet Remittance** (3 requests with example responses for all states)

### Postman Variables Needed

| Variable | Description | Example |
|----------|-------------|---------|
| `admin_token` | Admin auth token | From admin login |
| `driver_token` | Driver auth token | From driver OTP verify |
| `fleet_agreement_id` | UUID of a fleet agreement | From create or list response |
| `remittance_id` | UUID of a daily remittance | From agreement detail response |
| `driver_uuid` | UUID of a driver | From admin driver list |
| `vehicle_uuid` | UUID of a fleet vehicle | From driver vehicle endpoint |

### Quick Test Flow

1. Login as admin → get `admin_token`
2. Login as driver → get `driver_token`
3. Mark vehicle as fleet: `PATCH /admin/drivers/{id}/vehicle/fleet`
4. Create agreement: `POST /admin/fleet-agreements`
5. Check driver view: `GET /driver/fleet-agreement` (should show agreement with financial terms)
6. Check today: `GET /driver/remittance/today` (should be null until a ride happens)

### Full API Documentation

See `doc/API_REFERENCE.md` — sections:
- **Admin — Fleet Agreement Management**
- **Driver — Fleet Remittance**
- **Enums → Fleet Agreement Status**

All endpoints include full request/response schemas with example payloads.

---

## 29. Outstanding Items & Next Steps

### Client Action Required

1. **Confirm Q1:** Daily remittance amount (₦40,000 is currently configurable per agreement) and whether it varies by vehicle type
2. **Schedule Q8:** Meeting with Chairman on ownership transfer process

### Resolved

- ~~**Confirm payment provider:**~~ Paystack selected (October 2026). PSSP licence covers wallet credit. OPay transfer support for driver payouts.

### Engineering Next

1. **Cash overpayment → wallet credit:** Unblocked — Paystack PSSP licence confirmed. Ready to build when prioritised.
2. **Phase 2 planning:** Fleet maintenance module, independent driver onboarding

### Legal Review

- Hire-purchase price disclosure: Q7 has been reversed — drivers now see full financial terms (compliant)
- Hire-purchase agreement template review recommended before launch

---

*This document was last updated on 11 October 2026. It consolidates the Fleet Industry Standards, Fleet Decisions Report, and Fleet Feature Handoff into a single reference. All referenced regulations (Hire Purchase Act, FCCPA, CBN guidelines) should be reviewed by E-tiGo's legal counsel before commercial launch.*

*Questions? Ping the backend team. The Postman collection has all endpoints ready for testing.*
