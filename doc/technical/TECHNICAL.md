# E-tiGo Backend — Technical Documentation

How the E-tiGo API is built: architecture, business rules, invariants and the reasoning behind them. Read it before changing a module.

| Looking for | Go to |
|-------------|-------|
| Endpoints, request/response shapes, error messages | [API_REFERENCE.md](API_REFERENCE.md) |
| Trying the API | [postman_collection.json](postman_collection.json) + [postman_environments/](postman_environments/) (`php artisan postman:sync` publishes them to the team's Postman workspace) |
| Servers, background processes, scheduled jobs | [DEPLOYMENT.md](DEPLOYMENT.md) |
| Task status per feature | [task-breakdowns/E-Tigo_Backend_Task_Breakdown.md](task-breakdowns/E-Tigo_Backend_Task_Breakdown.md) |
| Mobile KYC integration | [MOBILE_KYC_GUIDE.md](MOBILE_KYC_GUIDE.md) |
| Client reports (PDF) | Edit the `.md` in `doc/client/`, then rebuild the PDF: `python3 doc/tools/build_client_pdf.py doc/client/<NAME>.md` (setup: `pip install -r doc/tools/requirements.txt`) |

---

## 1. Architecture

The API serves three clients: the **Passenger app** and **Driver app** (mobile) and the **Admin dashboard** (web).

| Component | Technology |
|-----------|------------|
| Framework | Laravel 13, API-only, routes under `/api/v1` |
| PHP | 8.5 everywhere: servers (8.5.12), local development and CI. `composer.json` requires `^8.5` and pins `config.platform.php` to 8.5.12 so dependencies always resolve for the servers |
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
| `SmsGateway` | `SendchampGateway` when `SENDCHAMP_ACCESS_KEY` is set — sends over **WhatsApp first (approved templates) and falls back to SMS**; otherwise `LogSmsGateway` | OTP codes, bank-account codes and change alerts, SOS alerts to `ETIGO_SAFETY_NUMBER`, third-party rider messages, Lost & Found texts |
| `MapsGateway` | `FailoverMapsGateway`: each call tries Google (`GOOGLE_MAPS_API_KEY` / `GOOGLE_PLACES_API_KEY`), then Mapbox (`MAPBOX_ACCESS_TOKEN`), then `HaversineMapsGateway` (straight-line estimate), logging a warning for each provider that fails, so a rate limit or outage (Google 429, Mapbox "no routes") never fails an estimate or booking. With no provider configured it is the straight line alone | Distance/duration, routes, geocoding |
| `PlaceSearchGateway` | Failover chain from `PLACE_SEARCH_DRIVERS` (default `google,mapbox,geoapify,locationiq,photon`; providers without credentials are skipped) | Address autocomplete |
| `PaymentGateway` | Paystack primary with Flutterwave failover when both keys are set (`FallbackPaymentGateway`); a single provider otherwise; `FakePaymentGateway` when `PAYSTACK_FAKE=true` | Ride card/bank-transfer payments, saved cards, tips, refunds |
| `WalletGateway` | `PaystackWalletGateway` (requires `PAYSTACK_SECRET_KEY`) | Wallet top-ups, bank list/resolution, payout transfers |
| `KycGateway` | QoreID (`QOREID_CLIENT_ID`/`QOREID_SECRET_KEY`); `FakeKycGateway` when `KYC_SKIP_VERIFICATION=true` (or `QOREID_SKIP_VERIFICATION`) or in local/testing without credentials; **throws** elsewhere without credentials | NIN, driver's licence, plate, liveness |
| `PushNotificationGateway` | Firebase when credentials resolve (`FIREBASE_CREDENTIALS_PATH`: path or raw JSON); otherwise `LogPushGateway` | Push notifications (invalid device tokens are deactivated automatically) |

**Messaging (Sendchamp).** WhatsApp only lets a business start a conversation with a Meta-approved template, so the gateway never sends free WhatsApp text:

- **Codes** (`SmsGateway::sendOtp`) go through the authentication template `SENDCHAMP_WHATSAPP_OTP_TEMPLATE`, whose `{{1}}` is the code. The SMS fallback carries the full text from `OtpService`, which names what the code is for ("verification code" or "code to change your payout bank account").
- **Everything else** (`send` / `deliver`) goes through the utility template `SENDCHAMP_WHATSAPP_TEMPLATE`, whose `{{1}}` is the whole message. Meta refuses line breaks in template variables, so lines are joined with ` | ` for WhatsApp; SMS keeps them.
- WhatsApp is tried only when `SENDCHAMP_WHATSAPP_SENDER` and that message's template code are set; otherwise the message goes straight to SMS.
- SMS (`POST /sms/send`) uses the approved sender name `SENDCHAMP_SENDER_NAME` and the route `SENDCHAMP_SMS_ROUTE` (`dnd`, which also reaches numbers on the Do-Not-Disturb list) for Nigerian numbers, `international` for the rest. Typographic characters (dashes, curly quotes, `₦`) are swapped for GSM ones so each part is billed at 160 characters, not 70.
- A request counts as accepted only when Sendchamp answers `status: success`. An empty wallet (HTTP 407, "Low balance") is logged as **critical**; every message fails until the wallet is funded.
- Console: `php artisan sendchamp:status` (setup and wallet balance), `sendchamp:test {phone} --channel=auto|sms|whatsapp|otp` (charged), `sendchamp:register-sender {name=Etigo}` (asks Sendchamp to approve an SMS sender name).

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

Admin roles: `super_admin`, `operations`, `safety_operator`, `support`, `support_lead`, `finance` (the per-endpoint matrix is in the API reference). `support_lead` is tier 2 of dispute handling (§6.4) and has the `support` role's other access. Every request is logged by `LogRequests` (method, path, status, duration, user, IP).

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

Leaving `approved` by any route (operations, an automatic sanction, L3) fires the `Driver::updated` hook after commit: `ScheduledRideReservationService::releaseAllFor` releases the driver's upcoming reservations (`driver_suspended`) and `DriverShiftService::withdrawDriver` ends a shift in progress (`ends_at` = now) and cancels upcoming ones (`driver_shift_withdrawn`), with one operations alert. Reactivation restores neither (Phase 2 review, RV-01).

### Vehicle and payout details

- A driver may change their own vehicle class only before submitting (`onboarding`) or after a rejection. From submission on, only an admin with driver-management access (operations or safety operator) can change it (`PUT /admin/drivers/{driver}/vehicle-class`, audited), so a driver cannot upgrade themselves into a higher-fare class.
- Fleet drivers cannot edit the E-tiGo vehicle assigned to them.
- Adding the payout bank account, the first one included, and every later change require a one-time code sent to the driver's phone (`POST /driver/bank-account/otp`, purpose-bound), so a stolen session cannot direct payouts to another account. A driver can build up earnings before adding an account, so the first one is protected as well. The account is resolved before the code is checked, so a Paystack failure does not use up the code. Every save sends `PayoutAccountChangedNotification`: in-app and push (preferences apply) plus WhatsApp/SMS through `SmsChannel`, which ignores preferences so a change the driver did not make is always reported. A failed SMS is retried by the queue (3 tries). Account names are resolved with Paystack before saving.
- **Payout safeguards** (`PayoutAccountSafeguards`, settings in the wallet group, editable at `PUT /admin/settings/wallet`):
  - *Name check:* `AccountNameMatcher` compares the Paystack account name with the driver's verified name (profile first and last name, which KYC checks against the NIN and drivers cannot edit, plus the middle name from the verified NIN record). It ignores word order, case, punctuation and titles, accepts a one-letter difference in names of 5+ letters, and needs the surname plus one given name. `payout_account_name_check`: `review` (default — a mismatch is saved with `review_status = pending` and paid only after finance approves it), `block` (refused before the code is checked) or `off` (recorded only).
  - *Hold:* an account that replaces another gets `payouts_held_until = now + payout_account_hold_hours` (default 24, 0 = off); a first account only if `payout_account_hold_first_account`. The hold gives the driver time to react to the change alert before money leaves.
  - *Enforcement:* `blockFor()` (rejected → under review → on hold) is checked by every path that sends money: driver payout request (422 with `code`), weekly auto-payout (skipped, paid in a later week), admin approve and retry (409), and `ProcessPayoutTransferJob` just before a new transfer (the payout fails and the money is returned), which covers an account blocked after approval and reconciliation's re-dispatch. Pending payouts created before a change keep the account they were requested to.
  - *Finance:* `/admin/payout-accounts` lists accounts (pending reviews first, with counts) and approves, rejects or releases holds, all audited; approve/reject notify the driver (`payout_account_reviewed`). `php artisan payout-accounts:check-names [--apply]` checks accounts saved before the name check existed.
  - Defaults were chosen by E-tiGo's developers following common practice (a cooling-off period on changed payout destinations; name match against verified identity with manual review rather than a hard block, because Nigerian names vary in order and spelling across banks); finance can change them at any time.

---

## 5. Cities, vehicle classes, pricing and surge

- **Cities** have a GeoJSON boundary, radius, timezone and currency, and a launch status (§5.1). **Vehicle classes** are platform-wide and enabled per city through `city_vehicle_classes` (`is_active` on both the pivot and the class). The public city/class lists are cached (§16) and invalidated on every admin change.
- **Pricing configs** are append-only and versioned per city + class. The effective one has the latest `effective_from` not in the future. Fields: `base_fare`, `per_km_rate`, `per_minute_rate`, `minimum_fare`, `waiting_time_rate`, `free_waiting_minutes` (default 5).
- **Surge rules** (`manual`, `time_based`, `demand_based`) apply per city, optionally per class. The highest-priority matching rule wins (ties go to the higher multiplier). Multipliers are 1.00–5.00. Time windows support overnight spans. Demand-based rules compare live demand with supply (`SurgePricingService::demandSnapshot`, cached 60 s per city and class): distinct passengers who booked in the last `SURGE_DEMAND_WINDOW_MINUTES` (10) against approved, online, idle drivers in the city with a fresh location (demo drivers excluded). A rule fires when the ratio reaches `min_demand_supply_ratio` and at least `min_requests` passengers are asking (default `SURGE_DEMAND_MIN_REQUESTS`, 5). The exact ratio is compared (the admin view shows it rounded). If driver locations cannot be read, supply is unknown and demand rules do not fire (the failure is not cached). The admin current-multiplier endpoint shows the figures. Supply is counted by the driver's registered city, not their live position. Conditions are validated to the keys `SurgeType::conditionKeys()` lists (unknown keys refused, other types' keys dropped by `AdminSurgeRuleController::conditionsFor`); the 2026-10-29 migration rewrote rules seeded in the old `days` / `min_pending_rides` / `min_utilization_pct` shape.

