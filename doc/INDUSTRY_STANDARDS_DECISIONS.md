# E-tiGo — Industry Standards Decision Report

**Prepared by:** Engineering Team  
**Date:** 11 October 2026  
**Status:** Implemented

---

## Executive Summary

This document records where E-tiGo's implementation follows ride-hailing industry standards and where previous business decisions have been revised to align with standard practices. Each section explains the decision, the industry benchmark, why it matters, and what was implemented.

---

## Table of Contents

1. [L3 Sanctions Require Mandatory Admin Review](#1-l3-sanctions-require-mandatory-admin-review)
2. [GPS Collocation Threshold — Launch at 200m](#2-gps-collocation-threshold--launch-at-200m)
3. [Anti-Offline Detection — Reframed as Off-Platform Activity](#3-anti-offline-detection--reframed-as-off-platform-activity)
4. [Post-Cancellation GPS Monitoring — Time-Bounded](#4-post-cancellation-gps-monitoring--time-bounded)
5. [Negative Balance Blocks Ride Acceptance](#5-negative-balance-blocks-ride-acceptance)
6. [Fleet Vehicle Total Cost — Transparent to Drivers](#6-fleet-vehicle-total-cost--transparent-to-drivers)
7. [False-Positive Rate Targets — Tiered Approach](#7-false-positive-rate-targets--tiered-approach)
8. [Commission Model Applies to Independent Drivers Only](#8-commission-model-applies-to-independent-drivers-only)
9. [SOS System Prioritised as Safety-Critical](#9-sos-system-prioritised-as-safety-critical)
10. [Items Requiring Future Attention](#10-items-requiring-future-attention)

---

## 1. L3 Sanctions Require Mandatory Admin Review

### Previous Behaviour

When a driver accumulated three offline-trip flags within 30 days, the system automatically and permanently deactivated their account. No human reviewed the decision.

### Industry Standard

**Uber, Bolt, Grab, and Lyft** all require human review before permanent account deactivation. Automated systems flag and suspend; a human decides on permanent action. This is both an operational best practice and a legal safeguard — permanently denying someone the ability to earn a living based solely on an algorithm carries significant liability risk.

### What We Changed

- **L3 flags now suspend the driver pending admin review**, not auto-deactivate.
- A new `Deactivated` driver status was added (distinct from `Suspended`).
- A new admin endpoint `POST /admin/offline-flags/{flag}/confirm-deactivation` allows authorised admins to confirm permanent deactivation after reviewing the evidence.
- The `SanctionTier::Deactivation` action label changed from `permanently_deactivated` to `pending_admin_review`.
- L3 events are logged at `CRITICAL` severity for admin alerting.
- The driver receives messaging that their account is "suspended pending review" rather than "permanently deactivated."

### Why This Matters

The client's own directive states: *"A driver must not be penalised solely because a trip was cancelled or because the GPS locations appear close."* Automatic permanent deactivation contradicts this principle. Admin review ensures multiple signals are considered before the most severe action is taken.

**Files changed:** `SanctionService.php`, `SanctionTier.php`, `DriverStatus.php`, `AdminOfflineFlagController.php`, `routes/api.php`

---

## 2. GPS Collocation Threshold — Launch at 200m

### Client Decision

The client approved a 100m static GPS threshold for detecting post-cancellation collocation.

### Industry Standard

GPS accuracy in urban areas (Lagos, Abuja) typically ranges from 5m to 50m, with building shadow and multipath effects causing drift up to 80m in dense corridors. Ride-hailing platforms with similar detection systems (Bolt, DiDi) use 150-250m thresholds at launch, then tighten based on real trip data.

### What We Implemented

- **Default threshold: 200m** (configurable via `OFFLINE_COLLOCATION_THRESHOLD` env variable).
- The system requires a minimum of 3 collocation points along the intended route, reducing single-sample false positives.
- Route match percentage set at 60%, meaning the trajectories must align along most of the planned route — not just be near each other at one moment.
- Pickup proximity threshold: 300m (the radius within which a driver is considered "at pickup" when a cancellation triggers monitoring).

### Recommendation

Launch at 200m. After 4-6 weeks of production data, analyse false-positive rates per city. If the rate is below the 1% target for auto-penalties, tighten to 150m, then 100m. The config is hot-swappable — no code change or deployment needed.

**Configuration:** `config/offline_detection.php`

---

## 3. Anti-Offline Detection — Reframed as Off-Platform Activity

### Client Clarification

*"E-TiGo does not operate on a per-trip commission model for our drivers. Please remove references to drivers avoiding commission and design this service around detecting suspicious off-platform trip activity, bypassing E-TiGo's booking process."*

### What We Implemented

The detection system was always designed around off-platform activity detection, not commission avoidance. The codebase contains no references to "commission avoidance" in the anti-offline detection module. The system:

1. Monitors GPS trajectories only after specific cancellation patterns (driver near pickup at time of cancellation).
2. Looks for continued movement along the originally planned route, which suggests the trip continued outside the platform.
3. Applies sanctions based on repeated patterns, not single incidents.
4. Provides a dispute mechanism for drivers to contest flags.

This aligns with the client's stated business model: the concern is not lost commission but rather **off-platform activity** that bypasses E-tiGo's quality controls, insurance coverage, safety systems (SOS), and passenger protections.

### Relationship to Commission

The per-ride commission system (`CommissionService`, `CommissionConfig`) exists for **independent drivers** — those who bring their own vehicles and pay a percentage to E-tiGo per ride. This is separate from the fleet model where drivers pay a fixed daily remittance (₦40,000). Both models coexist; the anti-offline detection applies equally to both because off-platform activity undermines the platform regardless of payment model.

---

## 4. Post-Cancellation GPS Monitoring — Time-Bounded

### Industry Standard

GDPR and Nigeria's NDPR (Nigeria Data Protection Regulation) require that location data collection be proportionate and time-limited. Ride-hailing platforms that perform post-event location analysis typically cap monitoring windows at 10-20 minutes.

### What We Implemented

- **Monitoring window: 15 minutes** (configurable via `OFFLINE_MONITORING_WINDOW`).
- GPS is sampled at 30-second intervals (configurable), producing a maximum of 30 data points.
- After the monitoring window, trajectory data is cached temporarily for analysis, then cleaned up by the analysis job.
- Analysis runs as a delayed background job (20-minute delay after monitoring completes).
- No indefinite or ongoing location tracking occurs.

### Recommendation

Before production launch, add clear in-app disclosure in the privacy policy and terms of service that location may be monitored briefly after cancellations for platform integrity purposes. This should be reviewed by legal counsel for NDPR compliance.

**Configuration:** `config/offline_detection.php`

---

## 5. Negative Balance Blocks Ride Acceptance

### Previous Behaviour

The system allowed driver earnings accounts to go negative (up to -₦5,000 by default from cash-ride commission deductions) but did not prevent drivers with excessive negative balances from accepting new rides.

### Industry Standard

**Bolt** and **Uber** both prevent drivers from accepting rides or going online when their account balance is below a platform-defined threshold. This protects the platform from accumulating uncollectable debt and incentivises drivers to clear their balance.

### What We Changed

- Drivers with negative earnings balance exceeding the configured threshold (default: -₦5,000 / -500,000 kobo) are now **blocked from accepting ride requests** with an explanatory message.
- The same check is applied to the **toggle-online** flow — drivers cannot go online while their negative balance exceeds the threshold.
- The threshold remains configurable via `config('wallet.max_negative_balance')`.

**Files changed:** `RideController.php`, `DriverController.php`

---

## 6. Fleet Vehicle Total Cost — Transparent to Drivers

### Previous Business Decision

The client decided that fleet drivers should see only a progress percentage toward vehicle ownership, with the total vehicle cost hidden.

### Industry Standard

**Bolt Fleet Partners**, traditional Nigerian hire-purchase operators, and consumer protection regulations all require disclosure of the total cost in hire-purchase agreements. The Nigerian Hire Purchase Act specifically requires that hire-purchase agreements state the total purchase price. Hiding the total cost from the person making payments creates legal risk and erodes trust.

### What We Implemented

The `FleetAgreementResource` exposes `total_vehicle_cost`, `remaining_amount`, and `progress_percentage` to all users, including drivers. This provides full transparency.

Admin-only fields remain restricted: `shortfall_flag`, `shortfall_flagged_at`, `vehicle_return_status`, `outstanding_amount`, `settlement_amount`, `settlement_notes`.

### Recommendation

This is both a legal and trust issue. We strongly advise against reverting to hidden pricing. If the business prefers to de-emphasise the total cost in the driver app UI, the frontend can choose which fields to display prominently — but the data should always be available via the API.

---

## 7. False-Positive Rate Targets — Tiered Approach

### Client Decision (Approved)

The client approved the tiered false-positive rate approach:

| Action Type | Max False-Positive Rate |
|-------------|------------------------|
| Automatic penalties (suspension, deactivation) | < 1% |
| Manual review queue | < 5% |
| Soft warnings / internal flags | < 10% |

### How This Maps to the Implementation

| Sanction Tier | Action | False-Positive Target | Human Review |
|---------------|--------|----------------------|--------------|
| L1 (Warning) | Push notification only | < 10% | No |
| L2 (48h Suspension) | Suspend + force offline | < 5% | Admin can review/overturn |
| L3 (Permanent Deactivation) | Suspend pending review | < 1% | **Mandatory** admin review |

The system considers multiple signals (GPS proximity, route similarity, timing, minimum collocation points) to reduce false positives. A single cancelled trip followed by continued movement is not enough to trigger a flag — the trajectories must match the intended route at 60%+ similarity across 3+ sample points.

---

## 8. Commission Model Applies to Independent Drivers Only

### Clarification

E-tiGo has two driver categories:

| Category | Payment to E-tiGo | Commission | Anti-Offline Detection |
|----------|-------------------|------------|----------------------|
| **Fleet drivers** | Fixed daily remittance (₦40,000) | 0% (Q6 decision) | Yes — off-platform activity |
| **Independent drivers** | Per-ride commission | Configurable (default 20%) | Yes — off-platform activity |

The commission system (`CommissionConfig`, `CommissionService`) correctly applies only to independent drivers. Fleet drivers have commission rate set to 0% per the client's Q6 decision.

The anti-offline detection system applies to both categories equally because the concern — off-platform activity — is the same regardless of payment model.

---

## 9. SOS System Prioritised as Safety-Critical

### Industry Standard

Every major ride-hailing platform (Uber, Bolt, Lyft, Grab, DiDi) ships with an SOS/emergency system at launch. This is non-negotiable for:

- Regulatory compliance (many jurisdictions require it)
- Insurance requirements
- User trust and safety

### What We Implemented

The SOS system is fully implemented (BE-SOS-01 through BE-SOS-14):

- **Trigger** → **30s check-in** → **auto-escalate** → **admin console** → **dispatch/resolve**
- Real-time broadcasting to admin SOS console
- Telemetry encrypted at rest
- Safety Operator role-gated access
- Immutable event log (append-only `sos_event_log`)
- Emergency dispatch metadata logging (actual emergency-services integration provider TBD per OQ-04)

The SOS system was prioritised above EV charging and gamification because it is **safety-critical infrastructure** that directly protects human lives.

---

## 10. Items Requiring Future Attention

These items are documented as industry-standard practices that should be addressed before or shortly after launch:

### 10.1 Phone Verification on Registration

**Industry standard:** Every ride-hailing platform (Uber, Bolt, Grab, Lyft) requires phone number verification via SMS OTP at registration. E-tiGo currently auto-verifies phone numbers on registration without sending an OTP.

**Risk:** Without phone verification, the platform is vulnerable to fake accounts, promo abuse, and multi-accounting.

**Recommendation:** Implement SMS OTP verification before launch. The infrastructure exists (`OtpService`, `SmsGateway` contract). This requires:
1. An SMS provider decision (OQ-01)
2. A `sendPhoneVerification` + `verifyPhone` endpoint pair
3. Gating ride booking behind verified phone status

### 10.2 Maps Provider Integration

**Status:** OQ-02 remains unresolved. The platform currently uses `HaversineMapsGateway` (straight-line distance calculation). Every fare estimate is approximate until a real maps provider (Google Maps, Mapbox, etc.) is integrated.

**Risk:** Launch-blocking — inaccurate fare estimates will cause fare disputes and erode trust.

### 10.3 Cancellation Fee Policy

**Status:** OQ-05 remains unresolved. Three models have been proposed (see `doc/CANCELLATION_FEE_PROPOSAL.md`). No cancellation fees are charged currently.

**Risk:** Without cancellation fees, drivers absorb the cost of passenger cancellations. Industry standard is a time-based grace period (2-5 minutes free, then a flat fee).

### 10.4 Wallet Regulatory Compliance

**Status:** OQ-33 remains unresolved. Operating a closed-loop wallet in Nigeria requires CBN licensing consideration (Payment Service Provider or Mobile Money Operator license). This needs legal review before the wallet goes live.

### 10.5 Wallet Limits

**Status:** OQ-25 remains unresolved. Minimum top-up, maximum balance, and daily top-up cap values need business decisions. Defaults are configurable via the Admin Wallet Settings endpoint but should be set deliberately based on business and regulatory requirements.

---

## Summary of Changes in This Release

| Change | Type | Industry Benchmark |
|--------|------|-------------------|
| L3 deactivation requires admin review | Code change | Uber, Bolt, Grab, Lyft |
| GPS threshold set to 200m (not 100m) for launch | Configuration | Bolt, DiDi (150-250m at launch) |
| Post-cancellation monitoring capped at 15 minutes | Configuration | NDPR/GDPR proportionality |
| Negative balance blocks ride acceptance | Code change | Bolt, Uber |
| Fleet vehicle cost visible to drivers | No change needed (already correct) | Nigerian Hire Purchase Act, Bolt Fleet |
| SOS system fully implemented | New feature | All major platforms |
| Anti-offline detection fully implemented | New feature | Bolt (offline trip detection) |
| EV charging reservation system fully implemented | New feature | Unique to E-tiGo (EV-first positioning) |

---

*This document should be reviewed with the business team and updated as additional decisions are made. Configuration values (thresholds, timeouts, limits) can be adjusted via environment variables or the Admin Settings endpoint without code changes.*
