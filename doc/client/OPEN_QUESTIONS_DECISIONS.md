# E-tiGo — Open Questions Decision Report

**Phase 1 & Phase 2 Backend — Decision Record for All Outstanding Questions**

**Prepared by:** Engineering Team | **Date:** 16 October 2026 | **Document version:** 4.1 | **Status:** All Phase 1 decisions resolved and implemented; Phase 2 scheduled-ride defaults and Phase 2 recommendations awaiting client confirmation

---

<div class="summary" markdown="1">

**Executive Summary**

This document records the decisions for all **26 Phase 1 open questions** identified during E-tiGo backend development, and the **Phase 2 questions** raised so far. Every Phase 1 question has been resolved and the corresponding code has been implemented and verified.

Phase 1 questions are grouped into five categories: Provider & Integration (5), Business Rules (8), Detection & Enforcement (2), EV Charging (2) and Wallet & Ledger (9). Phase 2 adds **Scheduled Rides** (7 questions, built with industry-standard defaults and awaiting client confirmation) and **5 other Phase 2 questions** (OQ-18 to OQ-22), which now have engineering recommendations awaiting client confirmation.

Items tagged <span class="badge new">NEW</span> were added in version 4.0 (16 October 2026). Items tagged <span class="badge updated">UPDATED</span> changed since version 3.0 (13 October 2026); in version 4.1 (16 October 2026) the five open Phase 2 questions OQ-18 to OQ-22 received recommendations. All other decisions are unchanged.

</div>

---

## Table of Contents

1. **Provider & Integration Decisions**
    - OQ-01 — SMS/OTP Gateway
    - OQ-02 — Maps & Geocoding Provider
    - OQ-03 — Card Payment Gateway
    - OQ-04 — Emergency Services Dispatch
    - OQ-17 — Minimum App Version Enforcement
2. **Business Rule Decisions**
    - OQ-05 — Ride Cancellation Fee <span class="badge updated">UPDATED</span>
    - OQ-06 — Driver Payout Frequency
    - OQ-07 — Admin Role Structure
    - OQ-08 — Admin Refund Authority
    - OQ-09 — Gamification Tier Naming
    - OQ-10 — Carbon Emissions Baseline
    - OQ-11 — Surge Pricing Scope
    - OQ-12 — Promo Code Application
3. **Detection & Enforcement Thresholds**
    - OQ-13 — GPS Collocation & Jitter Tolerance
    - OQ-14 — False-Positive Rate & L3 Overturn
4. **EV Charging Decisions**
    - OQ-15 — EV Station Data Source
    - OQ-16 — EV Fee Waiver Scope
5. **Wallet & Ledger Decisions**
    - OQ-25 — Wallet Limits
    - OQ-26 — Fallback Payment Method
    - OQ-27 — Refund Policy
    - OQ-28 — Driver Earnings Settlement Delay
    - OQ-29 — Cash-Ride Commission
    - OQ-30 — Minimum Payout Amount
    - OQ-31 — Payout Schedule
    - OQ-32 — Tips in Wallet Flow <span class="badge updated">UPDATED</span>
    - OQ-33 — Regulatory Compliance
6. **Phase 2 — Scheduled Rides** <span class="badge new">NEW</span>
    - SR-1 to SR-7
7. **Phase 2 — Other Questions** <span class="badge updated">UPDATED</span>
    - OQ-18 — Third-Party Bookings: PIN and Tracking
    - OQ-19 — Vehicle Charging & Maintenance Scope
    - OQ-20 — Driver Scheduling Model
    - OQ-21 — Dispute Escalation Tiers and Ownership
    - OQ-22 — Cities and Target Dates
8. **Decision Summary Matrix**
9. **Action Items & Next Steps**

---

<h2 class="section">A. Provider & Integration Decisions</h2>

### OQ-01 — SMS/OTP Gateway

#### Question
Which SMS/OTP gateway should E-tiGo use for phone number verification?

#### Decision
> **WhatsApp Business API** is the primary OTP delivery channel.
>
> - `WhatsAppGateway` — auto-selected in `AppServiceProvider` when the `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_ACCESS_TOKEN` env vars are set
> - `LogSmsGateway` — dev/testing fallback (logs the OTP to the Laravel log file)
> - WhatsApp was chosen over traditional SMS for cost efficiency and higher delivery rates in the Nigerian market