### Fare formula

```
metered        = max(minimum_fare, base_fare + km × per_km_rate + min × per_minute_rate + waiting_charge)
km, min        = the quoted route (see Upfront fares below)
waiting_charge = max(0, waiting_minutes − free_waiting_minutes) × waiting_time_rate     (final fare only)
surged         = metered × surge_multiplier
tier_discount  = min(base_fare × booking_fee_discount_pct / 100, surged)                (loyalty tier benefit)
fare           = surged − tier_discount
final charged  = fare − promo_discount                                                  (at completion)
```

`FareEstimationService::applyAdjustments()` is used for both the estimate and the final fare, so they cannot drift apart.

### What is locked in at booking

The ride's `pricing_snapshot` (JSONB) freezes the pricing config, the **surge multiplier** and the passenger's **tier discount** at booking time. It also records the quoted route (`distance_km`, `duration_minutes`). A surge that ends mid-trip doesn't change the agreed multiplier, and a pricing change doesn't affect rides already booked. Completion adds `quoted_distance_km`, `quoted_duration_minutes`, `actual_trip_minutes`, `time_adjusted`, `time_capped`, `waiting_charge`, `tier_discount`, `promo` and (for fleet drivers) `fleet_remittance` to the snapshot, and `duration_minutes` becomes the billed minutes.

### Upfront fares

Fares are upfront, as on Uber and Bolt: the passenger pays the quote (plus any waiting charge), and the quote already allows for expected traffic. `RideService::calculateFinalFare()` reuses the quoted distance and time from the snapshot instead of asking the maps provider again (rides booked before the quote was recorded fall back to a fresh route). Only the time charge can change, because there is no mid-trip destination change and no GPS trail:

```
actual    = started_at → completed_at (minutes)
tolerance = max(quoted × trip_time_tolerance_pct / 100, trip_time_tolerance_min_minutes)     (25%, 10 min)
billed    = quoted                                   if actual ≤ quoted + tolerance (also when quicker)
          = min(actual, max(quoted × max_billed_time_multiplier, quoted + tolerance))   otherwise (3×)
```

A trip that runs past the tolerance is billed its whole actual time (`time_adjusted`). The cap stops a trip nobody ended from running up the fare: a capped ride sets `time_capped` and logs a warning for review. The settings live in `config/pricing.php`. The receipt's `trip_time` block shows quoted, actual and billed minutes.

### Estimate edge cases

Cross-city trips use pickup-city pricing and return a warning. Destinations outside every service area still get a quote, with a warning. Routes over 100 km get a warning. Cities that are not live (onboarding or paused) and identical pickup/destination are rejected (422). Short trips are floored at the minimum fare.

### 5.1 Multi-city launches (Phase 2)

Built to the OQ-22 recommendation (`doc/client/PHASE2_DECISIONS.md`), not yet client-confirmed. Runbook for operations: `doc/technical/CITY_ONBOARDING.md`.

