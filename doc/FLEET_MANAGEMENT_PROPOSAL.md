# E-tiGo Fleet Vehicle Management & Hire-to-Own Programme

**Prepared for:** E-tiGo Leadership  
**Date:** 5 October 2026  
**Status:** Proposal — awaiting business decisions  
**Version:** 1.0

---

## Table of Contents

1. [Overview](#1-overview)
2. [What Has Been Built](#2-what-has-been-built)
3. [How the Fleet Flow Works Today](#3-how-the-fleet-flow-works-today)
4. [Hire-to-Own Programme (Proposed)](#4-hire-to-own-programme-proposed)
5. [Driver Experience](#5-driver-experience)
6. [Admin Experience](#6-admin-experience)
7. [Business Decisions Required](#7-business-decisions-required)
8. [Edge Cases & Risk Scenarios](#8-edge-cases--risk-scenarios)
9. [Suggested Roadmap](#9-suggested-roadmap)

---

## 1. Overview

E-tiGo operates two categories of vehicles on the platform:

| Category | Description | Plate Verification |
|----------|-------------|-------------------|
| **Driver-owned** | Driver brings their own vehicle | Required (via QoreID) |
| **Fleet (company-owned)** | E-tiGo owns the vehicle and assigns it to a driver | Skipped — E-tiGo already knows the vehicle |

Fleet vehicles introduce a new business dimension: **Hire-to-Own** — drivers make daily payments toward eventual ownership of the vehicle they drive. Until the vehicle is fully paid off, it remains company property with associated rules and restrictions.

This document covers what is already built, how the Hire-to-Own model would work, and the business decisions needed before development continues.

---

## 2. What Has Been Built

The following is already implemented and tested:

- **`is_fleet` flag on vehicles** — Admin can mark any vehicle as fleet-owned via the dashboard
- **KYC flow adapts automatically** — Fleet vehicle drivers only need NIN + Driver's License verification (plate verification is skipped since the company already verified the vehicle)
- **Admin toggle endpoint** — `PATCH /admin/drivers/{id}/vehicle/fleet` marks or unmarks a vehicle as fleet
- **KYC status recalculation** — When a vehicle is toggled to fleet, the driver's KYC status is recalculated immediately. If NIN and license are already verified, the driver becomes KYC-verified without waiting for plate verification
- **Mobile API support** — The KYC status endpoint tells the mobile app which verifications are required vs optional, so the UI adapts for fleet drivers

**What is NOT yet built** (pending decisions below):

- Hire-to-Own payment tracking
- Daily deduction logic
- Vehicle reassignment workflow
- Fleet vehicle pool/inventory management
- Ownership transfer process

---

## 3. How the Fleet Flow Works Today

### For a Driver Using a Fleet Vehicle

```
1. Driver registers on E-tiGo (same as any driver)
2. Admin assigns a fleet vehicle to the driver (or driver registers
   the vehicle and admin marks it as fleet)
3. Driver completes KYC:
   ✅ NIN verification (required)
   ✅ Driver's License verification (required)
   ⬜ Vehicle plate verification (skipped — fleet vehicle)
4. Admin reviews and approves the driver
5. Driver goes online and starts accepting rides
```

### For an Admin

```
1. Admin registers a vehicle in the system
2. Admin marks the vehicle as fleet via the dashboard toggle
3. Admin assigns the vehicle to a driver
4. Admin can unmark a vehicle as fleet at any time
   (e.g., after ownership transfer is complete)
```

---

## 4. Hire-to-Own Programme (Proposed)

### Concept

Fleet drivers make daily payments toward owning the vehicle they drive. This is common in ride-hailing across Nigeria and similar markets (e.g., Bolt fleet partners, MAX.ng hire-purchase).

### Proposed Data Model

Each fleet vehicle would track:

| Field | Description |
|-------|-------------|
| Total vehicle price | Full purchase price of the vehicle |
| Daily payment amount | Fixed daily amount the driver pays |
| Payment start date | When the hire-to-own agreement started |
| Total paid to date | Running sum of all payments made |
| Expected completion date | Calculated from price ÷ daily amount |
| Ownership transferred | Whether the vehicle has been fully paid off |
| Agreement status | Active, paused, defaulted, completed, terminated |

### Payment Flow

```
Daily Cycle:
1. Driver earns fares throughout the day
2. At end-of-day (or configurable time), system calculates daily deduction
3. Deduction is taken from the driver's earnings balance
4. If earnings are insufficient → see "Shortfall Handling" in edge cases
5. Payment is recorded against the hire-to-own agreement
6. When total_paid >= vehicle_price → ownership transfer triggered
```

### Ownership Transfer

When the driver completes all payments:

1. System marks the agreement as `completed`
2. Vehicle's `is_fleet` flag is set to `false` (now driver-owned)
3. Vehicle plate verification is triggered automatically
4. The driver's KYC status recalculates (now requires plate verification)
5. Admin is notified to process the physical ownership transfer (paperwork, registration)
6. Admin confirms the transfer in the dashboard

---

## 5. Driver Experience

### Driver App — What Changes

| Screen | Fleet Driver | Driver-Owned |
|--------|-------------|-------------|
| KYC | NIN + License only | NIN + License + Plate |
| Vehicle details | Shows "Fleet Vehicle" badge | Normal |
| Earnings | Shows daily deduction line item | No deductions |
| Hire-to-Own tab | Progress bar, payment history, remaining balance | Not shown |
| Vehicle swap | May be possible (see decisions) | N/A |

### Daily Earnings Breakdown (Fleet Driver)

```
Today's Summary:
  Gross earnings:          ₦25,000
  Platform commission:     -₦5,000 (20%)
  Hire-to-Own deduction:   -₦3,500
  ─────────────────────────────────
  Net payout:              ₦16,500

  Hire-to-Own Progress:
  ██████████░░░░░░░░░░ 52%
  ₦780,000 of ₦1,500,000 paid
  Est. completion: March 2027
```

---

## 6. Admin Experience

### Admin Dashboard — Fleet Management

| Feature | Description |
|---------|-------------|
| Fleet inventory | List of all fleet vehicles, their assignment status, and hire-to-own progress |
| Assign vehicle | Assign an unassigned fleet vehicle to a driver |
| Unassign vehicle | Remove a vehicle from a driver (e.g., termination, swap) |
| Payment overview | Per-driver payment history, outstanding balance, missed payments |
| Mark ownership transferred | Complete the hire-to-own and convert to driver-owned |
| Defaulter list | Drivers with missed payments or low daily earnings |
| Reports | Fleet utilisation, revenue from hire-to-own, default rate |

---

## 7. Business Decisions Required

The following questions must be answered before the Hire-to-Own feature can be built. Each question includes the options we see and our recommendation where applicable.

---

### Q1: Daily Payment Amount — Fixed or Variable?

| Option | Pros | Cons |
|--------|------|------|
| **Fixed daily amount** (e.g., ₦3,500/day) | Simple, predictable for both parties | Driver may not earn enough on slow days |
| **Percentage of daily earnings** (e.g., 15%) | Adjusts to driver's actual income | Unpredictable completion timeline |
| **Fixed with minimum earnings threshold** | Balanced — only deducts when driver earns enough | Needs clear rules for shortfall |

**Our recommendation:** Fixed daily amount with a minimum earnings threshold. This is the most common model in Nigerian fleet operations and gives drivers a clear target.

---

### Q2: What Happens When a Driver's Daily Earnings Are Less Than the Deduction?

| Option | Description |
|--------|-------------|
| **A. Carry forward** | Shortfall rolls to the next day (driver owes ₦3,500 + ₦3,500 tomorrow) |
| **B. Partial deduction** | Deduct whatever is available, record the shortfall separately |
| **C. Grace period** | Allow X days of shortfall before consequences |
| **D. Minimum earning days** | Driver must drive at least X days per week; missed days don't accumulate debt |

**Our recommendation:** Option B (partial deduction) with Option C (grace period). Deduct what's available, track the shortfall, and alert admin after X consecutive shortfall days.

**Decision needed:** How many consecutive shortfall days before the driver is flagged?  
**Suggested:** 3 days → admin warning, 7 days → auto-pause account, 14 days → admin review for vehicle recovery.

---

### Q3: Can a Fleet Driver Switch Vehicles?

| Option | Description |
|--------|-------------|
| **No switching** | Driver is locked to the assigned vehicle until ownership is complete or agreement is terminated |
| **Admin-initiated swap** | Admin can reassign a different fleet vehicle (e.g., if the current vehicle needs maintenance). Payment history transfers. |
| **Payment resets on swap** | If vehicle changes, the hire-to-own agreement resets |

**Our recommendation:** Admin-initiated swap with payment history transferring (if vehicles are of equal value). If the new vehicle has a different price, the admin adjusts the agreement.

---

### Q4: What Happens if the Hire-to-Own Agreement is Terminated Early?

Possible reasons: driver quits, driver defaults, vehicle is damaged/totalled, driver is suspended.

| Scenario | Suggested Handling |
|----------|-------------------|
| Driver quits voluntarily | Vehicle returned, payments are forfeit (or partial refund — your call) |
| Driver defaults (too many missed payments) | Vehicle reclaimed after admin review, payments forfeit |
| Vehicle totalled/stolen | Insurance claim; agreement terminated; driver gets new vehicle or exits |
| Driver suspended for platform violation | Agreement paused; resumes if reinstated; terminated if permanent |

**Decision needed:**
- Are forfeited payments kept as a deposit/usage fee, or is there a partial refund?
- What is the threshold for "too many missed payments" before termination?

---

### Q5: Deduction Timing — When Does the Daily Cut Happen?

| Option | Description |
|--------|-------------|
| **End of day** (e.g., midnight) | Process all deductions at once |
| **Per-trip** | Take a percentage from each trip fare in real time |
| **Manual / weekly** | Driver pays manually or weekly settlement |

**Our recommendation:** End-of-day batch processing. Simpler to implement, easier to audit, and drivers see a clean daily summary.

**Decision needed:** What time should the "day" reset? Midnight local time? Or a custom time like 4:00 AM (when ride activity is lowest)?

---

### Q6: Should Fleet Drivers Have Different Commission Rates?

Since fleet drivers are effectively renting the vehicle from E-tiGo, should their platform commission rate differ from driver-owned vehicles?

| Option | Description |
|--------|-------------|
| **Same commission** | Fleet driver pays the same 20% commission + hire-to-own deduction |
| **Reduced commission** | Lower commission (e.g., 10%) since E-tiGo already earns from hire-to-own |
| **Zero commission during hire-to-own** | All platform revenue comes from the hire-to-own deduction |

**Decision needed:** This significantly affects driver economics and retention.

---

### Q7: Vehicle Price Transparency

| Option | Description |
|--------|-------------|
| **Full transparency** | Driver sees total price, daily rate, progress, expected completion date |
| **Partial** | Driver sees daily rate and progress but not the total vehicle price |

**Our recommendation:** Full transparency. Nigerian consumer protection regulations and market trust favour this. It also reduces disputes.

---

### Q8: What Happens After Ownership Transfer?

Once the driver fully pays off the vehicle:

| Item | Decision Needed |
|------|----------------|
| Vehicle plate verification | Auto-triggered (already built) — but who handles the re-registration paperwork? |
| Commission rate | Does it change to the standard driver-owned rate? |
| Vehicle class | Does the vehicle keep its assigned class, or does the driver choose? |
| Physical transfer | Who handles DVLA re-registration? E-tiGo admin or driver? |

---

### Q9: Can a Driver Bring Their Own Vehicle AND Use a Fleet Vehicle?

Currently, each driver has one vehicle. If a fleet driver also owns a personal car:

| Option | Description |
|--------|-------------|
| **One vehicle per driver** (current) | Simple. Driver chooses one. |
| **Multiple vehicles** | Driver can switch between personal and fleet vehicle |

**Our recommendation:** Keep one vehicle per driver for Phase 1. Multi-vehicle support adds significant complexity.

---

### Q10: Fleet Vehicle Insurance & Maintenance

| Item | Question |
|------|----------|
| Insurance | Who pays? Included in the daily rate or separate? |
| Routine maintenance | E-tiGo covers it, or deducted from driver's balance? |
| Accident damage | Driver's responsibility, or shared? Deposit system? |

These affect the daily rate calculation and the hire-to-own agreement terms.

---

## 8. Edge Cases & Risk Scenarios

### Technical Edge Cases

| Scenario | Risk | Mitigation |
|----------|------|------------|
| Driver goes offline for extended period (not driving) | No earnings to deduct, agreement stalls | Grace period + admin alerts + auto-pause after X days |
| Two drivers claim the same fleet vehicle | Data conflict | Enforce unique vehicle-to-driver assignment at DB level (already in place) |
| Vehicle is marked fleet while plate verification is in progress | Conflicting state | Cancel in-progress plate verification when vehicle is marked fleet |
| Admin unmarks fleet before hire-to-own is complete | Premature ownership status | Block unmark while hire-to-own agreement is active (unless terminated) |
| Driver deletes account mid-agreement | Orphaned agreement | Require agreement termination before account deletion |
| Daily deduction runs during a ride | Earnings change mid-deduction | Use atomic DB transactions; snapshot balance before deduction |
| Payment system is down at deduction time | Missed deduction | Retry queue with exponential backoff; reconcile next day |

### Business Risk Scenarios

| Scenario | Risk | Mitigation |
|----------|------|------------|
| Driver stops driving but doesn't formally quit | Vehicle sits idle, no payments coming in | Inactivity detection: if driver doesn't complete a ride in X days, auto-notify admin |
| Driver earns well below daily rate consistently | Will never complete hire-to-own | Admin dashboard flags drivers below a minimum utilisation threshold |
| Vehicle depreciation exceeds payment timeline | Vehicle worth less than remaining balance | Set vehicle prices conservatively; factor in depreciation |
| Driver disputes deduction amounts | Trust issue | Full payment history visible in-app; itemised daily statements |
| Multiple drivers default simultaneously (economic downturn) | Fleet revenue drops | Admin reporting dashboard with early warning metrics |
| Driver transfers hire-to-own vehicle to another person informally | Liability and platform integrity | Enforce driver-vehicle binding; require admin involvement for any transfer |

---

## 9. Suggested Roadmap

### Phase 1 — Already Built (Current)

- [x] Fleet vehicle flag (`is_fleet`)
- [x] Admin can mark/unmark vehicles as fleet
- [x] KYC flow adapts — fleet vehicles skip plate verification
- [x] Mobile API returns `required` per verification type
- [x] KYC status recalculates automatically on fleet toggle

### Phase 2 — Hire-to-Own Core (Estimated: 1–2 weeks after decisions)

- [ ] Hire-to-own agreement model (vehicle price, daily amount, start date, status)
- [ ] Admin: create hire-to-own agreement for a fleet driver
- [ ] Admin: view/edit/terminate agreement
- [ ] Daily deduction job (end-of-day batch)
- [ ] Shortfall tracking and grace period logic
- [ ] Driver app: hire-to-own progress screen
- [ ] Driver app: daily earnings breakdown with deduction line

### Phase 3 — Operations & Reporting

- [ ] Admin: fleet inventory dashboard
- [ ] Admin: defaulter alerts and reports
- [ ] Admin: fleet utilisation reports
- [ ] Ownership transfer workflow (auto-trigger plate verification, unmark fleet)
- [ ] Driver inactivity detection

### Phase 4 — Advanced

- [ ] Vehicle swap workflow
- [ ] Multi-tier pricing (different daily rates per vehicle model/value)
- [ ] Fleet partner support (third-party fleet owners)
- [ ] Automated agreement terms PDF generation

---

## Next Steps

1. **Review this document** and discuss with the team
2. **Answer the 10 business questions** in Section 7 — these directly determine what we build
3. **Prioritise** which Phase 2 items to build first
4. We will update the technical plan and begin implementation once decisions are confirmed

---

*This document is a living proposal. It will be updated as decisions are made and implementation progresses.*
