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

6. [Earnings Ledger & Payouts](#6-earnings-ledger--payouts)

### Phase 2

7. [Scheduling & Vehicle](#7-phase-2--scheduling--vehicle)
8. [Disputes](#8-phase-2--disputes)
9. [Cities](#9-phase-2--cities)

### Tracking

10. [Open Questions](#10-open-questions)

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
| FE-DE-01 | `[ ]` Build earnings dashboard: today's earnings total, weekly/monthly summary, trips completed count, online hours | D-10 | 📡 BE-RIDE-12, BE-PAY-14 | OQ-06 resolved: on-demand payouts with finance approval, plus an opt-in weekly auto-payout (Monday 06:00 Lagos). Show the available balance and the driver's payout preference |
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

## 6. Earnings Ledger & Payouts

**PRD refs:** D-10, D-11
**Design note:** Not a wallet — drivers see earnings from completed rides (net of commission) and request payouts to bank accounts.

### 6.1 Earnings Dashboard

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DEL-01 | `[ ]` Build earnings home screen: pending balance, available balance, total paid out; today / this week / this month summary cards; pull-to-refresh | D-10 | 📡 BE-EARN-06 | Replaces basic earnings total from FE-DE-01; richer breakdown |
| FE-DEL-02 | `[ ]` Build per-ride earnings breakdown: fare amount, commission rate, commission deducted, net earnings, payment type tag (wallet/card/cash), tip (if any); accessible from trip history | D-10 | 📡 BE-EARN-07 | Link from existing ride history (FE-DE-03) to earnings line |
| FE-DEL-03 | `[ ]` Build earnings chart: daily/weekly bar chart of net earnings over time; toggle between gross fare and net earnings views | D-10 | 📡 BE-EARN-06 | Enhances existing FE-DE-02 with commission visibility |

### 6.2 Ledger History

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DEL-04 | `[ ]` Build ledger history screen: paginated entries showing ride earnings, adjustments, clawbacks, and payouts with type icons and status badges | D-11 | 📡 BE-EARN-08 | Filter by date range and entry type |
| FE-DEL-05 | `[ ]` Build ledger entry detail screen: full entry details with reference ID, date/time, amount, type, linked ride (if applicable) | D-11 | 📡 BE-EARN-08 | — |

### 6.3 Bank Account Setup

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DEL-06 | `[ ]` Build bank account setup screen: bank selector (from Paystack bank list), account number input (10 digits), "Verify" button; display resolved account name for confirmation | D-10 | 📡 BE-EARN-09 | 🔒 Re-authentication or OTP required before saving |
| FE-DEL-07 | `[ ]` Build bank account display: show saved bank name, masked account number, account holder name; "Change" button with safeguard (re-auth confirmation) | D-10 | 📡 BE-EARN-11 | — |

### 6.4 Payout Requests

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DEL-08 | `[ ]` Build payout request screen: display available balance and minimum payout amount; amount input (pre-filled with available balance); bank account summary; "Request Payout" CTA with confirmation step | D-10 | 📡 BE-EARN-12 | Disable if below minimum or no verified bank account |
| FE-DEL-09 | `[ ]` Build payout history screen: list of payout requests with status badges (requested/approved/processing/paid/failed); tap for detail | D-10 | 📡 BE-EARN-14 | — |
| FE-DEL-10 | `[ ]` Build payout detail screen: amount, status timeline, bank account used, failure reason (if failed) with retry guidance | D-10 | 📡 BE-EARN-14 | — |

### 6.5 Cash-Ride Commission & Notifications

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DEL-11 | `[ ]` Build cash-ride commission indicator: show commission owed on cash rides; negative balance warning on earnings dashboard; block or warn on new ride acceptance above configurable threshold | D-10 | 📡 BE-EARN-05 | OQ-29 resolved: commission is debited from the driver ledger; the balance may go to −₦5,000, beyond which the driver cannot accept rides or reserve scheduled rides (warn as it approaches) |
| FE-DEL-12 | `[ ]` Build earnings push notification handlers: earnings credited, payout approved, payout paid, payout failed; tap navigates to relevant screen | D-10 | 📡 BE-WADM-27 | — |
| FE-DEL-13 | `[ ]` Handle loading, empty, and error states across all earnings screens: skeleton loaders, empty state illustrations, retry buttons on network failure | D-10 | — | — |

---

## 7. Phase 2 — Scheduling & Vehicle

**PRD refs:** D-12, D-13, D-14, D-15, D-16

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-DSH-01 | `[ ]` Build shift schedule screen (fleet cars in shift mode only): calendar/list view of assigned shifts with date, start/end time, vehicle, status; explain why going online is refused outside a shift | D-15 | ✅ BE2-SHIFT-03, BE2-SHIFT-05 | **API ready (18 Oct 2026).** `GET /driver/shifts` (`shift_driver`, `current_shift`, `rules`, each shift's `online_from` / `offers_stop_at`); `toggle-online` 422 `reasons` explain the window or that the car is still with the previous driver, and 200 returns `shift`. See [FRONTEND_UPDATE_DRIVER_SCHEDULING_MAINTENANCE.md](../FRONTEND_UPDATE_DRIVER_SCHEDULING_MAINTENANCE.md) |
| FE2-DSH-03 | `[ ]` Build "My availability" screen for all drivers: add/remove planned hours and area for the coming week; clearly optional | D-15 | ✅ BE2-SHIFT-07 | **API ready.** `GET/POST/DELETE /driver/availability-plans` (`repeat_weekly` 0–3, `area_label`). Voluntary; never blocks going online |
| FE2-DSH-02 | `[ ]` Build shift reminder notification handler | D-15 | ✅ Push notification | **API ready.** Types `driver_shift_assigned`, `driver_shift_updated`, `driver_shift_cancelled`, `driver_shift_missed`, `driver_shift_ending`, `driver_shift_ended`, `shift_handover_delayed` (data `shift_id`) |
| FE2-DVH-01 | `[ ]` Build vehicle status/health screen: maintenance items (service, tyres & brakes, insurance, roadworthiness, licence) with due/overdue/expired state; for electric/plug-in hybrid also battery health and charging status; charge-level prompt when going online (plug-in only); upload proof of completed maintenance | D-16 | ✅ BE2-VEH-03, BE2-VEH-04 | **API ready.** `GET /driver/vehicle/status` (items with ready-to-show `message`), `POST /driver/vehicle/maintenance-records` (own cars, proof required), `POST /driver/vehicle/odometer`, `battery_level_percent` on `toggle-online`, new document types `roadworthiness_certificate` and `vehicle_licence` (expiry required); `vehicle_notice` on going online during the grace period. Push types `vehicle_maintenance_due`, `vehicle_blocked`, `vehicle_unblocked`, `maintenance_record_reviewed` |
| FE2-DSR-01 | `[ ]` Build "Scheduled rides" screen: list bookings available to reserve (pickup time, trip, fare, passenger first name/rating) with a **Reserve** action; surface 422 reasons as returned | D-12 | 📡 BE2-SCH-10 | API ready: `GET /driver/scheduled-rides/available`, `POST /driver/scheduled-rides/{ride}/reserve`. See FRONTEND_UPDATE_SCHEDULED_RIDES.md |
| FE2-DSR-02 | `[ ]` Build "My reservations" list with **Release**; show "be online by `dispatch_at`" | D-12 | 📡 BE2-SCH-10 | API ready: `GET /driver/scheduled-rides`, `POST /driver/scheduled-rides/{ride}/release` |
| FE2-DSR-03 | `[ ]` Handle `scheduled_ride_reminder`, `scheduled_ride_reserved`, `scheduled_ride_reservation_released` and `ride_cancelled` (reserved booking) notifications; show `scheduled_pickup_at` on ride offers | D-12 | 📡 Push / `ride.request.dispatched` | A reserved ride arrives as a normal assigned ride at `dispatch_at` |
| FE2-D3P-01 | `[ ]` Third-party rides: show and call the person being picked up from the ride's `rider` object (`first_name`, `phone`), not `passenger`; show a "Booked for {name}" badge when `rider.is_third_party` / offer `is_third_party` is true; the offer's `passenger_name` is then the rider's first name | D-13 | 📡 BE2-3P-09 | API ready (18 Oct 2026). The PIN flow is unchanged (the rider reads it out). See FRONTEND_UPDATE_THIRD_PARTY_BOOKINGS.md |
| FE2-DVH-02 | `[ ]` Build lost item driver response screen: notification of reported lost item; "I have it" / "I don't have it" response; contact instructions if confirmed | D-14 | 📡 BE2-LF-06 | API ready (18 Oct 2026): `lost_item_report` push → `GET /lost-items/{id}`; `POST /lost-items/{id}/respond` (`found`, `note`), then the passenger's name and number in `contact`, messages (`/messages`) and **Mark returned** (`/returned`); buttons from `available_actions`. Also "Report a found item" on a past trip: `POST /rides/{ride}/lost-items`. List: `GET /lost-items`. See FRONTEND_UPDATE_LOST_AND_FOUND.md |

---

## 8. Phase 2 — Disputes

**PRD refs:** P-27 (both sides of the trip can report an issue)

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-DDIS-01 | `[ ]` Build "Report an issue" on a completed trip (category, description, photos, audio) and a "My reports" list and detail with `stage_label` | P-27 | 📡 BE-RATE-04, BE2-DISP-03 | API ready (18 Oct 2026): `POST /rides/{ride}/dispute`, `GET /disputes`, `GET /disputes/{id}`. A `safety_concern` goes to the Safety team. If the passenger also reported the trip, both cases are handled by the same reviewer. See FRONTEND_UPDATE_ADVANCED_DISPUTES.md |
| FE2-DDIS-02 | `[ ]` Build "Add evidence" on an open report | P-27 | 📡 BE2-DISP-03 | `POST /disputes/{id}/evidence` (multipart `files[]`, `captions[]`); show while `can_add_evidence` |
| FE2-DDIS-03 | `[ ]` Build "Appeal this decision" and the `dispute_update` push handler | P-27 | 📡 BE2-DISP-10, BE2-DISP-12 | `POST /disputes/{id}/appeal` while `appeal.can_appeal`; data has `dispute_id` |

---

## 9. Phase 2 — Cities

**PRD refs:** B-31, A-38 (multi-city), OQ-22

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-DCITY-01 | `[ ]` Sign-up and onboarding city picker: list cities with `GET /cities?include_onboarding=1` and label cities that are launching soon; lock the city once the application is submitted ("Contact support to move city") | D-01, B-31 | 📡 BE2-CITY-03, BE2-CITY-06 | API ready (18 Oct 2026). Paused cities are not offered; `PUT /driver/profile` with another city after submitting returns 422. See FRONTEND_UPDATE_MULTI_CITY.md |
| FE2-DCITY-02 | `[ ]` Going online: show the "not launched yet" reason from `toggle-online` and the paused-city `city_notice` banner; handle `city_status_changed` and `driver_city_changed` notifications | B-31 | 📡 BE2-CITY-04, BE2-CITY-06 | Drivers in an onboarding city cannot go online; in a paused city they can (for rides booked before the pause) |

---

## 10. Open Questions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-02 | Which maps/geocoding provider is approved? (determines map SDK) | FE-DR-01 | `[x]` **Resolved:** the backend uses Mapbox for directions, distance and duration, with a place-search failover (Mapbox → Geoapify → LocationIQ → Photon). See OPEN_QUESTIONS_DECISIONS OQ-02 |
| OQ-06 | How and how often are driver earnings paid out? | FE-DE-01 | `[x]` **Resolved:** on-demand payouts with finance approval, plus an opt-in weekly auto-payout (Monday 06:00 Lagos) |
| OQ-16 | Fee waiver applies to driver, passenger, or both? | FE-DEV-07 | `[x]` **Resolved:** drivers only; Platinum and Diamond drivers pay no EV reservation fee |
| OQ-17 | Minimum supported iOS and Android versions? | All screens | `[x]` **Resolved:** 1.0.0 on both platforms; `GET /config` returns the minimum versions and `force_update` |
| OQ-19 | Vehicle charging & maintenance: EV-specific, general, or both? | FE2-DVH-01 | `[~]` **Recommended (16 Oct 2026):** both — general items for all vehicles, EV items by fuel type. Backend built to this (18 Oct 2026). Awaiting client confirmation |
| OQ-20 | Driver scheduling: shift scheduling or availability planning? | FE2-DSH-01, FE2-DSH-03 | `[~]` **Recommended (16 Oct 2026):** voluntary availability planning for all; shifts only for shared fleet cars. Backend built to this (18 Oct 2026). Awaiting client confirmation |
| OQ-28 | Driver earnings settlement delay: instant, 24 hours, or weekly? | FE-DEL-01 | `[x]` **Resolved:** instant by default; finance can switch to 24 hours or weekly |
| OQ-29 | Cash-ride commission: debit from driver ledger? Allow negative balance? Threshold? | FE-DEL-11 | `[x]` **Resolved:** commission debited from the driver ledger; negative balance allowed to −₦5,000, beyond which the driver cannot accept rides or reserve scheduled rides |
| OQ-30 | Minimum payout amount for driver withdrawals? | FE-DEL-08 | `[x]` **Resolved:** ₦1,000; admin-adjustable |
| OQ-31 | Driver payouts: on-demand request or fixed schedule (weekly/bi-weekly)? | FE-DEL-08 | `[x]` **Resolved:** on-demand with finance approval; drivers can opt in to the weekly auto-payout (OQ-06) |

---

## Task Summary

| Section | Tasks |
|---------|-------|
| Phase 1 | 48 |
| Phase 2 | 14 |
| **Total** | **62** |

---

*Driver App scope from PRD v3.0. See [Passenger App](Passenger_App_Task_Breakdown.md), [Web](Web_Task_Breakdown.md), and [Backend](E-Tigo_Backend_Task_Breakdown.md) for other breakdowns.*
