# E-tiGo — Backend Task Breakdown

**Source:** PRD v3.0 (Phase 1 & Phase 2) + Product Brief  
**Scope:** API server, database, integrations, background services — everything consumed by Passenger App, Driver App, and Admin Dashboard  
**Phase 1 (Weeks 1–8):** Frozen scope  
**Phase 2 (Weeks 9–12):** Working draft — must be reviewed and re-frozen before Week 9

> **Legend**
> - `[ ]` To Do
> - `[~]` In Progress
> - `[x]` Done
> - ⚠ = Open question that must be resolved before building
> - 🔒 = Security-sensitive — requires review before deployment
> - ⏱ = Performance-critical — requires benchmarking

> **Implementation notes (Phase 1 audit, 15 Oct 2026).** Task rows keep their original planned wording. Where the build differs, the current behaviour is below. The [API reference](../API_REFERENCE.md) is the source of truth for endpoints and the [technical documentation](../TECHNICAL.md) for design.
>
> - **Auth:** passengers and drivers sign in with **phone OTP** (Termii → WhatsApp, SMS fallback) or social login: `/auth/otp/send`, `/auth/otp/verify`, `/auth/register/complete`. Email/password is for admins only. The planned email/password register/login tasks (BE-AUTH-02–04) were superseded.
> - **Payments:** **Paystack** is the primary provider for everything. Flutterwave is a failover for ride card/bank-transfer payments only. Wallet top-ups, bank resolution and payout transfers use Paystack (`PaystackWalletGateway`). Rows that say Flutterwave describe the original plan.
> - **Paths:** wallet `/passenger/wallet/*` (top-up verify takes the `tx_ref`: `/passenger/wallet/topup/{reference}/verify`); webhooks `/webhooks/paystack`, `/webhooks/flutterwave`, `/webhooks/qoreid`; EV reservations `/driver/ev-reservations/*`; driver ledger `/driver/ledger/summary` and `/driver/ledger/transactions`.
> - **Ride PIN:** 4 digits, valid 6 hours (a pickup can happen long after booking), 3 attempts, and the passenger can regenerate it until the trip starts.
> - **Matching:** radius tiers 3 → 7 → 15 km, 20 s offers, one automatic retry wave after 10 s, overall timeout 300 s per search. Stale drivers (no location for 2 min) and drivers holding another offer are skipped.
> - **Payouts:** on-demand with finance approval, plus an opt-in weekly auto-payout (`weekly_auto`, Monday 06:00 Lagos).
> - **Product decisions taken (15 Oct 2026):** EV reservations are limited to plug-in vehicles via a new vehicle `fuel_type` (BE-EV-09/10); the EV reservation fee is collected from driver earnings when a stall is held (refund within 2 minutes, kept on no-show); priority matching (G-05) keeps distance first and gives Gold+ passengers precedence for scarce drivers plus an extra retry wave; wallet rides can be tipped from the wallet. Payouts whose outcome is unknown are reconciled against Paystack (scheduled + finance "check status"). Follow-ups (16 Oct 2026): demand-based surge measures live demand/supply per city and class; bank-transfer rides can be tipped from the wallet or card; fuel type is required for new vehicles, and `vehicles:request-fuel-types` asks owners of older vehicles to set it; payouts held by Paystack for an OTP are flagged at once.
> - **Phase 2 — Scheduled rides (16 Oct 2026):** built (section 23). A new `scheduled` ride status that is not "active"; booking via `POST /rides` + `scheduled_pickup_at`; drivers can reserve bookings in advance and operations can pre-assign; minute-by-minute dispatcher; reservation and late-cancellation fees built but off; operations settings at `/admin/settings/scheduled-rides`. Decisions: `doc/client/SCHEDULED_RIDES_DECISIONS.md`.
> - **Phase 2 — open questions OQ-18 to OQ-22 (16 Oct 2026):** answered with engineering recommendations, not yet client-confirmed; the task rows in sections 24, 26, 27 and 28 now follow them. Third-party PIN and tracking to both rider and booker; vehicle maintenance for all vehicles plus EV items by fuel type; voluntary availability planning plus shifts for shared fleet cars only; three dispute tiers with a safety fast track; cities gated by a readiness checklist, dates for the client. Decisions: `doc/client/PHASE2_DECISIONS.md`.
> - **Phase 2 — Third-party bookings (18 Oct 2026):** built (section 24) to the OQ-18 recommendation. The booker stays the ride's passenger; the rider gets the driver details, PIN and a no-app tracking link (`/track/{ride}/{token}`) by WhatsApp with SMS fallback; the booker sees the PIN and tracking per a policy saved on each ride, and always when the rider's message fails. Wallet/card only by default; 2 active third-party rides and 10 rider numbers per day per booker. Operations settings at `/admin/settings/third-party`.
> - **Phase 2 — Lost & Found (18 Oct 2026):** built (section 25). The passenger reports within 30 days and the driver answers. The two arrange the return through messages and, once the driver has the item, each other's number. Support steps in on request, after 24 h of driver silence or a 7-day-late return; an undisputed return resolves after 72 h. Drivers can hand in found items. The return fee is built but off (OQ-36). Operations settings at `/admin/settings/lost-and-found`.
- **Audit fixes** (branch `fix/phase1-audit-hardening`): bugs and edge cases fixed across rides, matching, pricing, payments, wallet/ledger, payouts, refunds, webhooks, reconciliation, auth, KYC, SOS, sanctions, EV and fleet — see the commit history and the edge-case test suites listed in TECHNICAL.md §17.

---

## Table of Contents

### Phase 1 — Must-Launch Core (Weeks 1–8)

