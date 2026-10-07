# E-tiGo — Passenger App Task Breakdown

**Platform:** iOS & Android (Flutter)
**Source:** PRD v3.0 (Phase 1 & Phase 2) + Product Brief
**Backend API:** Consumed from `etigo-api` — see `E-Tigo_Backend_Task_Breakdown.md` for endpoint specifications
**Auth:** Email/password → Sanctum bearer token stored in secure storage (Keychain / Keystore)
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

**Related docs:** [Driver App](Driver_App_Task_Breakdown.md) | [Web (Admin + Landing)](Web_Task_Breakdown.md) | [Backend](E-Tigo_Backend_Task_Breakdown.md)

---

## Table of Contents

### Phase 1

1. [Auth & Onboarding](#1-auth--onboarding)
2. [Ride Booking Flow](#2-ride-booking-flow)
3. [In-Ride Experience](#3-in-ride-experience)
4. [Payment & Ratings](#4-payment--ratings)
5. [Gamification & Carbon Dashboard](#5-gamification--carbon-dashboard)
6. [Promo / Discount](#6-promo--discount)
7. [SOS Emergency](#7-sos-emergency)

8. [Wallet & Payments](#8-wallet--payments)

### Phase 2

9. [Scheduled Rides](#9-phase-2--scheduled-rides)
10. [Third-Party Booking](#10-phase-2--third-party-booking)
11. [Lost & Found](#11-phase-2--lost--found)

### Tracking

12. [Open Questions](#12-open-questions)

---

## 1. Auth & Onboarding

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

## 2. Ride Booking Flow

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

## 3. In-Ride Experience

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

## 4. Payment & Ratings

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

## 5. Gamification & Carbon Dashboard

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

## 6. Promo / Discount

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

## 7. SOS Emergency

**PRD refs:** SOS-01, SOS-02, SOS-03, SOS-04

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PSOS-01 | `[ ]` Build SOS trigger confirmation: modal with "Confirm Emergency" button; explain what happens next; large tap targets, high-contrast design | SOS-01, SOS-02 | 📡 BE-SOS-03 | 🔒 Must not be triggerable by accidental single-tap |
| FE-PSOS-02 | `[ ]` Build SOS active state UI: prominent visual indicator that SOS is active; show status (triggered → check-in → acknowledged/escalated); hide normal ride UI controls | SOS-02, SOS-03 | 📡 BE-SOS-01 (WS) | — |
| FE-PSOS-03 | `[ ]` Build SOS check-in prompt: modal "Are you safe?" with "I'm Safe" (acknowledge) and "Still need help" (escalate) buttons; auto-escalate if no response in 30s | SOS-03 | 📡 BE-SOS-07, BE-SOS-06 | ⏱ Timer must be server-authoritative; client-side timer is visual only |
| FE-PSOS-04 | `[ ]` Build SOS resolution UI: notification that SOS was resolved; allow feedback; return to normal ride view | SOS-04 | 📡 BE-SOS-08 | — |
| FE-PSOS-05 | `[ ]` Build SOS cancel flow: "Cancel SOS" option with confirmation (prevent accidental cancel); send cancel to backend | SOS-04 | 📡 BE-SOS-08 | — |

---

## 8. Wallet & Payments

**PRD refs:** P-15, P-16
**Design note:** Closed-loop wallet — top-up, pay for rides, receive refunds. No withdrawal, no peer-to-peer transfers.

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE-PW-01 | `[ ]` Build wallet home screen: current balance display, "Add Money" CTA button, recent transactions list (last 5); hide/show balance toggle; pull-to-refresh | P-15 | 📡 BE-WAL-01, BE-WAL-02 | Entry point accessible from home screen balance chip and profile/settings |
| FE-PW-02 | `[ ]` Build balance chip widget: compact wallet balance display on home screen or profile; tap navigates to wallet home | P-15 | 📡 BE-WAL-01 | Shows "Set up wallet" if no wallet exists |
| FE-PW-03 | `[ ]` Build top-up amount entry screen: preset quick-pick amounts (₦1,000 / ₦2,000 / ₦5,000 / ₦10,000), custom amount input; validate against min/max/daily limits from backend config; "Continue" CTA | P-15 | 📡 BE-WAL-03 | Show remaining daily limit; disable amounts that would exceed max balance |
| FE-PW-04 | `[ ]` Build Paystack checkout integration: launch Paystack SDK or in-app WebView with authorization_url from backend; handle success, failure, and user-cancelled callbacks | P-15 | 📡 BE-WAL-03 | 🔒 Card data handled entirely by Paystack SDK — never touches app |
| FE-PW-05 | `[ ]` Build top-up pending state: loading indicator while awaiting webhook confirmation; poll verify endpoint (`GET /wallet/topup/{ref}/verify`) as fallback; auto-dismiss after timeout | P-15 | 📡 BE-WAL-05 | — |
| FE-PW-06 | `[ ]` Build top-up result screens: success (animated confirmation, new balance, "Done" CTA), failed (error message, "Try Again" CTA), abandoned (return to amount entry) | P-15 | 📡 BE-WAL-05 | — |
| FE-PW-07 | `[ ]` Build transaction history screen: paginated list with type icons (top-up ↑, ride payment ↓, refund ↑, tip ↓) and status badges (success/pending/failed); filter by credits/debits/all; date grouping | P-15 | 📡 BE-WAL-02 | — |
| FE-PW-08 | `[ ]` Build transaction detail screen: amount, type, status, reference ID, date/time, linked ride (if applicable, tap to view ride detail); receipt-style layout | P-15 | 📡 BE-WAL-02 | Refund entries clearly labelled with original ride reference |
| FE-PW-09 | `[ ]` Extend payment method selector in booking flow: add "Wallet" option alongside Cash and Card; show current wallet balance next to option; disable if insufficient balance | P-15 | 📡 BE-WAL-01 | Remember last-used payment method; default to user preference |
| FE-PW-10 | `[ ]` Build wallet hold messaging: when wallet selected, show "₦X will be reserved for this ride" on booking confirmation screen; display hold amount during active ride | P-15 | 📡 BE-WAL-09 | — |
| FE-PW-11 | `[ ]` Build insufficient balance handling: inline "Add ₦X to continue" shortcut that launches top-up flow and returns to booking on success; "Use another payment method" fallback option | P-15 | 📡 BE-WAL-01 | Show shortfall amount clearly |
| FE-PW-12 | `[ ]` Build mid-ride shortfall handling: if final fare exceeds hold (route change, waiting time), display notification explaining additional charge; show updated wallet balance | P-15 | 📡 BE-WAL-12 | — |
| FE-PW-13 | `[ ]` Extend trip completion screen: show wallet deduction line item in fare breakdown; display cancellation fee (if applicable) as wallet deduction; show refund notice with "Refunded to Wallet" label | P-16 | 📡 BE-RIDE-09 | Integrate with existing trip completion flow (FE-PIR-07) |
| FE-PW-14 | `[ ]` Build wallet push notification handlers: top-up success, top-up failed, ride wallet payment, wallet refund; tap navigates to relevant screen (wallet home or transaction detail) | P-15 | 📡 BE-WADM-27 | — |
| FE-PW-15 | `[ ]` Handle loading, empty, and error states across all wallet screens: skeleton loaders, empty state illustrations, retry buttons on network failure, graceful degradation | P-15 | — | — |

---

## 9. Phase 2 — Scheduled Rides

**PRD refs:** P-20, P-21, P-22

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-PSR-01 | `[ ]` Add "Schedule" toggle to booking flow: date/time picker for future pickup; validate minimum lead time; submit as scheduled ride | P-20 | 📡 BE2-SCH-03 | Integrated into existing booking flow, not separate screen |
| FE2-PSR-02 | `[ ]` Build upcoming scheduled rides list: card for each with date, time, pickup, destination, "Edit" and "Cancel" actions | P-22 | 📡 BE2-SCH-06 | Accessible from home screen |
| FE2-PSR-03 | `[ ]` Build edit scheduled ride screen: modify time, pickup, destination before dispatch window | P-22 | 📡 BE2-SCH-07 | — |
| FE2-PSR-04 | `[ ]` Build scheduled ride reminder notification handler: push notification → deep link to ride detail | P-21 | 📡 Push notification | — |
| FE2-PSR-05 | `[ ]` Build scheduled ride dispatch transition: when scheduled ride dispatches, transition to standard searching → matched flow seamlessly | P-22 | 📡 BE2-SCH-04 | — |

---

## 10. Phase 2 — Third-Party Booking

**PRD refs:** P-23, P-24

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-P3P-01 | `[ ]` Add "Book for Someone Else" toggle to booking flow: show rider name + rider phone fields when active | P-23 | 📡 BE2-3P-03 | — |
| FE2-P3P-02 | `[ ]` Build third-party ride tracking: booker's view of ride progress depending on Admin config (may or may not receive PIN, may or may not see live tracking) | P-24 | 📡 BE2-3P-04 | ⚠ OQ-18: Routing depends on Admin config decision |
| FE2-P3P-03 | `[ ]` Build rider notification flow: SMS notification to rider with ride details and PIN (if configured to receive) | P-24 | 📡 BE2-3P-04 | — |

---

## 11. Phase 2 — Lost & Found

**PRD refs:** P-25, P-26

| ID | Task | PRD Ref | API Dep | Notes |
|----|------|---------|---------|-------|
| FE2-PLF-01 | `[ ]` Build report lost item screen: select from recent completed rides, choose item category, add description, submit | P-25 | 📡 BE2-LF-03 | Accessible from ride history detail |
| FE2-PLF-02 | `[ ]` Build lost item status tracker: list of user's reports with status (reported/driver notified/confirmed/denied/resolved); tap for detail | P-26 | 📡 BE2-LF-04 | — |
| FE2-PLF-03 | `[ ]` Build lost item status update notification handler: push notification on driver response or Admin resolution | P-26 | 📡 Push notification | — |

---

## 12. Open Questions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-02 | Which maps/geocoding provider is approved? (determines map SDK) | FE-PB-01, FE-PB-03 | `[ ]` Unresolved |
| OQ-05 | Does Phase 1 include a cancellation fee? (must show fee warning in cancel dialog) | FE-PB-12 | `[ ]` Unresolved |
| OQ-09 | Tier naming: Option A (Bronze → Diamond) or Option B (Seed → Forest)? | FE-PG-02 | `[ ]` Unresolved |
| OQ-12 | Auto-apply promos in Phase 1, or code-entry only? | FE-PP-02 | `[ ]` Unresolved |
| OQ-16 | Fee waiver applies to driver, passenger, or both? | FE-PG-06 | `[ ]` Unresolved |
| OQ-17 | Minimum supported iOS and Android versions? | All screens | `[ ]` Unresolved |
| OQ-18 | Who receives PIN/tracking for third-party bookings? | FE2-P3P-02, FE2-P3P-03 | `[ ]` Unresolved |
| OQ-25 | Wallet limits: minimum top-up, maximum balance, and daily top-up cap values? | FE-PW-03 | `[ ]` Unresolved |
| OQ-26 | Fallback payment method when wallet balance is insufficient for final fare? | FE-PW-12 | `[ ]` Unresolved |
| OQ-27 | Refund policy: wallet credit only, or back to original payment method? | FE-PW-13 | `[ ]` Unresolved |

---

## Task Summary

| Section | Tasks |
|---------|-------|
| Phase 1 | 70 |
| Phase 2 | 11 |
| **Total** | **81** |

---

*Passenger App scope from PRD v3.0. See [Driver App](Driver_App_Task_Breakdown.md), [Web](Web_Task_Breakdown.md), and [Backend](E-Tigo_Backend_Task_Breakdown.md) for other breakdowns.*
