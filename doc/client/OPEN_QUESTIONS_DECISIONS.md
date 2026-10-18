# E-tiGo — Open Questions Decision Report

**Phase 1 & Phase 2 Backend — Decision Record for All Outstanding Questions**

**Prepared by:** Engineering Team | **Date:** 18 October 2026 | **Document version:** 5.0 | **Status:** All Phase 1 questions resolved and implemented. All Phase 2 features built and reviewed (18 October 2026). The scheduled-ride booking window and fees were decided by the client; every other Phase 2 answer is an engineering default awaiting client confirmation. City launch dates and the Lost & Found return fee still need the client.

---

<div class="summary" markdown="1">

**Executive Summary**

This report is the single record of every product decision taken for the E-tiGo backend:

- the **28 Phase 1 questions** (OQ-01 to OQ-17 and OQ-25 to OQ-35), in five categories: Provider & Integration (5), Business Rules (8), Detection & Enforcement (2), EV Charging (2) and Wallet & Ledger (11);
- the **14 Phase 2 questions**: Scheduled Rides (SR-1 to SR-7), the five PRD questions (OQ-18 to OQ-22) and Lost & Found (OQ-36, OQ-37);
- **9 decisions made while building Phase 1** that were never listed as open questions (BD-01 to BD-09, Section H);
- **7 decisions made in the Phase 2 review** of 18 October 2026, which fixed gaps where Phase 2 features meet (RV-01 to RV-07, Section I).

**Labels.** Every decision says who made it:

| Label | Meaning |
|-------|---------|
| <span class="badge client">CLIENT</span> | Decided by the client. Final unless the client changes it |
| <span class="badge owner">PRODUCT OWNER</span> | Approved by E-tiGo's product owner; not yet confirmed by the client in writing |
| <span class="badge eng">ENGINEERING</span> | An engineering default or recommendation, built to industry practice and **awaiting client confirmation**. Almost all are settings operations can change without a release |
| <span class="badge settled">SETTLED</span> | Recorded as resolved in decision report v3.0 (13 October 2026), implemented, and unchanged in substance since |

Status words in the matrix: **Implemented** (live in the code), **Built, off** (built but switched off until the client decides), **Not built** (waits for a client decision).

**Totals (58 decisions):** 24 settled Phase 1 decisions, 6 client decisions, 3 product-owner decisions and 25 engineering decisions awaiting confirmation. SR-1, SR-4, OQ-07, OQ-08 and OQ-27 also contain engineering details the client has not confirmed, and OQ-22's launch dates are still the client's to give.

**What changed in version 5.0** (tagged <span class="badge new">NEW</span> or <span class="badge updated">UPDATED</span>; earlier tags are removed): every decision now carries a label; OQ-07, OQ-08 and OQ-27 reflect the Phase 2 dispute tiers; OQ-05 and SR-4 note that a late-cancellation fee can now be disputed; OQ-18 to OQ-22 are built; OQ-36 and OQ-37 (Lost & Found) are added; Sections H and I are new.

</div>

---

## Table of Contents

1. **A. Provider & Integration Decisions** — OQ-01, OQ-02, OQ-03, OQ-04, OQ-17
2. **B. Business Rule Decisions** — OQ-05 to OQ-12
3. **C. Detection & Enforcement Thresholds** — OQ-13, OQ-14
4. **D. EV Charging Decisions** — OQ-15, OQ-16
5. **E. Wallet & Ledger Decisions** — OQ-25 to OQ-35
6. **F. Phase 2 — Scheduled Rides** — SR-1 to SR-7
7. **G. Phase 2 — Other Questions** — OQ-18 to OQ-22, OQ-36, OQ-37 <span class="badge updated">UPDATED</span>
8. **H. Decisions Made While Building Phase 1** — BD-01 to BD-09 <span class="badge new">NEW</span>
9. **I. Phase 2 Review — Decisions Made While Fixing Edge Cases** — RV-01 to RV-07 <span class="badge new">NEW</span>
10. **Decision Summary Matrix**
11. **Action Items & Next Steps**

OQ-23 and OQ-24 (brand design system, "coming soon" cities on the landing page) belong to the web team's task breakdown and are not backend questions.

---

<h2 class="section">A. Provider & Integration Decisions</h2>

### OQ-01 — SMS/OTP Gateway <span class="badge settled">SETTLED</span>

#### Question
Which SMS/OTP gateway should E-tiGo use for phone number verification?

#### Decision
> **WhatsApp Business API** is the primary OTP delivery channel.
>
> - `WhatsAppGateway` — auto-selected in `AppServiceProvider` when the `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_ACCESS_TOKEN` env vars are set
> - `LogSmsGateway` — dev/testing fallback (logs the OTP to the Laravel log file)
> - WhatsApp was chosen over traditional SMS for cost efficiency and higher delivery rates in the Nigerian market

### OQ-02 — Maps & Geocoding Provider <span class="badge settled">SETTLED</span>

#### Question
Which maps/geocoding provider should E-tiGo use for distance, directions, and place search?

#### Decision
> **Mapbox** for directions, distance, and duration calculations. Multi-provider failover for place search.
>
> - `MapboxGateway` — primary directions provider; `HaversineMapsGateway` as offline fallback
> - `FailoverPlaceSearchGateway` — configurable provider chain: Mapbox → Geoapify → LocationIQ → Photon
> - Provider order configurable via the `PLACE_SEARCH_DRIVERS` env var
> - All providers auto-selected based on available API keys in `config/services.php`

### OQ-03 — Card Payment Gateway <span class="badge settled">SETTLED</span>

#### Question
Which card payment gateway should E-tiGo use for card tokenization and wallet operations?

#### Decision
> **Paystack** — Nigeria's leading payment processor.
>
> - `PaystackPaymentGateway` — card tokenization, charge authorization, and refunds
> - `PaystackWalletGateway` — wallet top-ups and transfers
> - `FakePaymentGateway` — dev/testing fallback (activated when `PAYSTACK_FAKE=true` or keys are absent in local/staging)
> - Config: `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` in `config/services.php`

### OQ-04 — Emergency Services Dispatch <span class="badge settled">SETTLED</span>

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