| Part | Rules |
|------|-------|
| Launch status | `cities.launch_status` (`CityLaunchStatus`): `onboarding` → `live` → `paused` → `live`, and `live` or `paused` → `onboarding` (close). `CityLaunchStatus` holds what each status allows: bookings and estimates only when live; driver sign-up when onboarding or live; drivers online when live or paused; scheduled dispatch when live or paused; waitlist when not live. `is_active` stays as a stored column that the `City` model's `saving` hook keeps equal to `launch_status == live`, so every older query and client reading it keeps working; a legacy write of `is_active` alone maps to live / paused (onboarding on a new city). `launched_at` is the first launch. New cities start in onboarding. |
| Changing status | `CityLaunchService::changeStatus` under a row lock on the city: checks the transition, runs the readiness checklist for onboarding → live only (a resume needs none), stamps or clears `paused_at`, `pause_reason`, `pause_scheduled_rides`, writes the audit event (`city_launched`, `city_resumed`, `city_paused`, `city_returned_to_onboarding`) and flushes the city cache. Then two queued jobs, each skipped if the status moved on: `CityLaunchStatusChangedJob` (retried; both steps skip finished work) cancels upcoming bookings when asked (`RideService::cancelRide` as the admin, reason `city_paused`, passenger-facing `cancellation_details`) and on going live tells the waitlist (`city_launched`, `notified_at` stamped per entry); `NotifyCityDriversJob` (`tries = 1`, since nothing records who was told) tells approved drivers in the city (`city_status_changed`) on launch, resume, pause and close. **Close** (`assertCanClose`, under the same lock): refused with `CityLaunchException` status 409 (`active_rides`, `upcoming_scheduled_rides`) while any `RideStatus::activeStatuses()` ride or upcoming booking is in the city; it then nulls `readiness_confirmations` and `launch_date` (old values in the audit event), sets the city's online drivers offline (`drivers_taken_offline`, removed from the location index after commit) and still queues the booking cancellation for one made while closing. `City::wasClosed()` (onboarding with `launched_at`) picks the "not running for now" wording for passengers, drivers and the move-driver warning. The admin city list reads every city's `{ready, missing}` with `CityReadinessService::summaries()`, the same gate functions as `report()` fed by grouped counts. The legacy `PATCH /admin/cities/{city}/status` maps to the same service. |
| Pause | Trips in progress are never touched. Upcoming bookings follow `pause_scheduled_rides` (`honour` by default from `AppSetting::cities()`): honoured bookings dispatch as normal; with `cancel` they are cancelled, and `ScheduledRideService` also refuses any that reaches dispatch (`dispatch_failure_reason` `city_paused`), so a race with the job cannot start one. Dispatch reads the city under a shared row lock held to the end of the dispatch transaction, and `changeStatus` locks the city for update, so a pause either lands first and is seen, or waits until the booking has left the upcoming set the cancellation reads. Back to onboarding behaves like cancel. Drivers may stay or go online in a paused city (for honoured bookings); `toggle-online` returns a `city_notice`. |
| Readiness | `CityReadinessService::report` computes `boundary` (drawn, no overlap), `pricing` (`PricingCoverageService` gaps; ≥ 1 class offered), `drivers` (approved + KYC verified ≥ `launch_min_approved_drivers`), `ev_charging` (≥ 1 active station when approved drivers have a vehicle whose `FuelType::canCharge()`; `not_applicable` otherwise or when `require_ev_charging` is off), `launch_date` (set) and `launch_promos` (optional: active promos limited to the city). `support_cover` and `permits` (`obtained` / `not_required`) are confirmed by an admin and stored in `cities.readiness_confirmations` with the admin and time. `counts` adds the waitlist and what a pause would affect. |
| Boundaries | `CityBoundaryService` refuses (form request `after()`; on update only when the boundary changed) a boundary that overlaps any other city, so detection always finds one city. Polygons (exterior ring) and Point + `radius_km` (30 when missing) are compared on a flat km projection around the shapes' latitude: crossing edges, a sample point (vertices, edge midpoints, centroid) strictly inside the other shape, circle distance, or circle-to-edge distance. Points within 10 m of an edge count as on it, so shared borders pass.  `sanityWarnings()` flags (never blocks) a boundary that is probably wrong: swapped `[lng, lat]` or outside Nigeria (bounding box, or swapped back near the state capital), a circle, under 10 or over 2,500 km², a self-crossing outline, a centre over 250 km from the state capital (`NigerianStates::capitalOf`); single-city admin `warnings` include them, and `POST /admin/cities/boundary-check` returns them with the overlap, area and centre before saving |
| Time zone and currency | Store/update accept only `config('cities.supported_timezones')` (`Africa/Lagos`) and `supported_currencies` (`NGN`) (update also the city's current value), because fleet business days, promo peaks, off-peak windows and weekly payouts still use Lagos time. Adding a city abroad needs those moved to `cities.timezone` first. |
| Passengers | `GET /cities` lists live cities (cached `cities:active`); `?include_onboarding=1` adds onboarding ones for the driver sign-up picker (cached `cities:open_to_drivers`). `CityDetectionService::detectCity` takes the launch statuses to search (live by default; the public detect endpoint searches all and answers 404 with `unavailable_city` outside a live city). Estimates and bookings outside a live city return 422 with `CityLaunchStatus::unavailableMessage()`. Waitlist: `city_waitlist_entries` (unique city + user), joined for onboarding or paused cities only. |
| Pickups | The server decides a ride's city from the pickup (24 Oct 2026): `CityDetectionService::pickupCity` (boundaries only, any launch status) returns the city sent when the pickup is inside it (shared border, or a legacy overlap) or outside every drawn city, else the smallest city holding the pickup. `StoreRideRequest::resolvePickupCity` (also used by `EstimateRideRequest`) refuses a city that is not live (422 on `city_id` when it is the one sent, else `pickup_lat`) or no city at all; `city()` on both requests is what controllers book and quote, and the estimate returns it as `city`. `city_id` is optional. `detectCity` checks boundaries first and matches the reverse-geocoded name only against cities with no boundary, so detection and booking agree: before this, a geocoded district ("Ikeja") matched a small legacy city by name while booking checked geometry, giving "This pickup is in Oshodi. Choose Oshodi…" on staging. Scheduled-ride pickup edits refuse a pickup in another city (a booking keeps its city). Boundaries saved before overlaps were refused are not swept by a job: `CityBoundaryService::overlapsFor` feeds `overlapping_cities` on `GET /admin/cities` and `warningsForCity` adds to every single-city admin response's `warnings`, so they are fixed where an admin edits the city (write-time refusal stays the guarantee) |
| Drivers | A move (`PUT /admin/drivers/{driver}/city`) is refused with an active ride, upcoming reservations or upcoming fleet shifts, and deletes the driver's upcoming availability plans for the old city (RV-03). Sign-up (`POST /auth/register/complete`, drivers only) and `PUT /driver/profile` accept onboarding or live cities only, and `GET /cities/{city}/vehicle-classes` answers for both; a driver can change city only in `onboarding` or `rejected`. `PUT /admin/drivers/{driver}/city` (operations, safety) moves a driver: under the driver's row lock (also taken by ride assignment and by `ScheduledRideReservationService::reserve`), refused during an active ride or with upcoming reserved bookings, takes them offline and out of the location index, notifies them (`driver_city_changed`), audits `driver_city_changed`, and warns when the city does not offer their class or has not launched. A driver in an onboarding city cannot go online. Matching, nearby rides and reservations already use the driver's `city_id`. |

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

scheduled ──→ searching (dispatcher, shortly before pickup)
          └─→ cancelled (passenger, admin, or the dispatcher when the ride cannot start)
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
| Cancel | Allowed until the trip starts (and from `no_driver_found`). Free, except the late-cancellation fee on a driver-reserved scheduled booking (§6.1). The wallet hold is released, the other party is notified with who cancelled (passenger, driver or E-tiGo support) and the reason, and a driver cancellation near the pickup starts off-app trip monitoring (§14). |
| Admin assignment | `requested`, `searching` or `no_driver_found`; for `no_driver_found` the passenger must not have booked again, and a wallet hold is re-placed. |
| Book for later | `POST /rides` with `scheduled_pickup_at` creates a `scheduled` ride (§6.1). Not an active ride: it does not block riding now, and an active ride does not block booking for later. |
| Book for someone else | `POST /rides` with `rider_name` + `rider_phone` creates a third-party ride (§6.2). It does not count as the booker's own active ride; it has its own limit. |

### 6.1 Scheduled rides (Phase 2)

Modelled on Uber Reserve and Bolt scheduled rides: the passenger books a pickup time, the price is locked at booking, and matching starts shortly before pickup. `ScheduledRideService` holds the logic; `scheduled_rides` (one row per booked ride) records the pickup time and what the dispatcher did. The ride's own `status` (`scheduled` until dispatch) is the state. Settings are `AppSetting::scheduledRides()`: operations' overrides (`PUT /admin/settings/scheduled-rides`) over `config/scheduled_rides.php`.

| Step | Rules |
|------|-------|
| Book | Pickup `min_lead_minutes` (30) to `max_advance_days` (3, set by the client) ahead, with an explicit UTC offset; at most `max_upcoming_per_passenger` (3, set by the client) upcoming bookings, `min_minutes_between_bookings` (30) apart. No PIN, no hold, no matching. A wallet booking must be affordable now (`WalletPaymentService::assertCanCover`), but nothing is held. |
| Quote | Locked at booking, like any upfront fare. Surge is evaluated **at the pickup time** (`SurgePricingService` `$at`): manual and time-based rules in effect then apply; demand-based rules never do, since live demand says nothing about later. Scheduled bookings are also left out of the live demand count. Promo validity and peak rules are checked at the pickup time, both at booking and at settlement. |
| Edit | `PUT /rides/{ride}/scheduled` until `dispatch_at` (pickup − `dispatch_lead_minutes`): time, places, payment method. The fare is quoted again and any promo re-checked. |
| Cancel | `DELETE /rides/{ride}/scheduled` or `POST /rides/{ride}/cancel`. Free, except a passenger cancelling a driver-reserved booking within 30 minutes of the ride pays the late-cancellation fee (see Fees below). `RideService::cancelRide` stamps `scheduled_rides.cancelled_at`. |
| Remind | `reminder_minutes` (60) before pickup, once (`reminder_sent_at`); warns a wallet passenger whose balance is short. Booked inside that window → no separate reminder. |
| Dispatch | At pickup − `dispatch_lead_minutes` (15): place the wallet hold, issue the PIN, `scheduled → searching`, then the normal matching flow (`RideService::startMatching`). The passenger gets `scheduled_ride_dispatched`. |
| Search window | Until pickup + `matching_grace_minutes` (10), never shorter than the normal `MATCHING_TIMEOUT` (so a late or re-broadcast search still gets a full window): `DriverMatchingService::searchDeadline` takes the later of the two, waves repeat every `retry_wave_delay_seconds` (30) with no retry cap, and a driver who declined is not offered it again for `MATCHING_TIMEOUT`. |
| Driver reservation | `ScheduledRideReservationService`. Until `dispatch_at` an approved driver in the same city and class (no excessive negative balance, ≤ `max_reservations_per_driver` (5), pickups ≥ `driver_reservation_gap_minutes` (60) apart) can reserve; operations can assign or move one (`reserved_by_admin_id`). A new pickup time or place releases it (`pickup_changed`). Drivers holding a pickup within `driver_reservation_buffer_minutes` (60) get no on-demand offers (`DriverMatchingService::driversKeptForReservedPickups`). The reserved driver gets a reminder (`driver_reminder_sent_at`). |
| Dispatch to the reserved driver | If the reserved driver is approved, online with a location under 2 minutes old, still in the class, not negative and not on a trip, `RideService::assignReservedDriver` takes the ride `searching → matched → driver_en_route` in the dispatch transaction (no offer round). Otherwise the reservation is cleared (`driver_unavailable`) and normal matching starts. |
| Fees | Client decisions (16 Oct 2026). `reservation_fee` ₦0 (no charge for reservations); when set it is added to the scheduled quote (`pricing_snapshot.reservation_fee`) and to the final fare before promo. **Late cancellation** (`ScheduledRideCancellationFeeService`): a passenger cancelling a reserved booking within `late_cancellation_window_minutes` (30) of the ride time pays the **economy base fare** of the ride's city (`late_cancellation_fee_basis` `economy_base_fare`, class `late_cancellation_fee_vehicle_class`; falls back to the ride's quoted base fare; `flat` and `off` also available), except for emergency, safety, vehicle-mismatch or driver-no-show reasons. Wallet/saved-card bookings are charged by `ChargeScheduledRideCancellationFeeJob` (one attempt; ledger journal payer → reserved driver in full, idempotency key `scheduled-cancel-fee-{id}`). Cash/bank-transfer bookings, a short or frozen wallet, and a declined card or missing card become a **balance due** (§10.6); an unknown card outcome is left `failed` for finance so the passenger can never pay twice. |
| Cannot start | Cancelled by the system with `cancellation_reason` `scheduled_dispatch_failed`, a passenger-facing `cancellation_details` (sent as the `ride_cancelled` push), and `scheduled_rides.dispatch_failure_reason`: `insufficient_wallet_balance`, `wallet_unavailable`, `account_suspended`, `service_unavailable` (city or class withdrawn), `missed_dispatch_window`. A passenger still on another ride is retried each minute until pickup + grace. |

**Durability:** nothing waits in memory or in a long-delayed queue job. `ProcessScheduledRidesJob` runs every minute from the scheduler and queues `ScheduledRideDispatchJob` / `ScheduledRideReminderJob` per due booking (unique per booking, idempotent under the row lock), so a restart, deploy or edited pickup time is picked up on the next sweep. If the scheduler was down past a booking's pickup + grace, the booking is cancelled as `missed_dispatch_window` rather than sending a driver late.

**Decisions:** every default above, the industry benchmark behind it and the open client questions are in `doc/client/SCHEDULED_RIDES_DECISIONS.md`. Not built: penalties for drivers who often release reservations (tracked in the audit log only).

### 6.2 Third-party bookings (Phase 2)

Modelled on Uber's "request for someone else": a passenger (the **booker**) books and pays for a ride someone else takes (the **rider**), who needs no app or account. The booker stays `rides.passenger_id`, so payment, holds, promos, tiers, cancellation, rating, disputes, receipts and history need no changes. The rider exists only as ride columns: `is_third_party`, `rider_name`, `rider_phone` (E.164), the policy snapshot `pin_recipient` / `tracking_recipient` (`ThirdPartyRecipient`: `booker`, `rider`, `both`), and the delivery state `rider_notification_status` (`RiderNotificationStatus`), `rider_notification_channel`, `rider_notified_at`. Settings are `AppSetting::thirdParty()`: operations' overrides (`PUT /admin/settings/third-party`) over `config/third_party.php`. Defaults follow the OQ-18 recommendation (`doc/client/PHASE2_DECISIONS.md`), which the client has not confirmed.