1. [Infrastructure & Project Setup](#1-infrastructure--project-setup)
2. [Authentication Service](#2-authentication-service)
3. [City & Vehicle-Class Management API](#3-city--vehicle-class-management-api)
4. [Pricing Engine](#4-pricing-engine)
5. [Ride State Machine & Core Engine](#5-ride-state-machine--core-engine)
6. [Real-Time Location Layer](#6-real-time-location-layer)
7. [Proximity-Based Matching Engine](#7-proximity-based-matching-engine)
8. [Payment & Settlement](#8-payment--settlement)
9. [Ratings & Disputes API](#9-ratings--disputes-api)
10. [Gamification & Carbon Scoring Engine](#10-gamification--carbon-scoring-engine)
11. [Promo / Discount Engine](#11-promo--discount-engine)
12. [SOS Telemetry & Escalation Pipeline](#12-sos-telemetry--escalation-pipeline)
13. [Anti-Offline Trip Detection Engine](#13-anti-offline-trip-detection-engine)
14. [EV Charging Reservation System](#14-ev-charging-reservation-system)
15. [Notification Service](#15-notification-service)
16. [Admin Management API](#16-admin-management-api)
17. [Admin Reporting API](#17-admin-reporting-api)

18. [Wallet & Ledger Infrastructure](#18-wallet--ledger-infrastructure)
19. [Passenger Wallet System](#19-passenger-wallet-system)
20. [Driver Earnings Ledger & Payouts](#20-driver-earnings-ledger--payouts)
21. [Wallet Administration & Reconciliation](#21-wallet-administration--reconciliation)

22. [Fleet Agreement & Remittance Tracking](#22-fleet-agreement--remittance-tracking)

### Phase 2 — Month-3 Rollout (Weeks 9–12)

23. [Phase 2 — Scheduled Rides](#23-phase-2--scheduled-rides)
24. [Phase 2 — Third-Party Bookings](#24-phase-2--third-party-bookings)
25. [Phase 2 — Lost & Found](#25-phase-2--lost--found)
26. [Phase 2 — Advanced Disputes](#26-phase-2--advanced-disputes)
27. [Phase 2 — Multi-City Support](#27-phase-2--multi-city-support)
28. [Phase 2 — Driver Scheduling & Vehicle Maintenance](#28-phase-2--driver-scheduling--vehicle-maintenance)

### Tracking

29. [Open Questions Tracker](#29-open-questions-tracker)

---

# PHASE 1 — Must-Launch Core (Weeks 1–8)

---

## 1. Infrastructure & Project Setup

**PRD refs:** B-01, B-02, B-03, §8.4, NF-01–NF-07

### 1.1 Repository & CI/CD

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-01 | `[x]` Initialize Laravel 13 project structure with proper directory layout: `app/`, `routes/`, `database/`, `config/`, `tests/` | — | — | Laravel 13 (PHP 8.5); `.env.example` configured for PostgreSQL |
| SETUP-02 | `[x]` Configure code quality tooling: Laravel Pint for code style | — | SETUP-01 | Pint runs on CI; Pest 5.x for testing |
| SETUP-03 | `[x]` Set up CI pipeline: lint → static analysis → unit tests → feature tests → build | — | SETUP-01 | Branch protection on `main`; require passing CI before merge |
| SETUP-04 | `[x]` Configure staging and production deployment pipelines with environment-specific `.env` config | — | SETUP-03 | Include `php artisan migrate --force` step in deploy pipeline |
| SETUP-05 | `[x]` Define and document API contract: request/response shapes, enums, error formats — see `doc/technical/API_REFERENCE.md`, `doc/technical/postman_collection.json`, `doc/technical/TECHNICAL.md` | — | SETUP-01 | PHP enums for UserType, AdminRole, DriverStatus, DocumentType, DocumentStatus |

### 1.2 Database Schema & Migrations

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-06 | `[x]` Provision PostgreSQL instance (staging + production) and configure connection pooling in `config/database.php` | B-02 | SETUP-04 | — |
| SETUP-07 | `[x]` Create migration: `users` table — id, first_name, last_name, phone (unique E.164), email (unique), type (enum: passenger/driver/admin), admin_role (enum nullable), password, phone_verified_at, is_active, profile_photo_path, created_at, updated_at | B-02 | SETUP-06 | Shared table for all user types; role-specific data in separate tables |
| SETUP-08 | `[x]` Create migration: `cities` table — id, name, slug (unique), boundary (GeoJSON polygon or point+radius), timezone, currency_code, is_active, created_at, updated_at | B-02 | SETUP-06 | Top-level scoping entity |
| SETUP-09 | `[x]` Create migration: `vehicle_classes` table — id, name, display_name, capacity, icon (slug: lite/comfort/xl), description, is_active, created_at, updated_at | B-02 | SETUP-06 | Platform-wide definitions; icon is a predefined slug, not a URL |
| SETUP-10 | `[x]` Create migration: `city_vehicle_classes` pivot table — city_id (FK), vehicle_class_id (FK), is_active, unique constraint on (city_id, vehicle_class_id) | B-02 | SETUP-08, SETUP-09 | Controls which classes are available in which cities |
| SETUP-11 | `[x]` Create migration: `drivers` table — id, user_id (FK unique), city_id (FK nullable), status (enum: onboarding/pending_review/approved/rejected/suspended), licence_number (nullable), rejection_reason (nullable), is_online (bool default false), approved_at (nullable), suspended_at (nullable), created_at, updated_at. Separate `vehicles` table for vehicle data. | B-02 | SETUP-07, SETUP-09 | Default status is `onboarding`; city_id links to cities table |
| SETUP-12 | `[x]` Create migration: `driver_documents` table — id, driver_id (FK), type (enum: driving_licence/vehicle_registration/insurance_certificate/government_id), file_path, original_filename, mime_type, file_size, expires_at (date nullable), status (enum: pending/approved/rejected), rejection_reason (nullable), reviewed_by (FK nullable), reviewed_at (nullable), created_at, updated_at | B-02, NF-04 | SETUP-11 | 🔒 Files stored on S3; re-upload replaces previous; expires_at tracks document expiry |
| SETUP-13 | `[x]` Create migration: `pricing_configs` table — id, city_id (FK), vehicle_class_id (FK), base_fare, per_km_rate, per_minute_rate, minimum_fare, waiting_time_rate (nullable), version (int), effective_from (timestamp), created_by_admin_id (FK), created_at | A-06, A-07 | SETUP-08, SETUP-10 | Versioned with effective timestamp |
| SETUP-14 | `[x]` Create migration: `rides` table — id (UUID), city_id (FK), vehicle_class_id (FK), passenger_id (FK), driver_id (FK nullable), pickup_lat, pickup_lng, pickup_address, destination_lat, destination_lng, destination_address, status (enum: requested/searching/matched/driver_en_route/driver_arrived/in_progress/completed/cancelled/no_driver_found), pin_code (char 4), share_token (unique), fare_estimate_amount, final_fare_amount (nullable), fare_currency, pricing_snapshot (jsonb), payment_method (enum: cash/card), payment_status (enum), cancelled_by (FK nullable), cancellation_reason (nullable), matched_at (nullable), started_at (nullable), completed_at (nullable), created_at, updated_at | B-02, §8.2, §8.5 | SETUP-08, SETUP-10, SETUP-07, SETUP-11 | Every ride scoped to city + vehicle class from creation |
| SETUP-15 | `[x]` Create migration: `ride_state_transitions` audit table — id, ride_id (FK), from_state, to_state, triggered_by_type (enum: user/system), triggered_by_id (nullable), metadata (jsonb nullable), created_at | B-09, NF-06 | SETUP-14 | Append-only; no UPDATE or DELETE; use DB trigger or policy to enforce |
| SETUP-16 | `[x]` Create migration: `payments` table — id, ride_id (FK), amount, currency, method (enum: cash/card), gateway_transaction_id (nullable), gateway_payment_method_id (nullable), tip_amount (default 0), status (enum: pending/authorized/captured/settled/refunded/failed), failure_reason (nullable), created_at, updated_at | B-02 | SETUP-14 | — |
| SETUP-17 | `[x]` Create migration: `user_payment_methods` table — id, user_id (FK), gateway_token, card_brand, card_last_four, card_expiry_month, card_expiry_year, is_default (bool), created_at, updated_at | B-02, NF-05 | SETUP-07 | 🔒 No raw card numbers; gateway token only |
| SETUP-18 | `[x]` Create migration: `ratings` table — id, ride_id (FK), rated_by_user_id (FK), rated_user_id (FK), score (tinyint 1-5), comment (text nullable), created_at | B-02 | SETUP-14 | — |
| SETUP-19 | `[x]` Create migration: `disputes` table — id, ride_id (FK), reported_by_user_id (FK), category (enum), description (text), status (enum: open/under_review/resolved/dismissed), resolution_notes (text nullable), resolved_by_admin_id (FK nullable), created_at, updated_at, resolved_at (nullable) | B-02 | SETUP-14 | — |
| SETUP-20 | `[x]` Create migration: `audit_logs` table — id, auditable_type, auditable_id (polymorphic), event (string), actor_type, actor_id, old_values (jsonb), new_values (jsonb), ip_address, user_agent, created_at | B-09, NF-06 | SETUP-07 | Append-only; polymorphic design covers all model state changes |
| SETUP-21 | `[x]` Create migration: `promo_codes` table — id, code (unique), discount_type (enum: percentage/flat), discount_value (decimal), max_discount_cap (decimal nullable), total_redemption_limit (int nullable), per_user_limit (int default 1), starts_at, expires_at, geo_fence (jsonb nullable), min_order_count (int nullable), max_order_count (int nullable), min_tier_level (int nullable), peak_only (bool default false), off_peak_only (bool default false), is_active (bool default true), created_at, updated_at | B-02 | SETUP-06 | — |
| SETUP-22 | `[x]` Create migration: `promo_redemptions` table — id, promo_code_id (FK), user_id (FK), ride_id (FK), discount_amount (decimal), redeemed_at (timestamp), created_at | B-02 | SETUP-21, SETUP-14 | Unique constraint on (promo_code_id, user_id, ride_id) to prevent double-redeem |
| SETUP-23 | `[x]` Create migration: `gamification_profiles` table — id, user_id (FK unique), total_carbon_score (decimal default 0), total_ranking_points (int default 0), current_tier (enum/int default 1), tier_upgraded_at (nullable), created_at, updated_at | B-02 | SETUP-07 | Created when user completes first trip |
| SETUP-24 | `[x]` Create migration: `tier_configs` table — id, tier_level (tinyint 1-5 unique), tier_name, min_points_required (int), booking_fee_discount_pct (decimal default 0), ev_reservation_fee_waived (bool default false), priority_matching_enabled (bool default false), created_at, updated_at | A-17 | SETUP-06 | Seed with initial 5-tier config |
| SETUP-25 | `[x]` Create migration: `point_multiplier_configs` table — id, condition_type (enum: ev_ride/shared_journey/off_peak), multiplier_value (decimal), is_stackable (bool default false), created_at, updated_at | A-18 | SETUP-06 | Seed with initial defaults |
| SETUP-26 | `[x]` Create migration: `trip_carbon_scores` table — id, ride_id (FK), user_id (FK), distance_km (decimal), baseline_emission (decimal), vehicle_emission (decimal), co2_saved (decimal), base_points (int), multiplier_applied (decimal default 1.0), multiplier_reason (string nullable), final_points (int), created_at | B-02 | SETUP-14 | — |
| SETUP-27 | `[x]` Create migration: `sos_incidents` table — id, ride_id (FK), triggered_by_user_id (FK), trigger_type (enum: passenger/driver), status (enum: triggered/check_in_sent/acknowledged/escalated/operator_assigned/dispatched/resolved/cancelled), gps_lat (decimal), gps_lng (decimal), vehicle_details (jsonb), telemetry_data (jsonb), check_in_sent_at (nullable), check_in_acknowledged_at (nullable), escalated_at (nullable), operator_id (FK nullable), operator_notes (text nullable), resolved_at (nullable), created_at | B-02 | SETUP-14 | 🔒 Telemetry encrypted at rest |
| SETUP-28 | `[x]` Create migration: `sos_event_log` audit table — id, incident_id (FK), event_type (string), actor_id (FK nullable), metadata (jsonb nullable), created_at | NF-06 | SETUP-27 | Append-only immutable audit trail |
| SETUP-29 | `[x]` Create migration: `offline_trip_flags` table — id, ride_id (FK), driver_id (FK), passenger_id (FK nullable), detection_data (jsonb), sanction_tier (tinyint 1-3), sanction_action (string), is_disputed (bool default false), dispute_notes (text nullable), dispute_resolved_by_admin_id (FK nullable), dispute_outcome (enum: pending/upheld/overturned nullable), flagged_at, resolved_at (nullable), created_at | B-02 | SETUP-14 | — |
| SETUP-30 | `[x]` Create migration: `ev_charging_stations` table — id, name, city_id (FK), lat (decimal), lng (decimal), address, total_stalls (int), status (enum: active/inactive/maintenance), created_at, updated_at | B-02 | SETUP-08 | — |
| SETUP-31 | `[x]` Create migration: `ev_charging_stalls` table — id, station_id (FK), stall_number (int), status (enum: available/occupied/reserved/out_of_service), current_vehicle_driver_id (FK nullable), occupied_since (nullable), estimated_departure_at (nullable), updated_at | B-02 | SETUP-30 | — |
| SETUP-32 | `[x]` Create migration: `ev_reservations` table — id, stall_id (FK nullable), station_id (FK), driver_id (FK), status (enum: reserved/queued/active/completed/expired/cancelled), queue_position (int nullable), estimated_available_at (nullable), fee_amount (decimal default 0), fee_waived (bool default false), reserved_at, activated_at (nullable), completed_at (nullable), created_at | B-02 | SETUP-31 | — |
| SETUP-33 | `[x]` Create migration: `notifications` table — id, user_id (FK), type (string), title, body, data (jsonb nullable), is_read (bool default false), read_at (nullable), created_at | B-06 | SETUP-07 | — |
| SETUP-34 | `[x]` Create migration: `device_tokens` table — id, user_id (FK), platform (enum: ios/android/web), token (string), is_active (bool default true), created_at, updated_at | B-06 | SETUP-07 | Unique constraint on (user_id, platform, token) |
| SETUP-35 | `[x]` Admin roles stored in `users.admin_role` column (enum: super_admin/operations/safety_operator/support/finance). No separate admin_users table — single users table with `type=admin` + `admin_role`. Admin seeder creates accounts for all roles. | §8.4 | SETUP-07 | OQ-07 resolved: tiered roles with Finance role added |
| SETUP-36 | `[x]` Create database indexes for high-frequency queries: rides by (status, city_id), rides by (passenger_id, created_at), rides by (driver_id, created_at), drivers by (city via vehicle class, is_online, kyc_status), promo_codes by (code), gamification_profiles by (user_id), device_tokens by (user_id) | — | SETUP-14 through SETUP-34 | ⏱ Profile queries during load testing |
| SETUP-37 | `[x]` Create database seeders: `AdminSeeder` creates admin accounts for all roles (super_admin, operations, safety_operator, support, finance). Seeder skips in production. | — | SETUP-24, SETUP-25 | For development and staging environments |

### 1.3 Cache Layer (Redis)

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-38 | `[x]` Provision Redis instance (staging + production) and configure in `config/database.php` Redis connections | B-03 | SETUP-04 | — |
| SETUP-39 | `[x]` OTP/PIN codes stored in PostgreSQL `otp_codes` table (not Redis): SHA-256 hashed code, 3 max attempts, atomic increment; used for login OTPs (5-min expiry) and the ride-start PIN (6-hour expiry) | B-03 | SETUP-07 | Migration creates otp_codes table; OtpService handles generation/verification |
| SETUP-40 | `[x]` Configure Redis for session/token state (refresh token storage) | B-03 | SETUP-38 | Key pattern: `refresh_token:{token_hash}` |
| SETUP-41 | `[x]` Configure Redis for live driver-location cache using geo-indexing (GEOADD/GEORADIUS) | B-03 | SETUP-38 | Key: `driver_locations` (geo set); secondary key per driver: `driver:{id}:location` (hash with heading, speed, timestamp) |
| SETUP-42 | `[x]` Configure Redis for application caching (city configs, pricing, vehicle classes) with tagged cache and invalidation on Admin writes | B-03 | SETUP-38 | Use Laravel cache tags; short TTL with explicit invalidation |

### 1.4 API Server Bootstrap

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-43 | `[x]` Configure API route prefix `/api/v1/` with versioned route files | B-01 | SETUP-01 | Configured in `bootstrap/app.php` |
| SETUP-44 | `[x]` Implement Form Request validation with structured 422 error responses | B-01 | SETUP-43 | Laravel FormRequest classes per endpoint; format: `{ message, errors: { field: [messages] } }` |
| SETUP-45 | `[x]` Implement global exception handler with structured JSON error responses for all exception types | B-01 | SETUP-43 | Format: `{ message, error_code, details }` — never expose stack traces in production |
| SETUP-46 | `[x]` Implement request logging middleware: log method, path, status, duration, authenticated user_id | B-09 | SETUP-43 | — |
| SETUP-47 | `[x]` Implement rate limiting middleware: `throttle:5,1` on auth routes (5 requests/minute) | NF-01 | SETUP-43 | Laravel's built-in `ThrottleRequests`; applied to both `/auth` and `/admin/auth` route groups |
| SETUP-48 | `[x]` Configure CORS for Admin Dashboard and mobile app origins | B-01 | SETUP-43 | — |
| SETUP-49 | `[x]` Implement health check endpoint: `GET /api/v1/health` — returns DB and Redis connectivity status | NF-01 | SETUP-43 | Used by load balancer and monitoring |
| SETUP-50 | `[x]` Implement API resources for consistent response enveloping: UserResource, DriverResource, DriverDocumentResource, VehicleResource | B-01 | SETUP-43 | Laravel API Resources |

### 1.5 Authentication & Access Control

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-51 | `[x]` Implement Sanctum token authentication guard: `auth:sanctum` middleware | B-01 | SETUP-43 | Laravel Sanctum personal access tokens; shared across Passenger, Driver, Admin clients |
| SETUP-52 | `[x]` Implement RBAC middleware: `EnsureUserType` (comma-separated types), `EnsureAdminRole` (comma-separated roles), `EnsureDriverApproved` | §8.4 | SETUP-35, SETUP-51 | Role-based permissions enforced per admin route group |
| SETUP-53 | `[x]` Implement route-level permission guards: `user.type:admin`, `user.type:driver`, `user.type:passenger`, `admin.role:{roles}`, `driver.approved` | §8.4 | SETUP-52 | Applied as route middleware aliases in `bootstrap/app.php` |
| SETUP-53b | `[x]` Apply role-based middleware to all admin route groups: drivers (operations,safety_operator), cities (operations), vehicle-classes (operations), pricing (operations,finance), surge-rules (operations), rides (operations), fleet-agreements (operations,finance), gamification (operations), passengers (support,safety_operator), disputes (support), admins (super_admin) | §8.4 | SETUP-52, SETUP-35 | Super admin bypasses all role checks automatically |
| SETUP-54 | `[x]` Implement `AuditLog::record()` static method for immutable event logging: captures model, event, actor, old/new values, IP, user agent | B-09, NF-06 | SETUP-20, SETUP-52 | Used across all controllers; admin actions wrapped in DB::transaction() |

### 1.6 Third-Party Integration Adapters

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-55 | `[x]` Define `SmsGateway` contract interface: `send(string $phone, string $message): bool` | B-05 | SETUP-43 | Adapter pattern for swappable SMS providers |
| SETUP-56 | `[x]` Implement `LogSmsGateway` adapter (logs to channel instead of sending SMS); bound in `AppServiceProvider` | B-05 | SETUP-55 | Swap for Twilio/etc. by implementing `SmsGateway` interface |
| SETUP-57 | `[x]` Define push notification adapter interface: `sendToUser(userId, notification): void`, `sendToDevice(token, payload): void` | B-06 | SETUP-43 | — |
| SETUP-58 | `[x]` Implement concrete push notification adapter (FCM + APNs) | B-06 | SETUP-57 | — |
| SETUP-59 | `[x]` Define maps adapter interface: `geocode(address)`, `reverseGeocode(lat, lng)`, `autocomplete(query)`, `directions(origin, destination)`, `distanceMatrix(origins, destinations)` | B-07 | SETUP-43 | ⚠ OQ-02: Provider TBD; track per-call cost |
| SETUP-60 | `[x]` Implement concrete maps adapter for the confirmed provider | B-07 | SETUP-59 | — |
| SETUP-61 | `[x]` Define payment gateway adapter interface: `createCustomer(userId)`, `initializePayment(data)`, `verifyTransaction(transactionId)`, `chargeWithToken(data)`, `refund(transactionId, amount)` | B-08 | SETUP-43 | ⚠ OQ-03: Provider TBD. 🔒 No raw card data stored; tokenisation only (NF-05) |
| SETUP-62 | `[x]` Implement concrete payment gateway adapter for the confirmed provider | B-08, NF-05 | SETUP-61 | — |

---

## 2. Authentication Service

**PRD refs:** P-01, P-02, P-03, D-01, B-01

> **Note:** Passengers and drivers authenticate with phone OTP (plus social login); admins use email/password. `OtpService` also issues the 4-digit ride-start PIN (§9.1). BE-AUTH-02–04 below describe the original email/password plan, which was superseded.

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-AUTH-01 | `[x]` Create `AuthController@register` — `POST /api/v1/auth/register`: accept first_name, last_name, phone (E.164), email, type (passenger/driver), city_id (required for drivers); create User; auto-create Driver record for drivers with `onboarding` status and city; issue Sanctum token | P-01, D-01 | SETUP-07, SETUP-11 | Rate-limited via `throttle:5,1`; validates unique email + phone; city_id validated against cities table |
| BE-AUTH-02 | `[x]` Create `RegisterRequest` FormRequest — validate first_name, last_name, phone (E.164 regex, unique), email (unique), password (min:8, confirmed), type (passenger/driver) | P-01 | BE-AUTH-01 | Custom error messages for phone format and uniqueness |
| BE-AUTH-03 | `[x]` Create `AuthController@login` — `POST /api/v1/auth/login`: validate email + password + type against users table; reject wrong type, inactive accounts; issue Sanctum token with type-scoped abilities | P-01, P-02 | BE-AUTH-01 | Returns 401 for invalid credentials, 403 for deactivated |
| BE-AUTH-04 | `[x]` Create `LoginRequest` FormRequest — validate email, password, type (passenger/driver) | P-01 | BE-AUTH-03 | — |
| BE-AUTH-05 | `[x]` Create `AdminAuthController@login` — `POST /api/v1/admin/auth/login`: admin email/password login; token abilities include admin role (e.g. `['admin', 'super_admin']`); tokens expire after 8 hours (configurable via `SANCTUM_TOKEN_EXPIRATION`) | §8.4 | SETUP-35 | Rate-limited; admin accounts created via invitation by super admin |
| BE-AUTH-06 | `[x]` Create `AdminLoginRequest` FormRequest — validate email, password | §8.4 | BE-AUTH-05 | — |
| BE-AUTH-14 | `[x]` Create admin invitation flow: `AdminManagementController@invite` sends email with 48h-expiring signed token; `AdminInvitationController@accept` creates admin account; verify/resend/revoke endpoints | §8.4 | BE-AUTH-05 | Super admin only; cannot invite another super_admin |
| BE-AUTH-15 | `[x]` Create admin password reset: `AdminInvitationController@forgotPassword/resetPassword` using Laravel's password broker; enumeration-safe response; revokes all tokens on reset | §8.4 | BE-AUTH-05 | — |
| BE-AUTH-16 | `[x]` Create admin management CRUD: list/show/update-role/deactivate/reactivate admins; super admin only; cannot modify other super admins or self-deactivate | §8.4 | BE-AUTH-14 | Routes: `GET/PUT /admin/admins/{id}`, `POST .../deactivate`, `POST .../reactivate` |
| BE-AUTH-17 | `[x]` Super admin bypass in `EnsureAdminRole` middleware — super admins pass through all role-gated routes automatically | §8.4 | SETUP-52 | — |
| BE-AUTH-07 | `[x]` Create `AuthController@logout` and `AdminAuthController@logout` — `POST /api/v1/auth/logout` / `POST /api/v1/admin/auth/logout`: revoke current Sanctum token; audit log | P-03 | BE-AUTH-01 | — |
| BE-AUTH-08 | `[x]` Create `AuthController@me` and `AdminAuthController@me` — `GET /api/v1/auth/me` / `GET /api/v1/admin/auth/me`: return authenticated user profile via UserResource | P-03 | SETUP-51 | Used by all three apps on launch to verify session |
| BE-AUTH-09 | `[x]` Create `ProfileController@show/update` — `GET/PUT /api/v1/passenger/profile`: read and update passenger profile (first_name, last_name, email) | P-02 | BE-AUTH-08 | — |
| BE-AUTH-10 | `[x]` Create `UpdateProfileRequest` FormRequest — validate first_name, last_name, email (unique except self) | P-02 | BE-AUTH-09 | — |
| BE-AUTH-11 | `[x]` Create `User` Eloquent model with relationships: driver(); casts: type→UserType, admin_role→AdminRole, password→hashed; helpers: isPassenger(), isDriver(), isAdmin(), isSafetyOperator(), hasAdminRole() | — | SETUP-07 | — |
| BE-AUTH-12 | `[x]` Create `UserResource` API resource for consistent user serialization | — | BE-AUTH-11 | — |
| BE-AUTH-13 | `[x]` Create `OtpService` for ride-start PIN verification: 4-digit SHA-256 hashed codes, 6-hour expiry, 3 max attempts with atomic DB increment, constant-time comparison via `hash_equals()` | §9.1 | SETUP-55 | Optional but enabled by default; SMS delivery via SmsGateway contract |
| BE-AUTH-14 | `[x]` Implement social login (Google & Apple) via Laravel Socialite: `SocialProvider` enum, `SocialAccount` model, `SocialAuthService` (token-based mobile flow using `stateless()->userFromToken()`), `SocialAuthController@login`, `SocialLoginRequest` validation, `social_accounts` migration. Apple uses community provider `socialiteproviders/apple` with event listener. Users table `phone` made nullable for social-only signups. | P-01 | SETUP-07 | 8 Pest tests covering new user, existing user link, wrong type, deactivated, invalid provider |

---

## 3. City & Vehicle-Class Management API

**PRD refs:** A-01–A-05, §8.2

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-CITY-01 | `[x]` Create `City` Eloquent model with relationships: vehicleClasses(), rides(), pricingConfigs(), evStations() | — | SETUP-08 | Implemented in `App\Models\City` with factory |
| BE-CITY-02 | `[x]` Create `VehicleClass` Eloquent model with relationships: cities(), drivers(), pricingConfigs() | — | SETUP-09 | Implemented in `App\Models\VehicleClass` with factory |
| BE-CITY-03 | `[x]` Create `AdminCityController@store` — `POST /api/v1/admin/cities`: create city with name, boundary, timezone, currency; audit log | A-01 | BE-CITY-01, SETUP-52 | Admin-only; auto-generates slug from name |
| BE-CITY-04 | `[x]` Create `StoreCityFormRequest` — validate name unique, boundary format (GeoJSON), timezone, currency code | A-01 | BE-CITY-03 | Also created `UpdateCityRequest` for PUT |
| BE-CITY-05 | `[x]` Create `AdminCityController@index` — `GET /api/v1/admin/cities`: list cities with pagination, filter by is_active | A-01 | BE-CITY-01 | Supports search filter |
| BE-CITY-06 | `[x]` Create `AdminCityController@update` — `PUT /api/v1/admin/cities/{city}`: update city details; audit log | A-01 | BE-CITY-03 | — |
| BE-CITY-07 | `[x]` Create `AdminCityController@toggleStatus` — `PATCH /api/v1/admin/cities/{city}/status`: activate/deactivate; audit log | A-01 | BE-CITY-03 | — |
| BE-CITY-08 | `[x]` Create `CityController@index` — `GET /api/v1/cities`: public endpoint listing active cities with boundaries | A-01 | BE-CITY-01 | Cacheable; invalidate on Admin update |
| BE-CITY-09 | `[x]` Create `CityResource` and `VehicleClassResource` API resources | — | BE-CITY-01 | — |
| BE-CITY-10 | `[x]` Create `AdminVehicleClassController@store` — `POST /api/v1/admin/vehicle-classes`: create vehicle class with icon slug (lite/comfort/xl), optional is_active status, and optional city_ids for immediate city assignment | A-03 | BE-CITY-02, SETUP-52 | Aligned with admin dashboard design; cities attached in same transaction |
| BE-CITY-11 | `[x]` Create `AdminVehicleClassController@index` — `GET /api/v1/admin/vehicle-classes`: list all vehicle classes | A-03 | BE-CITY-10 | Supports is_active filter |
| BE-CITY-12 | `[x]` Create `AdminVehicleClassController@update` — `PUT /api/v1/admin/vehicle-classes/{vehicleClass}` | A-03 | BE-CITY-10 | — |
| BE-CITY-13 | `[x]` Create `AdminCityVehicleClassController@update` — `PUT /api/v1/admin/cities/{city}/vehicle-classes`: enable/disable classes for a city | A-04 | BE-CITY-03, BE-CITY-10 | Accepts array of {vehicle_class_id, is_active}; uses sync |
| BE-CITY-14 | `[x]` Create `CityVehicleClassController@index` — `GET /api/v1/cities/{city}/vehicle-classes`: public endpoint returning active classes for a city | A-04 | BE-CITY-13 | Filters by both pivot is_active and global is_active |
| BE-CITY-15 | `[x]` Create `VehicleClassResource` API resource | — | BE-CITY-02 | — |

---

## 4. Pricing Engine

**PRD refs:** A-06, A-07, P-06, P-07

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PRICE-01 | `[x]` Create `PricingConfig` Eloquent model with relationships: city(), vehicleClass() | — | SETUP-13 | Implemented with factory, scopes, `currentFor()`, and `toSnapshot()` |
| BE-PRICE-02 | `[x]` Create `AdminPricingController@store` — `POST /api/v1/admin/pricing`: create pricing config for city + vehicle class with effective_from; audit log | A-06 | BE-PRICE-01, SETUP-52 | Version auto-incremented; waiting_time_rate accepted as nullable |
| BE-PRICE-03 | `[x]` Create `StorePricingFormRequest` — validate city_id, vehicle_class_id, rates (positive decimals), effective_from (future timestamp) | A-06 | BE-PRICE-02 | — |
| BE-PRICE-04 | `[x]` Create `AdminPricingController@index` — `GET /api/v1/admin/pricing`: list pricing configs with filters, show current and historical versions | A-06 | BE-PRICE-01 | Includes `show` and `current` endpoints |
| BE-PRICE-05 | `[x]` Create `FareEstimationService` — accept pickup/destination coords, city, vehicle class → query maps adapter for distance + duration → apply formula: `max(minimum_fare, base_fare + (distance_km × per_km_rate) + (duration_min × per_minute_rate))` → return estimate | P-06 | BE-PRICE-01, SETUP-59 | Uses `MapsGateway` contract; current impl: `HaversineMapsGateway` (swap for real provider) |
| BE-PRICE-06 | `[x]` Create `RideEstimateController` — `POST /api/v1/rides/estimate`: accept pickup, destination, city_id → return fare estimates for all active vehicle classes | P-06 | BE-PRICE-05 | Invokable controller |
| BE-PRICE-07 | `[x]` Create `EstimateRideFormRequest` — validate pickup/destination coordinates, city_id exists and is active | P-06 | BE-PRICE-06 | Validates lat/lng ranges |
| BE-PRICE-08 | `[x]` Implement pricing snapshot capture: when a ride is created, snapshot the active pricing config as jsonb on the ride record | A-07 | BE-PRICE-01 | `PricingConfig::toSnapshot()` + `PricingConfig::currentFor()` |
| BE-PRICE-09 | `[x]` Create `PricingResource` API resource | — | BE-PRICE-01 | Conditional relationship loading |
| BE-PRICE-10 | `[x]` Create `PricingConfigSeeder` with recommended defaults (Abuja/Lagos rates: Base ₦600, Per-km ₦250, Per-min ₦40, Min ₦1,500, Wait ₦50/min) | — | BE-PRICE-01 | Skips production; seeds per city + vehicle class |

### Surge Pricing

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-SURGE-01 | `[x]` Create `SurgeType` enum (`manual`, `time_based`, `demand_based`) | — | — | Backed PHP enum |
| BE-SURGE-02 | `[x]` Create migration: `surge_rules` table — city_id (FK), vehicle_class_id (FK nullable), name, type, multiplier (3,2), conditions (jsonb), priority, is_active, effective_from, effective_until (nullable), created_by_admin_id (FK), timestamps | — | SETUP-08 | Multiplier capped 1.00–5.00 |
| BE-SURGE-03 | `[x]` Create `SurgeRule` Eloquent model with relationships: city(), vehicleClass(), scopes: active(), forCity(), forVehicleClass() | — | BE-SURGE-02 | With factory including `timeBased()`, `demandBased()`, `manual()`, `inactive()`, `expired()`, `future()` states |
| BE-SURGE-04 | `[x]` Create `SurgePricingService` — evaluate active surge rules by priority, match conditions (manual=always, time_based=schedule, demand_based=ratio threshold), return highest-priority matching multiplier | — | BE-SURGE-03 | Demand = distinct passengers booking in the last 10 min; supply = online, idle, freshly located drivers; `min_requests` guard (default 5); cached 60 s |
| BE-SURGE-05 | `[x]` Integrate surge into `FareEstimationService` — apply surge multiplier after base fare calculation; include `surge` object in estimate responses | — | BE-SURGE-04, BE-PRICE-05 | Formula: `final_fare = base_fare × surge_multiplier` |
| BE-SURGE-06 | `[x]` Create `AdminSurgeRuleController` — full CRUD (`POST/GET/PUT /admin/surge-rules`), toggle status (`PATCH /{id}/status`), current multiplier (`GET /current-multiplier`) | — | BE-SURGE-04 | Includes audit logging for create/update/toggle |
| BE-SURGE-07 | `[x]` Create `StoreSurgeRuleRequest` and `UpdateSurgeRuleRequest` — validate type enum, multiplier range (1.00–5.00), conditions by type (days_of_week + times for time_based, min_ratio for demand_based) | — | BE-SURGE-06 | — |
| BE-SURGE-08 | `[x]` Create `SurgeRuleResource` API resource | — | BE-SURGE-03 | Conditional relationship loading |

### Pricing Edge Cases

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PRICE-11 | `[x]` Cross-city ride detection — detect destination city via `CityDetectionService`, return `warnings` and `cross_city` object in estimate response, apply pickup city pricing | — | BE-PRICE-05 | Industry standard: pickup city pricing applies |
| BE-PRICE-12 | `[x]` Inactive city validation — `EstimateRideRequest` rejects inactive cities with 422 | — | BE-PRICE-07 | Prevents estimates for decommissioned cities |
| BE-PRICE-13 | `[x]` Same pickup/destination rejection — `EstimateRideRequest` rejects identical coordinates with 422 | — | BE-PRICE-07 | Catches user input errors before maps API call |
| BE-PRICE-14 | `[x]` Long-distance ride warning — routes > 100 km include a warning in estimate response | — | BE-PRICE-05 | Fare still calculated; warning for mobile app display |
| BE-PRICE-15 | `[x]` Extract `CityDetectionService` — reusable service for city-from-coordinates detection (reverse geocode + boundary matching), used by both `CityController` and `FareEstimationService` | — | BE-CITY-01 | Replaces duplicated detection logic in CityController |

---

## 5. Ride State Machine & Core Engine

**PRD refs:** §8.2, §8.5, §9.1, §9.4, P-08–P-10, P-14, D-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RIDE-01 | `[x]` Create `Ride` Eloquent model with relationships: city(), vehicleClass(), passenger(), driver(), stateTransitions(), payment(), ratings(), disputes(), cancelledByUser(); RideStatus, PaymentMethod, PaymentStatus, CancellationReason enums; factory with state helpers | — | SETUP-14 | Includes isActive(), isTerminal(), isCancellable() helpers |
| BE-RIDE-02 | `[x]` Create `RideStateMachine` service with explicit valid transitions map; reject invalid transitions; write every transition to `ride_state_transitions`; auto-set timestamps (matched_at, started_at, completed_at) | §8.5, §9.4 | BE-RIDE-01, SETUP-15 | Valid: Requested→Searching, Searching→Matched/No_Driver_Found, Matched→Driver_En_Route/Cancelled, etc. |
| BE-RIDE-03 | `[x]` Create `RideController@store` — `POST /api/v1/rides`: validate pickup/destination, active city check, same-location rejection, vehicle class availability, set status=Requested, snapshot pricing, generate 4-digit PIN, generate share_token, transition to Searching | P-08 | BE-RIDE-02, BE-PRICE-08, BE-CITY-14 | Prevents duplicate active rides per passenger |
| BE-RIDE-04 | `[x]` Create `StoreRideFormRequest` — validate pickup/destination coords, city_id, vehicle_class_id, payment_method; after() validates city active, same-location, city-vehicle-class availability | P-08 | BE-RIDE-03 | — |
| BE-RIDE-05 | `[x]` Implement `RidePinService` — generate random 4-digit numeric PIN on ride creation; validate driver PIN entry via OtpService; 3 max attempts with atomic increment | §9.1, P-14, D-09 | BE-RIDE-03 | Hard gate — trip cannot start without valid PIN |
| BE-RIDE-06 | `[x]` Create `RideController@cancel` — `POST /api/v1/rides/{ride}/cancel`: validate cancellation is allowed for current state (isCancellable check), transition to Cancelled, audit log | P-10 | BE-RIDE-02 | ⚠ OQ-05: Cancellation fee logic TBD |
| BE-RIDE-07 | `[x]` Create `RideController@driverArrived` — `POST /api/v1/rides/{ride}/driver-arrived`: validate driver is assigned, status is driver_en_route, transition to Driver_Arrived | D-09 | BE-RIDE-02 | — |
| BE-RIDE-08 | `[x]` Create `RideController@verifyPin` — `POST /api/v1/rides/{ride}/verify-pin`: validate PIN via RidePinService, on match transition to In_Progress, on mismatch return error | §9.1, D-09 | BE-RIDE-05 | — |
| BE-RIDE-09 | `[x]` Create `RideController@complete` — `POST /api/v1/rides/{ride}/complete`: transition to Completed, dispatch FinalFareCalculationJob, audit log | D-09 | BE-RIDE-02 | CarbonScoreJob and PaymentCaptureJob to be wired when those modules are built |
| BE-RIDE-10 | `[x]` Create `FinalFareCalculationJob` — use maps adapter for distance/duration × pricing snapshot to compute final fare; includes waiting time charges; update ride record | P-06 | BE-RIDE-09, BE-PRICE-05 | 3 retries, 30s backoff |
| BE-RIDE-11 | `[x]` Create `RideController@show` — `GET /api/v1/rides/{ride}`: return full ride details with state transitions; scope by role (passenger sees theirs, driver sees assigned, admin sees any) | — | BE-RIDE-01 | — |
| BE-RIDE-12 | `[x]` Create `RideController@index` — `GET /api/v1/rides`: list rides with filters (status, date range, city) and pagination; scope by role | P-19, D-11 | BE-RIDE-01 | — |
| BE-RIDE-13 | `[x]` Create `RideShareController@show` — `GET /api/v1/rides/{ride}/share/{token}`: no-auth, read-only endpoint returning trip status, limited driver info, vehicle class | P-13 | BE-RIDE-01 | Share token expires after trip completion + 1 hour (returns 410) |
| BE-RIDE-14 | `[x]` Create `RideResource`, `RideDetailResource`, `RideShareResource`, `RideStateTransitionResource` API resources | — | BE-RIDE-01 | PIN only shown to passenger (or admin in detail view) |
| BE-RIDE-15 | `[x]` Create `RideStateTransition` Eloquent model with RideStatus casts, ride() and triggeredBy() relationships | — | SETUP-15 | — |

---

## 6. Real-Time Location Layer

**PRD refs:** §8.3, B-04, A-08, A-09, NF-03

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-LOC-01 | `[x]` Create `DriverLocationController@update` — `POST /api/v1/driver/location`: receive lat, lng, heading, speed, timestamp; store in Redis GEOADD via `DriverLocationService`; rate-limit to max 1/second via `RateLimiter` | §8.3 | SETUP-41, SETUP-51 | Only accept from Online, Approved drivers; broadcasts `DriverLocationUpdated` event |
| BE-LOC-02 | `[x]` Create `LocationUpdateRequest` FormRequest — validate lat/lng bounds (-90/90, -180/180), heading (0-360), speed (>= 0), timestamp (date format) | §8.3 | BE-LOC-01 | — |
| BE-LOC-03 | `[x]` Set up Laravel Broadcasting with WebSocket server (Laravel Reverb) with Sanctum-authenticated channel subscriptions; installed `laravel/reverb`, published `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php` | B-04 | SETUP-43, SETUP-51 | Run `php artisan reverb:start` to start WebSocket server |
| BE-LOC-04 | `[x]` Define private broadcast channel `ride.{rideId}`: authorized for matched passenger and assigned driver only | B-04 | BE-LOC-03 | — |
| BE-LOC-05 | `[x]` Define private broadcast channel `admin.rides`: authorized for Admin users only; receives all active ride + driver location events | A-08 | BE-LOC-03 | — |
| BE-LOC-06 | `[x]` Create `DriverLocationUpdated` broadcastable event (`ShouldBroadcastNow`): publish driver location to ride channel (when active ride) and admin channel on each location update; custom broadcast name `driver.location.updated` | B-04 | BE-LOC-04, BE-LOC-05 | ⏱ Target latency: server-publish to client-render (NF-03) |
| BE-LOC-07 | `[x]` Create `EtaService` — on location update during active ride, recompute ETA via `MapsGateway`; throttled to every 30 seconds via cache to control API cost; returns distance_km + duration_minutes to pickup (en route) or destination (in progress) | B-04 | BE-LOC-01, SETUP-59 | — |
| BE-LOC-08 | `[x]` Create `RideLocationController@show` — `GET /api/v1/rides/{ride}/location`: fallback polling endpoint when WS drops; returns latest driver location from Redis and ETA; scoped to ride participant or admin | B-04 | BE-LOC-01 | — |

---

## 7. Proximity-Based Matching Engine

**PRD refs:** §8.6, §9.5, P-09, D-06, D-07, A-15

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-MATCH-01 | `[x]` Create `DriverMatchingService` — query Redis GEOSEARCH for Online, Approved drivers of the requested vehicle class within initial radius of pickup point; sort by distance; configurable via `config/matching.php` (initial 3km, step 2km, max 15km) | §8.6 | SETUP-41, BE-RIDE-03 | Initial radius: 3km; expansion: +2km steps; max: 15km |
| BE-MATCH-02 | `[x]` Create `DispatchRideRequestJob` — push ride request to best candidate driver via WS broadcast + push notification with pickup location, estimated fare, response countdown; auto-dispatched on ride creation | D-06 | BE-MATCH-01, BE-LOC-03, SETUP-57 | — |
| BE-MATCH-03 | `[x]` Create `RideRequestDispatched` broadcastable event for driver channel (`driver.{driverUserId}`) | D-06 | BE-MATCH-02 | — |
| BE-MATCH-04 | `[x]` Create `RideController@accept` — `POST /api/v1/rides/{ride}/accept`: driver accepts; transition to Matched → Driver_En_Route; reject concurrent acceptances atomically (row lock) | D-07 | BE-MATCH-02, BE-RIDE-02 | First-accept wins via `lockForUpdate()` |
| BE-MATCH-05 | `[x]` Create `RideController@reject` — `POST /api/v1/rides/{ride}/reject`: driver rejects; mark as rejected for this ride; dispatch to next candidate | D-07 | BE-MATCH-02 | — |
| BE-MATCH-06 | `[x]` Create `DriverResponseTimeoutJob` — delayed job (20s default, configurable via `MATCHING_DRIVER_RESPONSE_TIMEOUT`); if driver hasn't responded within the window, treat as rejection and re-dispatch | D-07 | BE-MATCH-02 | Response window: 20s (industry standard; configurable) |
| BE-MATCH-07 | `[x]` Implement radius expansion logic in `DriverMatchingService::calculateCurrentRadius()`: after exhausting candidates in the current radius tier, move to the next tier (3 → 7 → 15 km) and re-dispatch; all values configurable via `config/matching.php` | §8.6 | BE-MATCH-01 | Step: 2km; max: 15km |
| BE-MATCH-08 | `[x]` Create `MatchingTimeoutJob` — dispatched with delay on ride creation (300s default, configurable; restarted for each new search); if no match after timeout, transition to No_Driver_Found, notify passenger | P-09 | BE-MATCH-07, BE-RIDE-02 | Log no-match events for Admin reporting |
| BE-MATCH-09 | `[x]` Create `AdminRideController@assign` — `POST /api/v1/admin/rides/{ride}/assign`: Admin manually assigns an online driver; uses same state machine transitions | A-15 | BE-MATCH-04, SETUP-52 | Not a parallel path — plugs into shared state machine |
| BE-MATCH-10 | `[x]` Create `AssignRideRequest` — validate driver_id is online, approved, correct vehicle class, not on another active ride | A-15 | BE-MATCH-09 | — |

---

## 8. Payment & Settlement

**PRD refs:** P-15, P-16, B-08, NF-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PAY-01 | `[x]` Create `PaymentMethodController@store` — `POST /api/v1/payments/initialize`: Flutterwave-based card tokenization flow with initialize → verify → save token + masked details | P-15 | SETUP-61, SETUP-51 | 🔒 No raw card data stored; uses Flutterwave redirect flow |
| BE-PAY-02 | `[x]` Create `InitializePaymentRequest` — validate amount, currency, redirect_url (NOT raw card data) | P-15 | BE-PAY-01 | — |
| BE-PAY-03 | `[x]` Create `PaymentMethodController@index` — `GET /api/v1/payment-methods`: list saved methods (masked via PaymentMethodResource) | P-15 | BE-PAY-01 | — |
| BE-PAY-04 | `[x]` Create `PaymentMethodController@destroy` — `DELETE /api/v1/payment-methods/{paymentMethod}` | P-15 | BE-PAY-01 | — |
| BE-PAY-05 | `[x]` Create `UserPaymentMethod` Eloquent model with `gateway_token` hidden; `Payment` model with enum casts (PaymentMethod, PaymentStatus), factory, helper methods | — | SETUP-17 | — |
| BE-PAY-06 | `[x]` Create `PaymentService` — orchestrate cash and card flows on trip completion; handles tokenized charges, cash collection, tips, and refunds | P-15 | SETUP-61 | — |
| BE-PAY-07 | `[x]` Implement cash payment flow in `PaymentService`: on trip completion with method=cash, create Payment with status=pending_collection; `POST /api/v1/rides/{ride}/confirm-cash` for driver to mark collected via `RidePaymentController` | P-15 | BE-PAY-06, SETUP-16 | — |
| BE-PAY-08 | `[x]` Implement card payment flow in `PaymentService`: on trip completion with method=card, charge default card via Flutterwave tokenized-charges, create Payment with status=captured | P-15 | BE-PAY-06, SETUP-61 | — |
| BE-PAY-09 | `[x]` Create `ProcessPaymentJob` — dispatched on ride completion via `RideService::completeRide()`; calls PaymentService based on payment method; 3 retries, 30s backoff, idempotent | P-15 | BE-PAY-06, BE-RIDE-09 | — |
| BE-PAY-10 | `[x]` Create `RideTipController@store` — `POST /api/v1/rides/{ride}/tip`: add tip after completion; captures additional card charge for card rides or logs cash tip; prevents duplicate tips | P-16 | BE-PAY-08 | Cash-tip logging in scope via tip_amount field |
| BE-PAY-11 | `[x]` Create `StoreTipFormRequest` — validate amount (min ₦50, max ₦50,000), ride is completed, ride belongs to passenger, no duplicate tip | P-16 | BE-PAY-10 | — |
| BE-PAY-12 | `[x]` Create `RideReceiptController@show` — `GET /api/v1/rides/{ride}/receipt`: generate receipt with fare breakdown, date/time, driver, vehicle class, payment method, tip; accessible by passenger, driver, or admin | P-19 | BE-PAY-08 | — |
| BE-PAY-13 | `[x]` Create `Payment` Eloquent model with relationships: ride(), paymentMethod(); HasFactory, enum casts for method and status | — | SETUP-16 | — |
| BE-PAY-14 | `[x]` Create `PaymentResource`, `PaymentMethodResource` API resources; gateway details restricted to admin users | — | BE-PAY-13 | — |

---

## 9. Ratings & Disputes API

**PRD refs:** P-17, P-18, A-13, A-14

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RATE-01 | `[x]` Create `RideRatingController@store` — `POST /api/v1/rides/{ride}/rating`: submit 1-5 star rating with optional comment; validate ride is completed and user hasn't already rated; both passenger and driver can rate | P-17 | SETUP-18 | Passenger rates driver, driver rates passenger; `RatingResource` returned |
| BE-RATE-02 | `[x]` Create `StoreRatingFormRequest` — validate score (1-5), comment (nullable, max 1000 chars), ride completed, user is participant, no duplicate rating | P-17 | BE-RATE-01 | Uses `after()` validators for business rules |
| BE-RATE-03 | `[x]` Create `Rating` Eloquent model with factory; add `ratingsReceived()` relationship and `averageRating()` accessor on User model | — | SETUP-18 | `RatingFactory` with completed ride defaults |
| BE-RATE-04 | `[x]` Create `RideDisputeController@store` — `POST /api/v1/rides/{ride}/dispute`: ride participant reports an issue with category + description; creates dispute record with `open` status | P-18 | SETUP-19 | Both passenger and driver can file disputes |
| BE-RATE-05 | `[x]` Create `StoreDisputeFormRequest` — validate category (`DisputeCategory` enum), description (required, min 10, max 2000 chars), ride completed, user is participant, no duplicate dispute | P-18 | BE-RATE-04 | `DisputeCategory` enum: fare_dispute, driver_behaviour, route_deviation, vehicle_condition, safety_concern, payment_issue, item_left_behind, other |
| BE-RATE-06 | `[x]` Create `Dispute` Eloquent model with relationships: ride(), reportedBy(), resolvedBy(); `DisputeCategory` and `DisputeStatus` enums with `label()` helpers; `DisputeFactory` with `underReview()`, `resolved()`, `dismissed()` states | — | SETUP-19 | `DisputeStatus`: open, under_review, resolved, dismissed |
| BE-RATE-07 | `[x]` Create `AdminDisputeController@index` — `GET /api/v1/admin/disputes`: list disputes with filters (status, category, date range), pagination | A-13 | BE-RATE-06, SETUP-52 | Loads reportedBy, resolvedBy, ride relationships |
| BE-RATE-08 | `[x]` Create `AdminDisputeController@show` — `GET /api/v1/admin/disputes/{dispute}`: full dispute with ride detail including passenger, driver, vehicle class, city, payment | A-13 | BE-RATE-07 | — |
| BE-RATE-09 | `[x]` Create `AdminDisputeController@resolve` — `POST /api/v1/admin/disputes/{dispute}/resolve`: update status to resolved/dismissed, add resolution notes; audit log records old→new status | A-14 | BE-RATE-08 | ⚠ OQ-08: Confirm whether refund/fare adjustment actions are in scope |
| BE-RATE-10 | `[x]` Create `ResolveDisputeFormRequest` — validate status (resolved/dismissed), resolution_notes required (min 10 chars), prevents re-resolving terminal disputes | A-14 | BE-RATE-09 | — |
| BE-RATE-11 | `[x]` Create `DisputeResource` and `RatingResource` API resources | — | BE-RATE-06 | Conditional resolution_notes display; enum value + label pairs |

---

## 10. Gamification & Carbon Scoring Engine

**PRD refs:** G-01–G-08, B-10–B-12, A-17–A-19

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-GAME-01 | `[x]` Create `TierConfig`, `PointMultiplierConfig`, `GamificationProfile`, `TripCarbonScore` Eloquent models with relationships; factories for `GamificationProfile` (with tier state helpers) and `TripCarbonScore` | — | SETUP-23 through SETUP-26 | Models use HasUuids; `TierLevel` and `MultiplierConditionType` enums |
| BE-GAME-02 | `[x]` Create `CarbonScoreService` — calculate per-trip carbon score: CO₂ saved = distance_km × (baseline_emission − vehicle_class_emission); store in trip_carbon_scores; configurable emission factors in `config/gamification.php` | G-02, B-10 | BE-GAME-01, BE-RIDE-09 | Emission data: EU/IPCC averages adjusted for ride-sharing occupancy; configurable via config |
| BE-GAME-03 | `[x]` Create `PointMultiplierService` — check ride conditions (EV, off-peak), look up multiplier config, apply to base points, handle stacking; off-peak hours configurable | G-03, B-11 | BE-GAME-02, SETUP-25 | SharedJourney multiplier seeded but not auto-detected (Phase 2) |
| BE-GAME-04 | `[x]` Create `TierEvaluationService` — update cumulative points on gamification profile, compare against tier thresholds, promote if crossed; dispatches `TierUpgraded` event | B-11 | BE-GAME-03, SETUP-24 | — |
| BE-GAME-05 | `[x]` Create `CalculateCarbonScoreJob` — dispatched on ride completion via `RideService::completeRide()`; calls CarbonScoreService → PointMultiplierService → TierEvaluationService pipeline; idempotent, 3 retries, 30s backoff | B-10, B-11 | BE-GAME-02, BE-GAME-03, BE-GAME-04 | — |
| BE-GAME-06 | `[x]` Create `TierUpgraded` broadcastable event (`ShouldBroadcastNow`) — on tier promotion, broadcast to `user.{userId}` channel for celebration animation | G-04, B-11 | BE-GAME-04, SETUP-57 | Push notification can be wired via listener |
| BE-GAME-07 | `[x]` Create `GamificationController` — `GET /api/v1/gamification`: current tier, points, carbon score, progress to next tier, unlocked benefits; `GET /carbon-history`: paginated trip scores; `GET /tiers`: all tiers; `GET /leaderboard`: top users by points | G-04, B-12 | BE-GAME-01 | Auto-creates profile on first access |
| BE-GAME-08 | `[x]` Create `TierGateService` — utility checking user's tier for: (a) priority matching eligibility (G-05), (b) booking fee discount amount (G-06), (c) exclusive promo access (G-07), (d) EV fee waiver eligibility (G-08) | B-12 | BE-GAME-04 | All wired in: booking-fee discount (fares), EV fee waiver (reservations), priority matching (dispatch: nearest driver first, precedence for scarce drivers, extra retry wave) |
| BE-GAME-09 | `[x]` Create `AdminGamificationController@indexUsers` — `GET /api/v1/admin/gamification/users`: list users with tier/score data, filters (tier, search, min_points), pagination, sorting | A-19 | BE-GAME-01, SETUP-52 | — |
| BE-GAME-10 | `[x]` Create `AdminGamificationController@aggregate` — `GET /api/v1/admin/gamification/aggregate`: tier distribution, average carbon scores, total CO₂ saved, top 10 users | A-19 | BE-GAME-09 | — |
| BE-GAME-11 | `[x]` Create `AdminGamificationController@updateTiers` — `PUT /api/v1/admin/gamification/tiers`: update all 5 tier configs (names, thresholds, benefits); audit log | A-17 | BE-GAME-01, SETUP-52 | Tier names: Bronze → Silver → Gold → Platinum → Diamond (OQ-09 resolved) |
| BE-GAME-12 | `[x]` Create `AdminGamificationController@updateMultipliers` — `PUT /api/v1/admin/gamification/multipliers`: update multiplier values; audit log; creates new if not exists | A-18 | BE-GAME-01, SETUP-52 | — |
| BE-GAME-13 | `[x]` Create `GamificationResource`, `TierConfigResource`, `CarbonScoreResource`, `PointMultiplierConfigResource` API resources | — | BE-GAME-01 | GamificationResource includes progress calculation and benefits |

---

## 11. Promo / Discount Engine

**PRD refs:** PR-01–PR-05, A-20–A-23, B-13–B-15, NF-10

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PROMO-01 | `[x]` Create `PromoCode`, `PromoRedemption` Eloquent models with relationships; `DiscountType` enum; factories with state helpers (flat, percentage, expired, future, inactive, unlimited, newUsersOnly, tierRestricted) | — | SETUP-21, SETUP-22 | Models use HasUuids; PromoCode has scopes: active(), byCode(); helpers: isWithinTimeWindow(), hasReachedGlobalLimit(), hasReachedUserLimit() |
| BE-PROMO-02 | `[x]` Create `AdminPromoController@store` — `POST /api/v1/admin/promos`: create promo with discount type, value, cap, limits, eligibility rules, city/vehicle class restrictions; audit log | A-20–A-22 | BE-PROMO-01, SETUP-52 | Also supports description, minimum_fare_amount, geo_fence (radius or polygon) |
| BE-PROMO-03 | `[x]` Create `StorePromoFormRequest` — validate code uniqueness (auto-uppercased), discount type/value (percentage capped at 100%), cap, dates, geo-fence format (center+radius or polygon), tier level, peak/off-peak mutual exclusion | A-20 | BE-PROMO-02 | Also created `UpdatePromoFormRequest` for PUT |
| BE-PROMO-04 | `[x]` Create `AdminPromoController@index` — `GET /api/v1/admin/promos`: list promos with filters (active/expired/inactive/scheduled), search by code/description, city filter, pagination | A-20 | BE-PROMO-01 | Includes redemption count via withCount |
| BE-PROMO-05 | `[x]` Create `AdminPromoController@update` — `PUT /api/v1/admin/promos/{promo}`: update config; audit log with old/new values | A-20 | BE-PROMO-02 | — |
| BE-PROMO-06 | `[x]` Create `PromoValidationService` — validate code against all rules atomically: exists, active, within time window, within geo-fence (haversine radius + point-in-polygon), per-user cap, global cap, order history, tier requirement, peak/off-peak, city/vehicle class, minimum fare | B-13, PR-01 | BE-PROMO-01, BE-GAME-08 | Uses DB transaction + row locking (lockForUpdate) via validateAndLock(); peak hours configurable via `config/promo.php` |
| BE-PROMO-07 | `[x]` Create `PromoController@validate` — `POST /api/v1/promos/validate`: validate promo code at checkout; return discount preview with amount or rejection reason | PR-01 | BE-PROMO-06 | Returns discount_preview when fare_amount provided |
| BE-PROMO-08 | `[x]` Create `ValidatePromoFormRequest` — validate code, ride context (city_id, vehicle_class_id, pickup coords, fare_amount) | PR-01 | BE-PROMO-07 | Auto-uppercases code input |
| BE-PROMO-09 | `[x]` Implement redemption cap enforcement: row-level locking (SELECT FOR UPDATE) on promo_codes + count check on promo_redemptions within DB::transaction in PromoApplicationService | B-14, NF-10 | BE-PROMO-06 | Unique constraint (promo_code_id, user_id, ride_id) prevents double-redeem at DB level |
| BE-PROMO-10 | `[x]` Create `PromoApplicationService` — calculate discount amount (percentage with cap or flat), apply cap, create redemption record atomically; apply() method for ride integration | B-15 | BE-PROMO-09, BE-PRICE-05 | Discount capped at fare amount; calculateDiscount() available standalone |
| BE-PROMO-11 | `[x]` Create `AdminPromoController@performance` — `GET /api/v1/admin/promos/{promo}/performance`: redemption count, unique users, total discount, average discount, remaining redemptions, daily breakdown, recent 20 redemptions | A-23 | SETUP-22 | — |
| BE-PROMO-12 | `[x]` Create `PromoController@available` — `GET /api/v1/promos/available`: return promos the user is eligible for (filters by active window, user eligibility, per-user limits) | PR-04 | BE-PROMO-06, BE-GAME-08 | Code-entry model; auto-apply can be layered via ride creation flow |
| BE-PROMO-13 | `[x]` Create `PromoCodeResource`, `PromoRedemptionResource` API resources | — | BE-PROMO-01 | Admin-only fields: total_redemption_limit, geo_fence, order count rules, created_by, total_redemptions |

---

## 12. SOS Telemetry & Escalation Pipeline

**PRD refs:** SOS-01–SOS-04, A-24–A-27, B-16–B-18, NF-04, NF-08

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-SOS-01 | `[x]` Create `SosIncident`, `SosEventLog` Eloquent models with relationships; `SosIncidentStatus` and `SosTriggerType` enums; `SosIncidentFactory` with state helpers (checkInSent, acknowledged, escalated, operatorAssigned, resolved, cancelled, driverTriggered) | — | SETUP-27, SETUP-28 | SosEventLog is read-only; no update/delete; telemetry encrypted via `encrypted:array` cast (NF-04) |
| BE-SOS-02 | `[x]` Create `SosService` — orchestrate incident lifecycle: trigger → check-in → escalate → resolve/cancel; explicit state machine with valid transitions map; write every event to sos_event_log; configurable via `config/sos.php` | B-16–B-18 | BE-SOS-01 | Supports operator reassignment; atomic transitions with row locking |
| BE-SOS-03 | `[x]` Create `SosController@trigger` — `POST /api/v1/rides/{ride}/sos`: validate ride is active, capture GPS + vehicle + IDs, encrypt telemetry, create incident, dispatch check-in job; prevents duplicate active incidents per ride | B-16, SOS-02 | BE-SOS-02, BE-RIDE-01 | 🔒 Encrypt telemetry at rest (NF-04) via `encrypted:array` cast on `text` columns |
| BE-SOS-04 | `[x]` Create `TriggerSosFormRequest` — validate ride is in active state (requested through in_progress), GPS coords (lat -90/90, lng -180/180), optional speed/heading; validates user is ride participant | SOS-02 | BE-SOS-03 | — |
| BE-SOS-05 | `[x]` Create `SendSosCheckInJob` — push check-in prompt to triggering user's device via `PushNotificationGateway`; update incident status to check_in_sent; broadcast `SosCheckInRequested` event; dispatch escalation timeout job | B-17, SOS-03 | BE-SOS-02, SETUP-57 | ⏱ Safety-critical P0 — server-side timer, not client-dependent (NF-08); 3 retries, 5s backoff |
| BE-SOS-06 | `[x]` Create `SosEscalationTimeoutJob` — delayed 30-second job (configurable via `SOS_ESCALATION_TIMEOUT`); if check-in not acknowledged, escalate to Safety Operator queue; broadcast `SosIncidentEscalated` to admin SOS channel; notify other ride participant | A-25, B-17 | BE-SOS-05, BE-LOC-03 | — |
| BE-SOS-07 | `[x]` Create `SosController@acknowledge` — `POST /api/v1/sos/{incident}/acknowledge`: user acknowledges check-in; update status; only triggering user can acknowledge | SOS-03 | BE-SOS-05 | — |
| BE-SOS-08 | `[x]` Create `SosController@cancel` — `POST /api/v1/sos/{incident}/cancel`: user cancels SOS; update status; log cancellation (not silently discarded); triggering user or admin can cancel | SOS-04, A-27 | BE-SOS-02, SETUP-28 | — |
| BE-SOS-09 | `[x]` Create `AdminSosController@active` — `GET /api/v1/admin/sos/active`: list active/unacknowledged incidents with trip + telemetry detail; prioritized ordering (escalated → triggered → check_in_sent → operator_assigned → dispatched) | A-24 | BE-SOS-01, SETUP-52 | Safety Operator scope only; includes `show` and `history` endpoints |
| BE-SOS-10 | `[x]` Create `AdminSosController@assign` — `POST /api/v1/admin/sos/{incident}/assign`: operator self-assigns; supports reassignment; atomic with row locking | A-24 | BE-SOS-09 | — |
| BE-SOS-11 | `[x]` Create `AdminSosController@dispatch` — `POST /api/v1/admin/sos/{incident}/dispatch`: trigger emergency-services handoff; validates emergency_service_type (police/ambulance/fire/all), optional contact_number and notes | A-26, B-18 | BE-SOS-10 | ⚠ OQ-04: Integration provider TBD; currently logs dispatch metadata for manual outreach |
| BE-SOS-12 | `[x]` Create `AdminSosController@resolve` — `POST /api/v1/admin/sos/{incident}/resolve`: resolve with notes (min 10 chars); audit log; auto-assigns operator if not yet assigned | A-27 | BE-SOS-10 | — |
| BE-SOS-13 | `[x]` Create `SosIncidentEscalated` and `SosCheckInRequested` broadcastable events for admin SOS channel and user channel respectively | A-24 | BE-SOS-06, BE-LOC-03 | Real-time feed to SOS console; `admin.sos` channel authorized for SafetyOperator + SuperAdmin |
| BE-SOS-14 | `[x]` Create `SosIncidentResource` and `SosEventLogResource` API resources; admin-conditional fields for vehicle_details, telemetry_data, operator info, event logs | — | BE-SOS-01 | — |

---

## 13. Anti-Offline Trip Detection Engine

**PRD refs:** B-19–B-22, §9.3, OE-01–OE-03, A-28–A-30, NF-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-AO-01 | `[x]` Create `OfflineTripFlag` Eloquent model with relationships: ride(), driver(), passenger(), resolvedBy(); `SanctionTier` (int enum: 1=Warning, 2=Suspension, 3=Deactivation) and `DisputeOutcome` (string enum: pending/upheld/overturned) enums; `OfflineTripFlagFactory` with state helpers (tier2, tier3, disputed, upheld, overturned); scopes: active(), disputed(), forDriver(), withinLookback() | — | SETUP-29 | — |
| BE-AO-02 | `[x]` Create `OfflineTripDetectionService` — `shouldMonitor()` checks driver proximity to pickup via Redis location; `analyzeCollocation()` analyzes GPS trajectories against intended route using haversine distance + linear interpolation; configurable via `config/offline_detection.php` (collocation threshold 200m, route match 60%, min 3 points, pickup proximity 300m) | B-19, B-20 | BE-AO-01 | OQ-13 resolved: 200m collocation threshold; configurable per deployment |
| BE-AO-03 | `[x]` Create `MonitorCancellationJob` — dispatched on ride cancellation when driver within pickup proximity; samples driver GPS from Redis at configurable interval (30s) for monitoring window (15 min); stores trajectory in Cache; dispatches AnalyzeOfflineTripJob on completion | B-19 | BE-AO-02, BE-RIDE-06, BE-LOC-01 | Integrated into `RideService::cancelRide()` |
| BE-AO-04 | `[x]` Create `AnalyzeOfflineTripJob` — delayed job after monitoring window; retrieves cached trajectory, runs collocation analysis; if flagged, calls SanctionService; cleans up cache; 3 retries, 30s backoff, unique per ride | B-20 | BE-AO-03 | — |
| BE-AO-05 | `[x]` Create `SanctionService` — `determineTier()` counts non-overturned flags within 30-day lookback → L1=warning, L2=48h suspension, L3=permanent deactivation; `applyForRide()` creates flag + enforces sanction atomically; `reverseSanction()` reinstates driver when dispute overturned; audit logging | §9.3, B-21 | BE-AO-04, SETUP-29 | L2/L3: force driver offline + Suspended status |
| BE-AO-06 | `[x]` Dispatch compliance warning notification to driver on flagging via `PushNotificationGateway`; tier-specific messaging (warning, suspension with hours, permanent deactivation); reinstatement notification on sanction reversal | OE-01 | BE-AO-05, SETUP-57 | — |
| BE-AO-07 | `[x]` Create `DriverFlagController@dispute` — `POST /api/v1/driver/flags/{flag}/dispute`: driver disputes with notes (min 10 chars); validates ownership and not already disputed; sets `dispute_outcome=pending`; audit log | OE-02, B-22 | BE-AO-01 | — |
| BE-AO-08 | `[x]` Create `DriverComplianceController@show` — `GET /api/v1/driver/compliance`: returns active_flags_count, total_flags_count, active_sanction details, recent flags with ride info | OE-03 | BE-AO-01 | — |
| BE-AO-09 | `[x]` Create `AdminOfflineFlagController@index` — `GET /api/v1/admin/offline-flags`: list flagged trips with filters (sanction_tier, is_disputed, dispute_outcome, driver_id, date range, status); includes driver, passenger, ride, resolvedBy; paginated | A-28 | BE-AO-01, SETUP-52 | Operations + Safety Operator roles |
| BE-AO-10 | `[x]` Create `AdminOfflineFlagController@review` — `POST /api/v1/admin/offline-flags/{flag}/review`: accept outcome (upheld/overturned) + notes; if overturned, reverses sanction via SanctionService; audit log | A-29, B-22 | BE-AO-09 | — |
| BE-AO-11 | `[x]` Create `AdminOfflineFlagController@escalate` — `POST /api/v1/admin/offline-flags/{flag}/escalate`: increment sanction tier (1→2 or 2→3); validates not at max tier or resolved; enforces new sanction; audit log | A-30 | BE-AO-09 | OQ-14 resolved: super_admin can overturn L3 via review flow |
| BE-AO-12 | `[x]` Create `OfflineFlagResource` API resource — sanction tier (value + label), dispute outcome, conditional detection_data (admin only), loaded relationships | — | BE-AO-01 | — |

---

## 14. EV Charging Reservation System

**PRD refs:** EV-01–EV-04, B-23–B-26, A-31–A-32

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-EV-01 | `[x]` Create `EvChargingStation`, `EvChargingStall`, `EvReservation` Eloquent models with relationships | — | SETUP-30, SETUP-31, SETUP-32 | — |
| BE-EV-02 | `[x]` Create `AdminEvStationController@store` — `POST /api/v1/admin/ev-stations`: create station record | A-31 | BE-EV-01, SETUP-52 | ⚠ OQ-15: Data source TBD |
| BE-EV-03 | `[x]` Create `AdminEvStationController@index` — `GET /api/v1/admin/ev-stations`: list stations with filters | A-31 | BE-EV-01 | — |
| BE-EV-04 | `[x]` Create `AdminEvStationController@update` — `PUT /api/v1/admin/ev-stations/{station}` | A-31 | BE-EV-02 | — |
| BE-EV-05 | `[x]` Create `AdminEvStationController@utilisation` — `GET /api/v1/admin/ev-stations/utilisation`: real-time occupancy and queue status | A-32 | BE-EV-01 | — |
| BE-EV-06 | `[x]` Create `EvStationController@index` — `GET /api/v1/ev-stations`: public endpoint listing active stations with availability for a city | A-32 | BE-EV-01 | Used by Driver App |
| BE-EV-07 | `[x]` Create `StallAvailabilityService` — maintain real-time stall status based on reservations and occupancy events | B-23 | BE-EV-01 | — |
| BE-EV-08 | `[x]` Create `ReservationService` — handle three outcomes: (a) stall available → immediate lock, (b) occupied but departure imminent → queue hold, (c) wait > 15 min → return retry delay | B-24, B-25 | BE-EV-07 | Atomic stall locking (row lock) to prevent double-reservation |
| BE-EV-09 | `[x]` Create `EvReservationController@store` — `POST /api/v1/ev-stations/{station}/reserve`: driver requests reservation; validate EV-class driver; call ReservationService | EV-01, EV-02, EV-03 | BE-EV-08 | — |
| BE-EV-10 | `[x]` Create `StoreReservationFormRequest` — validate station exists, driver has EV vehicle class | EV-01 | BE-EV-09 | Implemented as a vehicle `fuel_type` (`electric` / `plug_in_hybrid` can charge) rather than a vehicle class |
| BE-EV-11 | `[x]` Create `TransferReservationJob` — when occupying vehicle departs, automatically transfer reservation to next queued driver and notify | B-24, EV-02 | BE-EV-08, SETUP-57 | — |
| BE-EV-12 | `[x]` Implement tier-based fee waiver in `ReservationService`: check user tier via TierGateService; waive fee for eligible top-tier users | B-26, EV-04, G-08 | BE-EV-08, BE-GAME-08 | ⚠ OQ-16: Waiver applies to driver, passenger, or both? |
| BE-EV-13 | `[x]` Create `EvReservationController@index` — `GET /api/v1/drivers/ev-reservations`: driver views active/past reservations | — | BE-EV-01 | — |
| BE-EV-14 | `[x]` Create `EvStationResource`, `EvReservationResource` API resources | — | BE-EV-01 | — |

---

## 15. Notification Service

**PRD refs:** B-06

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-NOTIF-01 | `[x]` Create `RideNotificationService` — handles push notifications for all ride state transitions via centralized `NotificationService`; persists in-app notifications + sends push; integrated into `RideStateMachine::transitionTo()` with try/catch so failures never break ride flow | B-06 | SETUP-57, SETUP-33 | Covers: ride_matched, driver_en_route, driver_arrived, ride_started, ride_completed, ride_cancelled, no_driver_found |
| BE-NOTIF-02 | `[x]` Define `NotificationType` enum with category mapping: ride_matched, ride_cancelled, driver_en_route, driver_arriving, ride_started, ride_completed, no_driver_found, ride_assigned, sos_check_in, sos_escalated, compliance_warning, promo_expiring, tier_upgrade, ev_reservation_ready, dispute_update, kyc_status_changed, scheduled_ride_reminder, lost_item_report (+ lost_item_update, lost_item_message) + wallet types (topup_success, topup_failed, ride_wallet_payment, wallet_refund, payout_approved, payout_paid, payout_failed, cash_change_credit). Each type maps to a `NotificationCategory` | B-06 | BE-NOTIF-01 | — |
| BE-NOTIF-02b | `[x]` Create `NotificationCategory` enum: ride_updates, safety, payments, promotions, compliance, gamification, ev_charging, account; each with label(), description(), isCritical(); safety & compliance are critical (cannot be disabled) | B-06 | BE-NOTIF-02 | — |
| BE-NOTIF-03 | `[x]` Create `Notification` Eloquent model | — | SETUP-33 | — |
| BE-NOTIF-03b | `[x]` Create centralized `NotificationService` — orchestrates in-app persistence + push dispatch; respects user notification preferences per category; `send()` for normal, `sendCritical()` for safety-critical (bypasses preferences), `sendPushOnly()` for transient updates, `sendToMany()` for batch; graceful push failure handling | B-06 | BE-NOTIF-03, SETUP-57 | — |
| BE-NOTIF-03c | `[x]` Create `NotificationResource` API resource — includes type, type_label, category, title, body, data, is_read, read_at, created_at | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-04 | `[x]` Create `NotificationController@index` — `GET /api/v1/notifications`: list in-app notifications for authenticated user, paginated, newest first; supports filtering by `type`, `category`, `is_read`; returns `NotificationResource` | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-05 | `[x]` Create `NotificationController@markRead` — `PATCH /api/v1/notifications/{notification}/read`: mark single notification as read | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-06 | `[x]` Create `NotificationController@markAllRead` — `POST /api/v1/notifications/read-all`: mark all notifications as read; returns updated_count | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-06b | `[x]` Create `NotificationController@destroy` — `DELETE /api/v1/notifications/{notification}`: delete a single notification; ownership check enforced | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-07 | `[x]` Create `DeviceTokenController@store` — `POST /api/v1/device-tokens`: register device token for push; uses updateOrCreate | B-06 | SETUP-34 | — |
| BE-NOTIF-08 | `[x]` Create `DeviceTokenController@destroy` — `DELETE /api/v1/device-tokens`: deactivate a device token by token + platform | B-06 | SETUP-34 | — |

### 15.1b Notification Preferences

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-NOTIF-PREF-01 | `[x]` Create migration: `notification_preferences` table — id (UUID), user_id (FK), category (string), push_enabled (bool default true), in_app_enabled (bool default true), timestamps; unique constraint on (user_id, category) | B-06 | SETUP-07 | — |
| BE-NOTIF-PREF-02 | `[x]` Create `NotificationPreference` Eloquent model with NotificationCategory cast and user() relationship | B-06 | BE-NOTIF-PREF-01 | — |
| BE-NOTIF-PREF-03 | `[x]` Create `NotificationPreferenceController@index` — `GET /api/v1/notification-preferences`: list all categories with current user preferences (falls back to config defaults); includes label, description, is_critical flag | B-06 | BE-NOTIF-PREF-02 | — |
| BE-NOTIF-PREF-04 | `[x]` Create `NotificationPreferenceController@update` — `PUT /api/v1/notification-preferences`: batch update preferences per category; critical categories (safety, compliance) forced to enabled regardless of user input | B-06 | BE-NOTIF-PREF-02 | — |
| BE-NOTIF-PREF-05 | `[x]` Create `UpdatePreferencesRequest` FormRequest — validates preferences array with category (valid enum), push_enabled (bool), in_app_enabled (bool) | B-06 | BE-NOTIF-PREF-04 | — |
| BE-NOTIF-PREF-06 | `[x]` Create `config/notification.php` — channels (push, in_app, mail toggles), category_defaults, retention_days (90), rate_limit (30/hour), per_page (20) | B-06 | — | — |

### 15.2 Email Notifications

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-NOTIF-09 | `[x]` Configure Resend SMTP mail transport (`MAIL_SCHEME=smtps`, port 465); publish and customise Laravel mail views with Etigo branding (logo, name, footer) | B-06 | SETUP-04 | ⚠ `etigo.com` domain must be verified on Resend before production delivery |
| BE-NOTIF-10 | `[x]` Create `AdminInvitationNotification` — queued email sent when super admin invites a new admin; includes accept-invite link with 48h-expiring token | B-06 | BE-AUTH-14 | — |
| BE-NOTIF-11 | `[x]` Create `AdminPasswordResetNotification` — queued email with password reset link (60-min expiry); enumeration-safe (always returns success) | B-06 | BE-AUTH-15 | — |
| BE-NOTIF-12 | `[x]` Create `AdminWelcomeNotification` — queued email sent when admin accepts invitation; includes dashboard login link and assigned role | B-06 | BE-AUTH-14 | — |
| BE-NOTIF-13 | `[x]` Create `RideCompletedNotification` — queued receipt email to passenger on ride completion (if email on file); includes trip summary, fare breakdown, payment method | B-06 | BE-RIDE-09 | Wired into `RideService::completeRide()` |
| BE-NOTIF-14 | `[x]` Create `TestMailCommand` (`mail:test`) — artisan command to test all email types: invitation, reset, welcome, receipt; sends synchronously bypassing queue | — | BE-NOTIF-09 | Dev/staging tool; accepts email and --type option |

---

## 16. Admin Management API

**PRD refs:** A-10–A-12, A-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-ADMIN-01 | `[x]` Create `Driver` Eloquent model with relationships: user(), documents(), vehicle(); casts status→DriverStatus enum | — | SETUP-11 | Implemented in `App\Models\Driver` |
| BE-ADMIN-02 | `[x]` Create `DriverDocument` Eloquent model with `DocumentType` and `DocumentStatus` enums | — | SETUP-12 | Soft-replacement: re-uploading same type deletes previous |
| BE-ADMIN-03 | `[x]` Create `OnboardingController@uploadDocument` — `POST /api/v1/driver/documents`: upload KYC documents (driving_licence, vehicle_registration, insurance_certificate, government_id); max 10MB, jpeg/png/pdf; optional `expires_at` date; stored on S3 | D-02 | BE-ADMIN-02 | Previous doc of same type is deleted on re-upload |
| BE-ADMIN-04 | `[x]` Create `OnboardingController@storeVehicle` / `updateVehicle` — `POST/PUT /api/v1/driver/vehicle`: register or update vehicle (make, model, colour, plate_number, year, vehicle_class_id); driver selects preferred vehicle class; final class confirmed by Admin | D-04 | BE-ADMIN-01 | PUT supports partial updates; vehicle_class_id required on creation |
| BE-ADMIN-05 | `[x]` Create `OnboardingController@status` — `GET /api/v1/driver/onboarding/status`: return driver profile, documents, vehicle, city, missing-items checklist, `can_submit` flag, and `has_city` flag | D-03 | BE-ADMIN-01 | Response includes full onboarding completeness check |
| BE-ADMIN-05b | `[x]` Create `OnboardingController@submitForReview` — `POST /api/v1/driver/onboarding/submit`: validate all required onboarding data (documents, vehicle, licence, city) is present; transition driver from `onboarding` or `rejected` to `pending_review`; returns 422 with specific errors if incomplete | D-03 | BE-ADMIN-05 | Explicit submission step — industry standard for KYC flows |
| BE-ADMIN-05c | `[x]` Create `DriverManagementController@reviewDocument` — `POST /api/v1/admin/drivers/{driver}/documents/{document}/review`: approve or reject individual documents with reason; auto-transitions driver to `rejected` when all docs reviewed and any rejected | A-10 | BE-ADMIN-09 | Per-document review matches design's granular rejection UX |
| BE-ADMIN-06 | `[x]` Create `DriverController@toggleOnline` — `POST /api/v1/driver/toggle-online`: toggle online/offline; validate approved + vehicle class assigned + not suspended | D-05 | BE-ADMIN-01 | Returns reasons array on failure |
| BE-ADMIN-07 | `[x]` Create `DriverManagementController@index` — `GET /api/v1/admin/drivers`: list drivers with status filter and search (name/email/phone), paginated | A-10 | BE-ADMIN-01, SETUP-52 | Admin-only via `user.type:admin` middleware |
| BE-ADMIN-08 | `[x]` Create `DriverManagementController@show` — `GET /api/v1/admin/drivers/{driver}`: full profile with user, documents, vehicle | A-10 | BE-ADMIN-07 | — |
| BE-ADMIN-09 | `[x]` Create `DriverManagementController@review` — `POST /api/v1/admin/drivers/{driver}/review`: approve or reject with reason; wrapped in DB::transaction(); validates status transition (only pending_review → approved/rejected); audit log | A-10, A-05 | BE-ADMIN-08 | Returns 422 if driver not in reviewable state |
| BE-ADMIN-10 | `[x]` Create `ReviewDriverRequest` — validate decision (approve/reject), reason (required) | A-10 | BE-ADMIN-09 | — |
| BE-ADMIN-11 | `[x]` Create `DriverManagementController@suspend` — `POST /api/v1/admin/drivers/{driver}/suspend`: suspend driver; validates driver is in `approved` status; no request body; wrapped in DB::transaction(); audit log | A-11 | BE-ADMIN-08 | Returns 422 if not approved |
| BE-ADMIN-12 | `[x]` Create `DriverManagementController@reactivate` — `POST /api/v1/admin/drivers/{driver}/reactivate`: lift suspension; validates currently suspended; wrapped in DB::transaction(); audit log | A-11 | BE-ADMIN-11 | Returns 422 if not suspended |
| BE-ADMIN-13 | `[x]` Create `AdminPassengerController@index` — `GET /api/v1/admin/passengers`: list passengers with filters (is_active), search (name/email/phone), pagination | A-12 | SETUP-52 | Uses `ilike` search across first_name, last_name, email, phone |
| BE-ADMIN-14 | `[x]` Create `AdminPassengerController@show` — `GET /api/v1/admin/passengers/{user}`: profile with ride statistics (total, completed, cancelled) | A-12 | BE-ADMIN-13 | Validates user is passenger type; returns 404 for non-passengers |
| BE-ADMIN-15 | `[x]` Create `AdminPassengerController@suspend` / `reactivate` — suspend or reactivate passenger account; revokes tokens on suspend; audit log | A-12 | BE-ADMIN-14 | Idempotency-safe: rejects if already in target state |
| BE-ADMIN-16 | `[x]` Create `DriverResource`, `DriverDocumentResource`, `VehicleResource` API resources | — | BE-ADMIN-01 | Used by both driver onboarding and admin endpoints |

### 16.1 KYC Identity Verification (QoreID)

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-KYC-01 | `[x]` Create migration: `kyc_verifications` table — driver_id (FK), type (enum), id_number, provider_reference, status, provider_response (text, encrypted), match_data (json), failure_reason, verified_at, expires_at, timestamps | D-02 | SETUP-11 | `provider_response` is `text` for `encrypted:array` cast (not `json`) |
| BE-KYC-02 | `[x]` Create migration: add `kyc_status` and `kyc_verified_at` to `drivers` table | D-02 | BE-KYC-01 | Aggregate status: not_started, in_progress, verified, failed |
| BE-KYC-03 | `[x]` Create `KycVerificationType`, `KycVerificationStatus`, `KycStatus` enums | — | — | Backed PHP enums with label() and qoreIdProductCode() helpers |
| BE-KYC-04 | `[x]` Create `KycVerification` Eloquent model with relationships, casts, scopes | — | BE-KYC-01 | encrypted:array for provider_response |
| BE-KYC-05 | `[x]` Define `KycGateway` contract interface: verifyNin, verifyDriversLicense, verifyVehiclePlate, createLivenessSession, getSessionResult | — | — | Adapter pattern matching existing SMS/Maps pattern |
| BE-KYC-06 | `[x]` Implement `QoreIdKycGateway` — real QoreID implementation with OAuth2 token caching, NIN Premium, Driver's License, License Plate Basic, and Liveness Session endpoints | D-02 | BE-KYC-05 | 🔒 Token cached 3500s; HMAC-SHA256 webhook verification |
| BE-KYC-07 | `[x]` Implement `FakeKycGateway` — deterministic test/dev adapter: IDs starting with '000' fail, others pass; logs all calls | — | BE-KYC-05 | Bound in AppServiceProvider when QOREID credentials absent |
| BE-KYC-08 | `[x]` Create `KycVerificationService` — orchestrator: verifyNin, verifyDriversLicense, verifyVehiclePlate, createLivenessSession, processLivenessResult, recalculateDriverKycStatus | D-02 | BE-KYC-05 | Duplicate prevention; DB::transaction wrapping; auto-recalculates aggregate status |
| BE-KYC-09 | `[x]` Create `KycController` — driver-facing endpoints: status, verifyNin, verifyDriversLicense, verifyVehiclePlate, createLivenessSession, verifications | D-02 | BE-KYC-08 | Routes under /driver/kyc/*; audit logging |
| BE-KYC-10 | `[x]` Create `KycWebhookController` — handles QoreID webhook callbacks for liveness verification results; HMAC-SHA256 signature verification | D-02 | BE-KYC-08 | 🔒 Route: POST /webhooks/qoreid; passes dev when no secret configured |
| BE-KYC-11 | `[x]` Create `VerifyVehiclePlateJob` — queued background job for auto-triggered plate verification on vehicle registration/update | D-04 | BE-KYC-08 | 2 retries; dispatched from OnboardingController |
| BE-KYC-12 | `[x]` Integrate auto plate verification into vehicle onboarding: dispatch job on storeVehicle, expire + re-dispatch on plate change in updateVehicle | D-04 | BE-KYC-11, BE-ADMIN-04 | — |
| BE-KYC-13 | `[x]` Create form requests: VerifyNinRequest (digits:11), VerifyDriversLicenseRequest (min:6, max:20), VerifyVehiclePlateRequest (min:3, max:20) | D-02 | BE-KYC-09 | — |
| BE-KYC-14 | `[x]` Create `KycVerificationResource` API resource — strips sdk_token from match_data for security | — | BE-KYC-04 | — |
| BE-KYC-15 | `[x]` Add kyc_status filter to admin driver listing; load kycVerifications on admin driver show | A-10 | BE-KYC-04, BE-ADMIN-07 | — |
| BE-KYC-16 | `[x]` Create KYC test suite (20 Pest tests): NIN, license, plate, liveness, webhooks, duplicate prevention, retry, auto-trigger, status aggregation | — | BE-KYC-09 | Uses FakeKycGateway; Queue::fake() for job assertions |

---

## 17. Admin Reporting API

**PRD refs:** A-16

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RPT-01 | `[x]` Create `AdminReportController@rideVolume` — `GET /api/v1/admin/reports/ride-volume`: total rides, rides by status, by city, by vehicle class; date-range filters; optional daily/weekly/monthly trend via `group_by` param | A-16 | SETUP-52 | City filter via `city_id` param |
| BE-RPT-02 | `[x]` Create `AdminReportController@completionRate` — `GET /api/v1/admin/reports/completion-rate`: completed vs cancelled, cancellation reasons breakdown, cancelled-by-role breakdown, completion/cancellation rates | A-16 | SETUP-52 | — |
| BE-RPT-03 | `[x]` Create `AdminReportController@revenue` — `GET /api/v1/admin/reports/revenue`: total revenue, total tips, by city, by payment method, average fare; optional trend via `group_by` param | A-16 | SETUP-52 | — |
| BE-RPT-04 | `[x]` Create `AdminReportController@driverUtilisation` — `GET /api/v1/admin/reports/driver-utilisation`: active drivers, trips per driver, earnings per driver, average trip duration, top drivers leaderboard | A-16 | SETUP-52 | Online hours tracking deferred (no session table yet) |
| BE-RPT-05 | `[x]` Create `ReportExportService` — generic CSV export service with `streamCsv()` and `streamFromQuery()` methods; memory-efficient chunked streaming via `response()->streamDownload()` | A-16 | BE-RPT-01 through BE-RPT-04 | CSV format confirmed |
| BE-RPT-06 | `[x]` Create `AdminReportController@export` — `GET /api/v1/admin/reports/export`: accept `type` (ride_volume/completion_rate/revenue/driver_utilisation) + `from`/`to` date range + optional `city_id`, return streamed CSV download | A-16 | BE-RPT-05 | — |

---

## 18. Wallet & Ledger Infrastructure

**PRD refs:** B-08, NF-05, NF-10
**Design note:** All monetary amounts stored as **integer kobo** (never floats). Double-entry bookkeeping — every journal must balance (sum of debits = sum of credits). Ledger entries are immutable (append-only; corrections via reversal entries). Regulatory position (custody of funds, safeguarding) to be validated with PSP and a Nigerian fintech lawyer.

### 18.1 Schema & Migrations

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WINFRA-01 | `[x]` Create migration: `accounts` table — id, owner_type (polymorphic), owner_id, type (enum: passenger_wallet/driver_earnings_pending/driver_earnings_available/platform_commission/psp_clearing/refunds), currency (char 3, default NGN), status (enum: active/frozen/closed), balance (bigint default 0), balance_version (int default 0 for optimistic locking), created_at, updated_at | B-08 | SETUP-06 | System accounts (platform_commission, psp_clearing, refunds) seeded on deploy; user accounts created on first transaction |
| BE-WINFRA-02 | `[x]` Create migration: `journals` table — id (UUID), reference (unique), description, idempotency_key (unique nullable), metadata (jsonb nullable), posted_at, created_at | NF-10 | BE-WINFRA-01 | Immutable; no UPDATE or DELETE |
| BE-WINFRA-03 | `[x]` Create migration: `ledger_entries` table — id, journal_id (FK), account_id (FK), type (enum: debit/credit), amount (bigint, positive), running_balance (bigint), created_at | NF-10 | BE-WINFRA-02 | Immutable; CHECK constraint: amount > 0; index on (account_id, created_at) |
| BE-WINFRA-04 | `[x]` Create migration: `holds` table — id, account_id (FK), ride_id (FK nullable), amount (bigint), status (enum: active/captured/released/expired), expires_at, captured_at (nullable), released_at (nullable), created_at | B-08 | BE-WINFRA-01 | Active holds reduce available balance |
| BE-WINFRA-05 | `[x]` Create migration: `bank_accounts` table — id, driver_id (FK), bank_code (string), account_number (string), account_name (string), is_verified (bool default false), is_primary (bool default true), created_at, updated_at | B-08 | SETUP-11 | One primary account per driver; verified via Flutterwave Account Resolve |
| BE-WINFRA-06 | `[x]` Create migration: `payouts` table — id, driver_id (FK), bank_account_id (FK), amount (bigint), status (enum: requested/approved/processing/paid/failed/reversed), gateway_transfer_id (nullable), gateway_reference (nullable), failure_reason (nullable), requested_at, approved_at (nullable), approved_by_admin_id (FK nullable), paid_at (nullable), created_at, updated_at | B-08 | BE-WINFRA-05 | — |
| BE-WINFRA-07 | `[x]` Create migration: `webhook_events` table — id, provider (string), event_type (string), payload (jsonb), signature (string), processed_at (nullable), created_at | NF-10 | SETUP-06 | Replay-safe: unique index on (provider, payload→id); idempotent processing |
| BE-WINFRA-08 | `[x]` Create database indexes: accounts by (owner_type, owner_id), ledger_entries by (account_id, created_at), holds by (account_id, status), payouts by (driver_id, status), webhook_events by (provider, event_type) | — | BE-WINFRA-01 through BE-WINFRA-07 | ⏱ Profile queries during load testing |

### 18.2 Models & Enums

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WINFRA-09 | `[x]` Define `AccountType`, `LedgerEntryType`, `HoldStatus`, `PayoutStatus`, `TransactionType` (top_up/ride_payment/ride_settlement/commission/tip/refund/adjustment/payout) PHP enums | — | — | Backed enums with label() helpers |
| BE-WINFRA-10 | `[x]` Create `Account` Eloquent model with relationships: owner() (polymorphic), entries(), holds(); scopes: active(), frozen(), forOwner(); helper: availableBalance() (balance − active holds) | — | BE-WINFRA-01 | Balance cached on column; updated atomically with entries |
| BE-WINFRA-11 | `[x]` Create `Journal`, `LedgerEntry` Eloquent models — Journal: entries() relationship; LedgerEntry: journal(), account() relationships; both read-only (guard against update/delete) | — | BE-WINFRA-02, BE-WINFRA-03 | — |
| BE-WINFRA-12 | `[x]` Create `Hold` Eloquent model with relationships: account(), ride(); scopes: active(), expired() | — | BE-WINFRA-04 | — |
| BE-WINFRA-13 | `[x]` Create `BankAccount` Eloquent model with relationships: driver(), payouts() | — | BE-WINFRA-05 | — |
| BE-WINFRA-14 | `[x]` Create `Payout` Eloquent model with relationships: driver(), bankAccount(), approvedBy() | — | BE-WINFRA-06 | — |

### 18.3 Core Ledger Service

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WINFRA-15 | `[x]` Create `LedgerService@postJournal()` — accept array of entries (account, type, amount), wrap in DB::transaction with row-level locking (`SELECT … FOR UPDATE` on accounts), validate debits = credits, create journal + entries, update cached balances atomically with version check | NF-10 | BE-WINFRA-10, BE-WINFRA-11 | 🔒 Double-spend prevention via row locks + optimistic version |
| BE-WINFRA-16 | `[x]` Implement idempotent posting in `LedgerService` — accept idempotency_key; if journal with key exists, return existing result without re-posting | NF-10 | BE-WINFRA-15 | Prevents duplicate postings from retries or webhook replays |
| BE-WINFRA-17 | `[x]` Implement `LedgerService@reverse()` — create a new counter-journal reversing all entries of the original; never edit or delete original entries | NF-10 | BE-WINFRA-15 | Reversal reference format: `REV-{original_reference}` |
| BE-WINFRA-18 | `[x]` Implement `LedgerService@getBalance()` — return available, held, and pending balances for an account | — | BE-WINFRA-10 | Available = balance − active holds |
| BE-WINFRA-19 | `[x]` Create `LedgerIntegrityCheckCommand` — artisan command verifying sum of all ledger entries = 0 (system-wide balance); report mismatches | NF-10 | BE-WINFRA-15 | Schedule daily via cron; alert on mismatch |
| BE-WINFRA-20 | `[x]` Create `AccountResource`, `JournalResource`, `LedgerEntryResource`, `HoldResource`, `PayoutResource`, `BankAccountResource` API resources | — | BE-WINFRA-10 through BE-WINFRA-14 | — |
| BE-WINFRA-21 | `[x]` Create database seeders: system accounts (platform_commission, psp_clearing, refunds) | — | BE-WINFRA-01 | Run on every deploy; idempotent (firstOrCreate) |

---

## 19. Passenger Wallet System

**PRD refs:** P-15, B-08, NF-05, NF-10
**Design note:** Closed-loop wallet — top-up, pay for rides, receive refunds. No withdrawal, no peer-to-peer transfers.

### 19.1 Wallet Funding

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WAL-01 | `[x]` Create `WalletController@show` — `GET /api/v1/wallet`: return passenger's wallet balance (available, held), account status | P-15 | BE-WINFRA-10, SETUP-51 | Auto-creates wallet account on first access if not exists |
| BE-WAL-02 | `[x]` Create `WalletController@transactions` — `GET /api/v1/wallet/transactions`: paginated ledger entries for passenger's wallet with type filter (credits/debits/all), date range | P-15 | BE-WINFRA-11 | — |
| BE-WAL-03 | `[x]` Create `WalletTopupController@store` — `POST /api/v1/wallet/topup`: validate amount against min/max/daily limits, initialize Flutterwave payment, return payment_link for client redirect | P-15 | SETUP-61, BE-WINFRA-10 | ⚠ OQ-25: Min top-up, max balance, and daily cap values TBD |
| BE-WAL-04 | `[x]` Create `StoreTopupFormRequest` — validate amount (positive integer kobo, within configured limits), check daily cap not exceeded, check max balance not exceeded post-top-up | P-15 | BE-WAL-03 | — |
| BE-WAL-05 | `[x]` Create `WalletTopupController@verify` — `GET /api/v1/wallet/topup/{transactionId}/verify`: fallback verification endpoint for missed webhooks; query Flutterwave Verify Transaction API by transaction ID, credit wallet if successful and not already processed | P-15 | BE-WAL-03 | Idempotent via idempotency_key on journal |
| BE-WAL-06 | `[x]` Create `FlutterwaveWalletWebhookController@handle` — `POST /api/v1/webhooks/flutterwave-wallet`: verify `verif-hash` header, deduplicate via webhook_events table, route by event type (charge.completed → credit wallet, transfer.completed/failed → update payout) | P-15 | BE-WINFRA-07, BE-WINFRA-15 | 🔒 Signature verification mandatory; replay-safe; returns 200 immediately, processes async |
| BE-WAL-07 | `[x]` Create `ProcessTopupWebhookJob` — queued job processing verified charge.success webhook: credit passenger wallet via LedgerService, debit psp_clearing account; handle already-processed gracefully | P-15 | BE-WAL-06, BE-WINFRA-15 | 3 retries, 30s backoff |
| BE-WAL-08 | `[x]` Create `ExpireAbandonedTopupsJob` — scheduled job marking top-up transactions older than 30 minutes with no webhook/verification as abandoned | P-15 | BE-WAL-03 | Prevents stale pending states; runs every 15 minutes |

### 19.2 Ride Payment Flow (Wallet)

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WAL-09 | `[x]` Create `WalletPaymentService@placeHold()` — place hold on passenger wallet at ride request or start; validate sufficient available balance; create Hold record with ride association | P-15 | BE-WINFRA-04, BE-WINFRA-18 | Hold amount = fare estimate; returns insufficient_balance error if not enough |
| BE-WAL-10 | `[x]` Create `WalletPaymentService@settle()` — on ride completion, capture hold for final fare amount (may differ from estimate); post journal: debit passenger wallet, credit platform commission + driver earnings; release any hold surplus | P-15 | BE-WAL-09, BE-WINFRA-15 | Final fare may be less or more than hold — handle both |
| BE-WAL-11 | `[x]` Create `WalletPaymentService@releaseHold()` — on ride cancellation or no_driver_found, release hold and restore available balance | P-15 | BE-WAL-09 | — |
| BE-WAL-12 | `[x]` Implement insufficient balance handling — if final fare exceeds hold (route change, waiting time), attempt to capture available balance up to final fare; if still short, flag ride for Admin review with shortfall amount | P-15 | BE-WAL-10 | Resolved: partial settlement captures available balance, records shortfall, marks payment as Failed |
| BE-WAL-13 | `[x]` Create `ExpireStaleHoldsJob` — scheduled job releasing holds older than configurable threshold (default 4 hours) with no associated active ride | P-15 | BE-WAL-09 | Runs hourly; prevents balance lockup from orphaned holds |
| BE-WAL-14 | `[x]` Integrate wallet as payment method in `RideController@store` — accept `payment_method: wallet`; validate sufficient balance; place hold on ride creation | P-15 | BE-WAL-09, BE-RIDE-03 | Extends existing payment_method enum (cash/card/wallet) |
| BE-WAL-15 | `[x]` Integrate wallet settlement into ride completion flow — wire `WalletPaymentService@settle()` into `PaymentService@processRidePayment()` for wallet rides | P-15 | BE-WAL-10, BE-PAY-09 | Settled via PaymentService match arm; creates Payment record with Captured/Failed status |

### 19.3 Wallet Refunds

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WAL-16 | `[x]` Create `WalletRefundService@refundToWallet()` — post journal: debit refunds account, credit passenger wallet; link to ride and dispute; support full and partial refunds | P-15 | BE-WINFRA-15 | ⚠ OQ-27: Refund policy — wallet only, or back to original payment method? |
| BE-WAL-17 | `[x]` Create `WalletRefundService@clawbackDriverEarnings()` — on refund, create corresponding debit entry on driver earnings account; link to original ride settlement journal | P-15 | BE-WAL-16, BE-WINFRA-17 | — |

---

## 20. Driver Earnings Ledger & Payouts

**PRD refs:** B-08, D-10, D-11
**Design note:** Not a wallet — drivers cannot top up. Credits arrive from ride completions net of commission. Payouts to bank accounts require Admin approval.

### 20.1 Commission & Earnings

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-EARN-01 | `[x]` Create migration: `commission_configs` table — id, rate (decimal 5,4, e.g. 0.2000 = 20%), driver_id (FK nullable for per-driver override), is_active (bool default true), created_by_admin_id (FK), created_at, updated_at | D-10 | SETUP-06 | Global default + optional per-driver override; nullable driver_id = global rate |
| BE-EARN-02 | `[x]` Create `CommissionConfig` Eloquent model; `CommissionService@getRate()` — return per-driver override if exists, else global default | — | BE-EARN-01 | — |
| BE-EARN-03 | `[x]` Create `DriverEarningsService@creditRideEarnings()` — on ride completion, calculate commission = fare × rate, net earnings = fare − commission; post journal: debit passenger wallet (or psp_clearing for card/cash), credit driver_earnings_pending + platform_commission | D-10 | BE-EARN-02, BE-WINFRA-15 | Dispatched from ride completion pipeline |
| BE-EARN-04 | `[x]` Create `SettleDriverEarningsJob` — move earnings from pending to available based on settlement rule (configurable delay: instant, 24h, or weekly); post journal: debit driver_earnings_pending, credit driver_earnings_available | D-10 | BE-EARN-03 | OQ-28 resolved: configurable via `config('wallet.settlement_delay')` — instant (default), 24h, or weekly; job runs hourly via scheduler |
| BE-EARN-05 | `[x]` Implement cash-ride commission handling — when payment_method = cash, driver collects fare directly; debit commission from driver_earnings_available; allow negative balance up to configurable threshold | D-10 | BE-EARN-03 | OQ-29 resolved: cash rides always use DriverEarningsAvailable; negative balance allowed up to `max_negative_balance` (default −₦5,000); warning logged when threshold exceeded |
| BE-EARN-06 | `[x]` Create `DriverEarningsController@show` — `GET /api/v1/driver/earnings`: return pending, available, total paid out balances; today/this week/this month summaries | D-10 | BE-WINFRA-10, SETUP-51 | — |
| BE-EARN-07 | `[x]` Create `DriverEarningsController@rideBreakdown` — `GET /api/v1/driver/earnings/rides/{ride}`: fare, commission amount, commission rate, net earnings, payment type, tip (if any) for a specific ride | D-10 | BE-EARN-03 | — |
| BE-EARN-08 | `[x]` Create `DriverLedgerController@index` — `GET /api/v1/driver/ledger`: paginated ledger entries (ride earnings, adjustments, clawbacks, payouts) with date and type filters | D-11 | BE-WINFRA-11 | — |

### 20.2 Bank Account & Payouts

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-EARN-09 | `[x]` Create `BankAccountController@store` — `POST /api/v1/driver/bank-account`: accept bank_code + account_number; call Flutterwave Account Resolve API to verify; save verified account | D-10 | BE-WINFRA-13, SETUP-61 | 🔒 Re-authentication or OTP required for account changes |
| BE-EARN-10 | `[x]` Create `StoreBankAccountRequest` — validate bank_code (from Flutterwave bank list), account_number (10 digits for Nigerian banks) | D-10 | BE-EARN-09 | — |
| BE-EARN-11 | `[x]` Create `BankAccountController@show` — `GET /api/v1/driver/bank-account`: return saved bank account details (masked account number) | D-10 | BE-WINFRA-13 | — |
| BE-EARN-12 | `[x]` Create `PayoutController@store` — `POST /api/v1/driver/payouts`: request payout of available balance; validate minimum payout amount; validate bank account exists and is verified; create Payout record with status=requested | D-10 | BE-WINFRA-14, BE-EARN-09 | ⚠ OQ-30: Minimum payout amount TBD |
| BE-EARN-13 | `[x]` Create `StorePayoutRequest` — validate amount (positive, ≤ available balance, ≥ minimum), no other pending payout exists | D-10 | BE-EARN-12 | — |
| BE-EARN-14 | `[x]` Create `PayoutController@index` — `GET /api/v1/driver/payouts`: list payout history with status filters (requested/approved/processing/paid/failed) | D-10 | BE-WINFRA-14 | — |
| BE-EARN-15 | `[x]` Create `ProcessPayoutTransferJob` — on Admin approval, initiate Flutterwave Transfer to driver's bank account; update payout status to processing; handle Flutterwave Transfer response | D-10 | BE-EARN-12, SETUP-61 | 3 retries, exponential backoff; 🔒 Flutterwave Transfer API keys restricted to server |
| BE-EARN-16 | `[x]` Handle payout transfer webhook — on transfer.success: post journal (debit driver_earnings_available, credit psp_clearing), update payout status=paid; on transfer.failed: update status=failed, record failure_reason | D-10 | BE-WAL-06, BE-EARN-15 | Idempotent via webhook_events table |
| BE-EARN-17 | `[x]` Create `PayoutFailureReversalJob` — on payout failure, reverse the earnings debit if it was pre-debited; notify driver of failure with retry guidance | D-10 | BE-EARN-16, BE-WINFRA-17 | — |

---

## 21. Wallet Administration & Reconciliation

**PRD refs:** A-16, B-08, NF-06, NF-10

### 21.1 Admin Wallet & Ledger Management

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-01 | `[x]` Create `AdminWalletController@index` — `GET /api/v1/admin/wallets`: list passenger wallets with search (name/phone/email), balance range filter, status filter, pagination | A-16 | BE-WINFRA-10, SETUP-52 | — |
| BE-WADM-02 | `[x]` Create `AdminWalletController@show` — `GET /api/v1/admin/wallets/{account}`: wallet detail with balance, active holds, recent transactions | A-16 | BE-WADM-01 | — |
| BE-WADM-03 | `[x]` Create `AdminWalletController@freeze` / `unfreeze` — `POST /api/v1/admin/wallets/{account}/freeze` / `unfreeze`: freeze wallet (blocks all transactions) or unfreeze with mandatory reason; audit log | A-16 | BE-WADM-01 | Frozen wallet rejects top-ups, ride payments, and refunds |
| BE-WADM-04 | `[x]` Create `AdminDriverLedgerController@index` — `GET /api/v1/admin/driver-ledgers`: list driver earnings accounts with pending/available balances, search, pagination | A-16 | BE-WINFRA-10, SETUP-52 | — |
| BE-WADM-05 | `[x]` Create `AdminDriverLedgerController@show` — `GET /api/v1/admin/driver-ledgers/{account}`: driver earnings detail with commission history, payouts, bank account | A-16 | BE-WADM-04 | — |
| BE-WADM-06 | `[x]` Create `AdminLedgerExplorerController@index` — `GET /api/v1/admin/ledger`: global transaction search by reference, user, ride, type, date range; journal view showing both sides of each entry; pagination | A-16 | BE-WINFRA-11, SETUP-52 | — |
| BE-WADM-07 | `[x]` Create `AdminLedgerExplorerController@export` — `GET /api/v1/admin/ledger/export`: CSV export of filtered ledger entries; stream large datasets | A-16 | BE-WADM-06 | — |

### 21.2 Manual Adjustments

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-08 | `[x]` Create migration: `adjustments` table — id, account_id (FK), type (enum: credit/debit), amount (bigint), reason (text), status (enum: pending/approved/rejected), created_by_admin_id (FK), approved_by_admin_id (FK nullable), journal_id (FK nullable), created_at, approved_at (nullable) | NF-06 | BE-WINFRA-01 | Maker-checker: creator ≠ approver |
| BE-WADM-09 | `[x]` Create `Adjustment` Eloquent model with relationships | — | BE-WADM-08 | — |
| BE-WADM-10 | `[x]` Create `AdminAdjustmentController@store` — `POST /api/v1/admin/adjustments`: create pending adjustment (credit or debit) with mandatory reason; does NOT post to ledger until approved | NF-06 | BE-WADM-09, SETUP-52 | 🔒 Audit log on creation |
| BE-WADM-11 | `[x]` Create `AdminAdjustmentController@approve` — `POST /api/v1/admin/adjustments/{adjustment}/approve`: second admin approves; post journal via LedgerService; update adjustment status and link journal_id | NF-06 | BE-WADM-10, BE-WINFRA-15 | 🔒 Approver must differ from creator; audit log |
| BE-WADM-12 | `[x]` Create `AdminAdjustmentController@reject` — `POST /api/v1/admin/adjustments/{adjustment}/reject`: reject with reason; audit log | NF-06 | BE-WADM-10 | — |
| BE-WADM-13 | `[x]` Create `AdminRefundController@refundToWallet` — `POST /api/v1/admin/rides/{ride}/refund`: Admin-initiated refund to passenger wallet from ride detail; full or partial amount | A-16 | BE-WAL-16, SETUP-52 | Linked to dispute if one exists |

### 21.3 Payout Administration

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-14 | `[x]` Create `AdminPayoutController@index` — `GET /api/v1/admin/payouts`: payout queue with status filter, driver search, date range, pagination | A-16 | BE-WINFRA-14, SETUP-52 | — |
| BE-WADM-15 | `[x]` Create `AdminPayoutController@approve` — `POST /api/v1/admin/payouts/{payout}/approve`: approve payout; dispatch `ProcessPayoutTransferJob`; audit log | A-16 | BE-EARN-15 | — |
| BE-WADM-16 | `[x]` Create `AdminPayoutController@reject` — `POST /api/v1/admin/payouts/{payout}/reject`: reject with reason; notify driver; audit log | A-16 | BE-WADM-14 | — |
| BE-WADM-17 | `[x]` Create `AdminPayoutController@retry` — `POST /api/v1/admin/payouts/{payout}/retry`: retry failed payout; re-dispatch transfer job | A-16 | BE-EARN-15 | Only for status=failed payouts |
| BE-WADM-18 | `[x]` Create `AdminPayoutController@export` — `GET /api/v1/admin/payouts/export`: CSV export of payout batch for finance reconciliation | A-16 | BE-WADM-14 | — |

### 21.4 Settings & Configuration

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-19 | `[x]` Create `AdminCommissionController@show` / `update` — `GET/PUT /api/v1/admin/settings/commission`: view and update global commission rate; per-driver override CRUD | A-16 | BE-EARN-01, SETUP-52 | Audit log on changes; changes apply to future rides only |
| BE-WADM-20 | `[x]` Create `AdminWalletSettingsController@show` / `update` — `GET/PUT /api/v1/admin/settings/wallet`: configure min top-up, max balance, daily top-up cap, minimum payout amount, settlement delay, hold expiry, abandoned topup timeout | A-16 | SETUP-52 | Uses `app_settings` key-value table; merges DB overrides with config file defaults |

### 21.5 Reconciliation & Monitoring

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-21 | `[x]` Create `DailyReconciliationJob` — compare Flutterwave settlements (charges + transfers) against ledger entries; flag mismatches for Admin review | NF-10 | BE-WINFRA-15, BE-WAL-06 | Scheduled daily at 02:00; results stored in `reconciliation_reports` table; idempotent per date |
| BE-WADM-22 | `[x]` Create `StuckTransactionSweeperJob` — find pending transactions older than threshold with no webhook received; attempt verification; escalate if unresolved | NF-10 | BE-WAL-05 | Runs every 30 minutes; verifies via Flutterwave API; credits wallet if successful, marks abandoned/failed otherwise |
| BE-WADM-23 | `[x]` Create `NegativeDriverBalanceReportJob` — scheduled report of drivers with negative earnings balance (from cash-ride commission); notify finance team | — | BE-EARN-05 | Weekly on Mondays at 08:00; logs summary with critical threshold breaches |
| BE-WADM-24 | `[x]` Create `AdminReconciliationController@show` — `GET /api/v1/admin/reports/reconciliation`: daily reconciliation view with Flutterwave settlements vs ledger, mismatches flagged | A-16 | BE-WADM-21, SETUP-52 | Also added `GET /reports/reconciliation/history` for paginated report list |
| BE-WADM-25 | `[x]` Create `AdminReconciliationController@walletLiability` — `GET /api/v1/admin/reports/wallet-liability`: total passenger wallet balances (platform liability), commission collected, driver earnings payable | A-16 | BE-WINFRA-10 | — |

### 21.6 Wallet Notifications

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-26 | `[x]` Extend `NotificationType` enum with wallet events: topup_success, topup_failed, ride_wallet_payment, wallet_refund, payout_approved, payout_paid, payout_failed | B-06 | BE-NOTIF-02 | Full enum created at `app/Enums/NotificationType.php` with label() and channel() helpers |
| BE-WADM-27 | `[x]` Create wallet push notification dispatches: send on top-up success/failure, ride wallet deduction, refund credit, payout status changes | B-06 | BE-WADM-26, SETUP-57 | 4 notification classes created; integrated into ProcessTopupWebhookJob, WalletPaymentService, WalletRefundService, ProcessPayoutWebhookJob, AdminPayoutController |

### 21.7 Security & Compliance

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-28 | `[x]` Implement rate limiting on funding and payout endpoints: `throttle:10,1` on top-up, `throttle:3,1` on payout requests | NF-01 | BE-WAL-03, BE-EARN-12 | Applied in `routes/api.php` |
| BE-WADM-29 | `[x]` Implement fraud guardrails: velocity limits (max top-ups per hour/day), unusual amount detection, rapid fund-and-spend patterns | NF-05 | BE-WAL-03 | 🔒 Hourly count (5/hr), daily count (10/day), daily amount cap, max balance check — all enforced in WalletTopupController |
| BE-WADM-30 | `[x]` Ensure RBAC for wallet admin actions: Support (read-only), Finance (payouts + adjustments), Super Admin (settings + freeze) | §8.4 | SETUP-52 | Verified: all wallet admin routes use `admin.role:` middleware with correct scoping |

### 21.8 Testing

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-WADM-31 | `[x]` Create ledger unit tests: postJournal balance invariant, double-spend prevention, idempotent posting, reversal entries, hold capture/release, available balance calculation | — | BE-WINFRA-15 | — |
| BE-WADM-32 | `[x]` Create concurrency tests: parallel debit attempts on same account (only one should succeed), concurrent top-up webhooks (idempotent), concurrent payout requests | — | BE-WINFRA-15 | ⏱ Run with `--parallel` flag |
| BE-WADM-33 | `[x]` Create webhook tests: signature verification, replay detection (duplicate event_id), out-of-order event processing, missing/malformed payloads | — | BE-WAL-06 | — |
| BE-WADM-34 | `[x]` Create Flutterwave integration tests: test-mode top-up end-to-end, transfer initiation and webhook callback | — | BE-WAL-03, BE-EARN-15 | Uses Flutterwave test keys |

---

## 22. Fleet Agreement & Remittance Tracking

**PRD refs:** Fleet hire-to-own model — remittance-first per-ride allocation

**Design note:** First ₦X of each ride fare goes to E-tiGo until the vehicle cost is fully remitted; remainder goes to the driver. Drivers see full financial terms including total_vehicle_cost (Q7 reversal — hire-purchase disclosure). Daily settlement job at 4 AM WAT tracks shortfall streaks with 3-7-14 day tiered escalation.

### 22.1 Schema & Migrations

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-01 | `[x]` Create migration: `fleet_agreements` table — id (UUID), driver_id (FK), vehicle_id (FK), daily_remittance_target (decimal 12,2), total_vehicle_cost (decimal 14,2), total_remitted (decimal 14,2 default 0), agreement_start_date (date), status (string default 'active'), shortfall_streak_days (int default 0), terminated_reason (text nullable), terminated_at, completed_at, paused_at, created_by_admin_id (FK nullable, nullOnDelete to users), timestamps; indexes on (driver_id, status) and (vehicle_id, status) | — | SETUP-06 | Admin deletion nullifies created_by_admin_id instead of cascading |
| BE-FLEET-02 | `[x]` Create migration: `daily_remittances` table — id (UUID), agreement_id (FK cascadeOnDelete), driver_id (FK), date, target_amount (decimal 12,2), remitted_amount (decimal 12,2 default 0), shortfall_amount (decimal 12,2 default 0), driver_earnings (decimal 12,2 default 0), total_fares (decimal 12,2 default 0), ride_count (int default 0), settled (bool default false), target_met_at (nullable), timestamps; unique on (agreement_id, date); indexes on (driver_id, date) and (settled, date) | — | BE-FLEET-01 | — |

### 22.2 Models & Enums

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-03 | `[x]` Create `FleetAgreementStatus` enum: Active, Paused, Terminated, Completed | — | — | Backed PHP enum with string values |
| BE-FLEET-04 | `[x]` Create `FleetAgreement` Eloquent model with relationships: driver(), vehicle(), createdByAdmin(), dailyRemittances(); scopes: active(), forDriver(); helpers: isActive(), isCompleted(), isTerminated(), progressPercentage(), remainingAmount() | — | BE-FLEET-01 | HasUuids, HasFactory |
| BE-FLEET-05 | `[x]` Create `DailyRemittance` Eloquent model with relationships: agreement(), driver(); scopes: unsettled(), forDate(); helpers: shortfall(), hasMetTarget() | — | BE-FLEET-02 | HasUuids, HasFactory |
| BE-FLEET-06 | `[x]` Create `FleetAgreementFactory` with states: terminated(), completed(), paused() | — | BE-FLEET-04 | — |
| BE-FLEET-07 | `[x]` Create `DailyRemittanceFactory` | — | BE-FLEET-05 | — |
| BE-FLEET-08 | `[x]` Add `activeFleetAgreement()` relationship on Driver model; add `is_fleet` flag support on Vehicle model | — | BE-FLEET-04 | — |

### 22.3 Fleet Remittance Service

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-09 | `[x]` Create `FleetRemittanceService@createAgreement()` — validate no existing active agreement for driver, vehicle must be fleet-flagged; create agreement in DB::transaction with audit log | — | BE-FLEET-04 | Throws DomainException on validation failures |
| BE-FLEET-10 | `[x]` Create `FleetRemittanceService@updateAgreement()` — only active agreements; audit log with old/new values | — | BE-FLEET-09 | — |
| BE-FLEET-11 | `[x]` Create `FleetRemittanceService@terminateAgreement()` — set status=terminated with reason and timestamp; audit log | — | BE-FLEET-09 | Rejects already completed/terminated |
| BE-FLEET-12 | `[x]` Create `FleetRemittanceService@pauseAgreement()` / `resumeAgreement()` — toggle between active/paused; audit log | — | BE-FLEET-09 | — |
| BE-FLEET-13 | `[x]` Create `FleetRemittanceService@recordRideRemittance()` — lock agreement + remittance rows (lockForUpdate), compute split: min(fare, daily_target_remaining, vehicle_cost_remaining) → E-tiGo, remainder → driver; increment counters; auto-complete agreement when total_vehicle_cost reached | — | BE-FLEET-05 | 🔒 Row-level locking prevents concurrent ride race conditions; caps remittance at remaining vehicle cost |
| BE-FLEET-14 | `[x]` Create `FleetRemittanceService@settleDay()` — lock remittance row (lockForUpdate), mark settled, update shortfall streak on agreement (increment on shortfall, reset on target met) | — | BE-FLEET-13 | 🔒 Locked-row check prevents double settlement |
| BE-FLEET-15 | `[x]` Create `FleetRemittanceService@getDriverTodayRemittance()` / `getDriverRemittanceHistory()` — driver-facing read methods | — | BE-FLEET-05 | — |

### 22.4 Admin Endpoints

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-16 | `[x]` Create `AdminFleetAgreementController@index` — `GET /api/v1/admin/fleet-agreements`: list with status and driver_id filters, pagination | — | BE-FLEET-04, SETUP-52 | Admin-only |
| BE-FLEET-17 | `[x]` Create `AdminFleetAgreementController@store` — `POST /api/v1/admin/fleet-agreements`: create agreement with driver, vehicle, daily target, total cost, start date | — | BE-FLEET-09 | — |
| BE-FLEET-18 | `[x]` Create `AdminFleetAgreementController@show` — `GET /api/v1/admin/fleet-agreements/{id}`: full agreement with 30 most recent daily remittances | — | BE-FLEET-04 | Eager-loads dailyRemittances (latest 30) |
| BE-FLEET-19 | `[x]` Create `AdminFleetAgreementController@update` — `PUT /api/v1/admin/fleet-agreements/{id}`: update daily target or total cost on active agreement | — | BE-FLEET-10 | — |
| BE-FLEET-20 | `[x]` Create `AdminFleetAgreementController@terminate` / `pause` / `resume` — `POST /api/v1/admin/fleet-agreements/{id}/terminate|pause|resume` | — | BE-FLEET-11, BE-FLEET-12 | — |
| BE-FLEET-21 | `[x]` Create `StoreFleetAgreementRequest`, `UpdateFleetAgreementRequest`, `TerminateFleetAgreementRequest` form requests | — | BE-FLEET-17 | — |

### 22.5 Driver Endpoints

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-22 | `[x]` Create `DriverRemittanceController@today` — `GET /api/v1/driver/remittance/today`: daily remittance progress | — | BE-FLEET-15 | Returns null when no active agreement |
| BE-FLEET-23 | `[x]` Create `DriverRemittanceController@history` — `GET /api/v1/driver/remittance/history`: paginated remittance history | — | BE-FLEET-15 | — |
| BE-FLEET-24 | `[x]` Create `DriverRemittanceController@fleetAgreement` — `GET /api/v1/driver/fleet-agreement`: agreement summary with full financial terms | — | BE-FLEET-04 | Q7 reversed: total_vehicle_cost and remaining_amount now visible to drivers (hire-purchase disclosure) |

### 22.6 Resources & Background Jobs

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-25 | `[x]` Create `FleetAgreementResource` — total_vehicle_cost/remaining_amount visible to all (Q7 reversal); admin-only fields: shortfall_flag, vehicle_return_status, outstanding_amount, settlement_amount, settlement_notes; includes daily_remittances when loaded | — | BE-FLEET-04 | Uses `$request->user()->isAdmin()` for admin-only settlement fields |
| BE-FLEET-26 | `[x]` Create `DailyRemittanceResource` | — | BE-FLEET-05 | — |
| BE-FLEET-27 | `[x]` Create `DailyRemittanceSettlementJob` — ensures every active agreement has a DailyRemittance row for the date (including zero-ride days), then settles all unsettled remittances | — | BE-FLEET-14 | 3 retries; creates missing rows before settlement to capture zero-ride shortfalls |

### 22.7 Tests

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-28 | `[x]` Create admin fleet agreement tests (18 tests): CRUD, validation, audit logs, status transitions, Q7 financial transparency, vehicle swap with/without carry-over, terminate with settlement, excuse day, zero commission | — | BE-FLEET-16 through BE-FLEET-21, BE-FLEET-30 through BE-FLEET-40 | Updated from 12 to 18 tests for Phase 1.5 |
| BE-FLEET-29 | `[x]` Create driver remittance tests (14 tests): today, history, ride accumulation, driver earnings after target met, auto-completion, shortfall streaks, shortfall reset, non-active rejection, progress percentage, Q7 financial terms visible, excused days don't increment streak, shortfall escalation flags, no re-flagging | — | BE-FLEET-22 through BE-FLEET-24, BE-FLEET-13, BE-FLEET-35 | Updated from 12 to 14 tests for Phase 1.5 |

### 22.8 Phase 1.5 — Client Decision Response Implementation

**Context:** Following the client's Fleet Decisions Report (October 2026), several features were identified as immediately implementable. The Chairman directed the team to follow industry standard practices.

#### 22.8.1 Schema Updates

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-30 | `[x]` Create migration: add `excused_reason` (text nullable) to `daily_remittances` table | Client Q2 | BE-FLEET-02 | Tracks why a day was excused (e.g., vehicle_downtime, approved_leave) |
| BE-FLEET-31 | `[x]` Create migration: add to `fleet_agreements` — `shortfall_flag` (string nullable), `shortfall_flagged_at` (timestamp nullable), `vehicle_return_status` (string nullable), `outstanding_amount` (decimal 14,2 nullable), `settlement_amount` (decimal 14,2 nullable), `settlement_notes` (text nullable), `settled_at` (timestamp nullable) | Client Q2,Q4 | BE-FLEET-01 | Supports escalation flagging and settlement tracking |

#### 22.8.2 Configuration

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-32 | `[x]` Create `config/fleet.php` — configurable daily_reset_time (default 04:00), shortfall thresholds (3/7/14 days), default_daily_target (40000) | Client Q2 | — | All values env-overridable |

#### 22.8.3 Service Layer Updates

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-33 | `[x]` Wire `FleetRemittanceService@recordRideRemittance()` into `ProcessPaymentJob@handle()` — automatic per-ride fleet remittance recording after payment processing | Critical gap | BE-FLEET-13 | Without this the fleet system was inert — fares were not being recorded |
| BE-FLEET-34 | `[x]` Add zero-commission enforcement in `CommissionService@getRate()` — return 0.0 for drivers with active fleet agreements | Client Q6 | BE-FLEET-04 | Early return before override/global/default logic |
| BE-FLEET-35 | `[x]` Update `FleetRemittanceService@settleDay()` to skip excused days (no streak increment/reset) | Client Q2 | BE-FLEET-30 | Excused days are neutral — streak stays unchanged |
| BE-FLEET-36 | `[x]` Create `FleetRemittanceService@excuseDay()` — mark remittance as excused, decrement streak by 1, create audit log | Client Q2 | BE-FLEET-30 | Admin action with reason categories |
| BE-FLEET-37 | `[x]` Create `FleetRemittanceService@swapVehicle()` — terminate current agreement, unassign old vehicle, assign new vehicle, create new agreement with optional carry-over of total_remitted | Client Q3 | BE-FLEET-11 | Single transaction; audit log records old and new agreement IDs |
| BE-FLEET-38 | `[x]` Create `FleetRemittanceService@terminateWithSettlement()` — terminate with vehicle_return_status, outstanding_amount, settlement_amount, settlement_notes | Client Q4 | BE-FLEET-11 | Full settlement audit trail |

#### 22.8.4 Background Jobs

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-39 | `[x]` Create `RemittanceShortfallAlertJob` — check active agreements against shortfall thresholds, assign flags: warning (3+ days), review (7+ days), escalated (14+ days); create audit log entries | Client Q2 | BE-FLEET-31, BE-FLEET-32 | Only updates if flag level changed; runs daily at fleet reset time |
| BE-FLEET-40 | `[x]` Schedule `DailyRemittanceSettlementJob` and `RemittanceShortfallAlertJob` at configurable time (4 AM WAT default) via `routes/console.php` | Client Q2 | BE-FLEET-27, BE-FLEET-39 | Uses `config('fleet.daily_reset_time')`, timezone `Africa/Lagos` |

#### 22.8.5 Admin Endpoints (Phase 1.5)

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-41 | `[x]` Create `AdminFleetAgreementController@swap` — `POST /api/v1/admin/fleet-agreements/{id}/swap` | Client Q3 | BE-FLEET-37 | Validates via `SwapFleetVehicleRequest` |
| BE-FLEET-42 | `[x]` Create `AdminFleetAgreementController@terminateWithSettlement` — `POST /api/v1/admin/fleet-agreements/{id}/terminate-settle` | Client Q4 | BE-FLEET-38 | Validates via `TerminateWithSettlementRequest` |
| BE-FLEET-43 | `[x]` Create `AdminFleetAgreementController@excuseDay` — `POST /api/v1/admin/fleet-agreements/{id}/remittances/{remittance_id}/excuse` | Client Q2 | BE-FLEET-36 | Validates via `ExcuseRemittanceDayRequest`; verifies remittance belongs to agreement |
| BE-FLEET-44 | `[x]` Create `SwapFleetVehicleRequest`, `TerminateWithSettlementRequest`, `ExcuseRemittanceDayRequest` form requests | — | BE-FLEET-41 through BE-FLEET-43 | — |

#### 22.8.6 Resource & Model Updates

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-FLEET-45 | `[x]` Update `FleetAgreementResource` — remove admin-only restriction on total_vehicle_cost/remaining_amount (Q7 reversal); add admin-only shortfall_flag, vehicle_return_status, settlement fields | Client Q7 | BE-FLEET-25, BE-FLEET-31 | Hire-purchase disclosure requirement |
| BE-FLEET-46 | `[x]` Update `DailyRemittanceResource` — add `excused_reason` (conditionally shown when not null) | — | BE-FLEET-30, BE-FLEET-26 | — |
| BE-FLEET-47 | `[x]` Update `FleetAgreementStatus::Completed` label to "Financially Complete"; add `isFinished()` helper | Client Q8 | BE-FLEET-03 | Separates payment completion from ownership transfer |
| BE-FLEET-48 | `[x]` Update `DailyRemittance` model — add `excused_reason` to fillable, add `isExcused()`, `scopeExcused()`, `scopeNotExcused()` | — | BE-FLEET-30 | — |
| BE-FLEET-49 | `[x]` Update `FleetAgreement` model — add new fields to fillable and casts | — | BE-FLEET-31 | — |

---

# PHASE 2 — Month-3 Rollout (Weeks 9–12)

> Working draft — must be reviewed and re-frozen before Week 9.

---

## 23. Phase 2 — Scheduled Rides

**PRD refs:** B-27, P-20–P-22, D-12, A-33, NF-11

> **Built (16 Oct 2026).** Industry-standard model (Uber Reserve / Bolt / Lyft): price locked at booking for the pickup time, drivers can reserve a booking in advance, matching (or the hand-over to the reserved driver) starts shortly before pickup and searches until a grace period after it. Design: [TECHNICAL.md §6.1](../TECHNICAL.md#61-scheduled-rides-phase-2); endpoints: [API reference — Scheduled Rides](../API_REFERENCE.md#scheduled-rides); **every default, its industry benchmark and the open client questions: [SCHEDULED_RIDES_DECISIONS.md](../../client/SCHEDULED_RIDES_DECISIONS.md)**; app changes: [FRONTEND_UPDATE_SCHEDULED_RIDES.md](../FRONTEND_UPDATE_SCHEDULED_RIDES.md). Defaults (operations-editable): pickup 30 min – **3 days** ahead, **3** upcoming bookings per passenger, **no reservation fee**, **late-cancellation fee = economy base fare within 30 min**, **balance due** for what cannot be collected (all set by the client, 16 Oct 2026); not yet client-confirmed: dispatch 15 min before, reminder 60 min before, search until 10 min after pickup, passenger bookings 30 min apart, 5 reservations per driver 60 min apart.

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-SCH-01 | `[x]` Create migration: `scheduled_rides` table — id, ride_id (FK), scheduled_pickup_at, reminder_sent_at (nullable), dispatched_at (nullable), status (enum: scheduled/reminded/dispatching/dispatched/cancelled), created_at | B-27 | SETUP-14 | Built without a status column: the ride's own `status` (`scheduled`) is the state, and the timestamps (`reminder_sent_at`, `dispatched_at`, `cancelled_at`) plus `dispatch_failure_reason` record the lifecycle. New `RideStatus::Scheduled` (`scheduled → searching / cancelled`) |
| BE2-SCH-02 | `[x]` Create `ScheduledRide` Eloquent model with relationship: ride() | — | BE2-SCH-01 | Plus `Ride::scheduledRide()` |
| BE2-SCH-03 | `[x]` Extend `RideController@store` — accept optional `scheduled_pickup_at` → create ride in Scheduled state instead of Requested | P-20 | BE2-SCH-02, BE-RIDE-03 | ISO 8601 with UTC offset required. No PIN, wallet hold or matching until dispatch; wallet balance checked at booking. Surge evaluated at the pickup time (no demand surge). `POST /rides/estimate` also takes `scheduled_pickup_at` |
| BE2-SCH-04 | `[x]` Create `ScheduledRideDispatchJob` — durable scheduled job; triggers at (scheduled_time − lead_time); transitions ride Scheduled → Requested → Searching; starts matching | B-27, NF-11 | BE2-SCH-03, BE-MATCH-01 | `ProcessScheduledRidesJob` (scheduler, every minute) queues one `ScheduledRideDispatchJob` per due booking; state lives in the database, so restarts and deploys lose nothing. Goes `scheduled → searching` directly (places the hold, issues the PIN). Bookings that cannot start are cancelled with `scheduled_dispatch_failed` and a reason the passenger is told |
| BE2-SCH-05 | `[x]` Create `ScheduledRideReminderJob` — send push notification at Admin-configured reminder time before pickup | P-21 | BE2-SCH-04, SETUP-57 | `scheduled_ride_reminder`; warns wallet passengers whose balance is short. Also `scheduled_ride_dispatched` when matching starts |
| BE2-SCH-06 | `[x]` Create `ScheduledRideController@index` — `GET /api/v1/rides/scheduled`: list upcoming scheduled rides for passenger | P-22 | BE2-SCH-02 | Soonest first, paginated. Admin list: `GET /admin/scheduled-rides` |
| BE2-SCH-07 | `[x]` Create `ScheduledRideController@update` — `PUT /api/v1/rides/{ride}/scheduled`: edit time, pickup, destination before dispatch | P-22 | BE2-SCH-06 | Also payment method. Allowed until `dispatch_at`; re-quotes the fare and re-checks the promo |
| BE2-SCH-08 | `[x]` Create `ScheduledRideController@cancel` — `DELETE /api/v1/rides/{ride}/scheduled`: cancel before dispatch | P-22 | BE2-SCH-06 | `ScheduledRideController@destroy`; `POST /rides/{ride}/cancel` works too |
| BE2-SCH-09 | `[x]` Create `AdminScheduledRideConfigController@update` — `PUT /api/v1/admin/config/scheduled-rides`: set lead-time window and reminder timing | A-33 | SETUP-52 | Built as `AdminScheduledRideController` at `GET/PUT /admin/settings/scheduled-rides` (operations), alongside the other settings endpoints. Covers enable/disable, booking window, dispatch lead, reminder, search grace, wave delay and per-passenger limits. Booking rules also in `GET /config` |
| BE2-SCH-10 | `[x]` Driver advance reservations — `GET /driver/scheduled-rides/available`, `GET /driver/scheduled-rides`, `POST /driver/scheduled-rides/{ride}/reserve`, `POST /driver/scheduled-rides/{ride}/release` (`ScheduledRideReservationService`) | D-12, B-27 | BE2-SCH-04 | Uber Reserve model. Same city and class, ≤ 5 reservations, 60 min apart, until `dispatch_at`. Passenger sees the driver; a new pickup time/place releases it |
| BE2-SCH-11 | `[x]` Dispatch straight to the reserved driver when online, fresh location, free and eligible; otherwise release and fall back to matching (`RideService::assignReservedDriver`) | B-27 | BE2-SCH-10 | No offer round; `driver_unavailable` release notifies both |
| BE2-SCH-12 | `[x]` Protect reserved pickups: no on-demand offers to a driver within 60 min of their reserved pickup; driver reminder 60 min before | B-27 | BE2-SCH-10 | `driver_reservation_buffer_minutes` |
| BE2-SCH-13 | `[x]` Admin pre-assignment — `POST/DELETE /admin/scheduled-rides/{ride}/assign` | A-33 | BE2-SCH-10 | Assign, move or remove a driver before dispatch |
| BE2-SCH-14 | `[x]` Reservation fee added to scheduled quotes and the final fare | B-27 | BE2-SCH-03 | **Client (16 Oct 2026): no charge for reservations** — stays ₦0; the setting remains for later |
| BE2-SCH-15 | `[x]` Late-cancellation fee on reserved bookings (`ScheduledRideCancellationFeeService`, `ChargeScheduledRideCancellationFeeJob`) | B-27 | BE2-SCH-10 | **Client (16 Oct 2026): the economy base fare of the ride's city, within 30 min of the ride time** (`late_cancellation_fee_basis` `economy_base_fare`; `flat`/`off` available). Paid to the reserved driver in full; safety reasons exempt; shown in advance as `cancellation_fee_if_cancelled_now`. Uncollectable → balance due (BE2-SCH-16) |
| BE2-SCH-16 | `[x]` Passenger balance due (`PassengerBalanceService`, `passenger_balances_due`): uncollectable late-cancellation fees (cash/bank transfer, short wallet, declined card) and wallet fare shortfalls; `GET /passenger/balance-due`, `POST /passenger/balance-due/pay` (wallet or card, all at once); booking refused with 422 `BALANCE_DUE` while outstanding; admin `GET /admin/balances-due`, `POST /admin/balances-due/{id}/waive` | B-27, BE-WAL-12 | BE2-SCH-15 | **Client decision (16 Oct 2026).** Collection rules (block booking; driver paid when collected) are engineering defaults — OQ-34, OQ-35. Wallet setting `block_booking_with_balance_due` |

---

## 24. Phase 2 — Third-Party Bookings

**PRD refs:** B-28, P-23, P-24, D-13, A-34

> **Built (18 Oct 2026)** to the OQ-18 recommendation below. API: [API reference — Third-Party Bookings](../API_REFERENCE.md#third-party-bookings); design: [TECHNICAL.md §6.2](../TECHNICAL.md#62-third-party-bookings-phase-2); app changes: [FRONTEND_UPDATE_THIRD_PARTY_BOOKINGS.md](../FRONTEND_UPDATE_THIRD_PARTY_BOOKINGS.md). The defaults are settings, so a different client answer is a configuration change.

> **OQ-18 answered (16 Oct 2026, engineering recommendation, not yet client-confirmed):** PIN and live tracking go to **both** the rider (WhatsApp, SMS fallback, with a no-app tracking link) and the booker (in app). Operations can switch either to rider-only or booker-only. If the rider's message fails, the booker is always shown the PIN. Booker pays by wallet or card only (cash off by default); 2 active third-party rides per booker; 10 different rider numbers per booker per day. Options and edge cases: [PHASE2_DECISIONS.md — OQ-18](../../client/PHASE2_DECISIONS.md#oq-18--third-party-bookings-who-gets-the-pin-and-tracking).

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-3P-01 | `[x]` Create migration: add to `rides` table — booker_user_id (FK nullable), rider_name (nullable), rider_phone (nullable, E.164), is_third_party (bool default false), pin_recipient and tracking_recipient (enum: booker/rider/both, nullable; snapshot of the policy at booking), rider_notification_status (enum: pending/sent_whatsapp/sent_sms/delivered/failed, nullable), rider_notified_at (nullable) | B-28 | SETUP-14 | Snapshot means a policy change never affects rides already booked. **Built (18 Oct 2026).** The booker is the ride's `passenger_id`, so there is no separate `booker_user_id`: payment, cancellation, rating and history work unchanged. Columns: `is_third_party`, `rider_name`, `rider_phone`, `pin_recipient`, `tracking_recipient`, `rider_notification_status`, plus `rider_notification_channel`, `rider_notified_at`; index (`passenger_id`, `is_third_party`, `status`) |
| BE2-3P-02 | `[x]` Store the third-party policy as an AppSetting group (`third_party`), like the scheduled-ride settings, instead of a `third_party_configs` table: pin_recipient (default **both**), tracking_recipient (default **both**), allow_cash (default false), allow_bank_transfer (default false), max_active_per_booker (default 2), max_rider_numbers_per_day (default 10), enabled | A-34 | SETUP-06 | OQ-18 recommended (awaiting client confirmation): defaults per recommendation. **Built.** `AppSetting::thirdParty()` over `config/third_party.php`; also `rider_trip_updates` (arrival/cancellation messages, default on). Exposed to the apps as `third_party_bookings` in `GET /config` |
| BE2-3P-03 | `[x]` Extend `RideController@store` — accept rider_name and rider_phone for third-party booking; set booker as authenticated user. Validate: rider phone ≠ booker phone (else treat as normal ride); payment method allowed by policy; booker under the active and daily-number limits; booker confirms the rider is 18+ or accompanied | P-23 | BE2-3P-01, BE-RIDE-03 | A third-party ride does not count as the booker's own active ride. Never reveal whether rider_phone has an E-tiGo account. **Built.** `ThirdPartyBookingService::prepare` under the booker's row lock; fields `rider_name`, `rider_phone` (E.164 or Nigerian local), `rider_age_confirmed`. The daily-number limit is a rolling 24 h and counts cancelled bookings. Own-ride checks use `Ride::ownTrips()`; `GET /passenger/active-ride` adds `third_party_rides`; `GET /rides?is_third_party=` |
| BE2-3P-04 | `[x]` Create `ThirdPartyRoutingService` — route PIN and live tracking per the ride's snapshot: send the rider a WhatsApp message (SMS fallback via the existing OTP channel chain) on driver assignment with booker first name, driver name/photo, car, plate, PIN and share link; show PIN/tracking to the booker in the ride resource when the policy includes the booker. **Delivery status contract:** `rider_notification_status` starts `pending`; WhatsApp accepted → `sent_whatsapp`; WhatsApp fails → SMS attempt → `sent_sms`; both fail → `failed`; a provider delivery receipt (where the channel gives one) → `delivered`. Exposed to the booker in the ride resource and broadcast on change (`ride.rider_notification.updated` on the booker's channel); re-sends (PIN regeneration) restart at `pending` | P-24, D-13, B-28 | BE2-3P-02, BE-RIDE-05 | Changes §9.1 PIN flow for third-party rides. Reuse the existing share token link (expires 1 h after the trip). **Built.** Queued from `RideStateMachine` on `driver_en_route`; `SendThirdPartyRiderMessageJob` (3 tries) via the new `SmsGateway::deliver` (returns the channel). Status broadcast on `user.{bookerId}`. `delivered` reserved: Termii delivery reports not consumed yet. No photo in the text message; the tracking page shows it. No-app page at `GET /track/{ride}/{token}`; the public share endpoint now returns the car, live position, ETA and safety contacts |
| BE2-3P-05 | `[x]` Create `AdminThirdPartyConfigController` — `GET`/`PUT /api/v1/admin/settings/third-party`: view and set the policy; audit-logged | A-34 | BE2-3P-02, SETUP-52 | Operations role, like scheduled-ride settings. **Built** as `AdminThirdPartySettingsController`, operations role, audit `third_party_settings.updated` |
| BE2-3P-06 | `[x]` Delivery fallback: when `rider_notification_status` becomes `failed`, notify the booker and expose the PIN to the booker regardless of policy | P-24 | BE2-3P-04 | Without this the ride cannot start. **Built.** The job's `failed()` marks `failed` (only while before pickup) and sends the booker `third_party_rider_unreachable` (critical push); `Ride::bookerSeesPin()` / `bookerSeesTracking()` then open the PIN and tracking |
| BE2-3P-07 | `[x]` PIN regeneration by the booker re-sends the new PIN to the rider when the policy includes the rider | P-24 | BE2-3P-04 | **Built.** Status restarts at `pending`, except after `failed` (the booker keeps the PIN) |
| BE2-3P-08 | `[x]` Scheduled third-party rides: rider reminder 60 min before pickup (booking details, no PIN); PIN and tracking sent at dispatch | P-23 | BE2-3P-04, BE2-SCH-04 | PINs are issued at dispatch for all scheduled rides. **Built.** The rider reminder goes with the booker's scheduled-ride reminder (`reminder_minutes`, 60); a booking already inside that window, or an edit with no reminder to come, tells the rider at once. Bookings for different riders don't clash on the minimum gap; dispatch isn't held up by the booker's own ride; editing to a disallowed payment method is refused |
| BE2-3P-09 | `[x]` Driver view: rider first name and rider phone (callable while the trip is active) instead of the booker's; safety: booker SOS on a third-party ride includes rider name and phone in the incident | D-13 | BE2-3P-03, BE-SOS-03 | Tracking page shows "Call 112" and the safety line. **Built.** `rider` object on ride resources (driver sees the phone until the trip ends); the driver sees only the booker's id and first name; ride offers and driver scheduled lists show the rider's first name. Booker SOS: telemetry `third_party_rider`, incident GPS = the car's live position, WhatsApp alert names rider and booker |
| BE2-3P-10 | `[x]` Tests: each policy combination, delivery failure fallback, rider = booker phone, payment restrictions, limits, scheduled third-party ride, policy change after booking | — | BE2-3P-01–09 | **Built.** `tests/Feature/ThirdPartyBookingTest.php` (48 tests) |

---

## 25. Phase 2 — Lost & Found

**PRD refs:** B-29, P-25, P-26, D-14, A-35

> **Built (18 Oct 2026)** to the Uber/Bolt/Lyft model: the passenger reports within 30 days, the driver answers, the two arrange the return through messages on the report (and each other's number once the driver has the item), the driver marks it returned and the passenger confirms. Support steps in when the passenger asks, the driver is silent for 24 h, or the return is 7 days late. API: [API reference — Lost & Found](../API_REFERENCE.md#lost--found); design: [TECHNICAL.md §6.3](../TECHNICAL.md#63-lost--found-phase-2); app changes: [FRONTEND_UPDATE_LOST_AND_FOUND.md](../FRONTEND_UPDATE_LOST_AND_FOUND.md). Every default is an operations setting (`/admin/settings/lost-and-found`). **Not client-confirmed:** the return fee (OQ-36, built off at ₦0, ₦2,000 recommended) and sharing phone numbers (OQ-37, on): [PHASE2_DECISIONS.md](../../client/PHASE2_DECISIONS.md#oq-36-and-oq-37--lost--found-return-fee-and-contact-details).

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-LF-01 | `[x]` Create migration: `lost_items` table — id, ride_id (FK), reported_by_user_id (FK), category (enum), description (text), status (enum: reported/driver_notified/driver_confirmed/driver_denied/resolved/unresolved), driver_response (text nullable), admin_notes (text nullable), resolved_by_admin_id (FK nullable), created_at, updated_at, resolved_at (nullable) | B-29 | SETUP-14 | **Built.** Also `reference_number` (`LF-`, Postgres sequence), `passenger_id`, `driver_id`, `reported_by` (passenger/driver), `photos`, `contact_phone`, timeline columns (`driver_notified_at`, `driver_responded_at`, `returned_at`, `escalated_at`, `closed_at`, `cancelled_at`), `escalation_reason`/`escalation_source`, `closure_reason`, `resolution_notes`, `return_fee`/`return_fee_status`. Statuses add `returned` and `cancelled`. Admin notes are internal rows in the new `lost_item_messages` table. One open report per ride (partial unique index) |
| BE2-LF-02 | `[x]` Create `LostItem` Eloquent model with relationships: ride(), reportedBy(), resolvedBy() | — | BE2-LF-01 | **Built.** Plus `passenger()`, `driver()`, `messages()`, scopes `open()` / `needsAttention()`, `actionsFor()` and `sharesContactDetails()`. Enums `LostItemStatus`, `LostItemCategory` (10), `LostItemClosureReason`. `LostItemService` makes every change under a row lock, audit-logged |
| BE2-LF-03 | `[x]` Create `LostItemController@store` — `POST /api/v1/rides/{ride}/lost-items`: passenger reports lost item against completed trip | P-25 | BE2-LF-02 | **Built.** Completed trips within `report_window_days` (30); ≤ 3 open reports per passenger; up to 3 photos; optional `contact_phone` (texted updates when the lost item is the phone). The same endpoint lets the **driver hand in an item they found** (starts `driver_confirmed`, passenger told). No active driver → `reported` and straight to support |
| BE2-LF-04 | `[x]` Create `LostItemController@index` — `GET /api/v1/lost-items`: list reports for authenticated user | P-26 | BE2-LF-02 | **Built.** Passenger: their reports; driver: reports on their trips. Filters `status`, `open`, `ride_id`. Also `GET /lost-items/{id}` with messages, and `available_actions` for the buttons |
| BE2-LF-05 | `[x]` Dispatch push notification to driver on new lost-item report | D-14 | BE2-LF-03, SETUP-57 | **Built.** `lost_item_report` (also the 24 h reminder); new `lost_item_update` (status changes, to passenger or driver) and `lost_item_message` |
| BE2-LF-06 | `[x]` Create `LostItemController@respond` — `POST /api/v1/lost-items/{item}/respond`: driver confirms or denies possession | D-14 | BE2-LF-02 | **Built.** `found` + optional `note`; "found" is accepted after "not found". Return flow: `POST /lost-items/{id}/returned` (driver), `/received` (passenger), `/cancel` (passenger, before the driver has it), `/escalate` (passenger, once), `/messages` (both) |
| BE2-LF-07 | `[x]` Create `AdminLostItemController@index` — `GET /api/v1/admin/lost-items`: queue with trip detail, category, status | A-35 | BE2-LF-02, SETUP-52 | **Built.** Roles `support`, `operations`. Escalated open reports first; filters `status` (comma list), `needs_attention`, `open`, `category`, `city_id`, `ride_id`, `passenger_id`, `driver_id`, `search`, `from`/`to`; `summary` counts. `GET /admin/lost-items/{id}` with messages and internal notes |
| BE2-LF-08 | `[x]` Create `AdminLostItemController@intervene` — `POST /api/v1/admin/lost-items/{item}/intervene`: Admin resolves or adds notes | A-35 | BE2-LF-07 | **Built.** `action`: `note` (internal), `message` (to both), `mark_found`, `resolve` (`waive_return_fee`), `close` (`closure_reason`) |
| BE2-LF-09 | `[x]` Create `LostItemResource` API resource | — | BE2-LF-02 | **Built.** Cut per viewer (`viewer_role`): the other side's first name only, phone numbers only in `contact` while the driver has the item; internal notes for support only. `LostItemMessageResource` |
| BE2-LF-10 | `[x]` Hourly sweep `ProcessLostItemsJob` (`LostItemService::processOverdue`) | B-29 | BE2-LF-06 | **Built.** Silent driver after `driver_response_hours` (24) → support + driver reminder; confirmed but not returned after `return_deadline_days` (7) → support; `returned` undisputed for `auto_resolve_hours` (72) → `resolved`; `driver_denied` not followed up for `denied_auto_close_days` (7) → `unresolved`; retries pending return fees |
| BE2-LF-11 | `[x]` Return fee (`LostItemReturnFeeService`) | B-29 | BE2-LF-06, BE2-SCH-16 | **Built, off by default (OQ-36).** Fixed when the driver confirms; on return taken from the wallet to the driver in full, or recorded as a balance due (`lost_item_return_fee`); one fee per trip; support can waive |
| BE2-LF-12 | `[x]` Settings `GET/PUT /admin/settings/lost-and-found` (operations, audit-logged) and `lost_and_found` in `GET /config` | A-35 | BE2-LF-02 | **Built.** `AppSetting::lostAndFound()` over `config/lost_and_found.php`. `/config` gives `enabled`, `report_window_days`, `return_fee`, `categories` |
| BE2-LF-13 | `[x]` Tests | — | BE2-LF-01–12 | **Built.** `tests/Feature/LostAndFoundTest.php` (44 tests) |

---

## 26. Phase 2 — Advanced Disputes

**PRD refs:** B-30, P-27, A-36, A-37, NF-12

> **OQ-21 answered (16 Oct 2026, engineering recommendation, not yet client-confirmed):** three tiers. **Tier 1** support agent (all new disputes, refunds up to ₦5,000); **Tier 2** support lead, a new admin role (escalations, appeals, SLA breaches, refunds up to ₦50,000); **Tier 3** super admin (larger refunds, permanent deactivation, legal/press, final appeal). Safety concerns go straight to `safety_operator` (1 h first response). Finance approves money movement beyond a wallet refund. Tier 1 unresolved after 72 h auto-escalates; no admin approves their own refund; one appeal within 7 days to a different reviewer one tier up. Options and edge cases: [PHASE2_DECISIONS.md — OQ-21](../../client/PHASE2_DECISIONS.md#oq-21--dispute-escalation-tiers-and-ownership).

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-DISP-01 | `[ ]` Create migration: `dispute_evidence` table — id, dispute_id (FK), file_path, file_type, file_size_bytes, uploaded_by_user_id (FK), uploaded_at, created_at | B-30 | SETUP-19 | 🔒 Access restricted to authorised reviewers (NF-12) |
| BE2-DISP-02 | `[ ]` Create `DisputeEvidence` Eloquent model | — | BE2-DISP-01 | — |
| BE2-DISP-03 | `[ ]` Create `DisputeEvidenceController@store` — `POST /api/v1/disputes/{dispute}/evidence`: upload photo evidence (validate image type/size, store securely). Evidence readable only by the assigned reviewer, higher tiers and Safety; every view audit-logged | P-27, B-30 | BE2-DISP-02 | 🔒 NF-12 |
| BE2-DISP-04 | `[ ]` Create migration: add to `disputes` table — escalation_tier (int default 1), assigned_admin_id (FK nullable), escalated_by_admin_id (FK nullable), escalated_at (nullable), escalation_reason (text nullable), first_response_due_at, resolution_due_at, first_responded_at (nullable), appealed_at (nullable), appeal_of_resolver_id (FK nullable), linked_dispute_id (FK nullable) | A-37 | SETUP-19 | linked_dispute_id joins passenger and driver disputes on the same trip |
| BE2-DISP-05 | `[ ]` Create `AdminDisputeController@escalate` — `POST /api/v1/admin/disputes/{dispute}/escalate`: move up one tier (reason required); also `@deescalate` (note required). Reject on terminal disputes and above tier 3; clear the assignee so the case lands in the new tier's queue | A-37 | BE2-DISP-04, SETUP-52 | OQ-21 recommended (awaiting client confirmation): tiers 1–3 |
| BE2-DISP-06 | `[ ]` Add `support_lead` to `AdminRole`; tier-to-role map in config (`disputes.tiers`): 1 → support, 2 → support_lead, 3 → super_admin. Higher tiers can act on lower-tier cases | A-37 | SETUP-35 | Super admin can act at any tier (existing middleware rule) |
| BE2-DISP-07 | `[ ]` Refund limits per tier (`disputes.refund_limits`, defaults ₦5,000 / ₦50,000 / none). A refund above the actor's limit becomes a pending refund request approved by a higher tier; the requester can never approve their own | A-37 | BE2-DISP-06, BE-WAL-16 | Applies to `resolve` with refund_amount and to `/admin/rides/{ride}/refund` when linked to a dispute |
| BE2-DISP-08 | `[ ]` Safety routing: disputes with category `safety_concern` start at the Safety queue (`safety_operator`) with a 1 h first-response target and link to any SOS incident on the ride | A-36 | BE2-DISP-04 | Skips tier 1 |
| BE2-DISP-09 | `[ ]` SLA job (every 15 min): set due dates on creation (Safety 1 h / 24 h; Tier 1 24 h / 72 h; Tier 2 24 h / 5 days); auto-escalate Tier 1 breaches to Tier 2; alert super admin on Safety and Tier 2 breaches | A-37 | BE2-DISP-04 | Targets are settings |
| BE2-DISP-10 | `[ ]` Appeals: `POST /api/v1/disputes/{dispute}/appeal` by the reporter (passenger or driver), once, within 7 days of resolution; reopens one tier up and excludes the original resolver. If an appeal reverses a refund, claw back via `WalletRefundService` | P-27 | BE2-DISP-04 | — |
| BE2-DISP-11 | `[ ]` When an admin is deactivated, return their open disputes to their tier queue | A-37 | BE2-DISP-04 | — |
| BE2-DISP-12 | `[ ]` Reporter notifications on escalation and appeal ("Your case is with our senior team"), never exposing admin names | P-27 | BE2-DISP-05 | — |
| BE2-DISP-13 | `[ ]` Tests: tier permissions, refund limits and self-approval block, safety routing, SLA auto-escalation, appeal window and reviewer exclusion, terminal-state guards | — | BE2-DISP-01–12 | — |

---

## 27. Phase 2 — Multi-City Support

**PRD refs:** B-31, A-38

> **OQ-22 answered in part (16 Oct 2026):** the **target dates are the client's decision**. Engineering recommendation: Lagos → Abuja → Port Harcourt → Ibadan, each launched when it passes a readiness checklist (boundary with no overlap, prices for every class, at least 30 approved drivers, support/safety cover, state permits, charging stations for EV classes); suggested Abuja no earlier than 6 weeks after Lagos is stable. Replace the city on/off switch with onboarding / live / paused states. No schema change is needed for currency or time zone inside Nigeria. Details: [PHASE2_DECISIONS.md — OQ-22](../../client/PHASE2_DECISIONS.md#oq-22--which-cities-and-when).

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-CITY-01 | `[ ]` Write integration tests verifying Phase 1 city-scoping model supports onboarding additional cities without schema changes | B-31 | BE-CITY-01 | Should hold if Phase 1 was built generically |
| BE2-CITY-02 | `[ ]` Document city onboarding procedure: Admin steps, pricing config, vehicle class activation, driver assignment, and the readiness checklist | A-38 | BE2-CITY-01 | All Nigerian cities use Africa/Lagos and NGN. A city outside Nigeria would need the hard-coded Lagos-time rules (fleet business day, promo peaks, off-peak, weekly payouts) moved to the city time zone |
| BE2-CITY-03 | `[ ]` Add `launch_status` to `cities` (enum: onboarding/live/paused; backfill from `is_active`). Onboarding: driver sign-up and KYC allowed, passenger booking refused (waitlist). Paused: no new rides or bookings | A-38 | BE2-CITY-01 | Keep `is_active` derived for existing clients |
| BE2-CITY-04 | `[ ]` Guard the city toggle / pause: trips in progress finish; upcoming scheduled bookings are honoured by default, with an option to cancel them all with a notice to each passenger | A-38 | BE2-CITY-03 | Today `AdminCityController` toggles with no in-flight checks |
| BE2-CITY-05 | `[ ]` Reject city boundaries that overlap an existing active city on create/update | A-38 | BE-CITY-01 | Keeps `CityDetectionService` deterministic |
| BE2-CITY-06 | `[ ]` Admin driver city transfer: refuse while the driver has an active ride or upcoming scheduled-ride reservations | A-38 | BE-CITY-01 | — |
| BE2-CITY-07 | `[ ]` City readiness endpoint `GET /api/v1/admin/cities/{city}/readiness`: reports each checklist gate. Computed: boundary with no overlap, pricing per class, approved driver count vs minimum, EV stations for EV classes. Confirmed by an admin (stored on the city with who and when): support and Safety cover, state permits (or "not required"), optional launch promos. `POST /api/v1/admin/cities/{city}/readiness/{gate}` to confirm or clear a manual gate. Moving to `live` requires every required gate | A-38 | BE2-CITY-03 | Minimum drivers is a setting (default 30) |

---

## 28. Phase 2 — Driver Scheduling & Vehicle Maintenance

**PRD refs:** B-32, D-15, D-16, A-39

> **OQ-20 answered (16 Oct 2026, engineering recommendation, not yet client-confirmed):** **voluntary availability planning** for all drivers (no penalty, never blocks going online) and **assigned shifts only for fleet cars in shift mode** (cars shared between drivers). Shift drivers can go online 15 min before their shift; new offers stop 15 min before it ends; not online 30 min after start → missed, operations alerted, no automatic fine.
>
> **OQ-19 answered (16 Oct 2026, engineering recommendation, not yet client-confirmed):** **both**, by the vehicle's `fuel_type`. All vehicles: service (5,000 km or 3 months), tyres & brakes (6 months), insurance / roadworthiness / vehicle licence expiry. Electric and plug-in hybrid also: battery health (12 months) and charging status. Records keyed on the vehicle. Overdue service warns only; expired legal papers block going online after a 7-day grace, never mid-trip.
>
> Options and edge cases: [PHASE2_DECISIONS.md — OQ-19 and OQ-20](../../client/PHASE2_DECISIONS.md#oq-19--vehicle-charging--maintenance-ev-only-general-or-both).

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-SHIFT-01 | `[ ]` Create migration: `driver_shifts` table — id, driver_id (FK), vehicle_id (FK), starts_at, ends_at (full datetimes, so overnight shifts work), status (enum: scheduled/active/completed/missed/cancelled), created_by_admin_id (FK), created_at, updated_at; and `vehicles.shift_mode` (bool default false) | B-32 | SETUP-11 | OQ-20 recommended (awaiting client confirmation): shifts only for fleet cars in shift mode. Reject overlaps per vehicle and per driver |
| BE2-SHIFT-02 | `[ ]` Create `DriverShift` Eloquent model | — | BE2-SHIFT-01 | — |
| BE2-SHIFT-03 | `[ ]` Create `DriverShiftController@index` — `GET /api/v1/drivers/shifts`: driver views schedule | D-15 | BE2-SHIFT-02 | — |
| BE2-SHIFT-04 | `[ ]` Create `AdminDriverShiftController@store/update` — Admin manages shift assignments; only for fleet vehicles in shift mode | A-39 | BE2-SHIFT-02, SETUP-52 | — |
| BE2-SHIFT-05 | `[ ]` Shift enforcement for vehicles in shift mode: go online allowed from 15 min before start to end; no new offers in the last 15 min; a trip running past the end finishes and the next driver is told; scheduled-ride reservations only inside the driver's shifts | D-15 | BE2-SHIFT-01, BE-MATCH-01 | Lead and cut-off are settings |
| BE2-SHIFT-06 | `[ ]` Job (every 5 min): mark shifts not started 30 min after `starts_at` as missed and alert operations; mark active/completed from online status | A-39 | BE2-SHIFT-01 | No automatic fine; the fleet agreement decides consequences |
| BE2-SHIFT-07 | `[ ]` Create migration + endpoints: `driver_availability_plans` — id, driver_id (FK), city_id (FK), area_label (nullable), starts_at, ends_at; `GET/POST/DELETE /api/v1/driver/availability-plans`; admin coverage view `GET /api/v1/admin/availability-coverage?city_id&from&to` (planned drivers per hour/area) | D-15, A-39 | SETUP-11 | Voluntary; never restricts going online; never shown to passengers |
| BE2-VEH-01 | `[ ]` Create migration: `vehicle_maintenance_items` table — id, vehicle_id (FK), type (enum: service/tyres_brakes/insurance/roadworthiness/vehicle_licence/battery_health), status (enum: ok/due_soon/overdue/expired), due_at (nullable), due_odometer_km (nullable), last_done_at (nullable), last_odometer_km (nullable), blocks_driving (bool), notes (nullable), created_at, updated_at | B-32 | SETUP-11 | OQ-19 recommended (awaiting client confirmation): both. Keyed on vehicle, not driver. battery_health only for electric/plug_in_hybrid |
| BE2-VEH-02 | `[ ]` Create `VehicleMaintenanceItem` model; create the item set from `fuel_type` when a vehicle is created or its fuel type changes (add or archive EV items) | — | BE2-VEH-01 | Non-plug-in hybrid gets general items only |
| BE2-VEH-03 | `[ ]` Create `DriverVehicleStatusController@show` — `GET /api/v1/drivers/vehicle/status`: maintenance items with due status, and for plug-in vehicles the charge level (driver-entered when going online) and any active EV reservation | D-16 | BE2-VEH-02 | Telematics integration can come later |
| BE2-VEH-04 | `[ ]` Create `AdminVehicleMaintenanceController@store/update` — Admin records completed maintenance (fleet cars) and approves driver-submitted proof (own cars). Reject odometer readings lower than the last one | A-39 | BE2-VEH-02, SETUP-52 | Booking a workshop reuses the existing `DriverRepair` flow (`REP-` numbers) |
| BE2-VEH-05 | `[ ]` Interval settings per item and fuel type (defaults: service 5,000 km / 3 months, tyres & brakes 6 months, battery 12 months); track km from completed trips between odometer readings | B-32 | BE2-VEH-01 | Time limit still applies to km driven off-platform |
| BE2-VEH-06 | `[ ]` Daily job: due-soon reminders (14 days); overdue warnings to driver and operations; expired insurance/roadworthiness/licence → driver blocked from going online after a 7-day grace (settings); never ends an active trip; releases scheduled-ride reservations with a passenger notice when a block starts | B-32, D-16 | BE2-VEH-01, BE2-VEH-07 | Legal-paper expiry comes from the vehicle's latest approved document (BE2-VEH-07), never from a driver-keyed record |
| BE2-VEH-07 | `[ ]` Link vehicle documents to the vehicle: add `vehicle_id` (FK nullable) to `driver_documents`, required for vehicle types (insurance_certificate, vehicle_registration, plus new roadworthiness_certificate and vehicle_licence types). Backfill existing vehicle-type documents with the driver's current vehicle; documents that cannot be matched are listed for operations instead of guessed. The insurance/roadworthiness/vehicle_licence maintenance items read `expires_at` from the vehicle's latest approved document of that type | B-32, NF-04 | SETUP-12, BE2-VEH-01 | Driving licence and government ID stay driver-level. A car moving to another driver keeps its papers |

---

## 29. Open Questions Tracker

### Provider & Integration Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-01 | Which SMS/OTP gateway is approved? | SETUP-55, SETUP-56, BE-AUTH-13 | `[x]` **Resolved:** WhatsApp Business API (`WhatsAppGateway`) is the primary delivery channel; auto-selected in `AppServiceProvider` when `WHATSAPP_PHONE_NUMBER_ID` + `WHATSAPP_ACCESS_TOKEN` are set; `LogSmsGateway` as dev/testing fallback |
| OQ-02 | Which maps/geocoding provider is approved? | SETUP-59, SETUP-60 | `[x]` **Resolved:** **Mapbox** for directions/distance/duration (`MapboxGateway`); `HaversineMapsGateway` fallback. Place search: **LocationIQ/Geoapify/Photon** failover chain via `FailoverPlaceSearchGateway`. Auto-selected by credentials in `AppServiceProvider` |
| OQ-03 | Which card payment gateway is approved? | SETUP-61, SETUP-62 | `[x]` **Resolved:** **Paystack** — `PaystackPaymentGateway` for card tokenization, `PaystackWalletGateway` for wallet top-ups and transfers. Config: `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` in `config/services.php`. `FakePaymentGateway` for dev/testing |
| OQ-04 | Which emergency-services dispatch integration is approved? | BE-SOS-11 | `[x]` **Resolved:** No external dispatch API. Panic button sends GPS + trip details via WhatsApp Business API to internal safety number and auto-dials 112. Admin dashboard notified in real-time with full incident view (rider/driver location, trip details, contact info). Incident stored for audit |
| OQ-17 | Minimum supported iOS and Android versions? | Mobile app tasks (frontend doc) | `[x]` **Resolved:** Set to launch version (1.0.0/1.0.0). `min_supported_version` stored in AppSettings and returned via `/api/config` endpoint for client-side force-update gate |

### Business Rule Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-05 | Does Phase 1 include a cancellation fee, and under what conditions? | BE-RIDE-06 | `[x]` **Resolved:** No cancellation fee in Phase 1. Riders may cancel freely at any point. `ride.free_cancellation_window_seconds` retained in config for future use when cancellation fees are introduced. **Update (16 Oct 2026, client):** on-demand rides stay free to cancel; a passenger cancelling a driver-reserved **scheduled** booking within 30 min of the ride pays the economy base fare (SR-4, BE2-SCH-15) |
| OQ-06 | How and how often are driver earnings paid out? | BE-PAY (payout logic) | `[x]` **Resolved:** On-demand payout requests by driver (`PayoutController@store`) with Admin approval (`AdminPayoutController@approve`); processed via Paystack Transfer API. Weekly auto-payout (Monday 6 AM) available as driver opt-in. Admin can configure payout frequency and thresholds via `AdminWalletSettingsController` |
| OQ-07 | Single Admin role or role-based tiers (Ops/Support/Finance/Safety)? | SETUP-35, SETUP-52 | `[x]` **Resolved:** Role-based tiers implemented — super_admin, operations, safety_operator, support, finance; enforced via `EnsureAdminRole` middleware |
| OQ-08 | Can Admin issue refunds/fare adjustments when resolving disputes? | BE-RATE-09 | `[x]` **Resolved:** `AdminRefundController@refundToWallet` — `POST /api/v1/admin/rides/{ride}/refund` issues full or partial refund to passenger wallet via `WalletRefundService`; linked to dispute if one exists; claws back driver earnings via `WalletRefundService@clawbackDriverEarnings()` |
| OQ-09 | Tier naming: Option A (Bronze → Diamond) or Option B (Seed → Forest)? | BE-GAME-11, SETUP-24 seed | `[x]` **Resolved:** Option A — Bronze (0 pts) → Silver (500) → Gold (2,000) → Platinum (5,000) → Diamond (15,000). Implemented in `TierLevel` enum and `TierConfigSeeder` |
| OQ-10 | Source for baseline/per-vehicle-class emissions data? | BE-GAME-02 | `[x]` **Resolved:** EU/IPCC averages adjusted for ride-sharing occupancy, configured in `config/gamification.php`: baseline 120 g CO₂/km, economy 65, comfort 80, premium 95, EV 0, default 75 |
| OQ-11 | Is surge pricing in scope in Phase 1? | BE-GAME-08 | `[x]` **Resolved:** Yes — fully built. `SurgePricingService` evaluates active rules by priority (manual/time_based/demand_based), `SurgeRule` model, `AdminSurgeRuleController` CRUD + toggle, integrated into `FareEstimationService`. Multiplier range 1.00–5.00 |
| OQ-12 | Auto-apply promos in Phase 1, or code-entry only? | BE-PROMO-12 | `[x]` **Resolved:** Code-entry only in Phase 1. `PromoController@validate` validates user-entered codes; `PromoController@available` lists eligible promos. Auto-apply can be layered onto ride creation flow in Phase 2 |

### Detection & Enforcement Thresholds

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-13 | GPS collocation threshold and jitter tolerance? | BE-AO-02 | `[x]` **Resolved:** 200m collocation threshold, 60% route match, min 3 GPS points, 300m pickup proximity, 30s sample interval, 15-min monitoring window. All configurable via `config/offline_detection.php` and env vars |
| OQ-14 | False-positive rate target and L3 overturn authority? | BE-AO-02, BE-AO-11 | `[x]` **Resolved:** Super admin can overturn L3 (permanent deactivation) via `AdminOfflineFlagController@review` with outcome=overturned; `SanctionService@reverseSanction()` reinstates driver and notifies. 30-day lookback window for tier determination |

### EV Charging Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-15 | EV station data source? | BE-EV-02 | `[x]` **Resolved:** E-tiGo's own proprietary charging network only — Admin-managed via `AdminEvStationController` CRUD. External station owners may apply to join the network via an onboarding form (Phase 2). Station data: name, location, stall count, connector types, power output (kW), pricing, operating hours, real-time availability |
| OQ-16 | Fee waiver applies to driver, passenger, or both? | BE-EV-12 | `[x]` **Resolved:** Driver only — `ReservationService` checks the requesting driver's tier via `TierGateService@isEvReservationFeeWaived()`. Drivers are the ones making EV reservations |

### Wallet & Ledger Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-25 | Wallet limits: minimum top-up, maximum balance, and daily top-up cap values? | BE-WAL-03, BE-WAL-04 | `[x]` **Resolved:** Min top-up ₦500 (50,000 kobo), max balance ₦500,000 (50,000,000 kobo), daily cap ₦100,000 (10,000,000 kobo). Configured in `config/wallet.php`; admin-adjustable at runtime via `AdminWalletSettingsController`. All amounts stored and validated in kobo |
| OQ-26 | Fallback payment method when wallet balance is insufficient for final fare? | BE-WAL-12 | `[x]` Resolved — partial settlement: capture available balance, record shortfall, mark payment Failed for admin review **Update (16 Oct 2026, client):** the shortfall is recorded as a balance due the passenger must pay (BE2-SCH-16); when paid, the payment becomes captured and the driver gets the rest less commission |
| OQ-27 | Refund policy: wallet credit only, or back to original payment method (card)? | BE-WAL-16 | `[x]` **Resolved:** Wallet-credit-only refunds for V1 — industry standard for African ride-hailing (Bolt, InDrive). Faster than card refunds (instant vs 5–10 business days), reduces chargebacks. Card refund reserved as admin-only escalation path for exceptional disputes; may be added in Phase 2 if required by regulation |
| OQ-28 | Driver earnings settlement delay: instant, 24 hours, or weekly? | BE-EARN-04 | `[x]` Resolved — all three options supported via `config('wallet.settlement_delay')`; default is `instant`; configurable at runtime via Admin Wallet Settings endpoint; `SettleDriverEarningsJob` runs hourly to move pending→available when delay is 24h or weekly |
| OQ-29 | Cash-ride commission: debit from driver ledger? Allow negative balance? Threshold? | BE-EARN-05 | `[x]` Resolved — cash rides debit commission from `DriverEarningsAvailable` (driver already has the cash); negative balance allowed up to configurable threshold (`max_negative_balance`, default −₦5,000 / −500000 kobo); warning logged when threshold exceeded; `hasExcessiveNegativeBalance()` helper available for ride-blocking logic |
| OQ-30 | Minimum payout amount for driver withdrawals? | BE-EARN-12 | `[x]` **Resolved:** ₦1,000 (100,000 kobo) — configured in `config/wallet.php` as `min_payout`; admin-adjustable via wallet settings endpoint |
| OQ-31 | Driver payouts: on-demand request or fixed schedule (weekly/bi-weekly)? | BE-EARN-12, BE-WADM-20 | `[x]` **Resolved:** On-demand with Admin approval — driver requests payout via `PayoutController@store`, Admin approves via `AdminPayoutController@approve`, transfer processed via Paystack Transfer API. No automated schedule enforced. **Update:** drivers can opt in to a weekly auto-payout (`weekly_auto`, Monday 06:00 Lagos) — the payout is created already approved |
| OQ-32 | Tips: included in V1 wallet flow or deferred? | BE-EARN-03 | `[x]` **Resolved:** No wallet-debit tips in V1. Tips via card payments only (`PaymentService@addTip()` charges via Paystack). Cash tips handled directly between rider and driver (logged as tip_amount on Payment record). Wallet-debit tips deferred — creates accounting complexity and regulatory grey area for closed-loop wallets. **Update (15 Oct 2026):** wallet tips are now supported — tips follow the trip's payment method, and a wallet tip moves from the passenger's balance to the driver in one ledger journal (no commission) |
| OQ-33 | Regulatory: custody of funds / safeguarding requirements for closed-loop wallet in Nigeria? | BE-WAL-01 | `[x]` **Resolved:** Paystack Wallet infrastructure (`PaystackWalletGateway`) handles fund custody under Paystack's CBN-licensed PSP status. E-tiGo operates as a sub-wallet under Paystack's license — funds held in Paystack's regulated trust accounts, not E-tiGo's operating account. Wallet caps (₦500k max balance) and double-entry bookkeeping provide additional safeguards. Formal Paystack partnership agreement recommended to document this arrangement |
| OQ-34 | How is a balance due collected? | BE2-SCH-16 | `[~]` **Engineering default (16 Oct 2026), awaiting client confirmation:** no new booking until paid (Uber/Bolt); pay everything at once from wallet or card; finance can waive or turn the block off |
| OQ-35 | Is the driver paid an outstanding balance before the passenger pays? | BE2-SCH-16 | `[~]` **Engineering default (16 Oct 2026), awaiting client confirmation:** no — the driver is paid when the passenger pays (Uber pays drivers up front and absorbs the risk) |
| OQ-36 | Lost & Found: should passengers pay a return fee, and how much? | BE2-LF-11 | `[~]` **Engineering recommendation (18 Oct 2026), awaiting client confirmation:** ₦2,000, all to the driver (Uber/Bolt practice). Built **off** (`return_fee` 0) until the client sets an amount. See PHASE2_DECISIONS.md |
| OQ-37 | Lost & Found: do passenger and driver see each other's phone number? | BE2-LF-09 | `[~]` **Engineering default (18 Oct 2026), awaiting client confirmation:** yes, once the driver has the item, until it is back (`share_contact_details`); in-app messages always. No masked calling yet |

### Phase 2 Decisions

Phase 1 and Phase 2 decisions, with industry benchmarks, are also published for the client in [OPEN_QUESTIONS_DECISIONS.md](../../client/OPEN_QUESTIONS_DECISIONS.md) / `.pdf`; scheduled-ride reasoning in [SCHEDULED_RIDES_DECISIONS.md](../../client/SCHEDULED_RIDES_DECISIONS.md) / `.pdf`; OQ-18 to OQ-22 options and edge cases in [PHASE2_DECISIONS.md](../../client/PHASE2_DECISIONS.md) / `.pdf`.

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-18 | Who receives PIN/tracking for third-party bookings? | BE2-3P-02, BE2-3P-04 | `[~]` **Recommended (16 Oct 2026):** both rider (WhatsApp/SMS + no-app tracking link) and booker (in app); admin can switch to rider-only or booker-only; booker always gets the PIN if the rider's message fails; wallet/card only by default; 2 active per booker. Awaiting client confirmation. **Built to this recommendation (18 Oct 2026)**; every choice is a setting |
| OQ-19 | Vehicle charging & maintenance: EV-specific, general, or both? | BE2-VEH-01 | `[~]` **Recommended (16 Oct 2026):** both, by fuel type — general items (service, tyres & brakes, legal papers) for all; battery health and charging status for electric/plug-in hybrid; keyed on vehicle; expired papers block going online after 7-day grace. Awaiting client confirmation |
| OQ-20 | Driver scheduling: shift scheduling or availability planning? | BE2-SHIFT-01, BE2-SHIFT-07 | `[~]` **Recommended (16 Oct 2026):** voluntary availability planning for all drivers; assigned shifts only for fleet cars in shift mode (shared cars). Awaiting client confirmation |
| OQ-21 | Dispute escalation tiers and ownership? | BE2-DISP-05–07 | `[~]` **Recommended (16 Oct 2026):** Tier 1 support (refunds ≤ ₦5,000) → Tier 2 new `support_lead` role (≤ ₦50,000) → Tier 3 super admin; safety cases straight to `safety_operator`; 72 h auto-escalation; one appeal in 7 days. Awaiting client confirmation |
| OQ-22 | Which cities are being activated and target date? | BE2-CITY-01–07 | `[~]` **Recommended order (16 Oct 2026):** Lagos → Abuja → Port Harcourt → Ibadan, each gated by a readiness checklist (≥ 30 approved drivers, pricing, boundary, permits). **Target dates: client to decide** |
| SR-1 | Scheduled rides: booking window? | BE2-SCH-03 | `[x]` **Client decided (16 Oct 2026):** 30 min to 3 days ahead, 3 upcoming bookings per passenger. Still awaiting confirmation: bookings 30 min apart |
| SR-2 | Scheduled rides: when does matching start and how long does it search? | BE2-SCH-04 | `[~]` Built with default 15 min before pickup; search until 10 min after (never less than the standard 5 min). Awaiting client confirmation |
| SR-3 | Reservation fee for booking ahead? | BE2-SCH-14 | `[x]` **Client decided (16 Oct 2026):** no charge for reservations |
| SR-4 | Late-cancellation fee on driver-reserved bookings (exception to OQ-05)? | BE2-SCH-15 | `[x]` **Client decided (16 Oct 2026):** the economy base fare, within 30 min of the ride time; paid to the reserved driver in full |
| SR-5 | Late cancellations of cash/bank-transfer bookings: waive or balance due? | BE2-SCH-16 | `[x]` **Client decided (16 Oct 2026):** record a balance due; the same for wallet passengers whose final fare exceeds their balance |
| SR-6 | Penalise drivers who often release reservations? | BE2-SCH-10 | `[ ]` No penalty; tracked in the audit log. Client to decide |
| SR-7 | Driver reservation limits? | BE2-SCH-10, BE2-SCH-12 | `[~]` Built with default 5 per driver, 60 min apart, no on-demand offers 60 min before. Awaiting client confirmation |

---

*Document generated from PRD v3.0 and Product Brief. Backend scope only — see [Passenger App](Passenger_App_Task_Breakdown.md), [Driver App](Driver_App_Task_Breakdown.md) and [Web](Web_Task_Breakdown.md) for client-app tasks. Update statuses: `[ ]` → `[~]` (in progress) → `[x]` (done).*