<p class="impl"><strong>Implementation:</strong> <code>SosIncidentTriggered</code> event (broadcasts to <code>admin.sos</code>), <code>SendSosWhatsAppAlertJob</code>, <code>auto_dial_emergency: "112"</code> in the API response, <code>WHATSAPP_SAFETY_NUMBER</code> config. Phase 2: an SOS raised by the booker of a ride booked for someone else gives the Safety team the rider's name and number and the car's live position.</p>

### OQ-17 — Minimum App Version Enforcement <span class="badge settled">SETTLED</span>

#### Question
What are the minimum supported iOS and Android versions, and how is version enforcement handled?

#### Decision
> **Minimum version set to the launch version** (1.0.0 for both iOS and Android).
>
> - `min_app_version_ios` / `min_app_version_android` stored in AppSettings — Admin-adjustable at runtime
> - Returned via the `GET /api/v1/config` public endpoint (no auth required)
> - `force_update` flag available for critical updates that block app usage entirely

<p class="impl"><strong>Implementation:</strong> <code>ConfigController</code> — <code>GET /api/v1/config</code> returns min versions, <code>force_update</code>, <code>emergency_number</code>, support contacts and the Phase 2 rules the apps need (scheduled rides, third-party bookings, Lost & Found).</p>

---

<h2 class="section">B. Business Rule Decisions</h2>

### OQ-05 — Ride Cancellation Fee Policy <span class="badge settled">SETTLED</span> <span class="badge updated">UPDATED</span>

#### Question
Should Phase 1 include a cancellation fee, and under what conditions?

#### Decision
> **No cancellation fee on rides booked for now.** Riders may cancel freely at any point. The `ride.free_cancellation_window_seconds` config is retained for future use.

**Phase 2 recommendation (not built, client to decide):** a ₦300–₦500 flat fee after a 60-second grace window, waived automatically if the driver's GPS shows no movement toward the pickup within 2 minutes. See the [Cancellation Fee Proposal](CANCELLATION_FEE_PROPOSAL.md).

**Scheduled rides** <span class="badge client">CLIENT</span>: for a booking a driver has reserved, the client chose a late-cancellation fee (the economy base fare, within 30 minutes of the ride time) — see **SR-4**. **Update (18 October 2026):** a passenger charged that fee can now dispute it in the app — see **RV-07**.

### OQ-06 — Driver Payout Frequency <span class="badge settled">SETTLED</span>

#### Question
How often should driver earnings be paid out — on-demand only, or with an automated schedule?

#### Decision
> **Both on-demand and weekly auto-payout.**
>
> - On-demand: the driver requests a payout in the app, Admin approves, processed via the Paystack Transfer API
> - Weekly auto-payout (opt-in): every Monday at 06:00 WAT, auto-approved
> - Admin-configurable payout thresholds via `AdminWalletSettingsController`

<p class="impl"><strong>Implementation:</strong> <code>payout_preference</code> column on drivers, <code>PayoutPreference</code> enum, <code>PUT /driver/payouts/preference</code> endpoint, <code>ProcessWeeklyAutoPayoutJob</code> scheduled Monday 06:00 WAT. Payout account safeguards: see BD-06.</p>

### OQ-07 — Admin Role Structure <span class="badge settled">SETTLED</span> <span class="badge updated">UPDATED</span>

#### Question
Should E-tiGo use a single Admin role or role-based tiers?

#### Decision
> **Role-based tiers**, enforced by the `EnsureAdminRole` middleware with route-level role requirements:
>
> - `super_admin` — full system access
> - `operations` — rides, drivers, cities, pricing, scheduled rides, shifts and vehicle maintenance
> - `safety_operator` — SOS incidents, flagged trips and the Safety dispute queue
> - `support` — disputes (tier 1), passengers, Lost & Found
> - `support_lead` — **new in Phase 2**: dispute tier 2, plus everything `support` can do
> - `finance` — payouts, refunds, reconciliation, wallet settings

**Update (18 October 2026):** `support_lead` was added for the dispute tiers (OQ-21, <span class="badge eng">ENGINEERING</span>). Operations can also view passenger wallets read-only (BD-09, <span class="badge owner">PRODUCT OWNER</span>).

### OQ-08 — Admin Refund Authority <span class="badge settled">SETTLED</span> <span class="badge updated">UPDATED</span>

#### Question
Can Admin issue refunds and fare adjustments when resolving disputes?

#### Decision
> **Yes.**
>
> - Finance: `POST /admin/rides/{ride}/refund` — full or partial refund to the passenger wallet via `WalletRefundService`, linked to the dispute if one exists, with the driver's share clawed back
> - Disputes: refunds when resolving a case, up to what is still refundable on the trip

**Update (18 October 2026):** dispute refunds now follow the Phase 2 tiers (OQ-21, <span class="badge eng">ENGINEERING</span>): tier 1 up to ₦5,000, tier 2 and Safety up to ₦50,000, super admin no limit. A larger refund waits for a second admin, and no one approves their own. Finance's own refund endpoint has no tier limit, because Finance owns the money and has no dispute tier.

### OQ-09 — Gamification Tier Naming <span class="badge settled">SETTLED</span>

#### Question
Which tier naming convention: Option A (Bronze → Diamond) or Option B (Seed → Forest)?

#### Decision
> **Option A** — industry-standard naming:
>
> - Bronze (0 pts) → Silver (500) → Gold (2,000) → Platinum (5,000) → Diamond (15,000)
> - Implemented in the `TierLevel` enum and seeded via `TierConfigSeeder`

### OQ-10 — Carbon Emissions Baseline <span class="badge settled">SETTLED</span>

#### Question
What baseline and per-vehicle emissions data should the carbon scoring engine use?

#### Decision
> **EU/IPCC averages adjusted for ride-sharing occupancy**, configured in `config/gamification.php`:
>
> - Baseline: 120 g CO₂/km (average private car)
> - Economy: 65 g/km · Comfort: 80 g/km · Premium: 95 g/km · EV: 0 g/km · Default: 75 g/km

### OQ-11 — Surge Pricing Scope <span class="badge settled">SETTLED</span>

#### Question
Is surge/dynamic pricing included in Phase 1?

#### Decision
> **Yes — fully built.**
>
> - `SurgePricingService` evaluates active rules by priority: manual → time_based → demand_based
> - `SurgeRule` model with `AdminSurgeRuleController` CRUD + toggle
> - Integrated into `FareEstimationService`; multiplier range 1.00–5.00
> - Demand-based surge measures live demand against supply per city and vehicle class
> - Scheduled rides are quoted with the surge in effect at their pickup time; demand-based surge never applies to a future pickup

