# E-tiGo — Web Task Breakdown (Admin Dashboard + Landing Page)

**Source:** PRD v3.0 (Phase 1 & Phase 2) + Product Brief
**Backend API:** Consumed from `etigo-api` — see `E-Tigo_Backend_Task_Breakdown.md` for endpoint specifications
**Phase 1 (Weeks 1–8):** Frozen scope
**Phase 2 (Weeks 9–12):** Working draft — must be reviewed and re-frozen before Week 9

> **Legend**
> - `[ ]` To Do
> - `[~]` In Progress
> - `[x]` Done
> - ⚠ = Open question that must be resolved before building
> - 🔒 = Security-sensitive — requires review before deployment
> - ⏱ = Performance-critical — requires benchmarking
> - 📡 = Requires corresponding backend endpoint (prefix: `BE-` in backend doc)

**Related docs:** [Passenger App](Passenger_App_Task_Breakdown.md) | [Driver App](Driver_App_Task_Breakdown.md) | [Backend](E-Tigo_Backend_Task_Breakdown.md)

---

## Table of Contents

### Admin Dashboard

**Platform:** Web (React or Next.js — TBD)
**Auth:** Email/password → Sanctum token stored in secure cookie or storage
**Role-based UI:** Navigation and actions filtered by admin role (super_admin, operations, safety_operator, support)