### OQ-02 — Maps & Geocoding Provider

#### Question
Which maps/geocoding provider should E-tiGo use for distance, directions, and place search?

#### Decision
> **Mapbox** for directions, distance, and duration calculations. Multi-provider failover for place search.
>
> - `MapboxGateway` — primary directions provider; `HaversineMapsGateway` as offline fallback
> - `FailoverPlaceSearchGateway` — configurable provider chain: Mapbox → Geoapify → LocationIQ → Photon
> - Provider order configurable via the `PLACE_SEARCH_DRIVERS` env var
> - All providers auto-selected based on available API keys in `config/services.php`

### OQ-03 — Card Payment Gateway

#### Question
Which card payment gateway should E-tiGo use for card tokenization and wallet operations?

#### Decision
> **Paystack** — Nigeria's leading payment processor.
>
> - `PaystackPaymentGateway` — card tokenization, charge authorization, and refunds
> - `PaystackWalletGateway` — wallet top-ups and transfers
> - `FakePaymentGateway` — dev/testing fallback (activated when `PAYSTACK_FAKE=true` or keys are absent in local/staging)
> - Config: `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` in `config/services.php`

### OQ-04 — Emergency Services Dispatch

#### Question
Which emergency-services dispatch integration should E-tiGo use for its SOS/panic button feature?

#### Industry Benchmark
In Nigeria, dedicated emergency dispatch APIs (such as RapidSOS) are unavailable or prohibitively expensive. Bolt Nigeria, Uber Nigeria, and InDrive route panic events through internal safety teams. Nigeria's 112 emergency line is the standard public safety number.