### OQ-12 — Promo Code Application <span class="badge settled">SETTLED</span>

#### Question
Should promos be auto-applied or code-entry only in Phase 1?

#### Decision
> **Code-entry only.**
>
> - `PromoController@validate` — validates user-entered codes against eligibility rules
> - `PromoController@available` — lists promos eligible for the authenticated user
> - Auto-apply was deferred to Phase 2 but is **not built**: the client needs to say which promos apply themselves and how they combine

---

<h2 class="section">C. Detection & Enforcement Thresholds</h2>

### OQ-13 — GPS Collocation & Jitter Tolerance <span class="badge settled">SETTLED</span>

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

**Note:** the client approved a 100 m threshold. Engineering launched at 200 m because GPS in dense parts of Lagos and Abuja drifts by up to 80 m, and Bolt and DiDi launch at 150–250 m (Industry Standards Decision Report, 11 October 2026). The plan is to tighten to 150 m and then the client's 100 m after 4–6 weeks of production data, if false positives stay below 1%. It is a setting, so no release is needed.

### OQ-14 — False-Positive Rate & L3 Overturn Authority <span class="badge settled">SETTLED</span>

#### Question
What is the false-positive rate target and who can overturn L3 permanent deactivation?

#### Decision
> **Super admin can overturn L3 deactivation**, and L3 always needs an admin's review first.
>
> - The client approved tiered false-positive targets: under 1% for automatic penalties, under 5% for the manual review queue, under 10% for warnings
> - `AdminOfflineFlagController@review` with `outcome=overturned`
> - `SanctionService@reverseSanction()` reinstates the driver and sends a notification
> - 30-day lookback window for sanction tier determination

---

<h2 class="section">D. EV Charging Decisions</h2>

### OQ-15 — EV Charging Station Data Source <span class="badge settled">SETTLED</span>

#### Question
Where does EV station data come from — a third-party API, a public dataset, or proprietary entry?

#### Decision
> **E-tiGo's own proprietary charging network only.**
>
> - Admin manages stations via CRUD endpoints (including delete)
> - No external data feeds — only E-tiGo-owned or approved stations
> - External station owners applying to join: planned for Phase 2 but **not built**; it needs the form and approval terms
>
> Station data fields: name, GPS location, stall count, connector types (CCS2/CHAdeMO/Type2/GBT/Tesla), power output (kW), pricing per kWh, operating hours, real-time availability.

<p class="impl"><strong>Implementation:</strong> migration adds <code>connector_types</code>, <code>power_output_kw</code>, <code>pricing_per_kwh</code>, <code>operating_hours</code> to <code>ev_charging_stations</code>. <code>DELETE /admin/ev-stations/{station}</code> is blocked while reservations are active.</p>

### OQ-16 — EV Fee Waiver Scope <span class="badge settled">SETTLED</span>

#### Question
Does the EV reservation fee waiver apply to drivers, passengers, or both?

#### Decision
> **Drivers only.**
>
> - `ReservationService` checks the requesting driver's tier via `TierGateService@isEvReservationFeeWaived()`
> - Drivers are the ones making EV reservations for their vehicles
> - Which vehicles can reserve, and how the fee is collected: BD-04 and BD-05

---

<h2 class="section">E. Wallet & Ledger Decisions</h2>

### OQ-25 — Wallet Limits <span class="badge settled">SETTLED</span>

#### Question
What are the minimum top-up, maximum balance, and daily top-up cap values?

#### Decision
> All amounts stored and validated in kobo. Configured in `config/wallet.php`; admin-adjustable via `AdminWalletSettingsController`:
>
> - Minimum top-up: ₦500 (50,000 kobo)
> - Maximum balance: ₦500,000 (50,000,000 kobo)
> - Daily top-up cap: ₦100,000 (10,000,000 kobo)

### OQ-26 — Fallback Payment Method <span class="badge client">CLIENT</span>

#### Question
What happens when the passenger's wallet balance is insufficient for the final fare?

#### Decision (version 3.0)
Partial settlement: capture whatever the wallet holds, record the shortfall, and mark the payment Failed for admin review.

#### Decision (16 October 2026 — client)
> **The shortfall becomes a balance due the passenger owes.**
>
> - The wallet pays what it can, as before, and the payment stays Failed until the rest is paid
> - The rest is recorded as a balance due (`wallet_shortfall`) and the passenger is notified
> - When the passenger pays it, the payment becomes Captured and the driver receives the rest of the fare, less the usual commission
> - How it is collected: see OQ-34 and OQ-35

### OQ-27 — Refund Policy <span class="badge settled">SETTLED</span> <span class="badge updated">UPDATED</span>

#### Question
Should refunds go to the passenger's wallet or back to the original payment method (card)?

#### Decision
> **Wallet credit by default.**
>
> - Refunds are instant wallet credits via `WalletRefundService`
> - A refund to the card is possible for card payments only, as an exception
> - Card refunds may expand if CBN regulations require it

**Update (18 October 2026):** in disputes, a refund now goes to the wallet unless the reviewer chooses the card, and a card refund always needs Finance to approve (OQ-21, <span class="badge eng">ENGINEERING</span>). Before this, dispute refunds went to the card. A disputed late-cancellation fee is always refunded to the wallet (RV-07).

#### Rationale
Wallet credits are instant, cheaper to process (no Paystack refund fees), and reduce chargeback exposure. Industry standard for Bolt and InDrive in Africa.

### OQ-28 — Driver Earnings Settlement Delay <span class="badge settled">SETTLED</span>

#### Question
Should driver earnings be settled instantly, after 24 hours, or weekly?

#### Decision
> **All three options supported:**
>
> - Configurable via `config('wallet.settlement_delay')` — default: instant
> - Admin-adjustable at runtime via the Wallet Settings endpoint
> - `SettleDriverEarningsJob` runs hourly to move pending → available when the delay is 24 h or weekly

### OQ-29 — Cash-Ride Commission Handling <span class="badge settled">SETTLED</span>

#### Question
For cash rides, should the platform commission be debited from the driver's earnings ledger? Should negative balances be allowed?

#### Decision
> **Debit from `DriverEarningsAvailable`** (the driver already has the cash).
>
> - Negative balance allowed up to a configurable threshold: `max_negative_balance` = −₦5,000 (−500,000 kobo)
> - Warning logged when the threshold is exceeded
> - `hasExcessiveNegativeBalance()` blocks ride acceptance and scheduled-ride reservations