| Step | Rules |
|------|-------|
| Book | `ThirdPartyBookingService::prepare`, under the booker's row lock. A rider phone equal to the booker's makes it a normal ride. The booking needs the rider's name and `rider_age_confirmed`, and wallet or card payment (cash and bank transfer only if allowed). Searches past their deadline are expired first. Limits: `max_active_per_booker` (2) rides under way, not counted for scheduled bookings, and `max_rider_numbers_per_day` (10) distinct numbers in a rolling 24 h, cancelled bookings included. The app never learns whether the number has an account. Own-ride checks use `Ride::ownTrips()`, so a third-party ride and the booker's own ride don't block each other. |
| PIN and tracking | Generated as for any ride. The booker sees `pin_code` only when `Ride::bookerSeesPin()` is true (policy includes the booker, or the rider's message `failed`). `bookerSeesTracking()` gates the booker's `share_token`, `GET /rides/{ride}/location` and whether `driver.location.updated` goes to the `ride.{id}` channel the same way (the booker still joins it for status and payment events). Rebroadcasting a third-party ride re-checks `max_active_per_booker` (booker row locked before the ride, as at booking). |
| Rider messages | `ThirdPartyRoutingService::queue` from `RideStateMachine::publishTransition` (after commit): `driver_en_route` → driver details (driver, car, plate, PIN if the policy includes the rider, tracking link if tracking does, 112 and safety line); `driver_arrived` → arrival; `cancelled` → cancellation, only if the rider had already been told. PIN regeneration → new PIN. The scheduled-ride reminder (§6.1) → a reminder without the PIN. Sent by `SendThirdPartyRiderMessageJob` through `SmsGateway::deliver` (WhatsApp, then SMS; returns the channel). The PIN is read from the cache when the message is sent, so it is always the latest. Messages that no longer fit the ride's status are skipped; a PIN past its 6-hour life is reissued before the driver-details message. If the job cannot even be queued, the message is marked `failed` at once. `LogSmsGateway` (no Sendchamp key) reports no channel, so local and keyless environments exercise the `failed` fallback. Booker-entered text (first name, addresses) is sent with links removed and length-limited (`plain()`), so the feature cannot carry phishing links. |
| Delivery status | `null` under a booker-only policy (nothing is sent); otherwise `pending` → `sent_whatsapp` / `sent_sms`. A refused message throws, so the job retries (3 tries, 15 s / 45 s back-off). `failed()` sets `failed` only while the ride is still before pickup and `pending`, then sends the booker `third_party_rider_unreachable` (critical push), which reveals the PIN and tracking. A new PIN resets to `pending`, except after `failed`, so the booker keeps the PIN. Each change is broadcast to the booker as `ride.rider_notification.updated` on `user.{bookerId}`. `delivered` is reserved: Sendchamp delivery receipts (webhook) are not consumed yet. |
| Who sees what | `PresentsThirdPartyRide` (ride resources): booker and admins get the `third_party` object. Everyone gets `rider` (the person picked up; the driver sees the phone until the trip ends). The driver gets only the booker's `id` and `first_name`. Ride offers and the driver's scheduled lists use the rider's first name. `GET /passenger/active-ride` returns the own ride as `ride` and third-party rides as `third_party_rides`. |
| Scheduled | A booking already inside the reminder window (or with reminders off) sends the rider reminder at booking; an edit of time or places with no reminder still to come sends it again. Bookings clash on `min_minutes_between_bookings` only for the same rider. Dispatch is not held up by the booker's own ride (and the other way round). Editing to a disallowed payment method is refused. |
| Safety | When the booker raises SOS on a third-party ride, the incident GPS is the car's live position (if under 2 min old); the booker's own position stays in telemetry (`reporter_gps`, `gps_source`). Telemetry also holds `third_party_rider`, and the WhatsApp alert names the rider and the booker. |
| Tracking page | `GET /track/{ride}/{token}` (web route, `resources/views/track.blade.php`): no login, `noindex`, Leaflet/OpenStreetMap map polling the public share endpoint every 10 s. It shows the driver photo, car and plate, ETA, "Call 112" and the safety line (`sos.safety_line`). The link sent to riders is `THIRD_PARTY_TRACKING_URL` (default `{APP_URL}/track/{ride}/{token}`). It expires 1 hour after the trip, like every share link. |

The driver's live location is shared with the passenger only while the ride is active (`GET /rides/{ride}/location`); afterwards only admins see it. Share links (`/rides/{ride}/share/{token}`, and the `/track/{ride}/{token}` page built on them) include the driver's live position and the car while the ride is active, and expire one hour after the trip ends.

### 6.3 Lost & Found (Phase 2)

Modelled on Uber, Bolt and Lyft. An item left in the car after a completed trip is a `lost_items` row (`LostItem`, reference `LF-`), with `lost_item_messages` for the conversation and support's internal notes (`is_internal`). The passenger reports it, or the driver hands in something they found (`reported_by`). The row records the ride's `passenger_id` (the booker on a third-party ride) and `driver_id`. `LostItemService` makes every change under a row lock, checks the expected status (`\DomainException` → 422 when the report has moved on), writes an `AuditLog` event (`lost_item.*`) and sends notifications after commit. Settings are `AppSetting::lostAndFound()`: operations' overrides (`PUT /admin/settings/lost-and-found`) over `config/lost_and_found.php`. The defaults are engineering choices (18 Oct 2026) the client has not confirmed.

| Step | Rules |
|------|-------|
| Report | Completed rides only, within `report_window_days` (30) of `completed_at`. The ride and passenger rows are locked; a ride has at most one open report (also a partial unique index on Postgres), and a passenger at most `max_open_reports_per_passenger` (3). Passenger report → `driver_notified` + `lost_item_report` push to the driver. With no active driver account it stays `reported` and is escalated (`escalation_source: system`). Driver report → `driver_confirmed`, passenger told. Optional `contact_phone` (normalised to E.164) for when the lost item is the phone: the "driver has it", "returned" and "found by support" updates are also texted to it (`SmsGateway::send`). Photos (≤ 3) go to the uploads disk under `lost-items/{ride}`. |
| Driver answer | `respond`: `found` → `driver_confirmed`, otherwise `driver_denied`. "Found" is also accepted after "not found". An automatic (system) escalation is cleared when the driver answers; a passenger's escalation stays. |
| Return | `markReturned` (driver, from `driver_confirmed`) → `returned`. `confirmReceived` (passenger, from `driver_confirmed` or `returned`) → `resolved`. While the status is `driver_confirmed` or `returned`, each side sees the other's first name and phone (`LostItem::sharesContactDetails()`, setting `share_contact_details`). The phone is the passenger's `contact_phone` if given. |
| Return fee | `return_fee` (NGN, default 0 = off) is fixed on the report when the driver (or support) confirms, so later setting changes don't touch it. On resolution with the item back, `return_fee_status` becomes `pending` and `LostItemReturnFeeService::collect` runs. If the wallet is active and covers the fee, it posts wallet → driver earnings in full (idempotency key `lost-item-return-fee-{id}`) → `charged`. Otherwise it records a balance due (`lost_item_return_fee`, payee the driver) → `balance_due`, which becomes `charged` or `waived` when that balance is settled. No driver → `waived`. A trip carries one return fee: under a lock on the ride, a second returned item from the same trip is `waived` when another report already holds a `charged` or `balance_due` fee, however that one was collected. Support can waive at resolution. A collection that errors stays `pending` and the hourly sweep retries it. |
| Support | `escalate` (passenger, once, not on `reported`) sets `escalated_at`, `escalation_reason` and `escalation_source: passenger`. The admin queue (`support`, `operations`) puts open, escalated reports first; `needsAttention()` = open and (escalated or `reported`). `intervene` actions: `note` (internal), `message` (to both), `mark_found`, `resolve` (`waive_return_fee`), `close` (`LostItemClosureReason`). Support messages are allowed on closed reports; participants' messages are not. |
| Sweep | `ProcessLostItemsJob` (hourly) → `processOverdue()`. A driver who has not answered within `driver_response_hours` (24) is escalated: the passenger is told and the driver reminded. A confirmed item not back within `return_deadline_days` (7) is escalated. `returned` with no objection for `auto_resolve_hours` (72) → `resolved` (fee collected). `driver_denied` with no escalation for `denied_auto_close_days` (7) → `unresolved` (`not_found`). `pending` fees older than 10 minutes are retried. Escalated reports are never auto-resolved or auto-closed. Each step tells the people involved (`lost_item_update`, or `lost_item_report` for the driver reminder). A report that fails (escalation or status change) is logged and skipped, so one bad row never stops the sweep. |
| Who sees what | `LostItemResource` is cut per viewer (`viewer_role`). Passenger and driver see each other's first name only (the rider's on a third-party trip), and the phone only through `contact`. The car (`ride.vehicle`), `contact_phone` and `escalation_reason` are passenger and support only. Internal notes and full user objects are support only. `available_actions` (`LostItem::actionsFor()`) tells the apps which buttons to show. Non-participants get 404. |

Disputes keep the `item_left_behind` category for older app versions; the apps should send lost items to this flow instead (PHASE2_DECISIONS.md: "Use the Phase 2 Lost & Found flow, not disputes"). Not built: masked calling (phone numbers are shared directly once the driver has the item), drop-off at an E-tiGo office (support can record it with `mark_found` and `resolve`), and a card charge for the return fee (an uncovered fee becomes a balance due instead).

### 6.4 Disputes and escalation (Phase 2)

Built to the OQ-21 recommendation (`doc/client/PHASE2_DECISIONS.md`), which the client has not confirmed. A completed trip's passenger (the booker on a third-party ride) or driver files a dispute (`disputes`, reference `DPT-`), one per person per trip; the passenger of a cancelled scheduled booking can also dispute its late-cancellation fee (`ScheduledRideCancellationFeeService::disputableFee`), and an upheld fee dispute is paid by `ScheduledRideCancellationFeeService::refund` — a charged fee back to the wallet with a full clawback from the driver who received it (`clawbackDriverEarnings` takes that driver), an owed fee waived in full and stored as `disputes.waived_amount`, apart from `refund_amount` (RV-07); filing locks the ride row so the two sides' disputes always link. `DisputeService` makes every change under a row lock, writes an `AuditLog` event (`dispute_*`) and notifies after commit; `DisputeAccess` holds who can see and work what. Settings are `AppSetting::disputes()`: super admins' overrides (`PUT /admin/settings/disputes`) over `config/disputes.php`.

