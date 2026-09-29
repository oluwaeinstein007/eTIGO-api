# E-tiGo — Frontend Task Breakdown

**Source:** PRD v3.0 (Phase 1 & Phase 2) + Product Brief  
**Scope:** Passenger App, Driver App, Admin Dashboard — all client-side UI/UX work  
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

---

## Table of Contents

### Phase 1 — Passenger App (Weeks 1–8)

1. [Passenger: Auth & Onboarding](#1-passenger-auth--onboarding)
2. [Passenger: Ride Booking Flow](#2-passenger-ride-booking-flow)
3. [Passenger: In-Ride Experience](#3-passenger-in-ride-experience)
4. [Passenger: Payment & Ratings](#4-passenger-payment--ratings)
5. [Passenger: Gamification & Carbon Dashboard](#5-passenger-gamification--carbon-dashboard)
6. [Passenger: Promo / Discount](#6-passenger-promo--discount)
7. [Passenger: SOS Emergency](#7-passenger-sos-emergency)

### Phase 1 — Driver App (Weeks 1–8)

8. [Driver: KYC & Onboarding](#8-driver-kyc--onboarding)
9. [Driver: Ride Handling](#9-driver-ride-handling)
10. [Driver: Earnings & History](#10-driver-earnings--history)
11. [Driver: Anti-Offline Trip Notices](#11-driver-anti-offline-trip-notices)
12. [Driver: EV Charging](#12-driver-ev-charging)

### Phase 1 — Admin Dashboard (Weeks 1–8)

13. [Admin: Auth & Layout](#13-admin-auth--layout)
14. [Admin: City Management](#14-admin-city-management)
15. [Admin: Vehicle Class Management](#15-admin-vehicle-class-management)
16. [Admin: Pricing Configuration](#16-admin-pricing-configuration)
17. [Admin: Live Ride Monitoring](#17-admin-live-ride-monitoring)
18. [Admin: Driver Management](#18-admin-driver-management)
19. [Admin: Passenger Management](#19-admin-passenger-management)
20. [Admin: Disputes](#20-admin-disputes)
21. [Admin: Manual Ride Assignment](#21-admin-manual-ride-assignment)
22. [Admin: Reporting & Analytics](#22-admin-reporting--analytics)
23. [Admin: Gamification Config](#23-admin-gamification-config)
24. [Admin: Promo Management](#24-admin-promo-management)
25. [Admin: SOS Console](#25-admin-sos-console)
26. [Admin: Anti-Offline Monitoring](#26-admin-anti-offline-monitoring)
27. [Admin: EV Charging Management](#27-admin-ev-charging-management)

### Phase 2 — Additional Screens (Weeks 9–12)

28. [Phase 2 — Passenger: Scheduled Rides UI](#28-phase-2--passenger-scheduled-rides-ui)
29. [Phase 2 — Passenger: Third-Party Booking UI](#29-phase-2--passenger-third-party-booking-ui)
30. [Phase 2 — Passenger: Lost & Found UI](#30-phase-2--passenger-lost--found-ui)
31. [Phase 2 — Driver: Scheduling & Vehicle UI](#31-phase-2--driver-scheduling--vehicle-ui)
32. [Phase 2 — Admin: Phase 2 Admin Screens](#32-phase-2--admin-phase-2-admin-screens)

### Tracking

33. [Open Questions Tracker](#33-open-questions-tracker)

---

# PHASE 1 — Passenger App (Weeks 1–8)

---

## 1. Passenger: Auth & Onboarding

**PRD refs:** P-01, P-02, P-03, P-04, P-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PA-01 | `[ ]` Build splash / welcome screen with branding, "Get Started" CTA | P-01 | — | First launch experience |
| FE-PA-02 | `[ ]` Build registration screen: first name, last name, phone (E.164), email, password, password confirmation, type selector; call `POST /auth/register` | P-01 | 📡 BE-AUTH-01 | Show inline validation errors (422); auto-store token on success |
| FE-PA-03 | `[ ]` Build login screen: email, password, type selector; call `POST /auth/login` | P-01 | 📡 BE-AUTH-03 | Handle 401 (invalid credentials), 403 (deactivated account) |
| FE-PA-04 | `[ ]` Implement secure token storage: persist Sanctum bearer token in secure storage (Keychain/Keystore) | P-03 | 📡 BE-AUTH-05 | 🔒 Never store in plaintext/AsyncStorage |
| FE-PA-05 | `[ ]` Build profile edit screen: first name, last name, email; call `PUT /passenger/profile` | P-02 | 📡 BE-AUTH-09 | — |
| FE-PA-06 | `[ ]` Implement auto-login flow on app launch: check stored token → call `GET /auth/me` → navigate to home or auth | P-03 | 📡 BE-AUTH-08 | — |
| FE-PA-07 | `[ ]` Implement 401 interceptor: on 401 response → clear stored token → redirect to login screen | P-03 | 📡 BE-AUTH-03 | Sanctum tokens don't expire by default; 401 means revoked/invalid |
| FE-PA-08 | `[ ]` Build settings screen with: profile edit, saved payment methods, notification preferences, logout, app version | P-02, P-15 | 📡 BE-AUTH-07 | Logout clears secure storage |

---

## 2. Passenger: Ride Booking Flow

**PRD refs:** P-04, P-05, P-06, P-07, P-08, P-09, P-10

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PB-01 | `[ ]` Build home screen map view: show user's current location on map, "Where to?" search bar | P-04 | — | ⚠ OQ-02: Map SDK depends on maps provider |
| FE-PB-02 | `[ ]` Implement location permissions flow: request on first booking, handle denied/restricted states gracefully with rationale screen | P-04 | — | — |
| FE-PB-03 | `[ ]` Build destination search screen: text input with autocomplete suggestions from maps API, recent destinations, saved places | P-05 | 📡 SETUP-59 (maps) | Debounce autocomplete calls |
| FE-PB-04 | `[ ]` Build pickup location refinement: draggable pin on map, address display updates in real time via reverse geocode | P-04 | 📡 SETUP-59 | Allow manual address entry as fallback |
| FE-PB-05 | `[ ]` Build route preview: show pickup + destination pins, draw route polyline on map, display estimated distance and duration | P-06 | 📡 SETUP-59 | — |
| FE-PB-06 | `[ ]` Build vehicle class selector: horizontally scrollable list of available vehicle classes for this city with name, icon, capacity, fare estimate per class | P-06 | 📡 BE-PRICE-06, BE-CITY-14 | Fetch all estimates in one call; highlight selected |
| FE-PB-07 | `[ ]` Build fare estimation display: estimated fare range, fare breakdown on expand (base fare, distance, time), payment method selector (cash/card) | P-06, P-07 | 📡 BE-PRICE-06 | — |
| FE-PB-08 | `[ ]` Build payment method selector: toggle cash/card, show saved card last-four; option to add new card flows to payment screen | P-15 | 📡 BE-PAY-03 | Default to user's preferred method |
| FE-PB-09 | `[ ]` Build promo code entry UI at checkout: text input, "Apply" button; show discount amount or error inline | PR-01 | 📡 BE-PROMO-07 | Discount reflected in fare estimate immediately |
| FE-PB-10 | `[ ]` Build "Request Ride" confirmation: summary of pickup, destination, vehicle class, fare estimate, payment method, applied promo → confirm CTA | P-08 | 📡 BE-RIDE-03 | — |
| FE-PB-11 | `[ ]` Build searching-for-driver animation screen: animated indicator, search status, "Cancel" button | P-09 | 📡 BE-RIDE-01 (WS) | Subscribe to ride channel for match event |
| FE-PB-12 | `[ ]` Build cancellation confirmation dialog: "Are you sure?", show cancellation policy text; on confirm, call cancel endpoint | P-10 | 📡 BE-RIDE-06 | ⚠ OQ-05: Show fee warning if applicable |

---

## 3. Passenger: In-Ride Experience

**PRD refs:** P-11, P-12, P-13, P-14, SOS-01, SOS-02

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PIR-01 | `[ ]` Build driver matched screen: driver photo, name, rating, vehicle (make/model/colour/plate), vehicle class badge, ETA to pickup | P-11 | 📡 BE-RIDE-14 | Transition from searching screen |
| FE-PIR-02 | `[ ]` Build en-route-to-pickup map view: live driver location on map (via WS), ETA countdown, route from driver to pickup | P-11 | 📡 BE-LOC-06 (WS) | ⏱ Location updates must render smoothly; interpolate between updates |
| FE-PIR-03 | `[ ]` Build PIN display card: show 4-digit PIN prominently when driver arrives; instruction text "Share this PIN with your driver" | P-14, §9.1 | 📡 BE-RIDE-14 | PIN from ride record; display only in Driver_Arrived state |
| FE-PIR-04 | `[ ]` Build trip-in-progress map view: live route on map, real-time progress along route, destination ETA, driver location tracking | P-12 | 📡 BE-LOC-06 (WS) | — |
| FE-PIR-05 | `[ ]` Build trip sharing: "Share trip" button → generate shareable link (share_token); native share sheet with link; track sharing state | P-13 | 📡 BE-RIDE-13 | — |
| FE-PIR-06 | `[ ]` Build SOS button: persistent floating emergency button during active ride; tap opens confirmation → triggers SOS | SOS-01, SOS-02 | 📡 BE-SOS-03 | 🔒 Always accessible; cannot be hidden or disabled by UI state |
| FE-PIR-07 | `[ ]` Build trip completion screen: final fare display, fare breakdown, payment method used, distance, duration, "Rate your ride" CTA | P-16 | 📡 BE-RIDE-09 | Auto-navigate here on ride completion event |

---

## 4. Passenger: Payment & Ratings

**PRD refs:** P-15, P-16, P-17, P-18, P-19

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PPR-01 | `[ ]` Build saved payment methods screen: list of saved cards (brand icon, last four, expiry), "Add New Card" button, swipe-to-delete | P-15 | 📡 BE-PAY-03, BE-PAY-04 | — |
| FE-PPR-02 | `[ ]` Build add card flow: integrate payment gateway SDK for tokenization; form fields per gateway requirements; save tokenized method | P-15 | 📡 BE-PAY-01 | 🔒 Card data goes to gateway, never to backend |
| FE-PPR-03 | `[ ]` Build rating screen: 5-star selector (tap/drag), optional text comment, "Submit" button; shown after trip completion | P-17 | 📡 BE-RATE-01 | Allow skip; prompt but don't block |
| FE-PPR-04 | `[ ]` Build tipping UI: post-ride tip selector (suggested amounts + custom), shown after rating or on receipt screen | P-16 | 📡 BE-PAY-10 | — |
| FE-PPR-05 | `[ ]` Build dispute reporting screen: category dropdown (fare issue, safety, driver behaviour, route, other), description text area, "Submit" CTA | P-18 | 📡 BE-RATE-04 | Accessible from ride history detail or post-ride |
| FE-PPR-06 | `[ ]` Build ride history screen: list of past rides (date, pickup→destination, fare, status) with pagination; tap for detail | P-19 | 📡 BE-RIDE-12 | — |
| FE-PPR-07 | `[ ]` Build ride detail / receipt screen: full fare breakdown, map with route, driver info, payment method, promo applied, tip, dispute option | P-19 | 📡 BE-PAY-12 | — |

---

## 5. Passenger: Gamification & Carbon Dashboard

**PRD refs:** G-01, G-04, G-05, G-06

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PG-01 | `[ ]` Build carbon dashboard screen: cumulative CO₂ saved, carbon score visualisation (chart/ring), equivalent metrics (e.g. "trees planted") | G-01 | 📡 BE-GAME-07 | Primary gamification surface |
| FE-PG-02 | `[ ]` Build tier status card: current tier badge with name, progress bar to next tier (points/threshold), tier benefits summary | G-04 | 📡 BE-GAME-07 | Displayed on dashboard and profile |
| FE-PG-03 | `[ ]` Build tier-up celebration animation: confetti/animation overlay, new tier badge reveal, unlocked benefits list | G-04 | 📡 TierUpgraded (push) | Triggered by push notification payload |
| FE-PG-04 | `[ ]` Build post-trip carbon score summary: on trip completion screen, show trip's CO₂ saved, points earned, multiplier applied (if any) | G-01 | 📡 BE-GAME-07 | Integrated into trip completion flow |
| FE-PG-05 | `[ ]` Build leaderboard or community ranking view: user's position among peers, anonymised top scores | G-04 | 📡 BE-GAME-07 | ⚠ Confirm if leaderboard is in scope for Phase 1 |
| FE-PG-06 | `[ ]` Display tier benefits across app: booking fee discount badge on fare estimate, EV fee waiver indicator on EV reservation, priority matching indicator | G-05, G-06 | 📡 BE-GAME-08 | Contextual badges — not a separate screen |

---

## 6. Passenger: Promo / Discount

**PRD refs:** PR-01, PR-02, PR-03, PR-04, PR-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PP-01 | `[ ]` Build promo code entry screen (accessible from profile/settings): text input, "Apply" button, validation feedback (success/error with reason) | PR-01 | 📡 BE-PROMO-07 | — |
| FE-PP-02 | `[ ]` Build available promos list screen: promos user qualifies for; show code, discount value, conditions, expiry; "Use" button pre-fills at checkout | PR-04 | 📡 BE-PROMO-12 | ⚠ OQ-12: Show this screen only if auto-surfacing promos is in scope |
| FE-PP-03 | `[ ]` Build promo applied success state: green badge on fare estimate, discount line in fare breakdown, celebration micro-animation | PR-02 | — | — |
| FE-PP-04 | `[ ]` Build promo rejection feedback: inline error below input with reason (expired, max redemptions, not in area, tier too low, etc.) | PR-03 | 📡 BE-PROMO-07 | Use specific error reasons from API response |
| FE-PP-05 | `[ ]` Build promo expiry notification handling: push notification → deep link to promo list or booking screen | PR-05 | 📡 Push notification | — |
| FE-PP-06 | `[ ]` Integrate promo into booking flow: show applied promo on confirmation screen, allow removal, show original vs discounted fare | PR-02 | 📡 BE-PROMO-10 | — |

---

## 7. Passenger: SOS Emergency

**PRD refs:** SOS-01, SOS-02, SOS-03, SOS-04

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PSOS-01 | `[ ]` Build SOS trigger confirmation: modal with "Confirm Emergency" button; explain what happens next; large tap targets, high-contrast design | SOS-01, SOS-02 | 📡 BE-SOS-03 | 🔒 Must not be triggerable by accidental single-tap |
| FE-PSOS-02 | `[ ]` Build SOS active state UI: prominent visual indicator that SOS is active; show status (triggered → check-in → acknowledged/escalated); hide normal ride UI controls | SOS-02, SOS-03 | 📡 BE-SOS-01 (WS) | — |
| FE-PSOS-03 | `[ ]` Build SOS check-in prompt: modal "Are you safe?" with "I'm Safe" (acknowledge) and "Still need help" (escalate) buttons; auto-escalate if no response in 30s | SOS-03 | 📡 BE-SOS-07, BE-SOS-06 | ⏱ Timer must be server-authoritative; client-side timer is visual only |
| FE-PSOS-04 | `[ ]` Build SOS resolution UI: notification that SOS was resolved; allow feedback; return to normal ride view | SOS-04 | 📡 BE-SOS-08 | — |
| FE-PSOS-05 | `[ ]` Build SOS cancel flow: "Cancel SOS" option with confirmation (prevent accidental cancel); send cancel to backend | SOS-04 | 📡 BE-SOS-08 | — |

---

# PHASE 1 — Driver App (Weeks 1–8)

---

## 8. Driver: KYC & Onboarding

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

## 9. Driver: Ride Handling

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

## 10. Driver: Earnings & History

**PRD refs:** D-10, D-11

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DE-01 | `[ ]` Build earnings dashboard: today's earnings total, weekly/monthly summary, trips completed count, online hours | D-10 | 📡 BE-RIDE-12, BE-PAY-14 | ⚠ OQ-06: Payout schedule/balance display TBD |
| FE-DE-02 | `[ ]` Build earnings chart: daily/weekly bar chart of earnings over time | D-10 | 📡 BE-RIDE-12 | — |
| FE-DE-03 | `[ ]` Build ride history list (driver view): past rides with date, passenger, route, fare; tap for detail | D-11 | 📡 BE-RIDE-12 | — |
| FE-DE-04 | `[ ]` Build ride detail screen (driver view): route on map, fare breakdown, payment method, passenger rating given/received | D-11 | 📡 BE-RIDE-14, BE-PAY-12 | — |

---

## 11. Driver: Anti-Offline Trip Notices

**PRD refs:** OE-01, OE-02, OE-03

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-DAO-01 | `[ ]` Build compliance warning notification: push notification handler; tap navigates to compliance status screen | OE-01 | 📡 Push notification | — |
| FE-DAO-02 | `[ ]` Build compliance status screen: current status (clear/warned/suspended), active flags with details, sanction tier and countdown (for suspensions) | OE-03 | 📡 BE-AO-08 | — |
| FE-DAO-03 | `[ ]` Build flag dispute screen: view flag details, text area for dispute explanation, "Submit Dispute" CTA | OE-02 | 📡 BE-AO-07 | — |
| FE-DAO-04 | `[ ]` Build suspension state: block online toggle, show suspension reason and duration, link to compliance screen | OE-03 | 📡 BE-ADMIN-05 | Gate the entire online/offline flow |

---

## 12. Driver: EV Charging

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

# PHASE 1 — Admin Dashboard (Weeks 1–8)

---

## 13. Admin: Auth & Layout

**PRD refs:** A-01, A-02, §8.4

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AA-01 | `[ ]` Build Admin login screen: email + password; call `POST /admin/auth/login`; store Sanctum token in secure cookie or storage | A-01 | 📡 BE-AUTH-05 | Admin roles: super_admin, operations, safety_operator, support |
| FE-AA-02 | `[ ]` Build Admin dashboard shell: sidebar navigation, top bar (user info, notifications, logout), content area, responsive layout | A-02 | — | — |
| FE-AA-03 | `[ ]` Implement role-based navigation: show/hide sidebar items based on authenticated user's role/permissions (super_admin sees all, safety_operator sees SOS only, etc.) | §8.4 | 📡 BE-AUTH-08 | — |
| FE-AA-04 | `[ ]` Build Admin notification centre: bell icon with unread count, dropdown with recent notifications, "View All" link | A-02 | 📡 BE-NOTIF-04 | — |

---

## 14. Admin: City Management

**PRD refs:** A-01, A-02

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AC-01 | `[ ]` Build cities list page: table with name, status (active/inactive toggle), timezone, created date; search + filter | A-01 | 📡 BE-CITY-05 | — |
| FE-AC-02 | `[ ]` Build create/edit city form: name, boundary (map polygon or point+radius), timezone selector, currency code | A-01 | 📡 BE-CITY-03, BE-CITY-06 | Map widget for boundary definition |
| FE-AC-03 | `[ ]` Build city vehicle-class assignment UI: within city detail, checkboxes/toggles for each platform vehicle class to enable/disable | A-04 | 📡 BE-CITY-13 | — |
| FE-AC-04 | `[ ]` Build city detail page: overview stats (active drivers, rides today), sub-tabs for vehicle classes and pricing configs | A-02 | 📡 BE-CITY-05 | — |

---

## 15. Admin: Vehicle Class Management

**PRD refs:** A-03, A-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AVC-01 | `[ ]` Build vehicle classes list page: table with name, capacity, icon, active status; "Add Vehicle Class" CTA | A-03 | 📡 BE-CITY-11 | — |
| FE-AVC-02 | `[ ]` Build create/edit vehicle class form: name, display name, capacity, icon upload, description | A-03 | 📡 BE-CITY-10, BE-CITY-12 | — |
| FE-AVC-03 | `[ ]` Build vehicle class assignment to driver (within KYC review flow): dropdown to assign vehicle class when approving driver | A-05 | 📡 BE-ADMIN-09 | Integrated into driver KYC review, not standalone |

---

## 16. Admin: Pricing Configuration

**PRD refs:** A-06, A-07

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APR-01 | `[ ]` Build pricing config list page: table filtered by city + vehicle class showing base fare, per-km, per-minute, min fare, effective date, version | A-06 | 📡 BE-PRICE-04 | — |
| FE-APR-02 | `[ ]` Build create pricing config form: select city + vehicle class, enter rates, set effective_from date/time; preview fare calculation with sample distance/duration | A-06, A-07 | 📡 BE-PRICE-02 | — |
| FE-APR-03 | `[ ]` Build pricing version history: chronological list of all pricing versions for a city+class pair; highlight currently active | A-07 | 📡 BE-PRICE-04 | — |

---

## 17. Admin: Live Ride Monitoring

**PRD refs:** A-08, A-09, A-15

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ALR-01 | `[ ]` Build live ride map: full-screen map with all active rides plotted (pickup/destination markers + moving driver icons); filter by city, status | A-08 | 📡 BE-LOC-05 (WS) | ⏱ Must handle hundreds of concurrent markers efficiently |
| FE-ALR-02 | `[ ]` Build ride detail sidebar: click a ride on map or list → slide-in panel with full ride detail, state history, passenger/driver info | A-09 | 📡 BE-RIDE-11 | — |
| FE-ALR-03 | `[ ]` Build active rides table: searchable, filterable list of rides by status, city, vehicle class; real-time status updates | A-08 | 📡 BE-RIDE-12, BE-LOC-05 (WS) | Alternative to map view |
| FE-ALR-04 | `[ ]` Build ride state history timeline: vertical timeline showing each state transition with timestamp and trigger | A-09 | 📡 BE-RIDE-15 | — |
| FE-ALR-05 | `[ ]` Build KPI counters: real-time summary stats — active rides, rides today, completion rate, average wait time; update via WS | A-08 | 📡 BE-LOC-05 (WS) | — |

---

## 18. Admin: Driver Management

**PRD refs:** A-10, A-11, A-05

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ADR-01 | `[ ]` Build driver list page: table with name, phone, KYC status, compliance status, vehicle class, online status; search + filters + pagination | A-10 | 📡 BE-ADMIN-07 | — |
| FE-ADR-02 | `[ ]` Build driver detail page: tabs for Profile, Vehicle, KYC Documents, Compliance History, Ride History, Earnings | A-10 | 📡 BE-ADMIN-08 | — |
| FE-ADR-03 | `[ ]` Build KYC review interface: view uploaded documents (image viewer/PDF viewer), approve/reject each with mandatory rejection reason; assign vehicle class on approval | A-10, A-05 | 📡 BE-ADMIN-09 | 🔒 KYC document viewer must not allow download in unsecured contexts |
| FE-ADR-04 | `[ ]` Build KYC pending queue: filtered view showing only drivers with status=pending; count badge in sidebar navigation | A-10 | 📡 BE-ADMIN-07 | — |
| FE-ADR-05 | `[ ]` Build driver suspend/reactivate actions: confirmation dialog with reason field; reflect in driver status immediately | A-11 | 📡 BE-ADMIN-11, BE-ADMIN-12 | — |

---

## 19. Admin: Passenger Management

**PRD refs:** A-12

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APM-01 | `[ ]` Build passenger list page: table with name, phone, status, tier, ride count, rating; search + filters + pagination | A-12 | 📡 BE-ADMIN-13 | — |
| FE-APM-02 | `[ ]` Build passenger detail page: profile info, tier data, ride history, dispute history | A-12 | 📡 BE-ADMIN-14 | — |
| FE-APM-03 | `[ ]` Build passenger suspend/reactivate actions: confirmation dialog with reason; reflect in passenger status immediately | A-12 | 📡 BE-ADMIN-15 | — |

---

## 20. Admin: Disputes

**PRD refs:** A-13, A-14

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-ADIS-01 | `[ ]` Build dispute queue page: table with ride ID, reporter, category, status, created date; filter by status; count badge for open disputes | A-13 | 📡 BE-RATE-07 | — |
| FE-ADIS-02 | `[ ]` Build dispute detail page: trip context (map, fare, driver, passenger), dispute description, resolution form (status change + notes), linked ride detail | A-13 | 📡 BE-RATE-08 | — |
| FE-ADIS-03 | `[ ]` Build dispute resolution form: status selector (under_review/resolved/dismissed), resolution notes (required), submit; confirm dialog | A-14 | 📡 BE-RATE-09 | ⚠ OQ-08: Add refund/fare adjustment fields if in scope |

---

## 21. Admin: Manual Ride Assignment

**PRD refs:** A-15

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AMA-01 | `[ ]` Build manual assignment interface: from ride detail (when status allows), "Assign Driver" button → search online drivers by name/phone/vehicle class; show distance to pickup | A-15 | 📡 BE-MATCH-09 | — |
| FE-AMA-02 | `[ ]` Build assignment confirmation dialog: confirm driver selection, show driver details; on confirm, trigger assignment and reflect in ride state | A-15 | 📡 BE-MATCH-09, BE-MATCH-10 | — |

---

## 22. Admin: Reporting & Analytics

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

## 23. Admin: Gamification Config

**PRD refs:** A-17, A-18, A-19

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AGF-01 | `[ ]` Build tier configuration page: editable table of tiers (level, name, points threshold, benefits); save with confirmation | A-17 | 📡 BE-GAME-11 | ⚠ OQ-09: Tier naming TBD |
| FE-AGF-02 | `[ ]` Build point multiplier configuration page: editable table of conditions (EV ride, shared journey, off-peak) with multiplier values and stackability toggle | A-18 | 📡 BE-GAME-12 | — |
| FE-AGF-03 | `[ ]` Build gamification analytics page: tier distribution pie/bar chart, average carbon scores, top users list, trends over time | A-19 | 📡 BE-GAME-09, BE-GAME-10 | — |

---

## 24. Admin: Promo Management

**PRD refs:** A-20, A-21, A-22, A-23

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-APO-01 | `[ ]` Build promo list page: table with code, discount type/value, status (active/expired/paused), redemption count, created date; search + filters | A-20 | 📡 BE-PROMO-04 | — |
| FE-APO-02 | `[ ]` Build create/edit promo form: code (auto-generate option), discount type/value/cap, dates, redemption limits, eligibility rules (geo-fence map, order count range, tier, peak/off-peak) | A-20, A-21 | 📡 BE-PROMO-02, BE-PROMO-05 | Geo-fence: draw polygon or circle on map |
| FE-APO-03 | `[ ]` Build promo eligibility rule builder: visual UI for combining conditions (tier, order count, geography, time-of-day) | A-22 | 📡 BE-PROMO-02 | — |
| FE-APO-04 | `[ ]` Build promo performance dashboard: per-promo analytics — redemption count over time, total discount given, unique users, revenue impact chart | A-23 | 📡 BE-PROMO-11 | — |
| FE-APO-05 | `[ ]` Build promo activate/deactivate toggle: one-click toggle with confirmation; reflect in status immediately | A-20 | 📡 BE-PROMO-05 | — |

---

## 25. Admin: SOS Console

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

## 26. Admin: Anti-Offline Monitoring

**PRD refs:** A-28, A-29, A-30

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AAO-01 | `[ ]` Build offline-trip flags list page: table with driver, ride, sanction tier, dispute status, flag date; filter by tier/status | A-28 | 📡 BE-AO-09 | — |
| FE-AAO-02 | `[ ]` Build flag detail page: GPS trajectory map of post-cancellation movement, detection data, sanction tier rationale, driver's dispute (if any) | A-28 | 📡 BE-AO-09 | — |
| FE-AAO-03 | `[ ]` Build flag review form: "Uphold" or "Overturn" decision with mandatory notes; overturn reverses sanction; show impact preview | A-29 | 📡 BE-AO-10 | — |
| FE-AAO-04 | `[ ]` Build manual escalation action: escalate sanction tier with confirmation; show what the new tier means | A-30 | 📡 BE-AO-11 | ⚠ OQ-14: L3 authority TBD |
| FE-AAO-05 | `[ ]` Build anti-offline analytics card: total flags, upheld vs overturned rate, sanctions by tier, trend chart | A-28 | 📡 BE-AO-09 | — |

---

## 27. Admin: EV Charging Management

**PRD refs:** A-31, A-32

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-AEV-01 | `[ ]` Build EV station list page: table with name, city, total stalls, available stalls, queue length, status; search + filter by city | A-31 | 📡 BE-EV-03 | — |
| FE-AEV-02 | `[ ]` Build create/edit station form: name, city, address, total stalls, location picker on map | A-31 | 📡 BE-EV-02, BE-EV-04 | — |
| FE-AEV-03 | `[ ]` Build station utilisation dashboard: real-time stall occupancy grid, reservations queue, utilisation rate over time, busiest stations chart | A-32 | 📡 BE-EV-05 | — |

---

---

# PHASE 2 — Additional Screens (Weeks 9–12)

> Working draft — must be reviewed and re-frozen before Week 9.

---

## 28. Phase 2 — Passenger: Scheduled Rides UI

**PRD refs:** P-20, P-21, P-22

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-PSR-01 | `[ ]` Add "Schedule" toggle to booking flow: date/time picker for future pickup; validate minimum lead time; submit as scheduled ride | P-20 | 📡 BE2-SCH-03 | Integrated into existing booking flow, not separate screen |
| FE2-PSR-02 | `[ ]` Build upcoming scheduled rides list: card for each with date, time, pickup, destination, "Edit" and "Cancel" actions | P-22 | 📡 BE2-SCH-06 | Accessible from home screen |
| FE2-PSR-03 | `[ ]` Build edit scheduled ride screen: modify time, pickup, destination before dispatch window | P-22 | 📡 BE2-SCH-07 | — |
| FE2-PSR-04 | `[ ]` Build scheduled ride reminder notification handler: push notification → deep link to ride detail | P-21 | 📡 Push notification | — |
| FE2-PSR-05 | `[ ]` Build scheduled ride dispatch transition: when scheduled ride dispatches, transition to standard searching → matched flow seamlessly | P-22 | 📡 BE2-SCH-04 | — |

---

## 29. Phase 2 — Passenger: Third-Party Booking UI

**PRD refs:** P-23, P-24

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-P3P-01 | `[ ]` Add "Book for Someone Else" toggle to booking flow: show rider name + rider phone fields when active | P-23 | 📡 BE2-3P-03 | — |
| FE2-P3P-02 | `[ ]` Build third-party ride tracking: booker's view of ride progress depending on Admin config (may or may not receive PIN, may or may not see live tracking) | P-24 | 📡 BE2-3P-04 | ⚠ OQ-18: Routing depends on Admin config decision |
| FE2-P3P-03 | `[ ]` Build rider notification flow: SMS notification to rider with ride details and PIN (if configured to receive) | P-24 | 📡 BE2-3P-04 | — |

---

## 30. Phase 2 — Passenger: Lost & Found UI

**PRD refs:** P-25, P-26

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-PLF-01 | `[ ]` Build report lost item screen: select from recent completed rides, choose item category, add description, submit | P-25 | 📡 BE2-LF-03 | Accessible from ride history detail |
| FE2-PLF-02 | `[ ]` Build lost item status tracker: list of user's reports with status (reported/driver notified/confirmed/denied/resolved); tap for detail | P-26 | 📡 BE2-LF-04 | — |
| FE2-PLF-03 | `[ ]` Build lost item status update notification handler: push notification on driver response or Admin resolution | P-26 | 📡 Push notification | — |

---

## 31. Phase 2 — Driver: Scheduling & Vehicle UI

**PRD refs:** D-15, D-16

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-DSH-01 | `[ ]` Build shift schedule screen: calendar/list view of assigned shifts with date, start/end time, status | D-15 | 📡 BE2-SHIFT-03 | ⚠ OQ-20: UI scope depends on scheduling model decision |
| FE2-DSH-02 | `[ ]` Build shift reminder notification handler | D-15 | 📡 Push notification | — |
| FE2-DVH-01 | `[ ]` Build vehicle status/health screen: charging status (for EV), maintenance indicators (due/overdue), next service date | D-16 | 📡 BE2-VEH-03 | ⚠ OQ-19: EV-specific vs general TBD |
| FE2-DVH-02 | `[ ]` Build lost item driver response screen: notification of reported lost item; "I have it" / "I don't have it" response; contact instructions if confirmed | D-14 | 📡 BE2-LF-06 | — |

---

## 32. Phase 2 — Admin: Phase 2 Admin Screens

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

## 33. Open Questions Tracker

### Provider & Integration Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-01 | Which SMS provider is approved? (affects ride-start PIN delivery) | BE-AUTH-13 | `[ ]` Partially resolved: SmsGateway contract + LogSmsGateway adapter in place; real provider TBD |
| OQ-02 | Which maps/geocoding provider is approved? (determines map SDK for all apps) | FE-PB-01, FE-PB-03, FE-DR-01 | `[ ]` Unresolved |
| OQ-17 | Minimum supported iOS and Android versions? | All mobile screens | `[ ]` Unresolved |

### Business Rule Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-05 | Does Phase 1 include a cancellation fee, and under what conditions? (must show fee warning in cancel dialog) | FE-PB-12 | `[ ]` Unresolved |
| OQ-06 | How and how often are driver earnings paid out? (determines payout section in earnings screen) | FE-DE-01 | `[ ]` Unresolved |
| OQ-07 | Single Admin role or role-based tiers? (affects login flow and nav visibility) | FE-AA-01, FE-AA-03 | `[ ]` Unresolved |
| OQ-08 | Can Admin issue refunds/fare adjustments when resolving disputes? (affects dispute resolution form) | FE-ADIS-03 | `[ ]` Unresolved |
| OQ-09 | Tier naming: Option A (Bronze → Diamond) or Option B (Seed → Forest)? (affects tier badge and celebration screens) | FE-PG-02, FE-AGF-01 | `[ ]` Unresolved |
| OQ-12 | Auto-apply promos in Phase 1, or code-entry only? (determines whether available promos list is shown) | FE-PP-02 | `[ ]` Unresolved |

### Detection & Enforcement Thresholds

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-14 | False-positive rate target and L3 overturn authority? (affects Anti-Offline review flow) | FE-AAO-04 | `[ ]` Unresolved |

### EV Charging Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-16 | Fee waiver applies to driver, passenger, or both? (affects fee waiver badge display) | FE-DEV-07 | `[ ]` Unresolved |

### Phase 2 Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-18 | Who receives PIN/tracking for third-party bookings? (determines booker vs rider views) | FE2-P3P-02, FE2-P3P-03 | `[ ]` Unresolved |
| OQ-19 | Vehicle charging & maintenance: EV-specific, general, or both? (affects vehicle status screen scope) | FE2-DVH-01 | `[ ]` Unresolved |
| OQ-20 | Driver scheduling: shift scheduling or availability planning? (affects schedule screen design) | FE2-DSH-01 | `[ ]` Unresolved |
| OQ-21 | Dispute escalation tiers and ownership? (affects escalation UI) | FE2-ADIS-01 | `[ ]` Unresolved |

---

*Document generated from PRD v3.0 and Product Brief. Frontend scope only — see `E-Tigo_Backend_Task_Breakdown.md` for API server, database, and background service tasks. Each frontend task references its backend dependency with the 📡 prefix. Update statuses: `[ ]` → `[~]` (in progress) → `[x]` (done).*