### OQ-30 — Minimum Payout Amount <span class="badge settled">SETTLED</span>

#### Question
What is the minimum amount a driver can withdraw?

#### Decision
> **₦1,000 (100,000 kobo)** — configured in `config/wallet.php` as `min_payout`; admin-adjustable via the wallet settings endpoint.

### OQ-31 — Payout Schedule <span class="badge settled">SETTLED</span>

#### Question
Should driver payouts be on-demand requests or a fixed schedule?

#### Decision
> **On-demand with Admin approval:**
>
> - Driver requests a payout via `PayoutController@store`
> - Admin approves via `AdminPayoutController@approve`
> - Transfer processed via the Paystack Transfer API

**Note:** OQ-06 extends this with an optional weekly auto-payout (Monday 06:00 WAT) for drivers who opt in.

### OQ-32 — Tips in Wallet Flow <span class="badge owner">PRODUCT OWNER</span>

#### Question
Should riders be able to tip drivers by debiting their wallet balance?

#### Decision (version 3.0, 13 October 2026)
No wallet-debit tips in V1: card tips via Paystack, cash tips handed to the driver, wallet tips deferred.

#### Decision (15–16 October 2026, at the product owner's request)
> **Wallet tips are supported. Tips follow the trip's payment method.**
>
> - Wallet rides: the tip moves from the passenger's wallet balance to the driver in one ledger journal, with no commission
> - Card rides: charged via Paystack (`PaymentService@addTip()`)
> - Bank-transfer rides: can be tipped from the wallet or a card
> - Cash rides: handed directly to the driver; logged as `tip_amount` on the Payment record

#### Rationale
The accounting concern behind the original deferral is handled by posting each wallet tip as a single balanced ledger journal (passenger wallet → driver earnings). Engineering made this change at the product owner's request; **the client has not confirmed it yet**.

### OQ-33 — Regulatory Compliance for Closed-Loop Wallet <span class="badge settled">SETTLED</span>

#### Question
Does E-tiGo need a separate PSP or MMO license to hold customer funds in its closed-loop wallet?

#### Decision
> **E-tiGo operates under Paystack's CBN-licensed PSP infrastructure.**
>
> - `PaystackWalletGateway` — all customer funds held in Paystack's regulated trust accounts
> - E-tiGo functions as a sub-wallet under Paystack's license
> - Additional safeguards: ₦500,000 max balance, double-entry bookkeeping, full audit trail

**Action required:** execute a formal partnership agreement with Paystack confirming the sub-wallet arrangement before public launch.

### OQ-34 — Collecting a Balance Due <span class="badge eng">ENGINEERING</span>

#### Question
The client decided that unpaid late-cancellation fees and wallet shortfalls are recorded as a balance due (SR-5, OQ-26). How is it collected?

#### Industry Benchmark
Uber and Bolt ask the rider to pay an outstanding balance before the next trip; Bolt can also add it to the next ride's fare. The Cancellation Fee Proposal recommended blocking new rides for cash users until the fee is settled.

#### Engineering Default (awaiting confirmation)
> **The passenger cannot book a new ride until the balance is paid.**
>
> - Booking returns a clear "outstanding balance of ₦X" message; the app shows a **Pay** screen
> - The passenger pays everything outstanding at once, from the wallet or the saved card
> - Bookings made before the balance arose are not cancelled
> - Finance can turn the block off (wallet setting `block_booking_with_balance_due`) and can waive a balance with a reason
> - Not built: adding the balance to the next ride's fare (would need the driver to collect it in cash on cash rides)
> - The Lost & Found return fee, when switched on, uses the same balance due (OQ-36)

### OQ-35 — Paying the Driver an Outstanding Balance <span class="badge eng">ENGINEERING</span>

#### Question
When a passenger owes a late-cancellation fee or part of a fare, is the driver paid now (the platform carries the risk) or only once the passenger pays?

#### Industry Benchmark
Uber pays drivers the full fare and collects from the rider itself.

#### Engineering Default (awaiting confirmation)
> **The driver is paid when the passenger pays.** The late-cancellation fee goes to the reserved driver in full; the rest of an unpaid fare goes to the driver less the usual commission. Paying drivers up front would mean E-tiGo absorbs what passengers never pay; it can be added later as a setting if the client prefers the Uber model.

---

<h2 class="section">F. Phase 2 — Scheduled Rides</h2>

Scheduled rides were built on 16 October 2026 (Uber Reserve / Bolt / Lyft model): passengers book up to 3 days ahead at a price locked at booking, drivers can reserve a booking in advance, and the ride is dispatched 15 minutes before pickup. All values can be changed by operations at any time without a release (`PUT /api/v1/admin/settings/scheduled-rides`). Full reasoning and sources: [Scheduled Rides — Decisions Report](SCHEDULED_RIDES_DECISIONS.md); alternatives and trade-offs: [Phase 2 Decisions](PHASE2_DECISIONS.md).

### SR-1 — Booking Window <span class="badge client">CLIENT</span>

#### Question
How far ahead can a passenger book, and how soon before pickup?

#### Industry Benchmark
Uber Reserve: 30 minutes to 2 hours minimum, up to 30–90 days ahead depending on the market. Lyft: up to 7 days. Bolt: 3 days (London launch), 72 hours (South Africa), up to 90 days (New Zealand).

#### Decision
> **30 minutes to 3 days ahead, at most 3 upcoming bookings per passenger** — set by the client (Augustus Opara, 16 October 2026). This matches Bolt's London and South Africa launches (3 days / 72 hours) and keeps the price locked at booking close to current rates.
>
> <span class="badge eng">ENGINEERING</span> Still awaiting confirmation: one passenger's bookings must be at least 30 minutes apart (bookings for different riders never clash).

### SR-2 — Dispatch Timing <span class="badge eng">ENGINEERING</span>

#### Question
When does matching start, and how long does it keep searching?

#### Engineering Default
> **Matching starts 15 minutes before pickup** and keeps searching until **10 minutes after** the pickup time (never less than the standard 5-minute search). Passenger reminder 60 minutes before pickup. Wallet hold and ride PIN are created at dispatch, not at booking.

### SR-3 — Reservation Fee <span class="badge client">CLIENT</span>