#### Decision
> **No external dispatch API.** The SOS panic button will:
>
> - Send GPS and trip details via the WhatsApp Business API to an internal E-tiGo safety number
> - Auto-dial 112 (Nigeria's emergency line) via the `auto_dial_emergency` response field
> - Notify the Admin dashboard in real time via a WebSocket broadcast on the initial trigger
> - Store each incident with encrypted telemetry for the audit trail

<p class="impl"><strong>Implementation:</strong> <code>SosIncidentTriggered</code> event (broadcasts to <code>admin.sos</code>), <code>SendSosWhatsAppAlertJob</code>, <code>auto_dial_emergency: "112"</code> in the API response, <code>WHATSAPP_SAFETY_NUMBER</code> config.</p>

### OQ-17 — Minimum App Version Enforcement

#### Question
What are the minimum supported iOS and Android versions, and how is version enforcement handled?

#### Decision
> **Minimum version set to the launch version** (1.0.0 for both iOS and Android).
>
> - `min_app_version_ios` / `min_app_version_android` stored in AppSettings — Admin-adjustable at runtime
> - Returned via the `GET /api/v1/config` public endpoint (no auth required)
> - `force_update` flag available for critical updates that block app usage entirely

<p class="impl"><strong>Implementation:</strong> <code>ConfigController</code> — <code>GET /api/v1/config</code> returns min versions, <code>force_update</code>, <code>emergency_number</code>, support contacts and (since 16 October 2026) the scheduled-ride booking rules.</p>

---

<h2 class="section">B. Business Rule Decisions</h2>

### OQ-05 — Ride Cancellation Fee Policy <span class="badge updated">UPDATED</span>

#### Question
Should Phase 1 include a cancellation fee, and under what conditions?

#### Decision
> **No cancellation fee in Phase 1.** Riders may cancel freely at any point. The `ride.free_cancellation_window_seconds` config is retained for future use.

**Phase 2 recommendation:** introduce a ₦300–₦500 flat fee after a 60-second grace window, with an automatic waiver if the driver's GPS shows no movement toward the pickup within 2 minutes. See the [Cancellation Fee Proposal](CANCELLATION_FEE_PROPOSAL.md).

**Update (16 October 2026):** scheduled rides include a *late-cancellation fee* for bookings a driver has reserved. To respect this decision it is **built but switched off (₦0)**; turning it on is question **SR-4**.

### OQ-06 — Driver Payout Frequency

#### Question
How often should driver earnings be paid out — on-demand only, or with an automated schedule?

#### Decision
> **Both on-demand and weekly auto-payout.**
>
> - On-demand: the driver requests a payout in the app, Admin approves, processed via the Paystack Transfer API
> - Weekly auto-payout (opt-in): every Monday at 06:00 WAT, auto-approved
> - Admin-configurable payout thresholds via `AdminWalletSettingsController`

<p class="impl"><strong>Implementation:</strong> <code>payout_preference</code> column on drivers, <code>PayoutPreference</code> enum, <code>PUT /driver/payouts/preference</code> endpoint, <code>ProcessWeeklyAutoPayoutJob</code> scheduled Monday 06:00 WAT.</p>

### OQ-07 — Admin Role Structure

#### Question
Should E-tiGo use a single Admin role or role-based tiers?

#### Decision
> **Role-based tiers** implemented with five distinct roles:
>
> - `super_admin` — full system access
> - `operations` — ride management, driver oversight (and scheduled-ride settings)
> - `safety_operator` — SOS incidents, flagged trips
> - `support` — disputes, passenger issues
> - `finance` — payouts, reconciliation
>
> Enforced via the `EnsureAdminRole` middleware with route-level role requirements.

### OQ-08 — Admin Refund Authority

#### Question
Can Admin issue refunds and fare adjustments when resolving disputes?

#### Decision
> **Yes.** `AdminRefundController@refundToWallet` — `POST /admin/rides/{ride}/refund`
>
> - Full or partial refund to the passenger wallet via `WalletRefundService`
> - Linked to the dispute if one exists
> - Claws back driver earnings via `WalletRefundService@clawbackDriverEarnings()`

### OQ-09 — Gamification Tier Naming

#### Question
Which tier naming convention: Option A (Bronze → Diamond) or Option B (Seed → Forest)?

#### Decision
> **Option A** — industry-standard naming:
>
> - Bronze (0 pts) → Silver (500) → Gold (2,000) → Platinum (5,000) → Diamond (15,000)
> - Implemented in the `TierLevel` enum and seeded via `TierConfigSeeder`

### OQ-10 — Carbon Emissions Baseline

#### Question
What baseline and per-vehicle emissions data should the carbon scoring engine use?

#### Decision
> **EU/IPCC averages adjusted for ride-sharing occupancy**, configured in `config/gamification.php`:
>
> - Baseline: 120 g CO₂/km (average private car)
> - Economy: 65 g/km · Comfort: 80 g/km · Premium: 95 g/km · EV: 0 g/km · Default: 75 g/km

### OQ-11 — Surge Pricing Scope

#### Question
Is surge/dynamic pricing included in Phase 1?

#### Decision
> **Yes — fully built.**
>
> - `SurgePricingService` evaluates active rules by priority: manual → time_based → demand_based
> - `SurgeRule` model with `AdminSurgeRuleController` CRUD + toggle
> - Integrated into `FareEstimationService`; multiplier range 1.00–5.00
> - Scheduled rides (Phase 2) are quoted with the surge in effect at their pickup time; demand-based surge never applies to a future pickup

### OQ-12 — Promo Code Application

#### Question
Should promos be auto-applied or code-entry only in Phase 1?

#### Decision
> **Code-entry only in Phase 1.**
>
> - `PromoController@validate` — validates user-entered codes against eligibility rules
> - `PromoController@available` — lists promos eligible for the authenticated user
> - Auto-apply deferred to Phase 2

---

<h2 class="section">C. Detection & Enforcement Thresholds</h2>

### OQ-13 — GPS Collocation & Jitter Tolerance

#### Question
What GPS collocation threshold and jitter tolerance should the anti-offline-trip detection engine use?

#### Decision
> All thresholds configurable via `config/offline_detection.php` and env vars:
>
> - Collocation threshold: 200 m
> - Route match minimum: 60%
> - Minimum GPS data points: 3
> - Pickup proximity: 300 m
> - GPS sample interval: 30 seconds
> - Monitoring window: 15 minutes

### OQ-14 — False-Positive Rate & L3 Overturn Authority

#### Question
What is the false-positive rate target and who can overturn L3 permanent deactivation?

#### Decision
> **Super admin can overturn L3 deactivation.**
>
> - `AdminOfflineFlagController@review` with `outcome=overturned`
> - `SanctionService@reverseSanction()` reinstates the driver and sends a notification
> - 30-day lookback window for sanction tier determination

---

<h2 class="section">D. EV Charging Decisions</h2>

### OQ-15 — EV Charging Station Data Source

#### Question
Where does EV station data come from — a third-party API, a public dataset, or proprietary entry?

#### Decision
> **E-tiGo's own proprietary charging network only.**
>
> - Admin manages stations via CRUD endpoints (including delete)
> - No external data feeds — only E-tiGo-owned or approved stations
> - External station owners may apply to join in Phase 2
>
> Station data fields: name, GPS location, stall count, connector types (CCS2/CHAdeMO/Type2/GBT/Tesla), power output (kW), pricing per kWh, operating hours, real-time availability.

<p class="impl"><strong>Implementation:</strong> migration adds <code>connector_types</code>, <code>power_output_kw</code>, <code>pricing_per_kwh</code>, <code>operating_hours</code> to <code>ev_charging_stations</code>. <code>DELETE /admin/ev-stations/{station}</code> is blocked while reservations are active.</p>

### OQ-16 — EV Fee Waiver Scope

#### Question
Does the EV reservation fee waiver apply to drivers, passengers, or both?

#### Decision
> **Drivers only.**
>
> - `ReservationService` checks the requesting driver's tier via `TierGateService@isEvReservationFeeWaived()`
> - Drivers are the ones making EV reservations for their vehicles

---

<h2 class="section">E. Wallet & Ledger Decisions</h2>

### OQ-25 — Wallet Limits

#### Question
What are the minimum top-up, maximum balance, and daily top-up cap values?

#### Decision
> All amounts stored and validated in kobo. Configured in `config/wallet.php`; admin-adjustable via `AdminWalletSettingsController`:
>
> - Minimum top-up: ₦500 (50,000 kobo)
> - Maximum balance: ₦500,000 (50,000,000 kobo)
> - Daily top-up cap: ₦100,000 (10,000,000 kobo)

### OQ-26 — Fallback Payment Method

#### Question
What happens when the passenger's wallet balance is insufficient for the final fare?

#### Decision
> **Partial settlement:**
>
> - Capture whatever balance is available in the wallet
> - Record the shortfall amount
> - Mark the payment status as Failed for admin review and resolution

### OQ-27 — Refund Policy

#### Question
Should refunds go to the passenger's wallet or back to the original payment method (card)?

#### Decision
> **Wallet-credit-only refunds for V1.**
>
> - All refunds issued as instant wallet credits via `WalletRefundService`
> - Card refund: admin-only escalation path — support admins resolving disputes via `AdminDisputeController::resolve()`
> - Card refund may expand in Phase 2 if required by CBN regulations

#### Rationale
Wallet credits are instant, cheaper to process (no Paystack refund fees), and reduce chargeback exposure. Industry standard for Bolt and InDrive in Africa.

### OQ-28 — Driver Earnings Settlement Delay

#### Question
Should driver earnings be settled instantly, after 24 hours, or weekly?

#### Decision
> **All three options supported:**
>
> - Configurable via `config('wallet.settlement_delay')` — default: instant
> - Admin-adjustable at runtime via the Wallet Settings endpoint
> - `SettleDriverEarningsJob` runs hourly to move pending → available when the delay is 24 h or weekly

### OQ-29 — Cash-Ride Commission Handling

#### Question
For cash rides, should the platform commission be debited from the driver's earnings ledger? Should negative balances be allowed?

#### Decision
> **Debit from `DriverEarningsAvailable`** (the driver already has the cash).
>
> - Negative balance allowed up to a configurable threshold: `max_negative_balance` = −₦5,000 (−500,000 kobo)
> - Warning logged when the threshold is exceeded
> - `hasExcessiveNegativeBalance()` blocks ride acceptance (and, since 16 October 2026, scheduled-ride reservations)

### OQ-30 — Minimum Payout Amount

#### Question
What is the minimum amount a driver can withdraw?

#### Decision
> **₦1,000 (100,000 kobo)** — configured in `config/wallet.php` as `min_payout`; admin-adjustable via the wallet settings endpoint.

### OQ-31 — Payout Schedule

#### Question
Should driver payouts be on-demand requests or a fixed schedule?

#### Decision
> **On-demand with Admin approval:**
>
> - Driver requests a payout via `PayoutController@store`
> - Admin approves via `AdminPayoutController@approve`
> - Transfer processed via the Paystack Transfer API

**Note:** OQ-06 extends this with an optional weekly auto-payout (Monday 06:00 WAT) for drivers who opt in.

### OQ-32 — Tips in Wallet Flow <span class="badge updated">UPDATED</span>

#### Question
Should riders be able to tip drivers by debiting their wallet balance?

#### Decision (version 3.0, 13 October 2026)
No wallet-debit tips in V1: card tips via Paystack, cash tips handed to the driver, wallet tips deferred.

#### Decision (updated 15–16 October 2026)
> **Wallet tips are supported. Tips follow the trip's payment method.**
>
> - Wallet rides: the tip moves from the passenger's wallet balance to the driver in one ledger journal, with no commission
> - Card rides: charged via Paystack (`PaymentService@addTip()`)
> - Bank-transfer rides: can be tipped from the wallet or a card
> - Cash rides: handed directly to the driver; logged as `tip_amount` on the Payment record

#### Rationale
The accounting concern behind the original deferral is handled by posting each wallet tip as a single balanced ledger journal (passenger wallet → driver earnings). This change was made by engineering at the product owner's request and **has not yet been confirmed by the client**.

### OQ-33 — Regulatory Compliance for Closed-Loop Wallet

#### Question
Does E-tiGo need a separate PSP or MMO license to hold customer funds in its closed-loop wallet?

#### Decision
> **E-tiGo operates under Paystack's CBN-licensed PSP infrastructure.**
>
> - `PaystackWalletGateway` — all customer funds held in Paystack's regulated trust accounts
> - E-tiGo functions as a sub-wallet under Paystack's license
> - Additional safeguards: ₦500,000 max balance, double-entry bookkeeping, full audit trail

**Action required:** execute a formal partnership agreement with Paystack confirming the sub-wallet arrangement before public launch.

---

<h2 class="section">F. Phase 2 — Scheduled Rides <span class="badge new">NEW</span></h2>

Scheduled rides were built on 16 October 2026 (Uber Reserve / Bolt / Lyft model): passengers book up to 7 days ahead at a price locked at booking, drivers can reserve a booking in advance, and the ride is dispatched 15 minutes before pickup. Every value below is an engineering default chosen to match industry practice; **all are awaiting client confirmation** and all can be changed by operations at any time without a release (`PUT /api/v1/admin/settings/scheduled-rides`). Full reasoning and sources: [Scheduled Rides — Decisions Report](SCHEDULED_RIDES_DECISIONS.md).

### SR-1 — Booking Window <span class="badge new">NEW</span>

#### Question
How far ahead can a passenger book, and how soon before pickup?

#### Industry Benchmark
Uber Reserve: 30 minutes to 2 hours minimum, up to 30–90 days ahead depending on the market. Lyft: up to 7 days. Bolt: 3 days (London launch), 72 hours (South Africa), up to 90 days (New Zealand).

#### Engineering Default
> **30 minutes to 7 days ahead.** At most 5 upcoming bookings per passenger, at least 30 minutes apart. The price is locked at booking, so a short horizon keeps it close to current rates.

### SR-2 — Dispatch Timing <span class="badge new">NEW</span>

#### Question
When does matching start, and how long does it keep searching?

#### Engineering Default
> **Matching starts 15 minutes before pickup** and keeps searching until **10 minutes after** the pickup time (never less than the standard 5-minute search). Passenger reminder 60 minutes before pickup. Wallet hold and ride PIN are created at dispatch, not at booking.

### SR-3 — Reservation Fee <span class="badge new">NEW</span>

#### Question
Should passengers pay a fee for booking ahead?

#### Industry Benchmark
Uber Reserve charges a reservation fee (for example £3–£5 in the UK). We have not seen one for Lyft or Bolt scheduled rides.

#### Engineering Default
> **Built, set to ₦0 (off).** If switched on, a flat amount is added to every scheduled ride's quote and paid with the fare. Suggested value if wanted: ₦200–₦300.

### SR-4 — Late-Cancellation Fee <span class="badge new">NEW</span>

#### Question
Should a passenger who cancels a booking a driver has reserved, close to pickup, pay a fee — an exception to OQ-05?

#### Industry Benchmark
Uber Reserve and Bolt (UK): free cancellation until 1 hour before pickup, then a fee (Uber charges the trip's minimum fare, paid to the driver); no fee if no driver has confirmed. Lyft: free until 30 minutes before.

#### Engineering Default
> **Built, set to ₦0 (off)** because of OQ-05. When switched on: charged only for bookings a driver has reserved, within **60 minutes** of pickup; paid to that driver **in full**; never charged for emergency, safety concern, vehicle mismatch or driver no-show, or when operations or the system cancel; shown to the passenger before they confirm. Suggested value if wanted: **₦1,500** (about the economy minimum fare).

### SR-5 — Late Cancellations Paid in Cash <span class="badge new">NEW</span>

#### Question
How should the late-cancellation fee be handled for cash and bank-transfer bookings, which cannot be charged after the fact?

#### Engineering Default
> **Waived.** The fee is collected from the wallet or saved card only. The Cancellation Fee Proposal's alternative — a balance due added to the passenger's next ride — would be a separate build.

### SR-6 — Drivers Who Often Release Reservations <span class="badge new">NEW</span>

#### Question
Should drivers who regularly give up reservations lose the ability to reserve (Bolt does this)?

#### Engineering Default
> **No penalty for now.** Releases are recorded in the audit log, in line with the Cancellation Fee Proposal's "track, don't fine drivers" recommendation.

### SR-7 — Driver Reservation Limits <span class="badge new">NEW</span>

#### Question
How many bookings can one driver reserve, and how is the reserved pickup protected?

#### Engineering Default
> **5 upcoming reservations per driver, at least 60 minutes apart.** No on-demand offers in the 60 minutes before a reserved pickup. At dispatch the ride goes straight to the reserved driver if they are online and free; otherwise it is matched normally. Operations can assign, move or remove a driver before dispatch.

---

<h2 class="section">G. Phase 2 — Other Questions <span class="badge updated">UPDATED</span></h2>

These five questions were listed in the PRD for Phase 2 and blocked their features. Engineering has now answered each one with an industry-standard default that operations can change later as a setting. **All five are recommendations awaiting client confirmation.** Please confirm or change them before the Phase 2 build starts (Week 9); if there is no answer by then, engineering builds to these defaults, which remain changeable settings. OQ-22 also needs the client's target dates: no new city goes live without one. Options, intervals and the full list of edge cases: [Phase 2 Decisions — Quick Reference](PHASE2_DECISIONS.md).

### OQ-18 — Third-Party Bookings: PIN and Tracking <span class="badge updated">UPDATED</span>

#### Question
When a passenger books a ride for someone else, who receives the ride PIN and the live tracking: the booker, the rider, or both?

#### Industry Benchmark
Uber: the rider gets a text with the driver's details and a tracking link and does not need the app; the booker follows the trip in their own app. Bolt reports that 2–6% of its rides are booked for someone else.

#### Engineering Recommendation
> **Both receive the PIN and the tracking** (operations can switch either to rider-only or booker-only).
>
> - The rider gets a WhatsApp message (SMS fallback) with the booker's first name, the driver, car and plate, the PIN and a tracking link that works without the app
> - The booker sees the same PIN and live map in the app
> - If the rider's message cannot be delivered, the booker is always shown the PIN, so the ride can still start
> - The booker pays by wallet or card only (cash off by default, because the payer is not in the car); up to 2 active third-party rides per booker; 10 different rider numbers per booker per day
> - The setting is saved on each ride at booking, so a change never affects rides already booked

### OQ-19 — Vehicle Charging & Maintenance Scope <span class="badge updated">UPDATED</span>

#### Question
Should vehicle charging and maintenance tracking be EV-specific, general, or both?

#### Engineering Recommendation
> **Both, chosen automatically from the vehicle's fuel type.**
>
> - Every vehicle: routine service (every 5,000 km or 3 months), tyres and brakes (6 months), and the expiry of insurance, roadworthiness and vehicle licence
> - Electric and plug-in hybrid vehicles also get a yearly battery health check and charging status
> - Records belong to the vehicle, not the driver, so they follow the car if it changes hands
> - Overdue service only warns. Expired legal papers block going online after a 7-day grace period, never during a trip

### OQ-20 — Driver Scheduling Model <span class="badge updated">UPDATED</span>

#### Question
Should driver scheduling be assigned shifts or availability planning?

#### Industry Benchmark
Uber and Bolt do not schedule drivers. DoorDash lets independent couriers book time slots voluntarily, and they can still work without one.

#### Engineering Recommendation
> **Availability planning for all drivers; assigned shifts only for E-tiGo fleet cars shared between drivers.**
>
> - Planning is voluntary: no penalty for skipping a plan, and drivers can always go online. Operations use plans to spot gaps
> - Shift mode is switched on per fleet car. The driver can go online 15 minutes before their shift; new offers stop 15 minutes before it ends; a shift not started within 30 minutes is marked missed and operations are alerted (no automatic fine)
> - Forcing shifts on independent drivers would make them look like employees, a legal risk

### OQ-21 — Dispute Escalation Tiers and Ownership <span class="badge updated">UPDATED</span>

#### Question
What are the dispute escalation tiers, and who owns each?

#### Engineering Recommendation
> **Three tiers, with a fast track for safety.**
>
> - Tier 1, Support agent: all new disputes; refunds up to ₦5,000
> - Tier 2, Support lead (a new admin role): escalations, appeals, missed deadlines, refunds up to ₦50,000
> - Tier 3, Super admin: larger refunds, permanent deactivation, legal and press cases, final appeals
> - Safety concerns go straight to the Safety team with a 1-hour first-response target
> - Tier 1 cases not resolved in 72 hours escalate automatically. No admin approves their own refund. One appeal within 7 days, to a different reviewer one tier up

### OQ-22 — Cities and Target Dates <span class="badge updated">UPDATED</span>

#### Question
Which cities are being activated, and on what target date?

#### Engineering Recommendation
> **Lagos → Abuja → Port Harcourt → Ibadan, each launched when it passes a readiness checklist. The dates are the client's decision.**
>
> - Checklist: map boundary with no overlaps, prices for every class, at least 30 approved drivers, support and safety cover, state permits, charging stations for EV classes
> - Suggested: Abuja no earlier than 6 weeks after Lagos is stable; Port Harcourt the following quarter
> - Replace the city on/off switch with three states (onboarding, live, paused) so drivers can be recruited before passengers can book
> - Pausing a city lets trips in progress finish and, by default, still serves scheduled bookings
> - No database changes are needed: all Nigerian cities share Lagos time and the naira

---

## Decision Summary Matrix

| # | Question | Decision | Status |
|---|----------|----------|--------|
| OQ-01 | SMS/OTP gateway | WhatsApp Business API | Implemented |
| OQ-02 | Maps/geocoding | Mapbox + failover place search | Implemented |
| OQ-03 | Payment gateway | Paystack (card + wallet) | Implemented |
| OQ-04 | Emergency dispatch | WhatsApp + 112 + Admin alerts | Implemented |
| OQ-05 | Cancellation fee | No fee in Phase 1 (scheduled-ride late fee built, off) | Aligned |
| OQ-06 | Payout frequency | On-demand + weekly auto-payout | Implemented |
| OQ-07 | Admin roles | Role-based tiers (5 roles) | Implemented |
| OQ-08 | Admin refunds | Wallet refund + driver clawback | Implemented |
| OQ-09 | Tier naming | Bronze → Diamond | Implemented |
| OQ-10 | Emissions baseline | EU/IPCC averages | Implemented |
| OQ-11 | Surge pricing | Fully built in Phase 1 | Implemented |
| OQ-12 | Promo application | Code-entry only | Implemented |
| OQ-13 | GPS collocation | 200 m threshold, 60% route match | Implemented |
| OQ-14 | L3 overturn | Super admin can overturn | Implemented |
| OQ-15 | EV station data | Proprietary network + full CRUD | Implemented |
| OQ-16 | EV fee waiver | Drivers only | Implemented |
| OQ-17 | Min app versions | 1.0.0, server-side enforcement | Implemented |
| OQ-25 | Wallet limits | ₦500 min / ₦500k max / ₦100k daily | Implemented |
| OQ-26 | Fallback payment | Partial settlement + shortfall | Implemented |
| OQ-27 | Refund policy | Wallet credit default, card admin-only | Aligned |
| OQ-28 | Settlement delay | Instant / 24 h / weekly — configurable | Implemented |
| OQ-29 | Cash commission | Debit driver, negative to −₦5k | Implemented |
| OQ-30 | Min payout | ₦1,000 | Implemented |
| OQ-31 | Payout schedule | On-demand + admin approval | Implemented |
| OQ-32 | Tips in wallet | Tips follow the payment method; wallet tips supported | Implemented — awaiting client confirmation |
| OQ-33 | Wallet regulatory | Paystack sub-wallet / PSP license | Aligned |
| SR-1 | Scheduled booking window | 30 min – 7 days; 5 bookings, 30 min apart | Built — awaiting client confirmation |
| SR-2 | Scheduled dispatch timing | 15 min before; search until 10 min after | Built — awaiting client confirmation |
| SR-3 | Reservation fee | Built, ₦0 (off) | Client decision needed |
| SR-4 | Late-cancellation fee | Built, ₦0 (off); 60-min window | Client decision needed |
| SR-5 | Late cancel, cash bookings | Waived | Client decision needed |
| SR-6 | Driver release penalties | None; tracked | Client decision needed |
| SR-7 | Driver reservation limits | 5 per driver, 60 min apart, 60-min protection | Built — awaiting client confirmation |
| OQ-18 | Third-party PIN/tracking | Both rider and booker; wallet/card only | Recommended — awaiting client confirmation |
| OQ-19 | Vehicle maintenance scope | Both: general for all, EV items by fuel type | Recommended — awaiting client confirmation |
| OQ-20 | Driver scheduling model | Voluntary availability; shifts for shared fleet cars | Recommended — awaiting client confirmation |
| OQ-21 | Dispute escalation tiers | 3 tiers + safety fast track; ₦5k / ₦50k refund limits | Recommended — awaiting client confirmation |
| OQ-22 | Cities and target date | Abuja → Port Harcourt → Ibadan, gated by checklist | Client to set dates |

---

## Action Items & Next Steps

| # | Priority | Action | Owner | Timeline |
|---|----------|--------|-------|----------|
| 1 | HIGH | Execute the formal sub-wallet partnership agreement with Paystack confirming regulatory coverage | Legal / BD | Before public launch |
| 2 | HIGH | Set up `WHATSAPP_SAFETY_NUMBER` and create the `sos_emergency_alert` WhatsApp template in Meta Business Manager | Ops / Eng | Before public launch |
| 3 | HIGH | Confirm or change the scheduled-ride defaults SR-1, SR-2 and SR-7, and decide on the fees SR-3 to SR-6 | Client / Product | Before scheduled rides are announced |
| 4 | HIGH | Confirm the OQ-18 to OQ-21 recommendations (or pick another option in the Phase 2 Decisions report) and give the target date for each new city (OQ-22) | Client / Product | Before Phase 2 build (Week 9); defaults are built if no answer |
| 5 | MED | Coordinate with the mobile teams: (a) 112 auto-dial via `auto_dial_emergency`, (b) force-update gate via `/api/v1/config`, (c) the new `scheduled` ride status and scheduled-ride screens (see FRONTEND_UPDATE_SCHEDULED_RIDES.md) | Mobile Team | Next sprint |
| 6 | MED | Confirm the wallet-tips change (OQ-32) | Client / Product | Next review |
| 7 | MED | Name the Support Lead(s) for dispute tier 2 and say which fleet cars are shared between drivers | Operations | Before Phase 2 build |
| 8 | MED | Confirm the state permits needed in Abuja (FCT) and Rivers State | Legal | Before each city launch |
| 9 | LOW | Design the Phase 2 "apply to charge" onboarding form for external EV station partners | Product | Phase 2 planning |
| 10 | LOW | Decide the on-demand cancellation fee for Phase 2 (OQ-05 recommendation: ₦300–₦500 flat after a 60-second grace window) | Product | Phase 2 planning |

---

<p class="footer">E-tiGo — Open Questions Decision Report v4.1 | Confidential | 16 October 2026<br>26 Phase 1 questions resolved | 7 scheduled-ride questions awaiting confirmation | 5 Phase 2 recommendations awaiting confirmation</p>