| Part | Rules |
|------|-------|
| Routing | `queue` (`support` or `safety`) and `escalation_tier` (1–3). New cases start at support tier 1; `safety_concern` goes to the Safety queue at tier 2 ("skips tier 1"), linked to the trip's latest SOS incident (`sos_incident_id`), and the active safety operators are emailed (`DisputeAlertNotification`; super admins if there are none). Tier 1 is `support`, tier 2 `support_lead`, Safety `safety_operator`, tier 3 `super_admin`. |
| Who sees and works what | Support roles see the support queue; the Safety team and super admins see everything. An admin works a case at their tier or below (`actDenial`); a super admin works any. Tier 1 agents can only take a case themselves; leads, Safety and super admins can assign it to anyone who can work it. Internal notes follow the same visibility. |
| Linked cases | When the other participant already has a dispute on the trip, the two are linked both ways (`linked_dispute_id`). The new case takes the higher routing of the two and the existing owner (if they can work it); after that, assignment, escalation and de-escalation of one are copied to the other while it is open — except that an appeal never follows the other case down to the tier that made its original decision (only the owner is shared then). Each case still gets its own decision. |
| First response and targets | Taking a case (`assign`), `PATCH status → under_review` or a decision is the first response; the reporter is told ("We are looking into your report"). Due dates are set when a case enters a tier (`sla_started_at`, `first_response_due_at`, `resolution_due_at`): Safety 60 min / 24 h, tier 1 24 h / 72 h, tier 2 24 h / 5 days, tier 3 none (0 in a setting = no target). `Dispute::slaStatus()`: `on_track`, `at_risk` (under a quarter of the window left), `breached`, and `met` / `missed` once closed. |
| Sweep | `ProcessDisputeTargetsJob` (every 15 min) → `processTargets()`. A missed target is stamped once (`first_response_breached_at`, `resolution_breached_at`). Tier 1 misses move to tier 2 (`escalation_source: system`) unless `auto_escalate_tier_1` is off (then only flagged); Safety and tier 2 misses email the super admins ("Response target missed" or "Resolution target missed"). A failing case is logged and skipped. |
| Escalation | `escalate` (reason required) moves one tier up, or to the Safety team with `to_safety`. It clears the assignee (the case lands in the new tier's shared queue), restarts the targets and tells the reporter "Your case is with our senior team" (or "...our safety team"). Not above tier 3, never on a closed case. `deescalate` (note required, kept as a dispute note) moves one tier down; a Safety case goes back to support tier 1; an appeal cannot go back to the tier that decided it. |
| Decision and refunds | `resolve` with `resolved` or `dismissed`. A refund (`refund_amount`, NGN) goes to the passenger's wallet by default through `WalletRefundService::refundRide` (driver's share clawed back unless `clawback_driver_earnings: false`; works for cash rides too). Limits per dispute, counting earlier refunds on it: tier 1 ₦5,000, tier 2 ₦50,000, Safety ₦50,000, super admin none. Above the limit, or with `refund_method: card` (always), the decision becomes a `dispute_refund_requests` row and the case stays open (202). Approval: a card refund by Finance or a super admin; a wallet refund by Finance, a super admin, or an admin on the case's own team (support roles for support cases, Safety for safety cases) whose limit covers the total. Nobody approves their own request; the requester can withdraw it; one pending request per case (partial unique index). On approval the refund is paid and the case resolved with the requester as the decider. Card and wallet refunds share one balance (`remainingRefundableKobo`). A card approval runs in two transactions around the provider call: `refund_sent_at` is committed first, so if closing the case fails after the provider refunded, the request can never be approved (and refunded) again; a provider error clears the mark. `decision_tier` = the higher of the case's tier and the decider's. |
| Appeals | The reporter can appeal once, within `appeal_window_days` (7) of the decision, unless it was decided at tier 3 (final). The case reopens (`open`) at `decision_tier + 1`, unassigned, with new targets; the earlier decision is kept in `original_decision`, and whoever made it (and approved its refund) cannot work the appeal (`excludedReviewerIds()`). A new refund on appeal follows the same limits and claws back the driver's share as usual. |
| Evidence (NF-12) | `dispute_evidence` rows: photos, audio and documents, uploaded when filing (also mirrored in the old `evidence_photos` / `evidence_audio` fields) or later by the reporter while the case is open (`POST /disputes/{id}/evidence`, ≤ `max_evidence_files` (10) per case). Files go to the uploads disk under `disputes/{ride}` and are served as 30-minute signed URLs where the disk supports them. Admins see evidence only as the assignee, a higher tier, the Safety team or a super admin (`evidence_locked` otherwise), only in the single-case view, and each view writes `dispute_evidence_viewed`. |
| Admin changes | Deactivating or deleting an admin returns their open cases to their tier's queue (`dispute_returned_to_queue`); a role change does the same for cases the new role cannot work. |
| Reporter view | `DisputeResource` is cut per viewer. The reporter gets `stage` / `stage_label` ("With our support team", "With our senior team", "With our safety team", "Decision made"), `can_add_evidence`, and `appeal` (`can_appeal`, `deadline`, `reason_unavailable`), and never an admin's name or the internal routing. Admins get `queue`, tier, assignee, `sla`, `escalation`, `appeal`, the linked case, the pending refund request and `available_actions`. |

Not built: messages between support and the reporter inside a case (support uses notes and the decision text), driver sanctions from a dispute decision (operations uses the driver tools), and a dashboard push channel for new cases (email alerts and the queue `summary` counts instead).

### 6.5 Driver scheduling and vehicle maintenance (Phase 2)

Built to the OQ-19 / OQ-20 recommendations (`doc/client/PHASE2_DECISIONS.md`), which the client has not confirmed. Settings: `AppSetting::driverScheduling()` over `config/driver_scheduling.php` and `AppSetting::vehicleMaintenance()` over `config/vehicle_maintenance.php` (operations, `PUT /admin/settings/driver-scheduling` and `/admin/settings/vehicle-maintenance`; the two interval maps are stored as JSON).