#### Question
Should passengers pay a fee for booking ahead?

#### Industry Benchmark
Uber Reserve charges a reservation fee (for example £3–£5 in the UK). We have not seen one for Lyft or Bolt scheduled rides.

#### Decision
> **No charge for reservations** — client decision (16 October 2026). The setting stays at ₦0; operations could add one later without a release.

### SR-4 — Late-Cancellation Fee <span class="badge client">CLIENT</span> <span class="badge updated">UPDATED</span>

#### Question
Should a passenger who cancels a booking a driver has reserved, close to pickup, pay a fee — an exception to OQ-05?

#### Industry Benchmark
Uber Reserve and Bolt (UK): free cancellation until 1 hour before pickup, then a fee (Uber charges the trip's minimum fare, paid to the driver); no fee if no driver has confirmed. Lyft: free until 30 minutes before.

#### Decision
> **The economy base fare, within 30 minutes of the ride time** — client decision (16 October 2026).
>
> - Charged only for bookings a driver has reserved, when the passenger cancels within 30 minutes of the ride time
> - The amount is the current **economy base fare in the ride's city** (if the city has no economy pricing, the base fare the ride was quoted with)
> - Paid to the reserved driver **in full**; never charged for emergency, safety concern, vehicle mismatch or driver no-show, or when operations or the system cancel; shown to the passenger before they confirm
> - Wallet and card bookings are charged at once; what cannot be collected becomes a balance due (SR-5)

<span class="badge eng">ENGINEERING</span> Two points the client has not confirmed: the client's "base economy fee" was read as the economy **base fare** (not the minimum fare); and since 18 October 2026 the passenger can **dispute** the fee in the app, with an upheld dispute refunding it to the wallet or waiving it (RV-07).

### SR-5 — Late Cancellations Paid in Cash <span class="badge client">CLIENT</span>

#### Question
How should the late-cancellation fee be handled for cash and bank-transfer bookings, which cannot be charged after the fact?

#### Decision
> **Record a balance due** — client decision (16 October 2026). The same applies when a wallet or card cannot pay the fee, and to wallet passengers whose final fare exceeds their balance (OQ-26). How the balance is collected and when the driver is paid: OQ-34 and OQ-35.

### SR-6 — Drivers Who Often Release Reservations <span class="badge eng">ENGINEERING</span>

#### Question
Should drivers who regularly give up reservations lose the ability to reserve (Bolt does this)?

#### Engineering Default
> **No penalty for now.** Releases are recorded in the audit log, in line with the Cancellation Fee Proposal's "track, don't fine drivers" recommendation. The client still has to decide.

### SR-7 — Driver Reservation Limits <span class="badge eng">ENGINEERING</span>

#### Question
How many bookings can one driver reserve, and how is the reserved pickup protected?

#### Engineering Default
> **5 upcoming reservations per driver, at least 60 minutes apart.** No on-demand offers in the 60 minutes before a reserved pickup. At dispatch the ride goes straight to the reserved driver if they are online and free; otherwise it is matched normally. Operations can assign, move or remove a driver before dispatch. A driver who is suspended, or whose car is taken off the road, loses their reservations at once (RV-01, RV-02).

---

<h2 class="section">G. Phase 2 — Other Questions</h2>

These questions blocked their Phase 2 features. Engineering answered each on 16 October 2026 with an industry-standard default, and **all of them were built to those defaults by 18 October 2026**. None has been confirmed by the client. Each answer is a setting, so a different answer from the client is a configuration change rather than new work. OQ-22 is the exception that needs the client before anything happens: **no new city goes live without a launch date from the client.** Options, trade-offs and every extra choice made while building: [Phase 2 Decisions](PHASE2_DECISIONS.md).

### OQ-18 — Third-Party Bookings: PIN and Tracking <span class="badge eng">ENGINEERING</span>

#### Question
When a passenger books a ride for someone else, who receives the ride PIN and the live tracking: the booker, the rider, or both?

#### Industry Benchmark
Uber: the rider gets a text with the driver's details and a tracking link and does not need the app; the booker follows the trip in their own app. Bolt reports that 2–6% of its rides are booked for someone else.

#### Decision (built 18 October 2026)
> **Both receive the PIN and the tracking** (operations can switch either to rider-only or booker-only).
>
> - The rider gets a WhatsApp message (SMS fallback) with the booker's first name, the driver, car and plate, the PIN and a tracking link that works without the app
> - The booker sees the same PIN and live map in the app
> - If the rider's message cannot be delivered, the booker is always shown the PIN, so the ride can still start
> - The booker pays by wallet or card only (cash off by default, because the payer is not in the car); up to 2 active third-party rides per booker; 10 different rider numbers per booker per 24 hours
> - The setting is saved on each ride at booking, so a change never affects rides already booked

### OQ-19 — Vehicle Charging & Maintenance Scope <span class="badge eng">ENGINEERING</span>

#### Question
Should vehicle charging and maintenance tracking be EV-specific, general, or both?

#### Decision (built 18 October 2026)
> **Both, chosen automatically from the vehicle's fuel type.**
>
> - Every vehicle: routine service (every 5,000 km or 3 months), tyres and brakes (6 months), and the expiry of insurance, roadworthiness and vehicle licence
> - Electric and plug-in hybrid vehicles also get a yearly battery health check and charging status
> - Records belong to the vehicle, not the driver, so they follow the car if it changes hands
> - Overdue service only warns. Expired legal papers block going online after a 7-day grace period, never during a trip
> - A paper with no record yet only reminds; it never blocks

### OQ-20 — Driver Scheduling Model <span class="badge eng">ENGINEERING</span>

#### Question
Should driver scheduling be assigned shifts or availability planning?

#### Industry Benchmark
Uber and Bolt do not schedule drivers. DoorDash lets independent couriers book time slots voluntarily, and they can still work without one.

#### Decision (built 18 October 2026)
> **Availability planning for all drivers; assigned shifts only for E-tiGo fleet cars shared between drivers.**
>
> - Planning is voluntary: no penalty for skipping a plan, and drivers can always go online. Operations use plans to spot gaps
> - Shift mode is switched on per fleet car. The driver can go online 15 minutes before their shift; new offers stop 15 minutes before it ends; a shift not started within 30 minutes is marked missed and operations are alerted (no automatic fine)
> - Forcing shifts on independent drivers would make them look like employees, a legal risk
> - Operations still need to say which fleet cars are shared

### OQ-21 — Dispute Escalation Tiers and Ownership <span class="badge eng">ENGINEERING</span>

#### Question
What are the dispute escalation tiers, and who owns each?

#### Decision (built 18 October 2026)
> **Three tiers, with a fast track for safety.**
>
> - Tier 1, Support agent: all new disputes; refunds up to ₦5,000
> - Tier 2, Support lead (a new admin role): escalations, appeals, missed deadlines, refunds up to ₦50,000
> - Tier 3, Super admin: larger refunds, permanent deactivation, legal and press cases, final appeals
> - Safety concerns go straight to the Safety team with a 1-hour first-response target (Safety refund limit ₦50,000)
> - Tier 1 cases not answered in 24 hours or not resolved in 72 hours escalate automatically. No admin approves their own refund. One appeal within 7 days, to a different reviewer one tier above whoever decided
> - Refunds go to the wallet by default; a refund to the card always needs Finance

### OQ-22 — Cities and Target Dates <span class="badge eng">ENGINEERING</span> · dates: <span class="badge client">CLIENT</span>

#### Question
Which cities are being activated, and on what target date?

#### Decision (process built 18 October 2026; dates still with the client)
> **Lagos → Abuja → Port Harcourt → Ibadan, each launched when it passes a readiness checklist. The dates are the client's decision.**
>
> - Checklist: map boundary with no overlaps, prices for every class, at least 30 approved drivers, support and safety cover, state permits, charging stations when the city has plug-in drivers, and the client's launch date
> - Suggested: Abuja no earlier than 6 weeks after Lagos is stable; Port Harcourt the following quarter
> - Cities are **onboarding** (drivers recruited, passengers join a waitlist), **live** or **paused** (trips in progress finish; bookings already made go ahead by default)
> - Lagos, Abuja and Port Harcourt, which were already switched on, are live; Ibadan is in onboarding
> - All Nigerian cities share Lagos time and the naira, so no database changes are needed

### OQ-36 — Lost & Found: Return Fee <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Question
Should a passenger pay a fee when a driver brings back a lost item, and how much?

#### Industry Benchmark
Uber and Bolt charge the passenger a return fee and the driver keeps all of it; it pays for the driver's time and fuel.

#### Recommendation (built **off** until the client decides)
> **₦2,000, all of it to the driver**, charged once the item is back: from the wallet, or as a balance due when the wallet is short. One fee per trip; support can waive it. Built at ₦0 (off), because the client chose no cancellation fees in Phase 1 and a new charge should be the client's call.

### OQ-37 — Lost & Found: Sharing Phone Numbers <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Question
Do passenger and driver see each other's phone number while arranging the return of an item?

#### Decision (built **on**)
> **Yes, once the driver has the item, until it is back.** Each sees the other's first name and number; messages in the app also work. E-tiGo has no masked calling yet; once it does, the apps can use it instead. Operations can switch sharing off (`share_contact_details`).

---

<h2 class="section">H. Decisions Made While Building Phase 1</h2>

These questions came up during the build and were never on the open-questions list. Each has a label; the engineering ones are settings or configuration values, so a different answer from the client is a configuration change.

### BD-01 — Free Waiting Time <span class="badge client">CLIENT</span> <span class="badge new">NEW</span>

#### Question
How long does a driver wait at pickup before the passenger pays for waiting?

#### Decision
> **5 minutes free**, then the per-minute waiting rate — confirmed by Augustus Opara (founder) on 29 September 2026. Bolt uses 4 minutes; 5 gives passengers a little more grace while protecting drivers from long waits. Set per city and vehicle class (`free_waiting_minutes`).

### BD-02 — Upfront Fare and Long Trips <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Question
Does the passenger pay the quoted fare, or the metered trip?

#### Decision (15 October 2026)
> **The quoted fare (Uber and Bolt's upfront model).** The time charge is recalculated from the real trip time only when the trip runs **more than 25% and at least 10 minutes** over the quoted time, and the billed time is capped at **3×** the quote (capped rides are flagged for review). A quicker trip keeps the quote. Distance stays the quoted route until GPS trails are recorded. Uber and Bolt do not publish their thresholds; these are engineering's choices (`config/pricing.php`).

### BD-03 — Priority Matching for Top Tiers <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Question
How do Gold, Platinum and Diamond passengers get "priority matching"?

#### Decision (15 October 2026)
> **Distance always comes first.** A waiting priority ride holds only the one driver it would be offered next, so other passengers are not starved, and priority rides get one extra automatic retry wave.

### BD-04 — Which Vehicles Can Reserve EV Charging <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Decision (15 October 2026)
> **Plug-in vehicles only**: electric and plug-in hybrid, from the vehicle's `fuel_type`. Fuel type is now required for new vehicles, and owners of older vehicles were asked to set it.

### BD-05 — Collecting the EV Reservation Fee <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Decision (15 October 2026)
> The fee is **taken from the driver's earnings when a stall is held** (not while the driver is queued), waived for Platinum and Diamond drivers (OQ-16), refunded if the driver cancels within 2 minutes, and kept on a no-show or late cancellation. It may take the driver down to the negative-balance limit (OQ-29).

### BD-06 — Driver Payout Account Safeguards <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Decision (16 October 2026)
> - **OTP for every bank account save**, the first one included (raised by the frontend team)
> - **24-hour payout hold** when an account replaces another one; the first account is exempt
> - **Name check** against the driver's KYC-verified name in **review** mode: a mismatch is saved but paid only after Finance approves. Review rather than block, because Nigerian names vary in order and spelling between banks
>
> All three are Finance settings (`payout_account_hold_hours`, `payout_account_hold_first_account`, `payout_account_name_check`).

### BD-07 — Drivers Whose App Stops Sending a Location <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

#### Decision (16 October 2026)
> After **5 minutes** without a location the driver gets a warning push; after **30 minutes** they are set offline. A driver on a trip is never set offline. The team found setting drivers offline after 10 minutes too harsh (a driver can minimise the app by accident) and suggested 30–60 minutes; a push is the only thing that reaches an app the phone has closed. Settings `silent_driver_warning_minutes`, `silent_driver_offline_minutes`.

### BD-08 — The ₦100 Add-Card Charge Goes Into the Wallet <span class="badge owner">PRODUCT OWNER</span> <span class="badge new">NEW</span>

#### Decision (17 October 2026)
> The ₦100 charged to verify a new card is **credited to the passenger's wallet** as a top-up that also saves the card (agreed with the product owner). <span class="badge eng">ENGINEERING</span> details: it follows every top-up rule, so a frozen wallet cannot add a card; adding a card has no Flutterwave fallback; ₦100 charges taken before the change were not credited back.

### BD-09 — Operations Can View Passenger Wallets <span class="badge owner">PRODUCT OWNER</span> <span class="badge new">NEW</span>

#### Decision (17 October 2026)
> Operations can **view** passenger wallets read-only (list, details, transactions, audit trail), as the admin dashboard design shows. Freezing and unfreezing stay with Finance; the Safety team has no wallet access. Approved by the product owner; not confirmed with the client in writing.

---

<h2 class="section">I. Phase 2 Review — Decisions Made While Fixing Edge Cases</h2>

On 18 October 2026 engineering reviewed every Phase 2 task against the code. All were built. Because the features were built one after another, the review concentrated on where they meet, and found seven gaps. The fixes needed the choices below, all <span class="badge eng">ENGINEERING</span> and awaiting confirmation. Alternatives and trade-offs: [Phase 2 Decisions — Phase 2 Review](PHASE2_DECISIONS.md#phase-2-review--choices-made-while-fixing-edge-cases).

### RV-01 — A Driver Who Stops Driving Gives Up Reservations and Shifts at Once <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> When a driver is suspended (by operations or an automatic sanction) or deactivated, their reserved scheduled pickups go back to the pool straight away and the passengers are told; a shift in progress ends and upcoming shifts are cancelled, and operations get one email listing the freed slots. Before, the passenger found out at dispatch, 15 minutes before pickup, and the car's shifts stayed blocked. Reactivation restores nothing automatically.

### RV-02 — A Shared Car Taken Off the Road Releases Every Shift Driver's Pickups <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> When a shared fleet car's papers expire past the grace period, every driver whose shift on that car covered a reserved pickup loses that reservation, not only the driver holding the car. Pickups covered by a shift on another car are kept.

### RV-03 — Moving a Driver to Another City <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> Refused while the driver has upcoming fleet shifts (shared cars stay in their city), as it already was with reservations. The driver's upcoming availability plans for the old city are removed.

### RV-04 — Planned Cover Counts Only Drivers Who Can Drive There <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> The operations coverage view counts only plans of drivers who are approved and in that city now. A suspended driver's plans, and plans left behind by a driver who moved, no longer look like cover.

### RV-05 — One Car Per Driver, Both Ways <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> A hire-purchase car cannot be given to a driver who still has upcoming shifts on a shared car, as a shift could already not be given to a driver with a car of their own.

### RV-06 — A Pickup Belongs to the City It Is In <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> Estimates, bookings and changes to a scheduled booking are refused when the pickup is inside another city's boundary. The passenger is pointed to that city if it is live, or shown its "not available yet" message. Before, the app's chosen city was trusted, so a city that had not opened could be booked under a live city's name and prices. A pickup outside every city keeps the chosen city, as before.

### RV-07 — Passengers Can Dispute a Late-Cancellation Fee <span class="badge eng">ENGINEERING</span> <span class="badge new">NEW</span>

> A passenger charged the scheduled-ride late-cancellation fee (SR-4) can contest it from the cancelled booking. If support upholds it, a fee already paid is refunded to the wallet and taken back from the driver who received it when driver clawback is on (the default) and that driver can be identified; a fee still owed is waived in full, and recorded as a waiver rather than a refund. The usual refund limits and approvals apply. Before, disputes were for completed trips only, so the fee could not be contested in the app.

---

## Decision Summary Matrix

| # | Question | Decision | Decided by | Status |
|---|----------|----------|------------|--------|
| OQ-01 | SMS/OTP gateway | WhatsApp Business API | Settled | Implemented |
| OQ-02 | Maps/geocoding | Mapbox + failover place search | Settled | Implemented |
| OQ-03 | Payment gateway | Paystack (card + wallet) | Settled | Implemented |
| OQ-04 | Emergency dispatch | WhatsApp + 112 + Admin alerts | Settled | Implemented |
| OQ-05 | Cancellation fee | None on rides booked for now; scheduled-ride late fee (SR-4) | Settled; SR-4 client | Implemented |
| OQ-06 | Payout frequency | On-demand + weekly auto-payout | Settled | Implemented |
| OQ-07 | Admin roles | Six roles, `support_lead` added for disputes | Settled; `support_lead` engineering | Implemented |
| OQ-08 | Admin refunds | Wallet refund + driver clawback; tier limits in disputes | Settled; limits engineering | Implemented |
| OQ-09 | Tier naming | Bronze → Diamond | Settled | Implemented |
| OQ-10 | Emissions baseline | EU/IPCC averages | Settled | Implemented |
| OQ-11 | Surge pricing | Fully built | Settled | Implemented |
| OQ-12 | Promo application | Code-entry only | Settled | Implemented (auto-apply not built) |
| OQ-13 | GPS collocation | 200 m at launch (client approved 100 m), 60% route match | Settled | Implemented |
| OQ-14 | L3 overturn | Super admin; tiered false-positive targets (client) | Settled | Implemented |
| OQ-15 | EV station data | Proprietary network + full CRUD | Settled | Implemented (external applications not built) |
| OQ-16 | EV fee waiver | Drivers only | Settled | Implemented |
| OQ-17 | Min app versions | 1.0.0, server-side enforcement | Settled | Implemented |
| OQ-25 | Wallet limits | ₦500 min / ₦500k max / ₦100k daily | Settled | Implemented |
| OQ-26 | Fallback payment | Wallet pays what it can; the rest is a balance due | **Client** | Implemented |
| OQ-27 | Refund policy | Wallet by default; card refunds need Finance | Settled; dispute default engineering | Implemented |
| OQ-28 | Settlement delay | Instant / 24 h / weekly — configurable | Settled | Implemented |
| OQ-29 | Cash commission | Debit driver, negative to −₦5k | Settled | Implemented |
| OQ-30 | Min payout | ₦1,000 | Settled | Implemented |
| OQ-31 | Payout schedule | On-demand + admin approval | Settled | Implemented |
| OQ-32 | Tips in wallet | Tips follow the payment method; wallet tips supported | **Product owner** | Implemented |
| OQ-33 | Wallet regulatory | Paystack sub-wallet / PSP license | Settled | Agreement pending |
| OQ-34 | Collecting a balance due | No new booking until paid; pay from wallet or card | Engineering | Implemented |
| OQ-35 | Driver paid on a balance due | When the passenger pays | Engineering | Implemented |
| SR-1 | Scheduled booking window | 30 min – 3 days ahead, 3 upcoming bookings; 30 min apart | **Client** (gap: engineering) | Implemented |
| SR-2 | Scheduled dispatch timing | 15 min before; search until 10 min after | Engineering | Implemented |
| SR-3 | Reservation fee | No charge | **Client** | Implemented (₦0) |
| SR-4 | Late-cancellation fee | Economy base fare, within 30 min of the ride; can be disputed | **Client** (reading and disputes: engineering) | Implemented |
| SR-5 | Late cancel, cash bookings | Balance due (also wallet shortfalls) | **Client** | Implemented |
| SR-6 | Driver release penalties | None; tracked | Engineering — client to decide | Implemented (tracking only) |
| SR-7 | Driver reservation limits | 5 per driver, 60 min apart, 60-min protection | Engineering | Implemented |
| OQ-18 | Third-party PIN/tracking | Both rider and booker; wallet/card only | Engineering | Implemented |
| OQ-19 | Vehicle maintenance scope | General for all, EV items by fuel type | Engineering | Implemented |
| OQ-20 | Driver scheduling model | Voluntary availability; shifts for shared fleet cars | Engineering | Implemented |
| OQ-21 | Dispute escalation tiers | 3 tiers + safety fast track; ₦5k / ₦50k refund limits | Engineering | Implemented |
| OQ-22 | Cities and target date | Abuja → Port Harcourt → Ibadan, gated by checklist | Engineering; **dates: client** | Implemented; dates outstanding |
| OQ-36 | Lost & Found return fee | ₦2,000 to the driver recommended | Engineering — client to decide | Built, off |
| OQ-37 | Lost & Found phone numbers | Shared once the driver has the item | Engineering | Implemented |
| BD-01 | Free waiting time | 5 minutes | **Client** | Implemented |
| BD-02 | Upfront fare | Quote; recalculate over 25% and 10 min; 3× cap | Engineering | Implemented |
| BD-03 | Priority matching | Distance first; one held driver; extra wave | Engineering | Implemented |
| BD-04 | EV reservations | Plug-in vehicles only | Engineering | Implemented |
| BD-05 | EV reservation fee | From driver earnings when a stall is held | Engineering | Implemented |
| BD-06 | Payout account safeguards | OTP always; 24 h hold; name check in review | Engineering | Implemented |
| BD-07 | Silent drivers | Warn at 5 min, offline at 30, never on a trip | Engineering | Implemented |
| BD-08 | Add-card charge | Credited to the wallet as a top-up | **Product owner** (details: engineering) | Implemented |
| BD-09 | Wallet access | Operations can view wallets; Finance freezes | **Product owner** | Implemented |
| RV-01 | Suspended drivers | Reservations and shifts released at once | Engineering | Implemented |
| RV-02 | Blocked shared car | Every shift driver's pickups on it released | Engineering | Implemented |
| RV-03 | Driver city move | Refused with upcoming shifts; old plans removed | Engineering | Implemented |
| RV-04 | Coverage | Only approved, in-city drivers count | Engineering | Implemented |
| RV-05 | One car per driver | No hire-purchase car for a driver on shifts | Engineering | Implemented |
| RV-06 | Pickup city | Pickup inside another city refused | Engineering | Implemented |
| RV-07 | Late-cancellation fee disputes | Wallet refund or waiver when upheld | Engineering | Implemented |

---

## Action Items & Next Steps

| # | Priority | Action | Owner | Timeline |
|---|----------|--------|-------|----------|
| 1 | HIGH | Execute the formal sub-wallet partnership agreement with Paystack confirming regulatory coverage (OQ-33) | Legal / BD | Before public launch |
| 2 | HIGH | Set up `WHATSAPP_SAFETY_NUMBER` and create the `sos_emergency_alert` WhatsApp template in Meta Business Manager | Ops / Eng | Before public launch |
| 3 | HIGH | Give the launch date for each new city (OQ-22) and confirm the state permits needed in Abuja (FCT) and Rivers State. No city goes live without its date | Client / Legal | Before each city launch |
| 4 | HIGH | Confirm or change every **ENGINEERING** decision: SR-2, SR-6, SR-7 and the 30-minute gap; the base-fare reading of SR-4; OQ-34, OQ-35; OQ-18 to OQ-21; OQ-37; BD-02 to BD-07; RV-01 to RV-07. Each is a setting, so a different answer is a configuration change | Client / Product | Before Phase 2 is announced to users |
| 5 | HIGH | Decide the Lost & Found return fee (OQ-36): ₦2,000, another amount, or none | Client / Product | Before Lost & Found is announced |
| 6 | MED | Confirm the **PRODUCT OWNER** decisions: wallet tips (OQ-32), the add-card wallet credit (BD-08) and Operations' wallet view (BD-09) | Client | Next review |
| 7 | MED | Name the Support Lead(s) for dispute tier 2, and say which fleet cars are shared between drivers (shift mode) | Operations | Before Phase 2 goes live |
| 8 | MED | Review the GPS collocation threshold (OQ-13) after 4–6 weeks of production data: tighten 200 m → 150 m → 100 m if false positives stay under 1% | Engineering / Ops | 4–6 weeks after launch |
| 9 | MED | Brief the app teams on the Phase 2 changes (the `FRONTEND_UPDATE_*` documents for scheduled rides, third-party bookings, Lost & Found, disputes, multi-city, scheduling and maintenance) | Mobile / Web teams | Next sprint |
| 10 | LOW | Decide the items mentioned for Phase 2 but not built: an on-demand cancellation fee (OQ-05: ₦300–₦500 after a 60-second grace window), promo auto-apply (OQ-12), and external charging-station applications (OQ-15) | Client / Product | Next planning cycle |

---

<p class="footer">E-tiGo — Open Questions Decision Report v5.0 | Confidential | 18 October 2026<br>All Phase 1 and Phase 2 features built | 24 settled, 6 client, 3 product-owner and 25 engineering decisions (awaiting confirmation) | City launch dates and the Lost & Found return fee outstanding</p>
