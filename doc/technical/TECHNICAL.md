# E-tiGo Backend — Technical Documentation

How the E-tiGo API is built: architecture, business rules, invariants and the reasoning behind them. Read it before changing a module.

| Looking for | Go to |
|-------------|-------|
| Endpoints, request/response shapes, error messages | [API_REFERENCE.md](API_REFERENCE.md) |
| Trying the API | [postman_collection.json](postman_collection.json) + [postman_environments/](postman_environments/) (`php artisan postman:sync` publishes them to the team's Postman workspace) |
| Servers, background processes, scheduled jobs | [DEPLOYMENT.md](DEPLOYMENT.md) |
| Task status per feature | [task-breakdowns/E-Tigo_Backend_Task_Breakdown.md](task-breakdowns/E-Tigo_Backend_Task_Breakdown.md) |
| Mobile KYC integration | [MOBILE_KYC_GUIDE.md](MOBILE_KYC_GUIDE.md) |

---

## 1. Architecture

The API serves three clients: the **Passenger app** and **Driver app** (mobile) and the **Admin dashboard** (web).

| Component | Technology |
|-----------|------------|
| Framework | Laravel 13, API-only, routes under `/api/v1` |
| PHP | Servers run 8.4 (8.4.26). `composer.json` pins `config.platform.php` to 8.4.26 so dependencies always resolve for the servers; CI runs the suite on 8.4 and 8.5 (local development) |
| Database | PostgreSQL 16 — UUID primary keys everywhere, JSONB for snapshots and payloads |
| Cache / sessions | Redis (`CACHE_STORE=redis`, tagged cache) |
| Driver locations | Redis connection `geolocation` (DB `REDIS_GEO_DB`, default 3) — GEO index + per-driver keys |
| Queue | `database` driver, 2 Supervisor workers in production |
| WebSockets | Laravel Reverb (ride updates, driver offers, SOS console); Workerman telemetry gateway for high-frequency driver GPS (`php artisan telemetry:serve`) |
| Auth | Laravel Sanctum personal access tokens; Socialite for Google/Apple/Facebook |
| Tests | Pest 5 against PostgreSQL (`etigo_api_test`) |

### Code layout

```
app/
├── Contracts/            # Interfaces for external providers (maps, payments, wallet, KYC, SMS, push, place search)
├── Gateways/, Services/  # Provider implementations and all business logic (services hold the rules; controllers stay thin)
├── Jobs/                 # Queued and scheduled work (matching timeouts, settlement, payouts, webhooks, SOS, EV, sanctions)
├── Events/               # Broadcast events (Reverb)
├── Notifications/        # Laravel notifications; Channels/AppNotificationChannel delivers them in-app + push
├── Enums/                # Backed enums for every status/type column
├── Exceptions/           # InvalidStateTransitionException (409)
├── Http/Controllers/Api/V1/{Admin,Auth,Driver,Passenger,Webhook}/
├── Http/Requests/        # Form requests (validation + authorization)
├── Http/Resources/       # API resources
└── Models/
routes/api.php            # All routes; routes/channels.php (broadcast auth); routes/console.php (schedule)
```

### Conventions that apply everywhere

- **Money.** Ride fares, payments and pricing are NGN decimals with 2 places. The wallet ledger, holds, payouts and wallet settings use **integer kobo**. Convert at the boundary with `(int) round($naira * 100)`.
- **Concurrency.** Anything that changes money or a status locks the row it is changing (`lockForUpdate()`) inside a transaction and re-checks its preconditions after taking the lock. Two requests can never both pass a check that only one of them should pass (double accept, double refund, double payout).
- **Side effects after commit.** Broadcasts, notifications and queued jobs triggered inside a transaction are deferred with `DB::afterCommit()` / `->afterCommit()`, so a rolled-back change never announces itself.
- **Idempotency.** Ledger journals carry idempotency keys (see §10), and webhook events are stored and deduplicated, so retries never apply money twice.
- **Reference numbers.** Human-readable references come from Postgres sequences: trips `TRP-NG-000123`, passengers `P-000123`, drivers `D-000123`, disputes `DPT-000123`, repairs `REP-1041` (`HasReferenceNumber` trait). Sequences are concurrency-safe and continue after the highest number already issued.
- **Time zones.** The app runs in UTC. Business rules that depend on local time use `Africa/Lagos`: the fleet business day, promo peak windows, gamification off-peak, weekly payouts.

### Error format

Every API error is JSON (`bootstrap/app.php`):

```json
{ "message": "Human-readable description.", "error_code": "MACHINE_READABLE_CODE" }
```

| Cause | Status | `error_code` | Notes |
|-------|--------|--------------|-------|
| Missing/invalid/expired token | 401 | `UNAUTHENTICATED` | |
| Validation | 422 | `VALIDATION_ERROR` | `errors` per field |
| Unknown model or route | 404 | `NOT_FOUND` | "Resource not found." vs "The requested endpoint does not exist." |
| Malformed UUID/date reaching PostgreSQL (SQLSTATE 22P02/22007/22008) | 422 | `INVALID_PARAMETER` | Reported, never a 500 |
| State changed under the request (ride or SOS transition no longer allowed) | 409 | `INVALID_STATE_TRANSITION` | Includes `current_status` |
| `abort()` / other HTTP exceptions | as thrown | `HTTP_ERROR` | |
| Anything else | 500 | `SERVER_ERROR` | `details` only when `APP_DEBUG=true` or on staging |

Controllers also return explicit 409s for business conflicts (an active ride already exists, the driver is busy) and 422s with `errors` for rule violations.

---

## 2. External providers

Every provider sits behind a contract in `app/Contracts`. `AppServiceProvider` picks the implementation from configuration, so local development and tests run without credentials.

| Contract | Implementation chosen | Used for |
|----------|----------------------|----------|
| `SmsGateway` | `TermiiGateway` when `TERMII_API_KEY` is set — sends over **WhatsApp first and falls back to DND SMS**; otherwise `LogSmsGateway` | OTP codes, bank-account change codes, SOS alerts to `ETIGO_SAFETY_NUMBER` |
| `MapsGateway` | Google (`GOOGLE_MAPS_API_KEY` / `GOOGLE_PLACES_API_KEY`) → Mapbox (`MAPBOX_ACCESS_TOKEN`) → `HaversineMapsGateway` (straight-line estimate) | Distance/duration, geocoding |
| `PlaceSearchGateway` | Failover chain from `PLACE_SEARCH_DRIVERS` (default `google,mapbox,geoapify,locationiq,photon`; providers without credentials are skipped) | Address autocomplete |
| `PaymentGateway` | Paystack primary with Flutterwave failover when both keys are set (`FallbackPaymentGateway`); a single provider otherwise; `FakePaymentGateway` when `PAYSTACK_FAKE=true` | Ride card/bank-transfer payments, saved cards, tips, refunds |
| `WalletGateway` | `PaystackWalletGateway` (requires `PAYSTACK_SECRET_KEY`) | Wallet top-ups, bank list/resolution, payout transfers |
| `KycGateway` | QoreID (`QOREID_CLIENT_ID`/`QOREID_SECRET_KEY`); `FakeKycGateway` when `KYC_SKIP_VERIFICATION=true` (or `QOREID_SKIP_VERIFICATION`) or in local/testing without credentials; **throws** elsewhere without credentials | NIN, driver's licence, plate, liveness |
| `PushNotificationGateway` | Firebase when credentials resolve (`FIREBASE_CREDENTIALS_PATH`: path or raw JSON); otherwise `LogPushGateway` | Push notifications (invalid device tokens are deactivated automatically) |

Payment providers require a customer email, but passengers may sign up with only a phone number. `User::paymentEmail()` supplies a stable `{user_id}@rider.etigo.ng` address in that case, and saved cards remember the email they were created with.

---

## 3. Authentication & access control

### Phone OTP (passengers and drivers)

```
POST /auth/otp/send     { phone }              → 6-digit code via WhatsApp (SMS fallback)
POST /auth/otp/verify   { phone, code, type }  → existing account: token
                                                 new account: is_new_user = true
POST /auth/register/complete { phone, type, first_name, last_name, ... } → account + token
```

- Codes are stored as SHA-256 hashes, compared with `hash_equals()`, expire after **5 minutes**, allow **5 attempts** (atomic counter) and have a **60-second** resend cooldown.
- Outside production the code is always `123456` and nothing is sent (`OtpService::STATIC_OTP`, gated by `app()->isProduction()`).
- **Registration binding:** a verification is bound to the account type it was verified for (`otp_codes.verified_for_type`) and can complete **one** registration (`consumed_at`) within **10 minutes**. A code verified for a passenger sign-in cannot open a driver account, and the same verification cannot be replayed.
- Phone uniqueness is per account type: one person can hold a passenger and a driver account on the same number.

### Social login

The mobile app completes the provider's OAuth flow natively and posts the provider token to `POST /auth/social`. The backend verifies it with Socialite `stateless()->userFromToken()`, then logs in the linked account, links a matching email+type account, or creates one. Google and Facebook use Socialite's built-in drivers; Apple uses `socialiteproviders/apple`.

### Admins

Admins are seeded or invited by a super admin (`/admin/admins/invite` → emailed link → `/admin/auth/invite/accept`); there is no self-registration. They sign in with email and password and can reset it by email.

### Sessions

| Audience | Lifetime | Setting |
|----------|----------|---------|
| Admin dashboard | 480 min (8 h) | `SANCTUM_TOKEN_EXPIRATION` |
| Passenger and driver apps | 43,200 min (30 days) | `SANCTUM_APP_TOKEN_EXPIRATION` |

The rule lives in `PersonalAccessToken::hasOutlivedSession()` and is applied by the Sanctum guard (`Sanctum::authenticateAccessTokensUsing`) and by the telemetry gateway, so an expired token is refused on every channel. Deactivating an admin or passenger account deletes its tokens.

### Roles and gates

| Middleware | Purpose |
|------------|---------|
| `user.type:passenger\|driver\|admin` | Route group per user type |
| `admin.role:<roles>` | Admin role check; `super_admin` always passes |
| `driver.approved` | Blocks ride endpoints for drivers who are not approved |

Admin roles: `super_admin`, `operations`, `safety_operator`, `support`, `finance` (the per-endpoint matrix is in the API reference). Every request is logged by `LogRequests` (method, path, status, duration, user, IP).

---

## 4. Drivers

### Onboarding

1. Register via OTP (`type=driver`) → `drivers` row in `onboarding`.
2. Choose vehicle ownership (own vehicle or E-tiGo fleet), upload documents, register the vehicle (own-vehicle drivers).
3. KYC through QoreID: NIN, driver's licence, vehicle plate (own vehicles only — the plate must match the registered vehicle), plus a liveness session in the app.
4. `POST /driver/onboarding/submit` checks completeness → `pending_review`.
5. Operations approves (requires `kyc_status = verified`) or rejects with a reason.

`kyc_status` is rolled up from the latest verification of each required type: all verified → `verified`; any failed → `failed`; otherwise `in_progress`. QoreID results for liveness arrive by webhook (`/webhooks/qoreid`).

The manual bypass `POST /admin/drivers/{driver}/complete-onboarding` marks everything verified for testing. It is **disabled in production** (403).

### Status transitions

| Action | From | To |
|--------|------|----|
| Submit | `onboarding`, `rejected` | `pending_review` |
| Approve / reject | `pending_review` | `approved` / `rejected` |
| Suspend | `approved` | `suspended` (forced offline) |
| Reactivate | `suspended` | `approved` |
| Compliance L3 confirmed | any | `deactivated` |

### Vehicle and payout details

- A driver may change their own vehicle class only before submitting (`onboarding`) or after a rejection. From submission on, only an admin with driver-management access (operations or safety operator) can change it (`PUT /admin/drivers/{driver}/vehicle-class`, audited), so a driver cannot upgrade themselves into a higher-fare class.
- Fleet drivers cannot edit the E-tiGo vehicle assigned to them.
- Adding the first payout bank account needs no extra step. Replacing it requires a one-time code sent to the driver's phone (`POST /driver/bank-account/otp`, purpose-bound), so a stolen session cannot redirect payouts. Account names are resolved with Paystack before saving.

---

## 5. Cities, vehicle classes, pricing and surge

- **Cities** have a GeoJSON boundary, radius, timezone and currency. **Vehicle classes** are platform-wide and enabled per city through `city_vehicle_classes` (`is_active` on both the pivot and the class). The public city/class lists are cached (§16) and invalidated on every admin change.
- **Pricing configs** are append-only and versioned per city + class. The effective one has the latest `effective_from` not in the future. Fields: `base_fare`, `per_km_rate`, `per_minute_rate`, `minimum_fare`, `waiting_time_rate`, `free_waiting_minutes` (default 5).
- **Surge rules** (`manual`, `time_based`, `demand_based`) apply per city, optionally per class. The highest-priority matching rule wins (ties go to the higher multiplier). Multipliers are 1.00–5.00. Time windows support overnight spans. Demand-based rules compare live demand with supply (`SurgePricingService::demandSnapshot`, cached 60 s per city and class): distinct passengers who booked in the last `SURGE_DEMAND_WINDOW_MINUTES` (10) against approved, online, idle drivers in the city with a fresh location (demo drivers excluded). A rule fires when the ratio reaches `min_demand_supply_ratio` and at least `min_requests` passengers are asking (default `SURGE_DEMAND_MIN_REQUESTS`, 5). The exact ratio is compared (the admin view shows it rounded). If driver locations cannot be read, supply is unknown and demand rules do not fire (the failure is not cached). The admin current-multiplier endpoint shows the figures. Supply is counted by the driver's registered city, not their live position.

### Fare formula

```
metered        = max(minimum_fare, base_fare + km × per_km_rate + min × per_minute_rate + waiting_charge)
waiting_charge = max(0, waiting_minutes − free_waiting_minutes) × waiting_time_rate     (final fare only)
surged         = metered × surge_multiplier
tier_discount  = min(base_fare × booking_fee_discount_pct / 100, surged)                (loyalty tier benefit)
fare           = surged − tier_discount
final charged  = fare − promo_discount                                                  (at completion)
```

`FareEstimationService::applyAdjustments()` is used for both the estimate and the final fare, so they cannot drift apart.

### What is locked in at booking

The ride's `pricing_snapshot` (JSONB) freezes the pricing config, the **surge multiplier** and the passenger's **tier discount** at booking time. The final fare uses the actual route (maps distance/duration) with those locked values. A surge that ends mid-trip doesn't change the agreed multiplier, and a pricing change doesn't affect rides already booked. Completion adds `waiting_charge`, `tier_discount`, `promo` and (for fleet drivers) `fleet_remittance` to the snapshot.

### Estimate edge cases

Cross-city trips use pickup-city pricing and return a warning. Destinations outside every service area still get a quote, with a warning. Routes over 100 km get a warning. Inactive cities and identical pickup/destination are rejected (422). Short trips are floored at the minimum fare.

---

## 6. Rides

### State machine (`RideStateMachine`)

```
requested → searching → matched → driver_en_route → driver_arrived → in_progress → completed
    │           │          │             │                 │
    └───────────┴──────────┴─────────────┴─────────────────┴──→ cancelled
                │
                └──→ no_driver_found ──→ searching (retry / admin assignment)
                                     └─→ cancelled (passenger dismisses)
```

Each transition locks the ride, validates the edge, writes an append-only `ride_state_transitions` row and sets the timestamps (`matched_at`, `started_at`, `completed_at`). Push notifications and the `ride.status.updated` broadcast are published **after commit**. An illegal transition — usually a race, such as a passenger cancelling while the driver accepts — throws `InvalidStateTransitionException` and the client receives 409.

### Lifecycle rules

| Step | Rules |
|------|-------|
| Book (`POST /rides`) | One active ride per passenger; the passenger row is locked, and searches older than the matching timeout are expired first. The pricing snapshot is captured, the promo validated (§12), and for wallet rides a hold for the estimate placed (§10). A share token and the ride PIN are generated. |
| Ride PIN | 4 digits, hashed, valid **6 hours**, **3 attempts**. Only the passenger sees it. Once a driver is assigned and until the trip starts, the passenger can regenerate it (`/pin/regenerate`), e.g. after the driver exhausts the attempts. |
| Accept | The driver must hold the offer, be approved, online and free; the ride and the driver are locked, so a driver can never accept two rides. |
| Arrive | `driver_en_route` → `driver_arrived` only when the driver's last location is within **150 m** of the pickup and less than **2 minutes** old. |
| Start | `driver_arrived` → `in_progress` only with the correct PIN (`hash_equals`). |
| Complete | `FinalFareCalculationJob` computes the final fare (§5), redeems the promo, processes payment (§9) and settles the driver (§10). |
| Cancel | Allowed until the trip starts (and from `no_driver_found`). The wallet hold is released, the other party is notified with who cancelled (passenger, driver or E-tiGo support) and the reason, and a driver cancellation near the pickup starts off-app trip monitoring (§14). |
| Admin assignment | `requested`, `searching` or `no_driver_found`; for `no_driver_found` the passenger must not have booked again, and a wallet hold is re-placed. |

The driver's live location is shared with the passenger only while the ride is active (`GET /rides/{ride}/location`); afterwards only admins see it. Share links (`/rides/{ride}/share/{token}`) expire one hour after the trip ends.

---

## 7. Matching engine

`DispatchRideRequestJob` offers the ride to **one driver at a time**, nearest first:

1. **Search** the Redis GEO index (`GEOSEARCH` on `driver_locations`) within the current radius tier: **3 → 7 → 15 km** (`MATCHING_RADIUS_TIER_*_KM`), reading up to `MATCHING_MAX_CANDIDATES` (20).
2. **Filter** to eligible drivers: online and approved; location reported within **120 s** (`DriverLocationService::STALE_LOCATION_SECONDS`; stale drivers are pruned from the index); vehicle of the requested class; no active ride; no unanswered offer for another ride; not already rejected or ignored for this ride.
3. **Offer**: `ride.request.dispatched` on `driver.{userId}` plus a push. A failed broadcast does not stop the push or the timeout.
4. **Timeout**: `DriverResponseTimeoutJob` after `MATCHING_DRIVER_RESPONSE_TIMEOUT` (20 s) treats silence as a rejection and moves on, even if the queue runs late.
5. **Expand** to the next tier when the current one is exhausted. After the last tier, wait `MATCHING_AUTO_RETRY_DELAY` (10 s) and start a new wave, up to `MATCHING_MAX_AUTO_RETRIES` (1) times.
6. **Give up**: `MatchingTimeoutJob` moves the ride to `no_driver_found` after `MATCHING_TIMEOUT` (300 s) from the start of the **current** search. Timers from an earlier search are ignored, so a retry gets a full window.

Per-ride matching state lives in the cache with the matching timeout as TTL: `ride:{id}:rejected_drivers`, `ride:{id}:dispatched_to`, `ride:{id}:radius_step`, `ride:{id}:auto_retry_count`, plus `driver:{userId}:offered_ride` (the double-offer guard).

**Priority matching** (Gold, Platinum, Diamond passengers; locked in at booking as `pricing_snapshot.priority_matching`) never trades distance — industry practice for loyalty priority is to speed matching up, not to send a farther car:
- Every ride, priority or not, is offered its **nearest** eligible driver first.
- **Precedence under scarcity:** a priority ride waiting for its next search (between radius tiers or retry waves, with no offer outstanding) keeps the driver it would be offered next, its nearest free driver within its current radius, instead of that driver being offered to a standard ride. Only that one driver is held per waiting priority ride (earliest booking first); every other driver stays available to everyone.
- **Persistence:** priority searches get `MATCHING_PRIORITY_EXTRA_RETRIES` (1) extra automatic retry waves before `no_driver_found`.

---

## 8. Driver location & real-time

### Location pipeline

- Drivers report positions through `POST /driver/location` (one per second) or the **telemetry gateway** (`php artisan telemetry:serve`, Workerman WebSocket on port 8081, Sanctum-authenticated, one update per `TELEMETRY_RATE_LIMIT_MS` = 500 ms; frame protocol in the API reference under *Driver Location Stream*). Only online, approved drivers may stream.
- `DriverLocationService` writes the GEO index `driver_locations` and a per-driver key `driver:{id}:location` (TTL 300 s) on the `geolocation` Redis connection. Positions are rebroadcast to the ride channel and to admins.
- ETAs (`EtaService`) are throttled to one maps call per ride every **30 s**.

### Broadcast channels (`routes/channels.php`)

| Channel | Who may subscribe | Events |
|---------|-------------------|--------|
| `ride.{rideId}` | The ride's passenger and driver | `ride.status.updated`, `driver.location.updated`, `payment.updated`, `ride.no-driver-found` |
| `driver.{userId}` | That driver | `ride.request.dispatched` |
| `user.{userId}` | That user | `sos.check_in.requested`, `tier.upgraded` |
| `admin.rides` | Admins | `ride.status.updated`, `driver.location.updated` |
| `admin.sos` | Safety operators, super admins | `sos.incident.triggered`, `sos.incident.escalated` |
| `passengers.nearby` / `drivers.nearby` | Passengers / drivers | `nearby.map.changed` |

Broadcast auth runs through Sanctum (`/broadcasting/auth` with the bearer token). Every critical flow has a non-WebSocket path as well — push notifications, polling endpoints, the WhatsApp SOS alert — so a Reverb outage degrades the experience without breaking rides or safety.

---

## 9. Ride payments

| Method | At booking | At completion | Captured when |
|--------|------------|---------------|---------------|
| `cash` | — | Payment `pending_collection` | Driver confirms collection (`/confirm-cash`). Change above the fare (max ₦2,000) goes to the passenger's wallet and is debited from the driver's earnings. |
| `card` | — | Saved default card charged (`chargeWithToken`) | Immediately, or by `charge.success` webhook (verified by reference, amount and currency checked) |
| `wallet` | Hold for the estimate | Hold captured for the final fare | Immediately. A balance shortfall is charged as far as possible and flagged (`failed` with `shortfall_kobo`) |
| `bank_transfer` | — | Paystack payment link | `charge.success` webhook |

After a payment counts as received (cash at trip end; others once captured), `RideSettlementService::applyPayment()` credits the driver and allocates fleet remittance. The driver's ledger shows the full fare as a credit and the platform commission as a separate debit (net: fare − commission), so drivers see both lines. It runs again on webhook capture and is idempotent per ride.

**Commission** (`CommissionService::getRate`): 0% for fleet drivers with an active agreement (they remit instead, §11), then a per-driver override, then the global rate, then `WALLET_DEFAULT_COMMISSION_RATE` (20%). On cash rides the driver already holds the money, so the commission is **debited** from their earnings balance. That balance may go negative down to `max_negative_balance` (−₦5,000), with a weekly report of drivers in debt.

**Tips** are paid by a `source` independent of the ride (card, wallet or cash), defaulting to the trip's payment method, once per ride; bank-transfer rides must name `wallet` or `card`, and cash tips are only recorded on cash rides. `payments.tip_method` records how each tip was paid. Wallet tips move from the passenger's available balance to the driver in one journal and are refused if the wallet cannot cover them. Card tips are charged to the saved card **before** being credited: the tip slot is claimed atomically with the attempt's reference (`payments.tip_reference`), released on a definite decline, and kept on a timeout or provider error until the provider confirms the reference, so a retry never charges twice. Credited to the driver in full. Cash tips are recorded only.

**Webhooks** (`/webhooks/paystack`, `/webhooks/flutterwave`) are signature-verified (HMAC-SHA512 / `verif-hash`) and stored in `webhook_events`, keyed by provider + event ID + event type. A redelivery of a processed event returns "Already processed". An event whose processing failed part-way is processed again on retry, which is safe because every handler is idempotent.

---

## 10. Wallet & double-entry ledger

All wallet and earnings money moves through `LedgerService::postJournal()` as balanced journals.

### Accounts (`AccountType`)

| Type | Owner | Meaning |
|------|-------|---------|
| `passenger_wallet` | User | Passenger's spendable balance |
| `driver_earnings_pending` | Driver | Earnings waiting for the settlement delay |
| `driver_earnings_available` | Driver | Withdrawable earnings (may be negative from cash commission) |
| `platform_commission` | System | E-tiGo revenue from ride commission |
| `platform_fees` | System | E-tiGo revenue from fees (EV stall reservations) |
| `psp_clearing` | System | Money in transit with Paystack/Flutterwave |
| `refunds` | System | Source of refunds and goodwill credits |

System accounts are created by `ReferenceDataSeeder`, which runs on every deploy.

### Posting rules

- Debits must equal credits. Every entry amount is a positive integer of kobo. A **credit increases** and a **debit decreases** an account's balance, for every account type.
- Accounts are locked **in ID order** (no deadlocks), must be `active` (a frozen account rejects postings), and are updated with an optimistic `balance_version` check. Each entry stores the running balance, and `metadata.category` (`ride_fare`, `commission`, `tip`, …) labels the journal for the driver's transaction history.
- An **idempotency key** makes a posting happen at most once:

| Key | Posting |
|-----|---------|
| `ride-settlement-{ride}` | Wallet ride: passenger → driver (full fare collected) |
| `wallet-commission-{ride}` | Wallet ride: driver → commission |
| `card-settlement-{ride}` | Card/bank-transfer ride: PSP clearing → driver (full fare) |
| `card-commission-{ride}` | Card/bank-transfer ride: driver → commission |
| `cash-commission-{ride}` | Cash ride: driver → commission |
| `card-tip-{ride}` | Card tip: PSP clearing → driver |
| `wallet-tip-{ride}` | Wallet tip: passenger → driver |
| `ev-fee-{reservation}` / `REV-…` | EV reservation fee: driver → platform fees, and its refund |
| `topup-{reference}` | Wallet top-up: PSP clearing → passenger |
| `refund-{ride}-{random}` / `clawback-refund-…` | Refund to wallet / driver share recovered (§10.4) |
| `adjustment-{id}` | Approved manual adjustment |
| payout debit / `REV-{journal}` | Payout debit and its reversal |

`php artisan ledger:check` (daily 02:30) verifies that balances match their entries.

### 10.1 Holds

A wallet ride places a **hold** for the estimate at booking. `availableBalance = balance − active holds`. At completion the hold is captured for the final fare; cancellation releases it. `ExpireStaleHoldsJob` releases holds older than `hold_expiry_hours` (default 4) **only if their ride is no longer active**, so a long trip keeps its hold.

### 10.2 Top-ups

`POST /passenger/wallet/topup` creates a pending `wallet_transactions` row and a Paystack checkout, after checking the frozen state, the max balance, the daily cap and the attempt limits (5/hour, 10/day). The wallet is credited by the `charge.success` webhook, the passenger's verify call (`/topup/{reference}/verify`, own references only) or the stuck-transaction sweeper — whichever comes first. All three credit **the amount Paystack collected**, at most once (`topup-{reference}`). Pending top-ups are marked abandoned after 30 minutes.

### 10.3 Driver earnings and payouts

- Earnings are credited to `pending` or directly to `available` depending on `settlement_delay` (`instant`, `24h`, `weekly`). `SettleDriverEarningsJob` moves them hourly once the delay has passed.
- **Payout lifecycle:** `requested` → `approved` (finance) → `processing` → `paid`, or `rejected` / `failed` / `reversed`. One open payout per driver. `PayoutLedgerService` debits the driver when the transfer is sent (re-checking the balance under lock) and reverses that debit if the transfer fails or is reversed. If Paystack later reports a written-off transfer as paid, the debit is re-applied. Approve, reject and retry all lock the payout.
- **Transfer references.** The first attempt uses `PAYOUT-{id}`. Paystack treats a reused reference as the same transfer, so before any further attempt the job looks up the previous reference (`WalletGateway::verifyTransfer`). If it succeeded, the payout is marked paid and nothing is sent. If it is still in flight, the job waits for the webhook. If it failed or was reversed, the money goes out under a new reference (`PAYOUT-{id}-2`, `-3`, …). If Paystack doesn't know it, the same reference is reused. A payout is never paid twice, and a retry after a real failure does go out.
- **Unknown outcomes.** A timeout, a Paystack 5xx or a 429 on a transfer is not a refusal: the transfer may exist, so the job keeps the money reserved and retries, verifying the reference first. Only a definite refusal (another 4xx, or a failed transfer status) fails the payout and returns the money. The gateway raises `PaymentProviderException` with the HTTP status so callers can tell them apart. If every attempt to reach Paystack fails, the money stays reserved and the payout stays `approved`; a missing webhook leaves it `processing`. `PayoutReconciliationService` settles such payouts against Paystack's record of the reference (success → paid; failed/reversed → money returned; in flight → wait; never created → sent again under the same reference). `ReconcilePayoutsJob` runs it every 15 minutes for payouts approved more than 10 minutes ago or processing for more than 30 (a payout that hits an unexpected error is reported and skipped; the rest are still settled); finance can run it on demand (`POST /admin/payouts/{payout}/verify`), and admin payout responses carry `needs_attention` and `attention_reason`. Payouts record Paystack's last status (`provider_status`); a transfer Paystack holds for an OTP (transfer OTP switched on in the dashboard) is flagged `awaiting_otp` at once and logged as critical. Finance can retry `failed` and `reversed` payouts.
- `ProcessWeeklyAutoPayoutJob` (Monday 06:00 Lagos) creates already-approved payouts for drivers who chose `weekly_auto` and have no open payout, and sends them straight away.

### 10.4 Refunds and adjustments

- **Refund to wallet** (finance): partial refunds up to what the passenger actually paid (tips excluded). The remaining amount is re-checked under a ride lock, so concurrent refunds cannot over-refund. By default the driver's share is clawed back in the proportion the ride was actually settled (net of commission and fleet remittance). The passenger is notified after commit.
- **Adjustments** use maker–checker: the creator cannot approve their own adjustment, and nothing is posted until approval.

### 10.5 Settings and reconciliation

- Wallet limits live in `config/wallet.php` and can be overridden at runtime by finance (`/admin/settings/wallet`, stored in `app_settings`, read via `AppSetting::wallet()`, audited). The minimum top-up can never exceed the maximum balance or the daily cap.
- `DailyReconciliationJob` (02:00) compares successful top-up charges and payout transfers reported by the gateways (Paystack and Flutterwave payloads; Flutterwave amounts are in naira) with the ledger, and stores a `clean` / `mismatched` report.

---

## 11. Fleet

E-tiGo-owned vehicles are assigned to drivers under a **fleet agreement**: a daily remittance target and a total amount. Ownership transfers once the total is reached.

- From each completed fare, the remittance share is `min(fare, today's remaining target, remaining total)`; the rest is the driver's. The share is moved out of the driver's earnings in the ledger and recorded on the ride (`pricing_snapshot.fleet_remittance`), once per ride.
- Fleet drivers with an active agreement pay **0% commission**.
- The business day runs from 04:00 Africa/Lagos (`FLEET_DAILY_RESET_TIME`). At that time `DailyRemittanceSettlementJob` settles the previous day, and **then** (chained) `RemittanceShortfallAlertJob` flags agreements with consecutive shortfall days: warning at 3, review at 7, escalation at 14 (`FLEET_SHORTFALL_*_DAYS`).

---

## 12. Promotions

- **At booking** a promo code is validated against the ride's context: active window, city, vehicle class, minimum fare, geo-fence, peak/off-peak windows (Africa/Lagos, `PROMO_PEAK_*`), minimum loyalty tier, first-ride only, global and per-user limits. An invalid code rejects the booking with 422.
- **At completion** the discount is calculated on the final fare and the redemption is recorded atomically (`validateAndLock`). A promo valid at booking is honoured even if it expires during the trip.
- Admins can cancel a promo (`cancelled_at`); it then stops validating for new bookings.

---

## 13. Loyalty (gamification)

- Each completed trip gets a **carbon score** (`CalculateCarbonScoreJob`): CO₂ saved against a 120 g/km petrol baseline (`CARBON_BASELINE_EMISSION`). Points are 10 per kg saved, minimum 5 per trip.
- **Multipliers** (`point_multiplier_configs`: EV ride, off-peak 22:00–06:00 Lagos, shared journey, …) apply to points. The highest non-stackable multiplier applies, and each stackable one adds its bonus on top (1.5× non-stackable + 1.2× stackable = 1.7×).
- **Tiers** are evaluated after each trip; an upgrade broadcasts `tier.upgraded` and notifies the user.

| Tier | Points | Booking-fee discount | EV reservation fee waived | Priority matching flag |
|------|--------|----------------------|---------------------------|------------------------|
| 1 Bronze | 0 | 0% | No | No |
| 2 Silver | 500 | 2% | No | No |
| 3 Gold | 2,000 | 5% | No | Yes |
| 4 Platinum | 5,000 | 10% | Yes | Yes |
| 5 Diamond | 15,000 | 15% | Yes | Yes |

Tier configs and multipliers are reference data (`ReferenceDataSeeder` creates missing rows and never overwrites admin edits). The booking-fee discount is applied to fares (§5), the EV fee waiver to reservations (§15) and priority matching to dispatch (§7). A trip counts as an EV, zero-emission ride when the driver's vehicle `fuel_type` is `electric` (or the class has zero emissions).

---

## 14. Safety

### SOS (`SosService`)

```
triggered ─→ check_in_sent ─→ escalated ─→ operator_assigned ─→ dispatched ─→ resolved
    └─→ acknowledged ←─┘
operator_assigned / resolved: from triggered, check_in_sent, acknowledged or escalated
cancelled: by the user or an admin, until an operator takes over
```

1. A ride participant triggers SOS on an active ride. Triggers on the same ride are serialised (row lock), so a double tap opens one incident.
2. A **WhatsApp alert** (Termii, SMS fallback) with GPS and trip details is queued to `ETIGO_SAFETY_NUMBER` **before** the console broadcast. A WebSocket outage cannot suppress it.
3. After 5 s (`SOS_CHECK_IN_DELAY`) the user gets an "Are you safe?" check-in. No answer within 30 s (`SOS_ESCALATION_TIMEOUT`) escalates the incident to the console.
4. Safety operators can take over or resolve the incident **at any open stage**. They never wait for the escalation timer.

Every step is locked and logged in the append-only `sos_event_log`. Incident telemetry and vehicle details are encrypted at rest (`encrypted:array` casts).

### Off-app trip detection and sanctions

Detects drivers who cancel a ride and then complete it off the app.

1. When a ride with an assigned driver is cancelled (by either party) while the driver is within **300 m** of the pickup, `MonitorCancellationJob` samples the driver's position every **30 s** for **15 min**.
2. **20 min** later `AnalyzeOfflineTripJob` compares the trajectory with the passenger's intended route. At least **3** samples within **200 m** of the route, making up at least **60%** of all samples, flags the ride (`OFFLINE_*` settings).
3. `SanctionService` escalates based on the driver's flags in the last **30 days**:

| Tier | Sanction | Lifted |
|------|----------|--------|
| L1 | Warning (push) | — |
| L2 | Forced offline + suspended | Automatically after 48 h (`LiftExpiredComplianceSuspensionsJob`, hourly). Suspensions set by admins for other reasons are never lifted automatically. |
| L3 | Forced offline + suspended pending review | Operations confirms permanent deactivation — not while the driver's dispute is pending or after it was overturned |

Drivers can dispute a flag; operations upholds or overturns it (overturning reverses the sanction).

---

## 15. EV charging

- **Stations** (E-tiGo's own network) have **stalls**. `StallAvailabilityService` tracks each stall's state and estimated departure.
- **Reserving** locks the station and the driver, then picks one of three outcomes: an available stall → `reserved`; all occupied but one leaving within 15 min → `queued` (max 10 per station); otherwise → `retry` after 15 min. One open reservation per driver.
- A `reserved` stall is held for **30 minutes** (`EV_RESERVATION_EXPIRY`). `ExpireEvReservationsJob` (every 5 min) expires no-shows. Whenever a stall frees up (expiry, completion, cancellation), `TransferReservationJob` gives it to the first queued driver who can pay the fee and notifies them (`ev_reservation_ready`); queued drivers skipped because the fee could not be taken are cancelled and notified (`ev_reservation_cancelled`).
- **EV only.** Vehicles record a `fuel_type` (`petrol`, `diesel`, `cng`, `hybrid`, `plug_in_hybrid`, `electric`); only plug-in vehicles (`electric`, `plug_in_hybrid`) may reserve a stall or submit a charge request. It is required when a vehicle is registered (driver or fleet) and checked when an application is submitted. Drivers of older vehicles with none can declare it once; later changes go through operations (`PUT /admin/drivers/{driver}/vehicle-class`, audited). `php artisan vehicles:request-fuel-types` asks those drivers to set it (`vehicle_fuel_type_required`) and lists fleet vehicles without one; the admin driver and fleet lists filter on `fuel_type=none`.
- **Reservation fee** (`EV_RESERVATION_FEE`, ₦500), collected by `EvReservationFeeService`:
  - debited from the driver's **available earnings** into the `platform_fees` account when a stall is actually held (immediately, or when a queued driver is promoted) — never while queued;
  - may take the balance down to `max_negative_balance` like cash commission; beyond that, or when the earnings account is frozen, the reservation is refused, and a queued driver who can no longer pay is skipped and notified;
  - waived for Platinum and Diamond drivers;
  - refunded for a cancellation within `EV_RESERVATION_FREE_CANCEL_MINUTES` (2) of the stall being held; kept on a later cancellation, a no-show or a completed session — the fee pays for holding the stall. Reservations expose the deadline as `free_cancellation_until`, so apps never hard-code the window.

---

## 16. Notifications, caching and audit

### Notifications

- `NotificationService` writes in-app notifications (`notifications` table) and sends push via the push gateway. It respects per-category preferences (`notification_preferences`); safety and other critical categories bypass them.
- Laravel notification classes (wallet, payouts, refunds, tier upgrades) deliver through `AppNotificationChannel`, which stores them in-app and pushes them, using the same `NotificationType` values.
- In-app notifications older than **90 days** are pruned daily (`NOTIFICATION_RETENTION_DAYS`).
- **Email** (Resend) covers admin invitations, password resets, welcome and ride receipts. Templates live in `resources/views/vendor/mail/`; `php artisan mail:test` sends samples.

### Caching

- `AppCacheService` caches active cities, city vehicle classes, vehicle classes (30 min) and current pricing (5 min) under cache tags. Admin changes invalidate the matching tags immediately, so a deactivated class disappears from the public list at once.
- `app_settings` (wallet and tip settings) are read through `AppSetting` with config-file defaults.

### Audit log

`AuditLog::record($model, 'event', $actor, $old, $new)` writes an immutable, polymorphic row with IP and user agent. Every admin mutation (drivers, cities, pricing, surge, wallets, payouts, refunds, adjustments, settings, sanctions), sign-ins and the key ride and payment events are recorded. `ride_state_transitions` and `sos_event_log` are additional append-only trails.

---

## 17. Testing

```bash
php artisan test --compact                     # full suite
php artisan test --compact --filter="refund"   # by name
vendor/bin/pint --dirty --format agent         # format changed files
```

- Tests use PostgreSQL (`etigo_api_test`, configured in `phpunit.xml`) with `RefreshDatabase`. Do not run `php artisan migrate` by hand against the default connection to prepare tests: without a `.env.testing` it migrates the development database.
- Queues run synchronously in tests. External providers are faked through their contracts (`Mockery::mock(WalletGateway::class)`, `FakePaymentGateway`, `FakeKycGateway`, `LogPushGateway`).
- Tests that need Redis (GEO search) are skipped when the `phpredis` extension is missing.
- When switching bearer tokens within one test, call `app('auth')->forgetGuards()` (Sanctum caches the resolved user).
- Edge-case suites per area: `RideEdgeCaseTest`, `MatchingEdgeCaseTest`, `WalletPayoutEdgeCaseTest`, `AccountSafetyEdgeCaseTest`, `OperationsEdgeCaseTest`, `ApiErrorHandlingTest`.
- CI (`.github/workflows/staging.yml`) runs the suite on PHP 8.4 and 8.5 with PostgreSQL 16 and Redis 7 for every pull request to `staging`; both must pass before a push to `staging` deploys.

---

## 18. Environment variables

The complete list is in `.env.example`. The ones that change behaviour:

| Area | Variables |
|------|-----------|
| Sessions | `SANCTUM_TOKEN_EXPIRATION` (admin, min), `SANCTUM_APP_TOKEN_EXPIRATION` (apps, min) |
| OTP / SMS / WhatsApp | `TERMII_API_KEY`, `TERMII_SENDER_ID`, `TERMII_WHATSAPP_FROM`, `ETIGO_SAFETY_NUMBER` |
| Maps & places | `GOOGLE_MAPS_API_KEY`, `MAPBOX_ACCESS_TOKEN`, `GEOAPIFY_API_KEY`, `LOCATIONIQ_API_KEY`, `PLACE_SEARCH_DRIVERS`, `PLACE_SEARCH_COUNTRY` |
| Payments | `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_FAKE`, `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_WEBHOOK_SECRET` |
| Wallet | `WALLET_MIN_TOPUP`, `WALLET_MAX_BALANCE`, `WALLET_DAILY_TOPUP_CAP`, `WALLET_MIN_PAYOUT`, `WALLET_SETTLEMENT_DELAY`, `WALLET_MAX_NEGATIVE_BALANCE`, `WALLET_DEFAULT_COMMISSION_RATE`, `WALLET_HOLD_EXPIRY_HOURS`, `WALLET_MAX_CASH_OVERPAYMENT` (kobo; finance can override most at runtime) |
| Matching | `MATCHING_RADIUS_TIER_1_KM`/`_2_KM`/`_3_KM`, `MATCHING_DRIVER_RESPONSE_TIMEOUT`, `MATCHING_TIMEOUT`, `MATCHING_MAX_AUTO_RETRIES`, `MATCHING_PRIORITY_EXTRA_RETRIES`, `MATCHING_AUTO_RETRY_DELAY`, `MATCHING_MAX_CANDIDATES` |
| Surge | `SURGE_DEMAND_WINDOW_MINUTES` (10), `SURGE_DEMAND_MIN_REQUESTS` (5), `SURGE_DEMAND_CACHE_SECONDS` (60) |
| KYC | `QOREID_CLIENT_ID`, `QOREID_SECRET_KEY`, `KYC_SKIP_VERIFICATION` |
| Push | `FIREBASE_CREDENTIALS_PATH` (path or raw JSON), `FIREBASE_PROJECT_ID` |
| Real-time | `BROADCAST_CONNECTION=reverb`, `REVERB_*`, `TELEMETRY_SERVER_PORT`, `TELEMETRY_RATE_LIMIT_MS`, `REDIS_GEO_DB` |
| Safety | `SOS_CHECK_IN_DELAY`, `SOS_ESCALATION_TIMEOUT`, `OFFLINE_*` (detection thresholds), `OFFLINE_SUSPENSION_HOURS` |
| Fleet / EV / promos / loyalty | `FLEET_*`, `EV_RESERVATION_FEE`, `EV_RESERVATION_EXPIRY`, `EV_RESERVATION_FREE_CANCEL_MINUTES`, `EV_MAX_QUEUE_SIZE`, `PROMO_PEAK_*`, `CARBON_BASELINE_EMISSION`, `GAMIFICATION_*` |
| Notifications | `NOTIFICATION_RETENTION_DAYS`, `NOTIFICATION_PUSH_ENABLED`, `NOTIFICATION_IN_APP_ENABLED` |
| Storage / mail | `UPLOADS_DISK` (documents and photos; `public`, `s3` or `cloudinary`), `CLOUDINARY_*`, `AWS_*`, `MAIL_*` (Resend) |

---

## 19. Known limitations

These are deliberate gaps that need a product decision or data, not bugs:

| Area | Status |
|------|--------|
| Demand-based surge | Measured per city (and class), not per zone inside a city; supply uses the driver's registered city |
| Existing vehicles' fuel type | Vehicles created before fuel type existed stay `null` until the driver or operations sets it (run `vehicles:request-fuel-types` once to ask them) |
| Booking latency | The maps call for the route runs inside the booking transaction (passenger row lock); fine at current volume, worth moving out if booking contention appears |