| Part | Rules |
|------|-------|
| Availability plans | `driver_availability_plans` (driver, city, `area_label`, `starts_at`, `ends_at`); `DriverAvailabilityService`. Approved drivers, no overlap with their own plans (driver row lock), limits from settings, `repeat_weekly` all or nothing, area labels normalised (trim, collapse spaces, title case). Coverage buckets plans into Lagos hours and groups areas case-insensitively, counting only drivers approved and in the city now (RV-04). Never read by go-online, matching or any passenger endpoint. |
| Shift mode | `vehicles.shift_mode` on fleet cars with no active fleet agreement (`DriverShiftService::setShiftMode`); `FleetRemittanceService::createAgreement` refuses a shift-mode car. `vehicles.driver_id` is the driver holding the car now, so matching, ride views and EV code are unchanged. |
| Shifts | `driver_shifts` (`DriverShift`, `DriverShiftStatus`). Created and edited under vehicle and driver row locks; overlaps checked on every non-cancelled shift (`holdingSlot`) per car and per driver. The driver must be approved and hold no car outside shift mode. `repeat_daily_for` creates a rota in one transaction. Moving, reassigning or cancelling a shift releases the driver's reservations no longer covered (`shift_changed`). |
| Going online | `DriverShiftService::onlineCheck` runs only for shift drivers (`isShiftDriver`: holds a shift-mode car, or holds none and has a workable shift ahead). The open shift is the workable one with `starts_at − lead ≤ now < ends_at`. If another driver holds the car and is online or on a trip → 422; otherwise every other go-online check runs against that car, then `goOnlineForShift`: one transaction under the car's and both drivers' row locks re-checks the holder, releases any other shift car the driver held, assigns the car (audit `shift_vehicle_handover`), sets the driver online and marks the shift started, so two drivers are never online with one car. |
| Offers | `Driver::scopeDispatchable()`: the car is not blocked, and (shifts on) the car is not in shift mode or the driver has a workable shift with `starts_at − lead ≤ now` and `ends_at > now + cut-off`. Used by `DriverMatchingService::findEligibleDrivers`, the operations available-drivers list and `ScheduledRideReservationService::canTakeNow`. Operations' manual assignment refuses a blocked car but not a shift past its cut-off. |
| Reservations | For shift drivers, `reservationErrors` requires a shift covering the pickup (`starts_at ≤ pickup`, `ends_at > pickup + cut-off`) and uses that shift car's class; `availableFor` filters with the same rule in SQL. A blocked car cannot reserve. A shift driver gets no hire-purchase car while shifts lie ahead (`FleetRemittanceService::createAgreement`, RV-05) |
| Shift sweep | `ProcessDriverShiftsJob` (every 5 min, each shift in its own try/catch): `scheduled` past `starts_at + missed_after` → `missed` (driver push, one `OperationsAlertNotification` email per run to active operations admins, super admins if none); `active` within the cut-off → `driver_shift_ending` once (`end_warning_sent_at`; skipped when the next shift on the car is the same driver's); a `scheduled` shift within its lead whose car is held by a driver on a trip → `shift_handover_delayed` once; past `ends_at`: `scheduled` → `missed`, `active` → `completed` and the holder set offline unless on a trip (retried next run) or continuing into a back-to-back shift on the same car (marked active). |
| Maintenance items | `vehicle_maintenance_items` (unique vehicle + type) created on `Vehicle::created` (one bulk insert, status `unknown`) and re-synced on a `fuel_type` change (battery health added or archived). `VehicleMaintenanceService::refresh` recomputes every current item: legal papers from the vehicle's best approved document (latest expiry), interval items from the latest approved record and the interval (per fuel type override first). Dates are Lagos calendar dates. The estimated odometer is `odometer_km + trip_km_since_odometer`; `RideService::completeRide` adds each trip's quoted distance to the car. |
| Records | `vehicle_maintenance_records`; `submitRecord` (driver: pending, proof required, own cars only; operations: approved at once) and `reviewRecord`, both under the vehicle row lock. An odometer reading must be ≥ the highest reading on or before its date and ≤ the lowest after it (records and the vehicle's last reading). An approved record with a reading at least as recent as the vehicle's sets `odometer_km` and resets the trip count. |
| Papers | `driver_documents.vehicle_id` (backfilled for the driver's single car; others listed at `/admin/vehicle-documents/unlinked`), `uploaded_by`; `driver_id` nullable for papers of a driverless fleet car. Driver uploads of vehicle papers keep the approved one (only a pending duplicate is replaced); approving a renewal deletes the earlier approved paper of the type and refreshes the car. Reviewers may set `expires_at`. |
| Block | `refresh` sets `vehicles.blocked_at` / `block_reason` when a legal item is `expired` and today ≥ expiry + grace + 1 (and blocking is on), and clears them when none is. On start (after commit): `vehicle_blocked` (critical push), the driver set offline unless on a trip, upcoming reservations released (`vehicle_blocked`; for a shift-mode car, each driver with a workable shift on it loses the reservations that shift covers, RV-02), operations alert for fleet cars. On lift: `vehicle_unblocked`. Go-online and the status endpoints refresh the car first, so a block never waits for the daily run. |
| Daily run | `ProcessVehicleMaintenanceJob` (06:00 Lagos): ensures items, refreshes, and `sendReminders` — one driver push per car listing items whose status differs from `notified_status` and needs attention (then `notified_status` is updated; back to `ok` clears it), and digest lines for fleet cars newly `overdue` / `expired`. A failing car is logged and skipped. |

Not built: telematics (odometer and charge level are entered), workshop booking inside the maintenance flow (the `DriverRepair` / `REP-` flow is reused and can be linked), recurring availability beyond 3 weeks, drivers with their own car also driving a shared car (one car per driver at a time), and pay or penalties tied to shifts (the fleet agreement decides).

---

## 7. Matching engine

`DispatchRideRequestJob` offers the ride to **one driver at a time**, nearest first:

1. **Search** the Redis GEO index (`GEOSEARCH` on `driver_locations`) within the current radius tier: **3 → 7 → 15 km** (`MATCHING_RADIUS_TIER_*_KM`), reading up to `MATCHING_MAX_CANDIDATES` (20).
2. **Filter** to eligible drivers: online and approved; location reported within **120 s** (`DriverLocationService::STALE_LOCATION_SECONDS`; stale drivers are pruned from the index); vehicle of the requested class; no active ride; no unanswered offer for another ride; not already rejected or ignored for this ride.
3. **Offer**: `ride.request.dispatched` on `driver.{userId}` plus a push with the same fields (both built by `RideRequestDispatched::fromRide()`). A failed broadcast does not stop the push or the timeout. An app that missed both reads the offer from `GET /driver/ride-offer`.
4. **Timeout**: `DriverResponseTimeoutJob` after `MATCHING_DRIVER_RESPONSE_TIMEOUT` (20 s) treats silence as a rejection and moves on, even if the queue runs late.
5. **Expand** to the next tier when the current one is exhausted. After the last tier, wait `MATCHING_AUTO_RETRY_DELAY` (10 s) and start a new wave, up to `MATCHING_MAX_AUTO_RETRIES` (1) times.
6. **Give up**: `MatchingTimeoutJob` moves the ride to `no_driver_found` after `MATCHING_TIMEOUT` (300 s) from the start of the **current** search. Timers from an earlier search are ignored, so a retry gets a full window.

Per-ride matching state lives in the cache with the matching timeout as TTL: `ride:{id}:rejected_drivers`, `ride:{id}:dispatched_to`, `ride:{id}:radius_step`, `ride:{id}:auto_retry_count`, `ride:{id}:dispatched_at` (starts the driver's countdown), plus `driver:{userId}:offered_ride` (the double-offer guard, also read by `GET /driver/ride-offer`).

**Priority matching** (Gold, Platinum, Diamond passengers; locked in at booking as `pricing_snapshot.priority_matching`) never trades distance — industry practice for loyalty priority is to speed matching up, not to send a farther car:
- Every ride, priority or not, is offered its **nearest** eligible driver first.
- **Precedence under scarcity:** a priority ride waiting for its next search (between radius tiers or retry waves, with no offer outstanding) keeps the driver it would be offered next, its nearest free driver within its current radius, instead of that driver being offered to a standard ride. Only that one driver is held per waiting priority ride (earliest booking first); every other driver stays available to everyone.
- **Persistence:** priority searches get `MATCHING_PRIORITY_EXTRA_RETRIES` (1) extra automatic retry waves before `no_driver_found`.

**Scheduled rides** search longer: until their pickup time plus the grace period (§6.1).

---

## 8. Driver location & real-time

### Location pipeline

- Drivers report positions through `POST /driver/location` (one per second) or the **telemetry gateway** (`php artisan telemetry:serve`, Workerman WebSocket on port 8081, Sanctum-authenticated, one update per `TELEMETRY_RATE_LIMIT_MS` = 500 ms; frame protocol in the API reference under *Driver Location Stream*). Only online, approved drivers may stream.
- `DriverLocationService` writes the GEO index `driver_locations` and a per-driver key `driver:{id}:location` (TTL 300 s) on the `geolocation` Redis connection. Positions are rebroadcast to the ride channel and to admins.
- ETAs (`EtaService`) are throttled to one maps call per ride every **30 s**.

### Broadcast channels (`routes/channels.php`)

| Channel | Who may subscribe | Events |
|---------|-------------------|--------|
| `ride.{rideId}` | The ride's passenger and driver | `ride.status.updated`, `driver.location.updated` (not sent here when a third-party ride's tracking excludes the booker: `DriverLocationUpdated::$toRideChannel`), `payment.updated`, `ride.no-driver-found` |
| `driver.{userId}` | That driver | `ride.request.dispatched` |
| `user.{userId}` | That user | `sos.check_in.requested`, `tier.upgraded`, `ride.rider_notification.updated` (§6.2) |
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
| `wallet` | Hold for the estimate | Hold captured for the final fare | Immediately. A balance shortfall is charged as far as possible; the payment stays `failed` (`shortfall_kobo`) and the rest becomes a **balance due** (§10.6); once the passenger pays it the payment is `captured` |
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
- **Transfer approval.** The job creates the Paystack recipient for the driver's bank account and saves its code on the payout (`gateway_recipient_code`), next to the reference, before it starts the transfer. With the approval URL set in the Paystack dashboard, Paystack asks `POST /webhooks/paystack/transfer-approval` about every transfer while the transfer request is in flight. The endpoint approves it only if the reference, amount and recipient match a payout that is `approved` or `processing`. Anything else is rejected and logged as critical, so a leaked secret key cannot move money. A rejected or blocked transfer is a failed status: the payout fails, the money is returned, and finance can retry.
- `ProcessWeeklyAutoPayoutJob` (Monday 06:00 Lagos) creates already-approved payouts for drivers who chose `weekly_auto` and have no open payout, and sends them straight away.

### 10.4 Refunds and adjustments

- **Refund to wallet** (finance, or a dispute decision within the decider's tier limit, §6.4): partial refunds up to what the passenger actually paid (tips excluded). The remaining amount is re-checked under a ride lock, so concurrent refunds cannot over-refund. By default the driver's share is clawed back in the proportion the ride was actually settled (net of commission and fleet remittance). The passenger is notified after commit.
- **Adjustments** use maker–checker: the creator cannot approve their own adjustment, and nothing is posted until approval.

### 10.5 Settings and reconciliation

- Wallet limits live in `config/wallet.php` and can be overridden at runtime by finance (`/admin/settings/wallet`, stored in `app_settings`, read via `AppSetting::wallet()`, audited). The minimum top-up can never exceed the maximum balance or the daily cap.
- `DailyReconciliationJob` (02:00) compares successful top-up charges and payout transfers reported by the gateways (Paystack and Flutterwave payloads; Flutterwave amounts are in naira) with the ledger, and stores a `clean` / `mismatched` report.


### 10.6 Balance due

`PassengerBalanceService`, table `passenger_balances_due` (client decision, 16 Oct 2026). Money a passenger owes that could not be collected at the time:

| Reason | Created when | Paid to on collection |
|--------|--------------|-----------------------|
| `late_cancellation_fee` | A scheduled ride's late-cancellation fee cannot be charged (cash/bank transfer, wallet short or frozen, card declined or none) | The reserved driver, in full |
| `wallet_shortfall` | `WalletPaymentService::settle` cannot cover the whole final fare | The ride's driver, less the usual commission |

One per ride and reason (unique). The passenger gets a `balance_due` notification. While any is `outstanding`, `POST /rides` returns 422 `BALANCE_DUE` (wallet setting `block_booking_with_balance_due`, default on; Uber/Bolt practice). `POST /passenger/balance-due/pay` pays **everything outstanding** in one go — wallet (available balance must cover it; all inside one transaction) or the saved card (one charge, then one journal per item from PSP clearing) — under a per-passenger cache lock so two taps cannot pay twice. Journals use the idempotency key `balance-due-{id}`. Paying a `wallet_shortfall` marks the ride's payment and `payment_status` `captured`; paying a late fee sets the booking's `late_cancellation_fee_status` to `charged`. Finance can list (`GET /admin/balances-due`) and waive (`POST /admin/balances-due/{id}/waive`, finance only; nobody is paid). The driver is paid only when the passenger pays (OQ-35, awaiting client confirmation).
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
2. A **WhatsApp alert** (Sendchamp, SMS fallback) with GPS and trip details is queued to `ETIGO_SAFETY_NUMBER` **before** the console broadcast. A WebSocket outage cannot suppress it.
3. After 5 s (`SOS_CHECK_IN_DELAY`) the user gets an "Are you safe?" check-in. No answer within 30 s (`SOS_ESCALATION_TIMEOUT`) escalates the incident to the console.
4. Safety operators can take over or resolve the incident **at any open stage**. They never wait for the escalation timer.

On a third-party ride, an SOS the booker raises uses the car's live position and records the rider (§6.2).

Every step is locked and logged in the append-only `sos_event_log`. Incident telemetry and vehicle details are encrypted at rest (`encrypted:array` casts).

### Off-app trip detection and sanctions

Detects drivers who cancel a ride and then complete it off the app.

1. When a ride with an assigned driver is cancelled (by either party) while the driver is within **300 m** of the pickup, `MonitorCancellationJob` samples the driver's position every **30 s** for **15 min**.
2. **20 min** later `AnalyzeOfflineTripJob` compares the trajectory with the passenger's planned driving route (`RideRouteService`; the straight line from pickup to destination when the maps provider has none). At least **3** samples within **200 m** of the route, making up at least **60%** of all samples, flags the ride (`OFFLINE_*` settings). The route, the driver's position at the cancellation and every sample are kept in `detection_data` for the compliance map.
3. `SanctionService` escalates based on the driver's flags in the last **30 days**:

| Tier | Sanction | Lifted |
|------|----------|--------|
| L1 | Warning (push) | — |
| L2 | Forced offline + suspended | Automatically after 48 h (`LiftExpiredComplianceSuspensionsJob`, hourly). Suspensions set by admins for other reasons are never lifted automatically. |
| L3 | Forced offline + suspended pending review | Operations confirms permanent deactivation, with a reason — not while the driver's dispute is pending or after it was overturned. Confirming upholds an unreviewed flag in the same step. The driver first gets 48 h to dispute (`OFFLINE_DEACTIVATION_RESPONSE_HOURS`); no uphold or confirmation before then unless they dispute (a super admin may confirm early) |

An admin can also move a flag straight to L3 by verifying that the driver took cash for the trip (`verify-cash-taking`).

Drivers can dispute a flag; operations upholds or overturns it. An upheld flag can still be overturned, escalated or de-escalated on appeal; an overturn is final. Overturning the driver's latest flag sets their status again from their remaining flags (`SanctionService::applyStandingSanction`). Undoing a confirmed deactivation takes a super admin overturning the flag (OQ-14), which reactivates the driver.

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
- `FirebasePushGateway` sends `data` values as strings (FCM requires it): booleans as `"true"` / `"false"`, arrays as JSON. Tokens FCM reports as unknown or invalid are deactivated. A push some device did not get is logged as `Push not delivered to every device` (counts and error types only, since Firebase's messages quote the token), and one skipped because the user has no active device as `Push skipped: user has no active device`.
- In-app notifications older than **90 days** are pruned daily (`NOTIFICATION_RETENTION_DAYS`).
- **Email** (Resend) covers admin invitations, password resets, welcome and ride receipts. Every queued mail notification uses `ThrottlesMail`: the `mail` rate limiter (`MAIL_RATE_LIMIT_PER_SECOND`, default 8; Resend allows 10) holds a burst back instead of letting Resend refuse it, and a failed send is retried for an hour (backoff 5 s, 30 s, 2 min). A test fails if a queued mail notification lacks it. Templates live in `resources/views/vendor/mail/`; `php artisan mail:test` sends samples.

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
- Queues run synchronously in tests. `phpunit.xml` blanks `SENDCHAMP_ACCESS_KEY`, so no test reaches Sendchamp unless it fakes HTTP (`SendchampGatewayTest`). External providers are faked through their contracts (`Mockery::mock(WalletGateway::class)`, `FakePaymentGateway`, `FakeKycGateway`, `LogPushGateway`).
- Tests that need Redis (GEO search) are skipped when the `phpredis` extension is missing.
- When switching bearer tokens within one test, call `app('auth')->forgetGuards()` (Sanctum caches the resolved user).
- Scheduled rides: `ScheduledRideTest` (booking limits, future-time surge and promo, edit/cancel, dispatch and its failures, reminders, admin settings) `ScheduledRideReservationTest` (driver reservations, dispatch to the reserved driver, admin assignment, reservation fee) and `ScheduledRideFeesAndBalanceDueTest` (late-cancellation fee, balance due, wallet shortfall).
- Third-party bookings: `ThirdPartyBookingTest` (rules and limits, stale searches, policy snapshot, rider messages and SMS fallback, link stripping, delivery failure and PIN fallback, PIN resend, who sees what, tracking page, scheduled third-party rides and edits, SOS position, admin settings, app config).
- Lost & Found: `LostAndFoundTest` (report rules and window, one open report per trip, photos, contact number SMS, unreachable driver, driver hand-in, third-party rider name, driver answers, contact sharing, return and confirmation, cancel and escalate, messages and internal notes, visibility, admin queue and actions, return fee from wallet / balance due / waived / once per trip, hourly sweep and its notifications, settings, app config).
- Disputes: `DisputeTest` (filing, admin list and filters, resolve, wallet and card refunds) and `AdvancedDisputesTest` (routing and safety fast track, SOS link, linked cases, visibility and tier permissions, taking and assigning, evidence lock and audit, evidence uploads, escalation and de-escalation, refund limits and approvals, target sweep and alerts, appeals and reviewer exclusion, admin deactivation and role change, reporter view, settings).
- Driver scheduling and vehicle maintenance: `DriverSchedulingTest` (shift mode, rota and overlaps, overnight shifts, limits, edits and cancellation, go-online window and car handover, offer cut-off in matching, missed / ending / late-handover / shift-end sweep, back-to-back shifts, reservations inside shifts, availability plans and coverage, settings) and `VehicleMaintenanceTest` (items by fuel type, driver records with proof and approval, odometer rules, km and date due, trip distance, grace warning, block and its side effects, renewal lifting it, no trip interrupted, matching and manual assignment, reminders once and the operations digest, expiry rules, admin papers and records, unlinked papers, charge level, settings).
- Edge-case suites per area: `RideEdgeCaseTest`, `MatchingEdgeCaseTest`, `WalletPayoutEdgeCaseTest`, `AccountSafetyEdgeCaseTest`, `OperationsEdgeCaseTest`, `ApiErrorHandlingTest`.
- CI (`.github/workflows/staging.yml`) runs the suite on PHP 8.5 with PostgreSQL 16 and Redis 7 for every pull request to `staging`; it must pass before a push to `staging` deploys.

---

## 18. Environment variables

The complete list is in `.env.example`. The ones that change behaviour:

| Area | Variables |
|------|-----------|
| Sessions | `SANCTUM_TOKEN_EXPIRATION` (admin, min), `SANCTUM_APP_TOKEN_EXPIRATION` (apps, min) |
| OTP / SMS / WhatsApp | `SENDCHAMP_ACCESS_KEY`, `SENDCHAMP_SENDER_NAME` (approved sender; default `Sendchamp`), `SENDCHAMP_SMS_ROUTE` (`dnd`), `SENDCHAMP_WHATSAPP_SENDER`, `SENDCHAMP_WHATSAPP_TEMPLATE`, `SENDCHAMP_WHATSAPP_OTP_TEMPLATE`, `SENDCHAMP_BASE_URL`, `ETIGO_SAFETY_NUMBER` (SOS alerts), `ETIGO_SAFETY_LINE` (shown to riders; falls back to `ETIGO_SAFETY_NUMBER`), `SOS_EMERGENCY_NUMBER` (112) |
| Maps & places | `GOOGLE_MAPS_API_KEY`, `MAPBOX_ACCESS_TOKEN`, `GEOAPIFY_API_KEY`, `LOCATIONIQ_API_KEY`, `PLACE_SEARCH_DRIVERS`, `PLACE_SEARCH_COUNTRY` |
| Payments | `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_FAKE`, `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_WEBHOOK_SECRET` |
| Wallet | `WALLET_MIN_TOPUP`, `WALLET_MAX_BALANCE`, `WALLET_DAILY_TOPUP_CAP`, `WALLET_MIN_PAYOUT`, `WALLET_SETTLEMENT_DELAY`, `WALLET_MAX_NEGATIVE_BALANCE`, `WALLET_DEFAULT_COMMISSION_RATE`, `WALLET_HOLD_EXPIRY_HOURS`, `WALLET_MAX_CASH_OVERPAYMENT`, `WALLET_BLOCK_BOOKING_WITH_BALANCE_DUE` (kobo; finance can override most at runtime) |
| Matching | `MATCHING_RADIUS_TIER_1_KM`/`_2_KM`/`_3_KM`, `MATCHING_DRIVER_RESPONSE_TIMEOUT`, `MATCHING_TIMEOUT`, `MATCHING_MAX_AUTO_RETRIES`, `MATCHING_PRIORITY_EXTRA_RETRIES`, `MATCHING_AUTO_RETRY_DELAY`, `MATCHING_MAX_CANDIDATES` |
| Surge | `SURGE_DEMAND_WINDOW_MINUTES` (10), `SURGE_DEMAND_MIN_REQUESTS` (5), `SURGE_DEMAND_CACHE_SECONDS` (60) |
| Scheduled rides | `SCHEDULED_RIDES_DRIVER_RESERVATIONS`, `SCHEDULED_RIDES_MAX_RESERVATIONS_PER_DRIVER` (5), `SCHEDULED_RIDES_DRIVER_GAP_MINUTES` (60), `SCHEDULED_RIDES_DRIVER_BUFFER_MINUTES` (60), `SCHEDULED_RIDES_RESERVATION_FEE` (0), `SCHEDULED_RIDES_LATE_CANCELLATION_FEE_BASIS` (`economy_base_fare`), `SCHEDULED_RIDES_LATE_CANCELLATION_FEE_CLASS` (`economy`), `SCHEDULED_RIDES_LATE_CANCELLATION_FEE` (0, flat basis only), `SCHEDULED_RIDES_LATE_CANCELLATION_WINDOW_MINUTES` (30), `SCHEDULED_RIDES_ENABLED`, `SCHEDULED_RIDES_MIN_LEAD_MINUTES` (30), `SCHEDULED_RIDES_MAX_ADVANCE_DAYS` (3), `SCHEDULED_RIDES_DISPATCH_LEAD_MINUTES` (15), `SCHEDULED_RIDES_REMINDER_MINUTES` (60), `SCHEDULED_RIDES_MATCHING_GRACE_MINUTES` (10), `SCHEDULED_RIDES_RETRY_WAVE_DELAY_SECONDS` (30), `SCHEDULED_RIDES_MAX_UPCOMING` (3), `SCHEDULED_RIDES_MIN_GAP_MINUTES` (30) — operations can override at runtime |
| Third-party bookings | `THIRD_PARTY_BOOKINGS_ENABLED`, `THIRD_PARTY_PIN_RECIPIENT` (`both`), `THIRD_PARTY_TRACKING_RECIPIENT` (`both`), `THIRD_PARTY_ALLOW_CASH` (false), `THIRD_PARTY_ALLOW_BANK_TRANSFER` (false), `THIRD_PARTY_MAX_ACTIVE_PER_BOOKER` (2), `THIRD_PARTY_MAX_RIDER_NUMBERS_PER_DAY` (10), `THIRD_PARTY_RIDER_TRIP_UPDATES` (true) — operations can override at runtime; `THIRD_PARTY_TRACKING_URL` (env only) |
| Lost & Found | `LOST_AND_FOUND_ENABLED`, `LOST_AND_FOUND_REPORT_WINDOW_DAYS` (30), `LOST_AND_FOUND_MAX_OPEN_REPORTS` (3), `LOST_AND_FOUND_DRIVER_RESPONSE_HOURS` (24), `LOST_AND_FOUND_RETURN_DEADLINE_DAYS` (7), `LOST_AND_FOUND_AUTO_RESOLVE_HOURS` (72), `LOST_AND_FOUND_DENIED_AUTO_CLOSE_DAYS` (7), `LOST_AND_FOUND_SHARE_CONTACT_DETAILS` (true), `LOST_AND_FOUND_RETURN_FEE` (0) — operations can override at runtime |
| Disputes | `DISPUTES_TIER_1_REFUND_LIMIT` (5000), `DISPUTES_TIER_2_REFUND_LIMIT` (50000), `DISPUTES_SAFETY_REFUND_LIMIT` (50000), `DISPUTES_SAFETY_FIRST_RESPONSE_MINUTES` (60), `DISPUTES_SAFETY_RESOLUTION_HOURS` (24), `DISPUTES_TIER_1_FIRST_RESPONSE_HOURS` (24), `DISPUTES_TIER_1_RESOLUTION_HOURS` (72), `DISPUTES_TIER_2_FIRST_RESPONSE_HOURS` (24), `DISPUTES_TIER_2_RESOLUTION_HOURS` (120), `DISPUTES_AUTO_ESCALATE_TIER_1` (true), `DISPUTES_APPEAL_WINDOW_DAYS` (7), `DISPUTES_MAX_EVIDENCE_FILES` (10) — super admins can override at runtime |
| Driver scheduling | `DRIVER_SHIFTS_ENABLED` (true), `DRIVER_SHIFT_ONLINE_LEAD_MINUTES` (15), `DRIVER_SHIFT_OFFER_CUTOFF_MINUTES` (15), `DRIVER_SHIFT_MISSED_AFTER_MINUTES` (30), `DRIVER_SHIFT_MIN_MINUTES` (60), `DRIVER_SHIFT_MAX_HOURS` (12), `DRIVER_SHIFT_MAX_ADVANCE_DAYS` (30), `DRIVER_AVAILABILITY_MAX_ADVANCE_DAYS` (14), `DRIVER_AVAILABILITY_MIN_MINUTES` (30), `DRIVER_AVAILABILITY_MAX_HOURS` (12), `DRIVER_AVAILABILITY_MAX_UPCOMING` (42) — operations can override at runtime |
| Vehicle maintenance | `VEHICLE_MAINTENANCE_ENABLED` (true), `VEHICLE_MAINTENANCE_DUE_SOON_DAYS` (14), `VEHICLE_MAINTENANCE_DUE_SOON_KM` (500), `VEHICLE_MAINTENANCE_LEGAL_GRACE_DAYS` (7), `VEHICLE_MAINTENANCE_BLOCK_ON_EXPIRED_PAPERS` (true); intervals in `config/vehicle_maintenance.php` — operations can override everything at runtime |
| Multi-city | `CITY_LAUNCH_MIN_APPROVED_DRIVERS` (30), `CITY_REQUIRE_EV_CHARGING` (true), `CITY_PAUSE_SCHEDULED_RIDES` (`honour`) — operations can override at runtime |
| Upfront fares | `PRICING_TRIP_TIME_TOLERANCE_PCT` (25), `PRICING_TRIP_TIME_TOLERANCE_MIN_MINUTES` (10), `PRICING_MAX_BILLED_TIME_MULTIPLIER` (3) |
| KYC | `QOREID_CLIENT_ID`, `QOREID_SECRET_KEY`, `KYC_SKIP_VERIFICATION` |
| Push | `FIREBASE_CREDENTIALS_PATH` (path or raw JSON), `FIREBASE_PROJECT_ID` |
| Real-time | `BROADCAST_CONNECTION=reverb`, `REVERB_*`, `TELEMETRY_SERVER_PORT`, `TELEMETRY_RATE_LIMIT_MS`, `REDIS_GEO_DB` |
| Safety | `SOS_CHECK_IN_DELAY`, `SOS_ESCALATION_TIMEOUT`, `OFFLINE_*` (detection thresholds), `OFFLINE_SUSPENSION_HOURS`, `OFFLINE_DEACTIVATION_RESPONSE_HOURS` (48: a driver's time to dispute a Level 3 flag before it can be upheld or the deactivation confirmed) |
| Fleet / EV / promos / loyalty | `FLEET_*`, `EV_RESERVATION_FEE`, `EV_RESERVATION_EXPIRY`, `EV_RESERVATION_FREE_CANCEL_MINUTES`, `EV_MAX_QUEUE_SIZE`, `PROMO_PEAK_*`, `CARBON_BASELINE_EMISSION`, `GAMIFICATION_*` |
| Notifications | `NOTIFICATION_RETENTION_DAYS`, `NOTIFICATION_PUSH_ENABLED`, `NOTIFICATION_IN_APP_ENABLED` |
| Storage / mail | `UPLOADS_DISK` (documents and photos; `public`, `s3` or `cloudinary`), `CLOUDINARY_*`, `AWS_*`, `MAIL_*` (Resend) |

---

## 19. Known limitations

These are deliberate gaps that need a product decision or data, not bugs:

| Area | Status |
|------|--------|
| Demand-based surge | Measured per city (and class), not per zone inside a city; supply uses the driver's registered city |
| Final fare distance | The quoted route distance is charged; there is no GPS trail, so a detour the passenger asks for isn't billed |
| Shared journey points | The 1.5× `shared_journey` multiplier can be configured but is never awarded until pooled rides exist (Phase 2); the admin multipliers endpoint returns `is_awarded: false` for it |
| Existing vehicles' fuel type | Vehicles created before fuel type existed stay `null` until the driver or operations sets it (run `vehicles:request-fuel-types` once to ask them) |
| Booking latency | The maps call for the route runs inside the booking transaction (passenger row lock); fine at current volume, worth moving out if booking contention appears |
| Third-party delivery receipts | `rider_notification_status` stops at `sent_whatsapp` / `sent_sms`: Sendchamp delivery reports (dashboard webhook) are not consumed, so `delivered` is never set. Messages are plain text (no driver photo in the message; the tracking page shows it) |
| WhatsApp setup | WhatsApp needs a WhatsApp number on Sendchamp and two Meta-approved templates (notification and OTP). Until their codes are set, every message goes by SMS. Number validation (`/whatsapp/validate`) is not called before sending: WhatsApp refusing the message already triggers the SMS fallback |
| Third-party rating | The driver's rating of the trip applies to the booker's account (the rider has none), as on Uber |
| Cities abroad | Only Lagos time and naira are accepted: payout days, promo peaks, off-peak windows and fleet business days are still Lagos-time rules |
| Waitlist reach | Waitlist passengers are told by push and in-app notification only (no SMS or email); a passenger without the app installed is not reached |
| Shared fleet cars | A driver drives one car on E-tiGo at a time, so a driver with their own car cannot also take shifts on a shared car; shift cars cannot be on hire purchase |
| Odometer and charge level | Entered by the driver or operations (no telematics). The km estimate counts the quoted trip distance only, not driving to pickups or off the platform, which is why the time limits also apply |
| Boundary drawing | Overlap checks use a flat projection (accurate at city scale) and ignore polygon holes; there is no map editor in the API, so shared borders must be drawn with the same points (10 m tolerance) |