1. [Auth & Layout](#1-auth--layout)
2. [City Management](#2-city-management)
3. [Vehicle Class Management](#3-vehicle-class-management)
4. [Pricing Configuration](#4-pricing-configuration)
5. [Live Ride Monitoring](#5-live-ride-monitoring)
6. [Driver Management](#6-driver-management)
7. [Passenger Management](#7-passenger-management)
8. [Disputes](#8-disputes)
9. [Manual Ride Assignment](#9-manual-ride-assignment)
10. [Reporting & Analytics](#10-reporting--analytics)
11. [Gamification Config](#11-gamification-config)
12. [Promo Management](#12-promo-management)
13. [SOS Console](#13-sos-console)
14. [Anti-Offline Monitoring](#14-anti-offline-monitoring)
15. [EV Charging Management](#15-ev-charging-management)
16. [Wallet & Ledger Administration](#16-wallet--ledger-administration)
17. [Payout Management](#17-payout-management)
18. [Phase 2 — Admin Screens](#18-phase-2--admin-screens)

### Landing Page

**Platform:** Web (static site or Next.js SSR/SSG — TBD)
**Purpose:** Public-facing marketing site for riders, drivers, and partners

19. [Landing Page](#19-landing-page)

### Tracking

20. [Open Questions](#20-open-questions)

---

# Admin Dashboard

---

## 1. Auth & Layout

**PRD refs:** A-01, A-02, §8.4

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AA-01 | `[ ]` Build Admin login screen: email + password; call `POST /admin/auth/login`; store Sanctum token in secure cookie or storage | A-01 | 📡 BE-AUTH-05 | Admin roles: super_admin, operations, safety_operator, support |
| FE-AA-02 | `[ ]` Build Admin dashboard shell: sidebar navigation, top bar (user info, notifications, logout), content area, responsive layout | A-02 | — | — |
| FE-AA-03 | `[ ]` Implement role-based navigation: show/hide sidebar items based on authenticated user's role/permissions (super_admin sees all, safety_operator sees SOS only, etc.) | §8.4 | 📡 BE-AUTH-08 | — |
| FE-AA-04 | `[ ]` Build Admin notification centre: bell icon with unread count, dropdown with recent notifications, "View All" link | A-02 | 📡 BE-NOTIF-04 | — |

---

## 2. City Management

**PRD refs:** A-01, A-02

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AC-01 | `[ ]` Build cities list page: table with name, status (active/inactive toggle), timezone, created date; search + filter | A-01 | 📡 BE-CITY-05 | — |
| FE-AC-02 | `[ ]` Build create/edit city form: name, boundary (map polygon or point+radius), timezone selector, currency code | A-01 | 📡 BE-CITY-03, BE-CITY-06 | Map widget for boundary definition |
| FE-AC-03 | `[ ]` Build city vehicle-class assignment UI: within city detail, checkboxes/toggles for each platform vehicle class to enable/disable | A-04 | 📡 BE-CITY-13 | — |
| FE-AC-04 | `[ ]` Build city detail page: overview stats (active drivers, rides today), sub-tabs for vehicle classes and pricing configs | A-02 | 📡 BE-CITY-05 | — |

---

## 3. Vehicle Class Management

**PRD refs:** A-03, A-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AVC-01 | `[ ]` Build vehicle classes list page: table with name, capacity, icon, active status; "Add Vehicle Class" CTA | A-03 | 📡 BE-CITY-11 | — |
| FE-AVC-02 | `[ ]` Build create/edit vehicle class form: name, display name, capacity, icon upload, description | A-03 | 📡 BE-CITY-10, BE-CITY-12 | — |
| FE-AVC-03 | `[ ]` Build vehicle class assignment to driver (within KYC review flow): dropdown to assign vehicle class when approving driver | A-05 | 📡 BE-ADMIN-09 | Integrated into driver KYC review, not standalone |

---

## 4. Pricing Configuration

**PRD refs:** A-06, A-07

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APR-01 | `[ ]` Build pricing config list page: table filtered by city + vehicle class showing base fare, per-km, per-minute, min fare, effective date, version | A-06 | 📡 BE-PRICE-04 | — |
| FE-APR-02 | `[ ]` Build create pricing config form: select city + vehicle class, enter rates, set effective_from date/time; preview fare calculation with sample distance/duration | A-06, A-07 | 📡 BE-PRICE-02 | — |
| FE-APR-03 | `[ ]` Build pricing version history: chronological list of all pricing versions for a city+class pair; highlight currently active | A-07 | 📡 BE-PRICE-04 | — |

---

## 5. Live Ride Monitoring

**PRD refs:** A-08, A-09, A-15

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ALR-01 | `[ ]` Build live ride map: full-screen map with all active rides plotted (pickup/destination markers + moving driver icons); filter by city, status | A-08 | 📡 BE-LOC-05 (WS) | ⏱ Must handle hundreds of concurrent markers efficiently |
| FE-ALR-02 | `[ ]` Build ride detail sidebar: click a ride on map or list → slide-in panel with full ride detail, state history, passenger/driver info | A-09 | 📡 BE-RIDE-11 | — |
| FE-ALR-03 | `[ ]` Build active rides table: searchable, filterable list of rides by status, city, vehicle class; real-time status updates | A-08 | 📡 BE-RIDE-12, BE-LOC-05 (WS) | Alternative to map view |
| FE-ALR-04 | `[ ]` Build ride state history timeline: vertical timeline showing each state transition with timestamp and trigger | A-09 | 📡 BE-RIDE-15 | — |
| FE-ALR-05 | `[ ]` Build KPI counters: real-time summary stats — active rides, rides today, completion rate, average wait time; update via WS | A-08 | 📡 BE-LOC-05 (WS) | — |

---

## 6. Driver Management

**PRD refs:** A-10, A-11, A-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ADR-01 | `[ ]` Build driver list page: table with name, phone, KYC status, compliance status, vehicle class, online status; search + filters + pagination | A-10 | 📡 BE-ADMIN-07 | — |
| FE-ADR-02 | `[ ]` Build driver detail page: tabs for Profile, Vehicle, KYC Documents, Compliance History, Ride History, Earnings | A-10 | 📡 BE-ADMIN-08 | — |
| FE-ADR-03 | `[ ]` Build KYC review interface: view uploaded documents (image viewer/PDF viewer), approve/reject each with mandatory rejection reason; assign vehicle class on approval | A-10, A-05 | 📡 BE-ADMIN-09 | 🔒 KYC document viewer must not allow download in unsecured contexts |
| FE-ADR-04 | `[ ]` Build KYC pending queue: filtered view showing only drivers with status=pending; count badge in sidebar navigation | A-10 | 📡 BE-ADMIN-07 | — |
| FE-ADR-05 | `[ ]` Build driver suspend/reactivate actions: confirmation dialog with reason field; reflect in driver status immediately | A-11 | 📡 BE-ADMIN-11, BE-ADMIN-12 | Suspend returns 422 if driver not approved; reactivate returns 422 if not suspended |

---

## 7. Passenger Management

**PRD refs:** A-12

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APM-01 | `[ ]` Build passenger list page: table with name, phone, status, tier, ride count, rating; search + filters + pagination | A-12 | 📡 BE-ADMIN-13 | — |
| FE-APM-02 | `[ ]` Build passenger detail page: profile info, tier data, ride history, dispute history | A-12 | 📡 BE-ADMIN-14 | — |
| FE-APM-03 | `[ ]` Build passenger suspend/reactivate actions: confirmation dialog with reason; reflect in passenger status immediately | A-12 | 📡 BE-ADMIN-15 | — |

---

## 8. Disputes

**PRD refs:** A-13, A-14

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ADIS-01 | `[ ]` Build dispute queue page: table with ride ID, reporter, category, status, created date; filter by status; count badge for open disputes | A-13 | 📡 BE-RATE-07 | — |
| FE-ADIS-02 | `[ ]` Build dispute detail page: trip context (map, fare, driver, passenger), dispute description, resolution form (status change + notes), linked ride detail | A-13 | 📡 BE-RATE-08 | — |
| FE-ADIS-03 | `[ ]` Build dispute resolution form: status selector (under_review/resolved/dismissed), resolution notes (required), submit; confirm dialog | A-14 | 📡 BE-RATE-09 | ⚠ OQ-08: Add refund/fare adjustment fields if in scope |

---

## 9. Manual Ride Assignment

**PRD refs:** A-15

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AMA-01 | `[ ]` Build manual assignment interface: from ride detail (when status allows), "Assign Driver" button → search online drivers by name/phone/vehicle class; show distance to pickup | A-15 | 📡 BE-MATCH-09 | — |
| FE-AMA-02 | `[ ]` Build assignment confirmation dialog: confirm driver selection, show driver details; on confirm, trigger assignment and reflect in ride state | A-15 | 📡 BE-MATCH-09, BE-MATCH-10 | — |

---

## 10. Reporting & Analytics

**PRD refs:** A-16

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ARPT-01 | `[ ]` Build reporting dashboard page: overview with key KPIs (ride volume, revenue, completion rate, avg fare), date range picker, city filter | A-16 | 📡 BE-RPT-01 through BE-RPT-04 | — |
| FE-ARPT-02 | `[ ]` Build ride volume chart: line/bar chart of rides over time, breakdowns by status, city, vehicle class | A-16 | 📡 BE-RPT-01 | — |
| FE-ARPT-03 | `[ ]` Build completion rate chart: completed vs cancelled breakdown, cancellation reasons pie chart | A-16 | 📡 BE-RPT-02 | — |
| FE-ARPT-04 | `[ ]` Build revenue report: revenue over time, by city, by payment method; average fare trend | A-16 | 📡 BE-RPT-03 | — |
| FE-ARPT-05 | `[ ]` Build driver utilisation report: online hours, trips per driver, earnings per driver; sortable table | A-16 | 📡 BE-RPT-04 | — |
| FE-ARPT-06 | `[ ]` Build CSV export functionality: "Export" button on each report page; trigger download of filtered report data | A-16 | 📡 BE-RPT-06 | — |

---

## 11. Gamification Config

**PRD refs:** A-17, A-18, A-19

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AGF-01 | `[ ]` Build tier configuration page: editable table of tiers (level, name, points threshold, benefits); save with confirmation | A-17 | 📡 BE-GAME-11 | ⚠ OQ-09: Tier naming TBD |
| FE-AGF-02 | `[ ]` Build point multiplier configuration page: editable table of conditions (EV ride, shared journey, off-peak) with multiplier values and stackability toggle | A-18 | 📡 BE-GAME-12 | — |
| FE-AGF-03 | `[ ]` Build gamification analytics page: tier distribution pie/bar chart, average carbon scores, top users list, trends over time | A-19 | 📡 BE-GAME-09, BE-GAME-10 | — |

---

## 12. Promo Management

**PRD refs:** A-20, A-21, A-22, A-23

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APO-01 | `[ ]` Build promo list page: table with code, discount type/value, status (active/expired/paused), redemption count, created date; search + filters | A-20 | 📡 BE-PROMO-04 | — |
| FE-APO-02 | `[ ]` Build create/edit promo form: code (auto-generate option), discount type/value/cap, dates, redemption limits, eligibility rules (geo-fence map, order count range, tier, peak/off-peak) | A-20, A-21 | 📡 BE-PROMO-02, BE-PROMO-05 | Geo-fence: draw polygon or circle on map |
| FE-APO-03 | `[ ]` Build promo eligibility rule builder: visual UI for combining conditions (tier, order count, geography, time-of-day) | A-22 | 📡 BE-PROMO-02 | — |
| FE-APO-04 | `[ ]` Build promo performance dashboard: per-promo analytics — redemption count over time, total discount given, unique users, revenue impact chart | A-23 | 📡 BE-PROMO-11 | — |
| FE-APO-05 | `[ ]` Build promo activate/deactivate toggle: one-click toggle with confirmation; reflect in status immediately | A-20 | 📡 BE-PROMO-05 | — |

---

## 13. SOS Console

**PRD refs:** A-24, A-25, A-26, A-27

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ASOS-01 | `[ ]` Build SOS console page: real-time feed of active/unresolved SOS incidents; auto-refresh via WS; prominent visual urgency indicators | A-24 | 📡 BE-SOS-09, BE-SOS-13 (WS) | ⏱ Safety-critical — must render within seconds of trigger |
| FE-ASOS-02 | `[ ]` Build SOS incident card: trip details (passenger, driver, vehicle), GPS location plotted on map, telemetry data (if available), status timeline | A-24, A-25 | 📡 BE-SOS-09 | — |
| FE-ASOS-03 | `[ ]` Build SOS operator assignment: "Take Over" button to self-assign; update incident status; prevent multiple operators on same incident | A-24 | 📡 BE-SOS-10 | — |
| FE-ASOS-04 | `[ ]` Build SOS dispatch action: "Dispatch Emergency Services" button with confirmation dialog; shows what data will be shared; irreversible action warning | A-26 | 📡 BE-SOS-11 | 🔒 Confirmation must be explicit; no accidental dispatch |
| FE-ASOS-05 | `[ ]` Build SOS resolve action: resolution form with notes (required); status change to resolved; audit trail visible | A-27 | 📡 BE-SOS-12 | — |
| FE-ASOS-06 | `[ ]` Build SOS history / archive: searchable list of resolved incidents; used for auditing and post-incident review | A-27 | 📡 BE-SOS-09 | — |

---

## 14. Anti-Offline Monitoring

**PRD refs:** A-28, A-29, A-30

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AAO-01 | `[ ]` Build offline-trip flags list page: table with driver, ride, sanction tier, dispute status, flag date; filter by tier/status | A-28 | 📡 BE-AO-09 | — |
| FE-AAO-02 | `[ ]` Build flag detail page: GPS trajectory map of post-cancellation movement, detection data, sanction tier rationale, driver's dispute (if any) | A-28 | 📡 BE-AO-09 | — |
| FE-AAO-03 | `[ ]` Build flag review form: "Uphold" or "Overturn" decision with mandatory notes; overturn reverses sanction; show impact preview | A-29 | 📡 BE-AO-10 | — |
| FE-AAO-04 | `[ ]` Build manual escalation action: escalate sanction tier with confirmation; show what the new tier means | A-30 | 📡 BE-AO-11 | ⚠ OQ-14: L3 authority TBD |
| FE-AAO-05 | `[ ]` Build anti-offline analytics card: total flags, upheld vs overturned rate, sanctions by tier, trend chart | A-28 | 📡 BE-AO-09 | — |

---

## 15. EV Charging Management

**PRD refs:** A-31, A-32

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AEV-01 | `[ ]` Build EV station list page: table with name, city, total stalls, available stalls, queue length, status; search + filter by city | A-31 | 📡 BE-EV-03 | — |
| FE-AEV-02 | `[ ]` Build create/edit station form: name, city, address, total stalls, location picker on map | A-31 | 📡 BE-EV-02, BE-EV-04 | — |
| FE-AEV-03 | `[ ]` Build station utilisation dashboard: real-time stall occupancy grid, reservations queue, utilisation rate over time, busiest stations chart | A-32 | 📡 BE-EV-05 | — |

---

## 16. Wallet & Ledger Administration

**PRD refs:** A-16, B-08, NF-06

### 16.1 Dashboard & Overview

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-01 | `[ ]` Build wallet/ledger KPI dashboard: total wallet liability, top-ups today, pending payouts, driver earnings payable, platform commission earned; real-time counters | A-16 | 📡 BE-WADM-25 | Finance and Super Admin roles only |
| FE-AWL-02 | `[ ]` Build financial alerts panel: failed webhooks count, stuck transactions count, negative driver balances count; each links to detail view | A-16 | 📡 BE-WADM-22, BE-WADM-23 | Prominent placement on dashboard; auto-refresh |

### 16.2 Passenger Wallet Management

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-03 | `[ ]` Build passenger wallet list page: searchable table (name/phone/email) with balance, status (active/frozen), last activity date; balance range filter; pagination | A-16 | 📡 BE-WADM-01 | — |
| FE-AWL-04 | `[ ]` Build passenger wallet detail page: balance summary (available/held), active holds list, transaction history with type icons and status badges; tabs for overview and transactions | A-16 | 📡 BE-WADM-02 | — |
| FE-AWL-05 | `[ ]` Build wallet freeze/unfreeze action: confirmation dialog with mandatory reason text area; reflect frozen status immediately with visual indicator; audit trail visible | A-16 | 📡 BE-WADM-03 | 🔒 Finance and Super Admin only |

### 16.3 Driver Ledger Management

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-06 | `[ ]` Build driver ledger list page: table with driver name, pending balance, available balance, total paid out, bank account status; search + filter; pagination | A-16 | 📡 BE-WADM-04 | — |
| FE-AWL-07 | `[ ]` Build driver ledger detail page: tabs for earnings breakdown, commission history, payout history, bank account details; negative balance highlighted | A-16 | 📡 BE-WADM-05 | Negative balance view for cash-ride commission owed |

### 16.4 Ledger Explorer

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-08 | `[ ]` Build global ledger explorer page: searchable transaction table by reference, user, ride, type, date range; journal view showing both debit and credit sides of each entry | A-16 | 📡 BE-WADM-06 | — |
| FE-AWL-09 | `[ ]` Build ledger CSV export: "Export" button on ledger explorer; apply current filters to export; stream download for large datasets | A-16 | 📡 BE-WADM-07 | — |

### 16.5 Manual Adjustments & Refunds

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-10 | `[ ]` Build create adjustment form: select account (passenger wallet or driver ledger), type (credit/debit), amount input, mandatory reason text area; submit creates pending adjustment | NF-06 | 📡 BE-WADM-10 | 🔒 Audit trail; Finance and Super Admin only |
| FE-AWL-11 | `[ ]` Build adjustment approval queue: table of pending adjustments with creator, account, type, amount, reason; "Approve" / "Reject" buttons with confirmation dialog | NF-06 | 📡 BE-WADM-11, BE-WADM-12 | 🔒 Maker-checker: approver must differ from creator |
| FE-AWL-12 | `[ ]` Build refund-to-wallet action: accessible from ride detail page; full or partial amount input; linked to dispute if one exists; confirmation dialog showing refund impact | A-16 | 📡 BE-WADM-13 | — |

### 16.6 Settings

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-13 | `[ ]` Build commission settings page: global commission rate display and edit; per-driver override table (CRUD); changes apply to future rides only warning | A-16 | 📡 BE-WADM-19 | Super Admin only |
| FE-AWL-14 | `[ ]` Build wallet settings page: configure min top-up, max balance, daily top-up cap, minimum payout amount, payout schedule; save with confirmation | A-16 | 📡 BE-WADM-20 | Super Admin only |

### 16.7 Reconciliation & Reports

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AWL-15 | `[ ]` Build daily reconciliation view: Paystack settlements vs ledger entries comparison; mismatches flagged with visual indicators; date selector | A-16 | 📡 BE-WADM-24 | — |
| FE-AWL-16 | `[ ]` Build wallet liability report: total passenger wallet balances (platform liability), commission collected, driver earnings payable; trend over time chart | A-16 | 📡 BE-WADM-25 | — |
| FE-AWL-17 | `[ ]` Build audit log viewer for financial actions: searchable log of all money-moving admin actions (who, what, when, before/after values) | NF-06 | 📡 SETUP-54 | — |

---

## 17. Payout Management

**PRD refs:** A-16, B-08

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APO-06 | `[ ]` Build payout queue page: table of requested payouts with driver name, amount, bank account, request date, status; filter by status (requested/approved/processing/paid/failed); pagination | A-16 | 📡 BE-WADM-14 | — |
| FE-APO-07 | `[ ]` Build payout approve/reject actions: "Approve" button with confirmation dialog; "Reject" button with mandatory reason; reflect status immediately | A-16 | 📡 BE-WADM-15, BE-WADM-16 | — |
| FE-APO-08 | `[ ]` Build bulk payout approval: checkbox selection on payout queue; "Approve Selected" action with count confirmation; process in batch | A-16 | 📡 BE-WADM-15 | — |
| FE-APO-09 | `[ ]` Build failed payout retry action: "Retry" button on failed payouts; show failure reason; confirmation dialog; re-dispatch transfer | A-16 | 📡 BE-WADM-17 | — |
| FE-APO-10 | `[ ]` Build payout status tracking: real-time status updates from Paystack transfer webhooks; status timeline on payout detail | A-16 | 📡 BE-EARN-16 | — |
| FE-APO-11 | `[ ]` Build payout batch export: "Export" button on payout queue; CSV download of filtered payouts for finance reconciliation | A-16 | 📡 BE-WADM-18 | — |

---

## 18. Phase 2 — Admin Screens

**PRD refs:** A-33–A-39

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-ASR-01 | `[ ]` Build scheduled rides Admin config page: set dispatch lead time, reminder timing | A-33 | 📡 BE2-SCH-09 | — |
| FE2-A3P-01 | `[ ]` Build third-party booking config page: set PIN recipient and tracking recipient policies | A-34 | 📡 BE2-3P-05 | — |
| FE2-ALF-01 | `[ ]` Build lost & found Admin queue: list of reports with trip, category, status; intervention form | A-35 | 📡 BE2-LF-07, BE2-LF-08 | — |
| FE2-ADIS-01 | `[ ]` Build advanced dispute view: evidence viewer (photos), escalation tier badge, escalate action | A-36, A-37 | 📡 BE2-DISP-03, BE2-DISP-05 | ⚠ OQ-21: Escalation tiers TBD |
| FE2-ACITY-01 | `[ ]` Build multi-city onboarding wizard: step-by-step guide for adding a new city (create city → configure pricing → activate vehicle classes → assign drivers) | A-38 | 📡 BE-CITY-03, BE-PRICE-02, BE-CITY-13 | — |
| FE2-ASHIFT-01 | `[ ]` Build driver shift management page: calendar view, assign/edit shifts, view coverage | A-39 | 📡 BE2-SHIFT-04 | — |
| FE2-AVEH-01 | `[ ]` Build vehicle maintenance tracker: list of maintenance records, create/edit, overdue alerts | A-39 | 📡 BE2-VEH-04 | — |

---

# Landing Page

---

## 19. Landing Page

**Platform:** Web (static site or Next.js SSR/SSG — TBD)
**Purpose:** Public-facing marketing site for riders, drivers, and partners

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-LP-01 | `[ ]` Build hero section: headline, value proposition, CTA buttons ("Ride with E-tiGo" → app store links, "Drive with E-tiGo" → driver signup) | — | — | Above-the-fold; mobile-first responsive |
| FE-LP-02 | `[ ]` Build "How It Works" section: 3-step visual flow for riders (book → ride → pay) and drivers (sign up → onboard → earn) | — | — | Tabbed or split layout for rider/driver |
| FE-LP-03 | `[ ]` Build features/benefits section: safety (SOS, PIN verification, trip sharing), green impact (carbon dashboard, EV fleet), fair pricing | — | — | Icons + short copy per feature |
| FE-LP-04 | `[ ]` Build carbon impact section: aggregate platform CO₂ savings counter (animated), green mission statement, link to sustainability page | — | — | Counter can be static initially; connect to API later |
| FE-LP-05 | `[ ]` Build driver recruitment section: earnings potential, flexibility messaging, vehicle requirements, "Apply to Drive" CTA → driver app download or web registration | — | — | — |
| FE-LP-06 | `[ ]` Build city availability section: list/map of active cities; "Coming Soon" for expansion cities | — | — | ⚠ Can be static initially or pull from API if available |
| FE-LP-07 | `[ ]` Build app download section: iOS App Store + Google Play Store badges with deep links; QR code for mobile scan | — | — | — |
| FE-LP-08 | `[ ]` Build footer: company links (About, Careers, Press), legal (Terms, Privacy, Cookie Policy), social media icons, contact info | — | — | — |
| FE-LP-09 | `[ ]` Build responsive navigation header: logo, nav links (Ride, Drive, Safety, About), CTA button; mobile hamburger menu | — | — | — |
| FE-LP-10 | `[ ]` Implement SEO: meta tags, Open Graph, structured data (Organization, LocalBusiness), sitemap.xml, robots.txt | — | — | — |
| FE-LP-11 | `[ ]` Build safety page: detailed safety features (SOS flow, PIN verification, real-time tracking, driver vetting), trust signals | — | — | Linked from main nav and footer |
| FE-LP-12 | `[ ]` Build FAQ / Help section: expandable accordion with common questions for riders and drivers | — | — | — |

---

## 20. Open Questions

### Admin Dashboard

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-07 | Admin role-based tiers | FE-AA-01, FE-AA-03 | `[x]` **Resolved:** super_admin, operations, safety_operator, support |
| OQ-08 | Can Admin issue refunds/fare adjustments when resolving disputes? | FE-ADIS-03 | `[ ]` Unresolved |
| OQ-09 | Tier naming: Option A (Bronze → Diamond) or Option B (Seed → Forest)? | FE-AGF-01 | `[ ]` Unresolved |
| OQ-14 | False-positive rate target and L3 overturn authority? | FE-AAO-04 | `[ ]` Unresolved |
| OQ-21 | Dispute escalation tiers and ownership? | FE2-ADIS-01 | `[ ]` Unresolved |

### Landing Page

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-22 | Static site or SSR framework? (Next.js SSG vs plain HTML/CSS vs Webflow) | FE-LP-01 through FE-LP-12 | `[ ]` Unresolved |
| OQ-23 | Brand design system: colours, typography, illustration style? | FE-LP-01 through FE-LP-12 | `[ ]` Unresolved |
| OQ-24 | Which cities to list as "Coming Soon"? | FE-LP-06 | `[ ]` Unresolved |

---

## Task Summary

| App | Phase 1 Tasks | Phase 2 Tasks | Total |
|-----|--------------|---------------|-------|
| Admin Dashboard | 78 | 7 | 85 |
| Landing Page | 12 | — | 12 |
| **Total** | **90** | **7** | **97** |

---

*Web scope from PRD v3.0. See [Passenger App](Passenger_App_Task_Breakdown.md), [Driver App](Driver_App_Task_Breakdown.md), and [Backend](E-Tigo_Backend_Task_Breakdown.md) for other breakdowns.*
