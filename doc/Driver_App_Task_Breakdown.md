# E-tiGo — Driver App Task Breakdown

**Platform:** iOS & Android (Flutter)
**Source:** PRD v3.0 (Phase 1 & Phase 2) + Product Brief
**Backend API:** Consumed from `etigo-api` — see `E-Tigo_Backend_Task_Breakdown.md` for endpoint specifications
**Auth:** Email/password → Sanctum bearer token stored in secure storage (Keychain / Keystore)
**Shared with Passenger App:** Auth screens (register/login), token storage, 401 interceptor — `type: "driver"` distinguishes
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

**Related docs:** [Passenger App](Passenger_App_Task_Breakdown.md) | [Web (Admin + Landing)](Web_Task_Breakdown.md) | [Backend](E-Tigo_Backend_Task_Breakdown.md)

---

## Table of Contents

### Phase 1

1. [Auth & KYC Onboarding](#1-auth--kyc-onboarding)
2. [Ride Handling](#2-ride-handling)
3. [Earnings & History](#3-earnings--history)
4. [Anti-Offline Trip Notices](#4-anti-offline-trip-notices)
5. [EV Charging](#5-ev-charging)

### Phase 2

6. [Scheduling & Vehicle](#6-phase-2--scheduling--vehicle)

### Tracking

7. [Open Questions](#7-open-questions)

---

## 1. Auth & KYC Onboarding

**PRD refs:** D-01, D-02, D-03, D-04, D-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DK-01 | `[ ]` Build driver registration entry point: same register/login screens as passenger with `type: "driver"`; auto-creates Driver record on backend | D-01 | 📡 BE-AUTH-01, BE-AUTH-03 | Driver and Passenger apps share auth components; type field distinguishes |
| FE-DK-02 | `[ ]` Build KYC document upload screen: section for each required document (licence, registration, insurance, government ID); camera capture + gallery picker; file validation (type, size) | D-02 | 📡 BE-ADMIN-03 | Show upload progress; handle retry on failure |
| FE-DK-03 | `[ ]` Build vehicle profile entry screen: make, model, colour, plate number fields; submit for Admin review | D-04 | 📡 BE-ADMIN-04 | Vehicle class is assigned by Admin post-review, not self-selected |
| FE-DK-04 | `[ ]` Build KYC pending review screen: "Your application is under review" status with document list showing individual review states (pending/approved/rejected) | D-03 | 📡 BE-ADMIN-05 | Poll on focus or receive push notification on status change |
| FE-DK-05 | `[ ]` Build KYC rejection screen: show rejection reason per document; allow re-upload of rejected documents only | D-03 | 📡 BE-ADMIN-03 | — |
| FE-DK-06 | `[ ]` Build KYC approved transition: celebration animation, assigned vehicle class display, "Go Online" CTA | D-03 | 📡 BE-ADMIN-05 | — |
| FE-DK-07 | `[ ]` Build online/offline toggle: prominent switch on home screen; validate prerequisites (approved KYC, vehicle class assigned, not suspended); show online duration counter | D-05 | 📡 BE-ADMIN-06 | Block toggle with message if prerequisites not met |

---

## 2. Ride Handling

**PRD refs:** D-06, D-07, D-08, D-09, D-10, D-11, SOS-01

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DR-01 | `[ ]` Build driver home screen (online state): map showing driver's location, online status indicator, area heatmap or demand indicators (if available), ride-request listener active | D-05 | 📡 BE-LOC-01 (WS) | — |
| FE-DR-02 | `[ ]` Implement background location service: continuous GPS updates to backend when online; handle background/foreground transitions; battery-efficient interval | D-06 | 📡 BE-LOC-01 | ⏱ Balance accuracy with battery; respect OS background limits |
| FE-DR-03 | `[ ]` Build incoming ride request overlay: pickup location on mini-map, passenger name/rating, estimated distance/fare, accept/reject buttons, countdown timer | D-06, D-07 | 📡 BE-MATCH-03 (WS) | Sound + vibration alert; auto-reject on timer expiry |
| FE-DR-04 | `[ ]` Build ride accepted screen: navigate-to-pickup button (open external navigation app with coordinates), passenger details, pickup address, cancel option | D-08, D-09 | 📡 BE-MATCH-04 | — |
| FE-DR-05 | `[ ]` Build navigation integration: deep-link to Google Maps / Apple Maps / Waze with destination coordinates for turn-by-turn navigation | D-08 | — | Let driver choose preferred nav app |
| FE-DR-06 | `[ ]` Build "Arrived at Pickup" action button: visible when near pickup location (GPS proximity check); on tap notify backend → wait for PIN | D-09 | 📡 BE-RIDE-07 | — |
| FE-DR-07 | `[ ]` Build PIN entry screen: 4-digit numeric keypad input; on submit, validate against backend; show error on mismatch with remaining attempts | D-09, §9.1 | 📡 BE-RIDE-08 | Hard gate — trip cannot start without valid PIN |
| FE-DR-08 | `[ ]` Build trip-in-progress screen: live map with route to destination, destination address, ride duration timer, navigate-to-destination button | D-09 | 📡 BE-LOC-06 | — |
| FE-DR-09 | `[ ]` Build "Complete Trip" action button: visible at/near destination; on tap, end ride, show earnings summary | D-09 | 📡 BE-RIDE-09 | — |
| FE-DR-10 | `[ ]` Build trip completion summary (driver view): earned fare, tip (if any), payment method, trip distance/duration, "Rate Passenger" prompt | D-10 | 📡 BE-RIDE-14, BE-PAY-14 | — |
| FE-DR-11 | `[ ]` Build passenger rating screen (driver view): 5-star selector, optional comment, submit | D-10 | 📡 BE-RATE-01 | — |
| FE-DR-12 | `[ ]` Build SOS button (driver): same persistent emergency button as passenger; tap → confirm → trigger SOS with driver trigger_type | SOS-01 | 📡 BE-SOS-03 | 🔒 Always accessible during active ride |
| FE-DR-13 | `[ ]` Build cash payment confirmation: if payment method is cash, show "Confirm Cash Received" button after trip completion | D-10 | 📡 BE-PAY-07 | — |

---

## 3. Earnings & History

**PRD refs:** D-10, D-11

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DE-01 | `[ ]` Build earnings dashboard: today's earnings total, weekly/monthly summary, trips completed count, online hours | D-10 | 📡 BE-RIDE-12, BE-PAY-14 | ⚠ OQ-06: Payout schedule/balance display TBD |
| FE-DE-02 | `[ ]` Build earnings chart: daily/weekly bar chart of earnings over time | D-10 | 📡 BE-RIDE-12 | — |
| FE-DE-03 | `[ ]` Build ride history list (driver view): past rides with date, passenger, route, fare; tap for detail | D-11 | 📡 BE-RIDE-12 | — |
| FE-DE-04 | `[ ]` Build ride detail screen (driver view): route on map, fare breakdown, payment method, passenger rating given/received | D-11 | 📡 BE-RIDE-14, BE-PAY-12 | — |

---

## 4. Anti-Offline Trip Notices

**PRD refs:** OE-01, OE-02, OE-03

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DAO-01 | `[ ]` Build compliance warning notification: push notification handler; tap navigates to compliance status screen | OE-01 | 📡 Push notification | — |
| FE-DAO-02 | `[ ]` Build compliance status screen: current status (clear/warned/suspended), active flags with details, sanction tier and countdown (for suspensions) | OE-03 | 📡 BE-AO-08 | — |
| FE-DAO-03 | `[ ]` Build flag dispute screen: view flag details, text area for dispute explanation, "Submit Dispute" CTA | OE-02 | 📡 BE-AO-07 | — |
| FE-DAO-04 | `[ ]` Build suspension state: block online toggle, show suspension reason and duration, link to compliance screen | OE-03 | 📡 BE-ADMIN-05 | Gate the entire online/offline flow |

---

## 5. EV Charging

**PRD refs:** EV-01, EV-02, EV-03, EV-04

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DEV-01 | `[ ]` Build EV station map: nearby charging stations plotted on map with availability indicators (green/yellow/red); filter by city | EV-01 | 📡 BE-EV-06 | Show only if driver's vehicle class is EV |
| FE-DEV-02 | `[ ]` Build station detail sheet: station name, address, stall count, real-time availability per stall, "Reserve" button | EV-01 | 📡 BE-EV-06 | — |
| FE-DEV-03 | `[ ]` Build reservation flow: three outcomes — (a) confirmed reservation with stall number + navigate CTA, (b) queued with position + estimated wait, (c) long wait with suggested retry time | EV-02, EV-03 | 📡 BE-EV-09 | UI must handle all three states cleanly |
| FE-DEV-04 | `[ ]` Build active reservation status card: countdown or queue position, stall number (when assigned), "Cancel" option | EV-02 | 📡 BE-EV-13 | Persistent card on home screen while reservation active |
| FE-DEV-05 | `[ ]` Build queue-to-reservation transition notification: push notification + in-app update when queue advances and stall becomes available | EV-02 | 📡 BE-EV-11 (push) | — |
| FE-DEV-06 | `[ ]` Build reservation history: list of past/active reservations with station, stall, status, fee, waiver applied | EV-04 | 📡 BE-EV-13 | — |
| FE-DEV-07 | `[ ]` Display tier fee waiver indicator: badge showing "Fee Waived" when user's tier qualifies; show original fee struck-through | EV-04, G-08 | 📡 BE-EV-12 | — |

---

## 6. Phase 2 — Scheduling & Vehicle

**PRD refs:** D-14, D-15, D-16

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-DSH-01 | `[ ]` Build shift schedule screen: calendar/list view of assigned shifts with date, start/end time, status | D-15 | 📡 BE2-SHIFT-03 | ⚠ OQ-20: UI scope depends on scheduling model decision |
| FE2-DSH-02 | `[ ]` Build shift reminder notification handler | D-15 | 📡 Push notification | — |
| FE2-DVH-01 | `[ ]` Build vehicle status/health screen: charging status (for EV), maintenance indicators (due/overdue), next service date | D-16 | 📡 BE2-VEH-03 | ⚠ OQ-19: EV-specific vs general TBD |
| FE2-DVH-02 | `[ ]` Build lost item driver response screen: notification of reported lost item; "I have it" / "I don't have it" response; contact instructions if confirmed | D-14 | 📡 BE2-LF-06 | — |

---

## 7. Open Questions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-02 | Which maps/geocoding provider is approved? (determines map SDK) | FE-DR-01 | `[ ]` Unresolved |
| OQ-06 | How and how often are driver earnings paid out? | FE-DE-01 | `[ ]` Unresolved |
| OQ-16 | Fee waiver applies to driver, passenger, or both? | FE-DEV-07 | `[ ]` Unresolved |
| OQ-17 | Minimum supported iOS and Android versions? | All screens | `[ ]` Unresolved |
| OQ-19 | Vehicle charging & maintenance: EV-specific, general, or both? | FE2-DVH-01 | `[ ]` Unresolved |
| OQ-20 | Driver scheduling: shift scheduling or availability planning? | FE2-DSH-01 | `[ ]` Unresolved |

---

## Task Summary

| Section | Tasks |
|---------|-------|
| Phase 1 | 31 |
| Phase 2 | 4 |
| **Total** | **35** |

---

*Driver App scope from PRD v3.0. See [Passenger App](Passenger_App_Task_Breakdown.md), [Web](Web_Task_Breakdown.md), and [Backend](E-Tigo_Backend_Task_Breakdown.md) for other breakdowns.*
