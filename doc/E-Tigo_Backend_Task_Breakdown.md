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

### Phase 2 — Month-3 Rollout (Weeks 9–12)

18. [Phase 2 — Scheduled Rides](#18-phase-2--scheduled-rides)
19. [Phase 2 — Third-Party Bookings](#19-phase-2--third-party-bookings)
20. [Phase 2 — Lost & Found](#20-phase-2--lost--found)
21. [Phase 2 — Advanced Disputes](#21-phase-2--advanced-disputes)
22. [Phase 2 — Multi-City Support](#22-phase-2--multi-city-support)
23. [Phase 2 — Driver Scheduling & Vehicle Maintenance](#23-phase-2--driver-scheduling--vehicle-maintenance)

### Tracking

24. [Open Questions Tracker](#24-open-questions-tracker)

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
| SETUP-03 | `[ ]` Set up CI pipeline: lint → static analysis → unit tests → feature tests → build | — | SETUP-01 | Branch protection on `main`; require passing CI before merge |
| SETUP-04 | `[ ]` Configure staging and production deployment pipelines with environment-specific `.env` config | — | SETUP-03 | Include `php artisan migrate --force` step in deploy pipeline |
| SETUP-05 | `[x]` Define and document API contract: request/response shapes, enums, error formats — see `doc/API_REFERENCE.md`, `doc/postman_collection.json`, `doc/TECHNICAL.md` | — | SETUP-01 | PHP enums for UserType, AdminRole, DriverStatus, DocumentType, DocumentStatus |

### 1.2 Database Schema & Migrations

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-06 | `[ ]` Provision PostgreSQL instance (staging + production) and configure connection pooling in `config/database.php` | B-02 | SETUP-04 | — |
| SETUP-07 | `[x]` Create migration: `users` table — id, first_name, last_name, phone (unique E.164), email (unique), type (enum: passenger/driver/admin), admin_role (enum nullable), password, phone_verified_at, is_active, profile_photo_path, created_at, updated_at | B-02 | SETUP-06 | Shared table for all user types; role-specific data in separate tables |
| SETUP-08 | `[ ]` Create migration: `cities` table — id, name, slug (unique), boundary (GeoJSON polygon or point+radius), timezone, currency_code, is_active, created_at, updated_at | B-02 | SETUP-06 | Top-level scoping entity |
| SETUP-09 | `[ ]` Create migration: `vehicle_classes` table — id, name, display_name, capacity, icon_url, description, is_active, created_at, updated_at | B-02 | SETUP-06 | Platform-wide definitions |
| SETUP-10 | `[ ]` Create migration: `city_vehicle_classes` pivot table — city_id (FK), vehicle_class_id (FK), is_active, unique constraint on (city_id, vehicle_class_id) | B-02 | SETUP-08, SETUP-09 | Controls which classes are available in which cities |
| SETUP-11 | `[x]` Create migration: `drivers` table — id, user_id (FK unique), status (enum: pending_review/approved/rejected/suspended), licence_number (nullable), rejection_reason (nullable), is_online (bool default false), approved_at (nullable), suspended_at (nullable), created_at, updated_at. Separate `vehicles` table for vehicle data. | B-02 | SETUP-07, SETUP-09 | — |
| SETUP-12 | `[x]` Create migration: `driver_documents` table — id, driver_id (FK), type (enum: driving_licence/vehicle_registration/insurance_certificate/government_id), file_path, original_filename, mime_type, file_size, status (enum: pending/approved/rejected), rejection_reason (nullable), reviewed_by (FK nullable), reviewed_at (nullable), created_at, updated_at | B-02, NF-04 | SETUP-11 | 🔒 Files stored via Laravel filesystem; re-upload replaces previous |
| SETUP-13 | `[ ]` Create migration: `pricing_configs` table — id, city_id (FK), vehicle_class_id (FK), base_fare, per_km_rate, per_minute_rate, minimum_fare, waiting_time_rate (nullable), version (int), effective_from (timestamp), created_by_admin_id (FK), created_at | A-06, A-07 | SETUP-08, SETUP-10 | Versioned with effective timestamp |
| SETUP-14 | `[ ]` Create migration: `rides` table — id (UUID), city_id (FK), vehicle_class_id (FK), passenger_id (FK), driver_id (FK nullable), pickup_lat, pickup_lng, pickup_address, destination_lat, destination_lng, destination_address, status (enum: requested/searching/matched/driver_en_route/driver_arrived/in_progress/completed/cancelled/no_driver_found), pin_code (char 4), share_token (unique), fare_estimate_amount, final_fare_amount (nullable), fare_currency, pricing_snapshot (jsonb), payment_method (enum: cash/card), payment_status (enum), cancelled_by (FK nullable), cancellation_reason (nullable), matched_at (nullable), started_at (nullable), completed_at (nullable), created_at, updated_at | B-02, §8.2, §8.5 | SETUP-08, SETUP-10, SETUP-07, SETUP-11 | Every ride scoped to city + vehicle class from creation |
| SETUP-15 | `[ ]` Create migration: `ride_state_transitions` audit table — id, ride_id (FK), from_state, to_state, triggered_by_type (enum: user/system), triggered_by_id (nullable), metadata (jsonb nullable), created_at | B-09, NF-06 | SETUP-14 | Append-only; no UPDATE or DELETE; use DB trigger or policy to enforce |
| SETUP-16 | `[ ]` Create migration: `payments` table — id, ride_id (FK), amount, currency, method (enum: cash/card), gateway_transaction_id (nullable), gateway_payment_method_id (nullable), tip_amount (default 0), status (enum: pending/authorized/captured/settled/refunded/failed), failure_reason (nullable), created_at, updated_at | B-02 | SETUP-14 | — |
| SETUP-17 | `[ ]` Create migration: `user_payment_methods` table — id, user_id (FK), gateway_token, card_brand, card_last_four, card_expiry_month, card_expiry_year, is_default (bool), created_at, updated_at | B-02, NF-05 | SETUP-07 | 🔒 No raw card numbers; gateway token only |
| SETUP-18 | `[ ]` Create migration: `ratings` table — id, ride_id (FK), rated_by_user_id (FK), rated_user_id (FK), score (tinyint 1-5), comment (text nullable), created_at | B-02 | SETUP-14 | — |
| SETUP-19 | `[ ]` Create migration: `disputes` table — id, ride_id (FK), reported_by_user_id (FK), category (enum), description (text), status (enum: open/under_review/resolved/dismissed), resolution_notes (text nullable), resolved_by_admin_id (FK nullable), created_at, updated_at, resolved_at (nullable) | B-02 | SETUP-14 | — |
| SETUP-20 | `[x]` Create migration: `audit_logs` table — id, auditable_type, auditable_id (polymorphic), event (string), actor_type, actor_id, old_values (jsonb), new_values (jsonb), ip_address, user_agent, created_at | B-09, NF-06 | SETUP-07 | Append-only; polymorphic design covers all model state changes |
| SETUP-21 | `[ ]` Create migration: `promo_codes` table — id, code (unique), discount_type (enum: percentage/flat), discount_value (decimal), max_discount_cap (decimal nullable), total_redemption_limit (int nullable), per_user_limit (int default 1), starts_at, expires_at, geo_fence (jsonb nullable), min_order_count (int nullable), max_order_count (int nullable), min_tier_level (int nullable), peak_only (bool default false), off_peak_only (bool default false), is_active (bool default true), created_at, updated_at | B-02 | SETUP-06 | — |
| SETUP-22 | `[ ]` Create migration: `promo_redemptions` table — id, promo_code_id (FK), user_id (FK), ride_id (FK), discount_amount (decimal), redeemed_at (timestamp), created_at | B-02 | SETUP-21, SETUP-14 | Unique constraint on (promo_code_id, user_id, ride_id) to prevent double-redeem |
| SETUP-23 | `[ ]` Create migration: `gamification_profiles` table — id, user_id (FK unique), total_carbon_score (decimal default 0), total_ranking_points (int default 0), current_tier (enum/int default 1), tier_upgraded_at (nullable), created_at, updated_at | B-02 | SETUP-07 | Created when user completes first trip |
| SETUP-24 | `[ ]` Create migration: `tier_configs` table — id, tier_level (tinyint 1-5 unique), tier_name, min_points_required (int), booking_fee_discount_pct (decimal default 0), ev_reservation_fee_waived (bool default false), priority_matching_enabled (bool default false), created_at, updated_at | A-17 | SETUP-06 | Seed with initial 5-tier config |
| SETUP-25 | `[ ]` Create migration: `point_multiplier_configs` table — id, condition_type (enum: ev_ride/shared_journey/off_peak), multiplier_value (decimal), is_stackable (bool default false), created_at, updated_at | A-18 | SETUP-06 | Seed with initial defaults |
| SETUP-26 | `[ ]` Create migration: `trip_carbon_scores` table — id, ride_id (FK), user_id (FK), distance_km (decimal), baseline_emission (decimal), vehicle_emission (decimal), co2_saved (decimal), base_points (int), multiplier_applied (decimal default 1.0), multiplier_reason (string nullable), final_points (int), created_at | B-02 | SETUP-14 | — |
| SETUP-27 | `[ ]` Create migration: `sos_incidents` table — id, ride_id (FK), triggered_by_user_id (FK), trigger_type (enum: passenger/driver), status (enum: triggered/check_in_sent/acknowledged/escalated/operator_assigned/dispatched/resolved/cancelled), gps_lat (decimal), gps_lng (decimal), vehicle_details (jsonb), telemetry_data (jsonb), check_in_sent_at (nullable), check_in_acknowledged_at (nullable), escalated_at (nullable), operator_id (FK nullable), operator_notes (text nullable), resolved_at (nullable), created_at | B-02 | SETUP-14 | 🔒 Telemetry encrypted at rest |
| SETUP-28 | `[ ]` Create migration: `sos_event_log` audit table — id, incident_id (FK), event_type (string), actor_id (FK nullable), metadata (jsonb nullable), created_at | NF-06 | SETUP-27 | Append-only immutable audit trail |
| SETUP-29 | `[ ]` Create migration: `offline_trip_flags` table — id, ride_id (FK), driver_id (FK), passenger_id (FK nullable), detection_data (jsonb), sanction_tier (tinyint 1-3), sanction_action (string), is_disputed (bool default false), dispute_notes (text nullable), dispute_resolved_by_admin_id (FK nullable), dispute_outcome (enum: pending/upheld/overturned nullable), flagged_at, resolved_at (nullable), created_at | B-02 | SETUP-14 | — |
| SETUP-30 | `[ ]` Create migration: `ev_charging_stations` table — id, name, city_id (FK), lat (decimal), lng (decimal), address, total_stalls (int), status (enum: active/inactive/maintenance), created_at, updated_at | B-02 | SETUP-08 | — |
| SETUP-31 | `[ ]` Create migration: `ev_charging_stalls` table — id, station_id (FK), stall_number (int), status (enum: available/occupied/reserved/out_of_service), current_vehicle_driver_id (FK nullable), occupied_since (nullable), estimated_departure_at (nullable), updated_at | B-02 | SETUP-30 | — |
| SETUP-32 | `[ ]` Create migration: `ev_reservations` table — id, stall_id (FK nullable), station_id (FK), driver_id (FK), status (enum: reserved/queued/active/completed/expired/cancelled), queue_position (int nullable), estimated_available_at (nullable), fee_amount (decimal default 0), fee_waived (bool default false), reserved_at, activated_at (nullable), completed_at (nullable), created_at | B-02 | SETUP-31 | — |
| SETUP-33 | `[ ]` Create migration: `notifications` table — id, user_id (FK), type (string), title, body, data (jsonb nullable), is_read (bool default false), read_at (nullable), created_at | B-06 | SETUP-07 | — |
| SETUP-34 | `[ ]` Create migration: `device_tokens` table — id, user_id (FK), platform (enum: ios/android/web), token (string), is_active (bool default true), created_at, updated_at | B-06 | SETUP-07 | Unique constraint on (user_id, platform, token) |
| SETUP-35 | `[x]` Admin roles stored in `users.admin_role` column (enum: super_admin/operations/safety_operator/support). No separate admin_users table — single users table with `type=admin` + `admin_role`. Admin seeder creates 3 dev accounts. | §8.4 | SETUP-07 | OQ-07 resolved: tiered roles implemented |
| SETUP-36 | `[ ]` Create database indexes for high-frequency queries: rides by (status, city_id), rides by (passenger_id, created_at), rides by (driver_id, created_at), drivers by (city via vehicle class, is_online, kyc_status), promo_codes by (code), gamification_profiles by (user_id), device_tokens by (user_id) | — | SETUP-14 through SETUP-34 | ⏱ Profile queries during load testing |
| SETUP-37 | `[x]` Create database seeders: `AdminSeeder` creates 3 admin accounts (super_admin, safety_operator, operations). Seeder skips in production. | — | SETUP-24, SETUP-25 | For development and staging environments |

### 1.3 Cache Layer (Redis)

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-38 | `[ ]` Provision Redis instance (staging + production) and configure in `config/database.php` Redis connections | B-03 | SETUP-04 | — |
| SETUP-39 | `[x]` OTP/PIN codes stored in PostgreSQL `otp_codes` table (not Redis): SHA-256 hashed code, 30-min expiry, 3 max attempts, atomic increment; used for ride-start PIN verification | B-03 | SETUP-07 | Migration creates otp_codes table; OtpService handles generation/verification |
| SETUP-40 | `[ ]` Configure Redis for session/token state (refresh token storage) | B-03 | SETUP-38 | Key pattern: `refresh_token:{token_hash}` |
| SETUP-41 | `[ ]` Configure Redis for live driver-location cache using geo-indexing (GEOADD/GEORADIUS) | B-03 | SETUP-38 | Key: `driver_locations` (geo set); secondary key per driver: `driver:{id}:location` (hash with heading, speed, timestamp) |
| SETUP-42 | `[ ]` Configure Redis for application caching (city configs, pricing, vehicle classes) with tagged cache and invalidation on Admin writes | B-03 | SETUP-38 | Use Laravel cache tags; short TTL with explicit invalidation |

### 1.4 API Server Bootstrap

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-43 | `[x]` Configure API route prefix `/api/v1/` with versioned route files | B-01 | SETUP-01 | Configured in `bootstrap/app.php` |
| SETUP-44 | `[x]` Implement Form Request validation with structured 422 error responses | B-01 | SETUP-43 | Laravel FormRequest classes per endpoint; format: `{ message, errors: { field: [messages] } }` |
| SETUP-45 | `[ ]` Implement global exception handler with structured JSON error responses for all exception types | B-01 | SETUP-43 | Format: `{ message, error_code, details }` — never expose stack traces in production |
| SETUP-46 | `[ ]` Implement request logging middleware: log method, path, status, duration, authenticated user_id | B-09 | SETUP-43 | — |
| SETUP-47 | `[x]` Implement rate limiting middleware: `throttle:5,1` on auth routes (5 requests/minute) | NF-01 | SETUP-43 | Laravel's built-in `ThrottleRequests`; applied to both `/auth` and `/admin/auth` route groups |
| SETUP-48 | `[ ]` Configure CORS for Admin Dashboard and mobile app origins | B-01 | SETUP-43 | — |
| SETUP-49 | `[ ]` Implement health check endpoint: `GET /api/v1/health` — returns DB and Redis connectivity status | NF-01 | SETUP-43 | Used by load balancer and monitoring |
| SETUP-50 | `[x]` Implement API resources for consistent response enveloping: UserResource, DriverResource, DriverDocumentResource, VehicleResource | B-01 | SETUP-43 | Laravel API Resources |

### 1.5 Authentication & Access Control

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-51 | `[x]` Implement Sanctum token authentication guard: `auth:sanctum` middleware | B-01 | SETUP-43 | Laravel Sanctum personal access tokens; shared across Passenger, Driver, Admin clients |
| SETUP-52 | `[x]` Implement RBAC middleware: `EnsureUserType` (comma-separated types), `EnsureAdminRole` (comma-separated roles), `EnsureDriverApproved` | §8.4 | SETUP-35, SETUP-51 | Safety Operator scope restricts to SOS console endpoints only |
| SETUP-53 | `[x]` Implement route-level permission guards: `user.type:admin`, `user.type:driver`, `user.type:passenger`, `admin.role:safety_operator`, `driver.approved` | §8.4 | SETUP-52 | Applied as route middleware aliases in `bootstrap/app.php` |
| SETUP-54 | `[x]` Implement `AuditLog::record()` static method for immutable event logging: captures model, event, actor, old/new values, IP, user agent | B-09, NF-06 | SETUP-20, SETUP-52 | Used across all controllers; admin actions wrapped in DB::transaction() |

### 1.6 Third-Party Integration Adapters

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-55 | `[x]` Define `SmsGateway` contract interface: `send(string $phone, string $message): bool` | B-05 | SETUP-43 | Adapter pattern for swappable SMS providers |
| SETUP-56 | `[x]` Implement `LogSmsGateway` adapter (logs to channel instead of sending SMS); bound in `AppServiceProvider` | B-05 | SETUP-55 | Swap for Twilio/etc. by implementing `SmsGateway` interface |
| SETUP-57 | `[ ]` Define push notification adapter interface: `sendToUser(userId, notification): void`, `sendToDevice(token, payload): void` | B-06 | SETUP-43 | — |
| SETUP-58 | `[ ]` Implement concrete push notification adapter (FCM + APNs) | B-06 | SETUP-57 | — |
| SETUP-59 | `[ ]` Define maps adapter interface: `geocode(address)`, `reverseGeocode(lat, lng)`, `autocomplete(query)`, `directions(origin, destination)`, `distanceMatrix(origins, destinations)` | B-07 | SETUP-43 | ⚠ OQ-02: Provider TBD; track per-call cost |
| SETUP-60 | `[ ]` Implement concrete maps adapter for the confirmed provider | B-07 | SETUP-59 | — |
| SETUP-61 | `[ ]` Define payment gateway adapter interface: `createCustomer(userId)`, `tokenizeCard(cardData)`, `authorize(amount, token)`, `capture(transactionId)`, `refund(transactionId, amount)` | B-08 | SETUP-43 | ⚠ OQ-03: Provider TBD. 🔒 No raw card data stored; tokenisation only (NF-05) |
| SETUP-62 | `[ ]` Implement concrete payment gateway adapter for the confirmed provider | B-08, NF-05 | SETUP-61 | — |

---

## 2. Authentication Service

**PRD refs:** P-01, P-02, P-03, D-01, B-01

> **Note:** Auth uses email/password for all user types (not OTP). The OTP/PIN system is repurposed for ride-start verification (§9.1).

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-AUTH-01 | `[x]` Create `AuthController@register` — `POST /api/v1/auth/register`: accept first_name, last_name, phone (E.164), email, password (confirmed), type (passenger/driver); create User; auto-create Driver record for drivers with `pending_review` status; issue Sanctum token | P-01, D-01 | SETUP-07, SETUP-11 | Rate-limited via `throttle:5,1`; validates unique email + phone |
| BE-AUTH-02 | `[x]` Create `RegisterRequest` FormRequest — validate first_name, last_name, phone (E.164 regex, unique), email (unique), password (min:8, confirmed), type (passenger/driver) | P-01 | BE-AUTH-01 | Custom error messages for phone format and uniqueness |
| BE-AUTH-03 | `[x]` Create `AuthController@login` — `POST /api/v1/auth/login`: validate email + password + type against users table; reject wrong type, inactive accounts; issue Sanctum token with type-scoped abilities | P-01, P-02 | BE-AUTH-01 | Returns 401 for invalid credentials, 403 for deactivated |
| BE-AUTH-04 | `[x]` Create `LoginRequest` FormRequest — validate email, password, type (passenger/driver) | P-01 | BE-AUTH-03 | — |
| BE-AUTH-05 | `[x]` Create `AdminAuthController@login` — `POST /api/v1/admin/auth/login`: admin email/password login; token abilities include admin role (e.g. `['admin', 'super_admin']`) | §8.4 | SETUP-35 | Rate-limited; admin accounts created by seeder or other admins |
| BE-AUTH-06 | `[x]` Create `AdminLoginRequest` FormRequest — validate email, password | §8.4 | BE-AUTH-05 | — |
| BE-AUTH-07 | `[x]` Create `AuthController@logout` and `AdminAuthController@logout` — `POST /api/v1/auth/logout` / `POST /api/v1/admin/auth/logout`: revoke current Sanctum token; audit log | P-03 | BE-AUTH-01 | — |
| BE-AUTH-08 | `[x]` Create `AuthController@me` and `AdminAuthController@me` — `GET /api/v1/auth/me` / `GET /api/v1/admin/auth/me`: return authenticated user profile via UserResource | P-03 | SETUP-51 | Used by all three apps on launch to verify session |
| BE-AUTH-09 | `[x]` Create `ProfileController@show/update` — `GET/PUT /api/v1/passenger/profile`: read and update passenger profile (first_name, last_name, email) | P-02 | BE-AUTH-08 | — |
| BE-AUTH-10 | `[x]` Create `UpdateProfileRequest` FormRequest — validate first_name, last_name, email (unique except self) | P-02 | BE-AUTH-09 | — |
| BE-AUTH-11 | `[x]` Create `User` Eloquent model with relationships: driver(); casts: type→UserType, admin_role→AdminRole, password→hashed; helpers: isPassenger(), isDriver(), isAdmin(), isSafetyOperator(), hasAdminRole() | — | SETUP-07 | — |
| BE-AUTH-12 | `[x]` Create `UserResource` API resource for consistent user serialization | — | BE-AUTH-11 | — |
| BE-AUTH-13 | `[x]` Create `OtpService` for ride-start PIN verification: 4-digit SHA-256 hashed codes, 30-min expiry, 3 max attempts with atomic DB increment, constant-time comparison via `hash_equals()` | §9.1 | SETUP-55 | Optional but enabled by default; SMS delivery via SmsGateway contract |

---

## 3. City & Vehicle-Class Management API

**PRD refs:** A-01–A-05, §8.2

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-CITY-01 | `[ ]` Create `City` Eloquent model with relationships: vehicleClasses(), rides(), pricingConfigs(), evStations() | — | SETUP-08 | — |
| BE-CITY-02 | `[ ]` Create `VehicleClass` Eloquent model with relationships: cities(), drivers(), pricingConfigs() | — | SETUP-09 | — |
| BE-CITY-03 | `[ ]` Create `AdminCityController@store` — `POST /api/v1/admin/cities`: create city with name, boundary, timezone, currency; audit log | A-01 | BE-CITY-01, SETUP-52 | Admin-only |
| BE-CITY-04 | `[ ]` Create `StoreCityFormRequest` — validate name unique, boundary format (GeoJSON), timezone, currency code | A-01 | BE-CITY-03 | — |
| BE-CITY-05 | `[ ]` Create `AdminCityController@index` — `GET /api/v1/admin/cities`: list cities with pagination, filter by is_active | A-01 | BE-CITY-01 | — |
| BE-CITY-06 | `[ ]` Create `AdminCityController@update` — `PUT /api/v1/admin/cities/{city}`: update city details; audit log | A-01 | BE-CITY-03 | — |
| BE-CITY-07 | `[ ]` Create `AdminCityController@toggleStatus` — `PATCH /api/v1/admin/cities/{city}/status`: activate/deactivate; audit log | A-01 | BE-CITY-03 | — |
| BE-CITY-08 | `[ ]` Create `CityController@index` — `GET /api/v1/cities`: public endpoint listing active cities with boundaries | A-01 | BE-CITY-01 | Cacheable; invalidate on Admin update |
| BE-CITY-09 | `[ ]` Create `CityResource` and `CityCollection` API resources | — | BE-CITY-01 | — |
| BE-CITY-10 | `[ ]` Create `AdminVehicleClassController@store` — `POST /api/v1/admin/vehicle-classes`: create vehicle class | A-03 | BE-CITY-02, SETUP-52 | — |
| BE-CITY-11 | `[ ]` Create `AdminVehicleClassController@index` — `GET /api/v1/admin/vehicle-classes`: list all vehicle classes | A-03 | BE-CITY-10 | — |
| BE-CITY-12 | `[ ]` Create `AdminVehicleClassController@update` — `PUT /api/v1/admin/vehicle-classes/{vehicleClass}` | A-03 | BE-CITY-10 | — |
| BE-CITY-13 | `[ ]` Create `AdminCityVehicleClassController@update` — `PUT /api/v1/admin/cities/{city}/vehicle-classes`: enable/disable classes for a city | A-04 | BE-CITY-03, BE-CITY-10 | Accepts array of {vehicle_class_id, is_active} |
| BE-CITY-14 | `[ ]` Create `CityVehicleClassController@index` — `GET /api/v1/cities/{city}/vehicle-classes`: public endpoint returning active classes for a city | A-04 | BE-CITY-13 | Used by Passenger App vehicle selector; cacheable |
| BE-CITY-15 | `[ ]` Create `VehicleClassResource` API resource | — | BE-CITY-02 | — |

---

## 4. Pricing Engine

**PRD refs:** A-06, A-07, P-06, P-07

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PRICE-01 | `[ ]` Create `PricingConfig` Eloquent model with relationships: city(), vehicleClass() | — | SETUP-13 | — |
| BE-PRICE-02 | `[ ]` Create `AdminPricingController@store` — `POST /api/v1/admin/pricing`: create pricing config for city + vehicle class with effective_from; audit log | A-06 | BE-PRICE-01, SETUP-52 | ⚠ Confirm whether waiting-time charges apply |
| BE-PRICE-03 | `[ ]` Create `StorePricingFormRequest` — validate city_id, vehicle_class_id, rates (positive decimals), effective_from (future timestamp) | A-06 | BE-PRICE-02 | — |
| BE-PRICE-04 | `[ ]` Create `AdminPricingController@index` — `GET /api/v1/admin/pricing`: list pricing configs with filters, show current and historical versions | A-06 | BE-PRICE-01 | — |
| BE-PRICE-05 | `[ ]` Create `FareEstimationService` — accept pickup/destination coords, city, vehicle class → query maps adapter for distance + duration → apply formula: `max(minimum_fare, base_fare + (distance_km × per_km_rate) + (duration_min × per_minute_rate))` → return estimate | P-06 | BE-PRICE-01, SETUP-59 | — |
| BE-PRICE-06 | `[ ]` Create `RideController@estimate` — `POST /api/v1/rides/estimate`: accept pickup, destination, city_id → return fare estimates for all active vehicle classes | P-06 | BE-PRICE-05 | — |
| BE-PRICE-07 | `[ ]` Create `EstimateRideFormRequest` — validate pickup/destination coordinates, city_id exists and is active | P-06 | BE-PRICE-06 | — |
| BE-PRICE-08 | `[ ]` Implement pricing snapshot capture: when a ride is created, snapshot the active pricing config as jsonb on the ride record | A-07 | BE-PRICE-01 | In-progress trips retain booking-time rate |
| BE-PRICE-09 | `[ ]` Create `PricingResource` API resource | — | BE-PRICE-01 | — |

---

## 5. Ride State Machine & Core Engine

**PRD refs:** §8.2, §8.5, §9.1, §9.4, P-08–P-10, P-14, D-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RIDE-01 | `[ ]` Create `Ride` Eloquent model with relationships: city(), vehicleClass(), passenger(), driver(), stateTransitions(), payment(), ratings(), disputes(), carbonScore() | — | SETUP-14 | — |
| BE-RIDE-02 | `[ ]` Create `RideStateMachine` service with explicit valid transitions map; reject invalid transitions; write every transition to `ride_state_transitions` | §8.5, §9.4 | BE-RIDE-01, SETUP-15 | Valid: Requested→Searching, Searching→Matched/No_Driver_Found, Matched→Driver_En_Route/Cancelled, etc. |
| BE-RIDE-03 | `[ ]` Create `RideController@store` — `POST /api/v1/rides`: validate pickup/destination within city boundary, set status=Requested, snapshot pricing, generate 4-digit PIN, generate share_token, transition to Searching, dispatch matching job | P-08 | BE-RIDE-02, BE-PRICE-08, BE-CITY-14 | — |
| BE-RIDE-04 | `[ ]` Create `StoreRideFormRequest` — validate pickup/destination coords, city_id, vehicle_class_id, payment_method | P-08 | BE-RIDE-03 | Validate user has completed minimum profile fields |
| BE-RIDE-05 | `[ ]` Implement `RidePinService` — generate random 4-digit numeric PIN on ride creation; validate driver PIN entry against ride record; track attempt count; lockout on max exceeded | §9.1, P-14, D-09 | BE-RIDE-03 | Hard gate — trip cannot start without valid PIN |
| BE-RIDE-06 | `[ ]` Create `RideController@cancel` — `POST /api/v1/rides/{ride}/cancel`: validate cancellation is allowed for current state, transition to Cancelled, notify driver if matched | P-10 | BE-RIDE-02 | ⚠ OQ-05: Cancellation fee logic TBD |
| BE-RIDE-07 | `[ ]` Create `RideController@driverArrived` — `POST /api/v1/rides/{ride}/driver-arrived`: validate driver is assigned, transition to Driver_Arrived, push PIN notification to passenger | D-09 | BE-RIDE-02 | — |
| BE-RIDE-08 | `[ ]` Create `RideController@verifyPin` — `POST /api/v1/rides/{ride}/verify-pin`: validate PIN, on match transition to In_Progress, on mismatch increment attempts | §9.1, D-09 | BE-RIDE-05 | — |
| BE-RIDE-09 | `[ ]` Create `RideController@complete` — `POST /api/v1/rides/{ride}/complete`: transition to Completed, dispatch FinalFareCalculationJob, dispatch CarbonScoreJob, dispatch PaymentCaptureJob, push rating prompt | D-09 | BE-RIDE-02 | — |
| BE-RIDE-10 | `[ ]` Create `FinalFareCalculationJob` — use actual distance/duration (GPS trace or maps adapter) × pricing snapshot to compute final fare; update ride record | P-06 | BE-RIDE-09, BE-PRICE-05 | — |
| BE-RIDE-11 | `[ ]` Create `RideController@show` — `GET /api/v1/rides/{ride}`: return full ride details; scope by role (passenger sees theirs, driver sees assigned, admin sees any) | — | BE-RIDE-01 | — |
| BE-RIDE-12 | `[ ]` Create `RideController@index` — `GET /api/v1/rides`: list rides with filters (status, date range, city) and pagination; scope by role | P-19, D-11 | BE-RIDE-01 | — |
| BE-RIDE-13 | `[ ]` Create `RideShareController@show` — `GET /api/v1/rides/{ride}/share/{token}`: no-auth, read-only endpoint returning live driver location, ETA, trip status | P-13 | BE-RIDE-01 | Share token expires after trip completion + 1 hour |
| BE-RIDE-14 | `[ ]` Create `RideResource`, `RideCollection`, `RideDetailResource` API resources | — | BE-RIDE-01 | — |
| BE-RIDE-15 | `[ ]` Create `RideStateTransition` Eloquent model (read-only) | — | SETUP-15 | — |

---

## 6. Real-Time Location Layer

**PRD refs:** §8.3, B-04, A-08, A-09, NF-03

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-LOC-01 | `[ ]` Create `DriverLocationController@update` — `POST /api/v1/drivers/location`: receive lat, lng, heading, speed, timestamp; store in Redis GEOADD; rate-limit to max 1/second | §8.3 | SETUP-41, SETUP-51 | Only accept from Online, Approved drivers |
| BE-LOC-02 | `[ ]` Create `LocationUpdateFormRequest` — validate lat/lng bounds, heading (0-360), speed (>= 0), timestamp format | §8.3 | BE-LOC-01 | — |
| BE-LOC-03 | `[ ]` Set up Laravel Broadcasting with WebSocket server (Laravel Reverb or Pusher) with JWT-authenticated channel subscriptions | B-04 | SETUP-43, SETUP-51 | — |
| BE-LOC-04 | `[ ]` Define private broadcast channel `ride.{rideId}`: authorized for matched passenger and assigned driver only | B-04 | BE-LOC-03 | — |
| BE-LOC-05 | `[ ]` Define private broadcast channel `admin.rides`: authorized for Admin users only; receives all active ride + driver location events | A-08 | BE-LOC-03 | — |
| BE-LOC-06 | `[ ]` Create `DriverLocationUpdated` broadcastable event: publish driver location to ride channel and admin channel on each location update | B-04 | BE-LOC-04, BE-LOC-05 | ⏱ Target latency: server-publish to client-render (NF-03) |
| BE-LOC-07 | `[ ]` Implement ETA recalculation service: on location update during active ride, recompute ETA via maps adapter; throttle to every ~30 seconds to control API cost | B-04 | BE-LOC-01, SETUP-59 | — |
| BE-LOC-08 | `[ ]` Create `RideLocationController@show` — `GET /api/v1/rides/{ride}/location`: fallback polling endpoint when WS drops, returns latest driver location and ETA | B-04 | BE-LOC-01 | — |

---

## 7. Proximity-Based Matching Engine

**PRD refs:** §8.6, §9.5, P-09, D-06, D-07, A-15

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-MATCH-01 | `[ ]` Create `DriverMatchingService` — query Redis GEORADIUS for Online, Approved drivers of the requested vehicle class within initial radius of pickup point; sort by distance | §8.6 | SETUP-41, BE-RIDE-03 | ⚠ Open: Initial radius value — Engineering proposes, PM confirms |
| BE-MATCH-02 | `[ ]` Create `DispatchRideRequestJob` — push ride request to best candidate driver via WS broadcast + push notification with pickup location, estimated fare, response countdown | D-06 | BE-MATCH-01, BE-LOC-03, SETUP-57 | — |
| BE-MATCH-03 | `[ ]` Create `RideRequestDispatched` broadcastable event for driver channel | D-06 | BE-MATCH-02 | — |
| BE-MATCH-04 | `[ ]` Create `RideController@accept` — `POST /api/v1/rides/{ride}/accept`: driver accepts; transition to Matched → Driver_En_Route; reject concurrent acceptances atomically (row lock) | D-07 | BE-MATCH-02, BE-RIDE-02 | First-accept wins |
| BE-MATCH-05 | `[ ]` Create `RideController@reject` — `POST /api/v1/rides/{ride}/reject`: driver rejects; mark as rejected for this ride; dispatch to next candidate | D-07 | BE-MATCH-02 | — |
| BE-MATCH-06 | `[ ]` Create `DriverResponseTimeoutJob` — delayed job; if driver hasn't responded within the window, treat as rejection and re-dispatch | D-07 | BE-MATCH-02 | ⚠ Open: Response window duration — confirm with PM |
| BE-MATCH-07 | `[ ]` Implement radius expansion logic: after exhausting candidates in current radius, expand in configurable steps and re-dispatch | §8.6 | BE-MATCH-01 | ⚠ Open: Expansion step sizes and max radius |
| BE-MATCH-08 | `[ ]` Create `MatchingTimeoutJob` — if no match after all expansion steps within timeout, transition to No_Driver_Found, notify passenger | P-09 | BE-MATCH-07, BE-RIDE-02 | Log no-match events for Admin reporting |
| BE-MATCH-09 | `[ ]` Create `AdminRideController@assign` — `POST /api/v1/admin/rides/{ride}/assign`: Admin manually assigns an online driver; uses same state machine transitions | A-15 | BE-MATCH-04, SETUP-52 | Not a parallel path — plugs into shared state machine |
| BE-MATCH-10 | `[ ]` Create `AssignRideFormRequest` — validate driver_id is online, approved, correct vehicle class, not on another active ride | A-15 | BE-MATCH-09 | — |

---

## 8. Payment & Settlement

**PRD refs:** P-15, P-16, B-08, NF-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PAY-01 | `[ ]` Create `PaymentMethodController@store` — `POST /api/v1/users/payment-methods`: tokenize card via gateway adapter, store token + masked details | P-15 | SETUP-61, SETUP-51 | 🔒 No raw card data stored |
| BE-PAY-02 | `[ ]` Create `StorePaymentMethodFormRequest` — validate gateway-provided token/nonce (NOT raw card data) | P-15 | BE-PAY-01 | — |
| BE-PAY-03 | `[ ]` Create `PaymentMethodController@index` — `GET /api/v1/users/payment-methods`: list saved methods (masked) | P-15 | BE-PAY-01 | — |
| BE-PAY-04 | `[ ]` Create `PaymentMethodController@destroy` — `DELETE /api/v1/users/payment-methods/{method}` | P-15 | BE-PAY-01 | — |
| BE-PAY-05 | `[ ]` Create `UserPaymentMethod` Eloquent model | — | SETUP-17 | — |
| BE-PAY-06 | `[ ]` Create `PaymentService` — orchestrate cash and card flows on trip completion | P-15 | SETUP-61 | — |
| BE-PAY-07 | `[ ]` Implement cash payment flow in `PaymentService`: on trip completion with method=cash, create Payment with status=pending_collection; `POST /api/v1/rides/{ride}/confirm-cash` for driver to mark collected | P-15 | BE-PAY-06, SETUP-16 | — |
| BE-PAY-08 | `[ ]` Implement card payment flow in `PaymentService`: on trip completion with method=card, capture charge via gateway adapter, create Payment with status=captured | P-15 | BE-PAY-06, SETUP-61 | — |
| BE-PAY-09 | `[ ]` Create `ProcessPaymentJob` — dispatched on ride completion; calls PaymentService based on payment method | P-15 | BE-PAY-06, BE-RIDE-09 | — |
| BE-PAY-10 | `[ ]` Create `RideTipController@store` — `POST /api/v1/rides/{ride}/tip`: add tip after completion; capture additional card charge or log cash tip | P-16 | BE-PAY-08 | ⚠ Confirm whether cash-tip logging is in scope |
| BE-PAY-11 | `[ ]` Create `StoreTipFormRequest` — validate amount (positive), ride is completed, ride belongs to user | P-16 | BE-PAY-10 | — |
| BE-PAY-12 | `[ ]` Create `RideReceiptController@show` — `GET /api/v1/rides/{ride}/receipt`: generate receipt with fare breakdown, date/time, driver, vehicle, payment method, discount, tip | P-19 | BE-PAY-08 | — |
| BE-PAY-13 | `[ ]` Create `Payment` Eloquent model with relationships: ride() | — | SETUP-16 | — |
| BE-PAY-14 | `[ ]` Create `PaymentResource`, `PaymentMethodResource` API resources | — | BE-PAY-13 | — |

---

## 9. Ratings & Disputes API

**PRD refs:** P-17, P-18, A-13, A-14

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RATE-01 | `[ ]` Create `RideRatingController@store` — `POST /api/v1/rides/{ride}/rating`: submit 1-5 star rating with optional comment; validate ride is completed and user hasn't already rated | P-17 | SETUP-18 | — |
| BE-RATE-02 | `[ ]` Create `StoreRatingFormRequest` — validate score (1-5), comment (nullable, max length), ride completed | P-17 | BE-RATE-01 | — |
| BE-RATE-03 | `[ ]` Create `Rating` Eloquent model; add average rating accessor on User model | — | SETUP-18 | — |
| BE-RATE-04 | `[ ]` Create `DisputeController@store` — `POST /api/v1/rides/{ride}/dispute`: passenger reports an issue with category + description; creates dispute record | P-18 | SETUP-19 | — |
| BE-RATE-05 | `[ ]` Create `StoreDisputeFormRequest` — validate category (enum), description (required, max length) | P-18 | BE-RATE-04 | — |
| BE-RATE-06 | `[ ]` Create `Dispute` Eloquent model with relationships: ride(), reportedBy(), resolvedBy() | — | SETUP-19 | — |
| BE-RATE-07 | `[ ]` Create `AdminDisputeController@index` — `GET /api/v1/admin/disputes`: list disputes with filters (status, date range), pagination | A-13 | BE-RATE-06, SETUP-52 | — |
| BE-RATE-08 | `[ ]` Create `AdminDisputeController@show` — `GET /api/v1/admin/disputes/{dispute}`: full dispute with trip detail | A-13 | BE-RATE-07 | — |
| BE-RATE-09 | `[ ]` Create `AdminDisputeController@resolve` — `POST /api/v1/admin/disputes/{dispute}/resolve`: update status, add resolution notes; audit log | A-14 | BE-RATE-08 | ⚠ OQ-08: Confirm whether refund/fare adjustment actions are in scope |
| BE-RATE-10 | `[ ]` Create `ResolveDisputeFormRequest` — validate status transition, resolution_notes required | A-14 | BE-RATE-09 | — |
| BE-RATE-11 | `[ ]` Create `DisputeResource` API resource | — | BE-RATE-06 | — |

---

## 10. Gamification & Carbon Scoring Engine

**PRD refs:** G-01–G-08, B-10–B-12, A-17–A-19

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-GAME-01 | `[ ]` Create `TierConfig`, `PointMultiplierConfig`, `GamificationProfile`, `TripCarbonScore` Eloquent models with relationships | — | SETUP-23 through SETUP-26 | — |
| BE-GAME-02 | `[ ]` Create `CarbonScoreService` — calculate per-trip carbon score: CO₂ saved = distance_km × (baseline_emission − vehicle_class_emission); store in trip_carbon_scores | G-02, B-10 | BE-GAME-01, BE-RIDE-09 | ⚠ OQ-10: Emission data source TBD |
| BE-GAME-03 | `[ ]` Create `PointMultiplierService` — check ride conditions (EV, shared, off-peak), look up multiplier config, apply to base points, handle stacking | G-03, B-11 | BE-GAME-02, SETUP-25 | — |
| BE-GAME-04 | `[ ]` Create `TierEvaluationService` — update cumulative points on gamification profile, compare against tier thresholds, promote if crossed | B-11 | BE-GAME-03, SETUP-24 | — |
| BE-GAME-05 | `[ ]` Create `CalculateCarbonScoreJob` — dispatched on ride completion; calls CarbonScoreService → PointMultiplierService → TierEvaluationService pipeline | B-10, B-11 | BE-GAME-02, BE-GAME-03, BE-GAME-04 | — |
| BE-GAME-06 | `[ ]` Create `TierUpgraded` event — on tier promotion, dispatch push notification for celebration animation and unlock tier-gated features | G-04, B-11 | BE-GAME-04, SETUP-57 | — |
| BE-GAME-07 | `[ ]` Create `GamificationController@show` — `GET /api/v1/users/gamification`: return current tier, total points, carbon score, progress to next tier, unlocked benefits | G-04, B-12 | BE-GAME-01 | — |
| BE-GAME-08 | `[ ]` Create `TierGateService` — utility checking user's tier for: (a) priority matching eligibility (G-05), (b) booking fee discount amount (G-06), (c) exclusive promo access (G-07), (d) EV fee waiver eligibility (G-08) | B-12 | BE-GAME-04 | ⚠ OQ-11: G-05 depends on surge pricing (not in Phase 1) — implement hook but flag inactive |
| BE-GAME-09 | `[ ]` Create `AdminGamificationController@indexUsers` — `GET /api/v1/admin/gamification/users`: list users with tier/score data, filters, pagination | A-19 | BE-GAME-01, SETUP-52 | — |
| BE-GAME-10 | `[ ]` Create `AdminGamificationController@aggregate` — `GET /api/v1/admin/gamification/aggregate`: tier distribution, average carbon scores, top users | A-19 | BE-GAME-09 | — |
| BE-GAME-11 | `[ ]` Create `AdminTierConfigController@update` — `PUT /api/v1/admin/gamification/tiers`: update tier thresholds/names; audit log | A-17 | BE-GAME-01, SETUP-52 | ⚠ OQ-09: Tier naming TBD |
| BE-GAME-12 | `[ ]` Create `AdminMultiplierConfigController@update` — `PUT /api/v1/admin/gamification/multipliers`: update multiplier values; audit log | A-18 | BE-GAME-01, SETUP-52 | — |
| BE-GAME-13 | `[ ]` Create `GamificationResource`, `TierConfigResource`, `CarbonScoreResource` API resources | — | BE-GAME-01 | — |

---

## 11. Promo / Discount Engine

**PRD refs:** PR-01–PR-05, A-20–A-23, B-13–B-15, NF-10

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PROMO-01 | `[ ]` Create `PromoCode`, `PromoRedemption` Eloquent models with relationships | — | SETUP-21, SETUP-22 | — |
| BE-PROMO-02 | `[ ]` Create `AdminPromoController@store` — `POST /api/v1/admin/promos`: create promo with discount type, value, cap, limits, eligibility rules | A-20–A-22 | BE-PROMO-01, SETUP-52 | — |
| BE-PROMO-03 | `[ ]` Create `StorePromoFormRequest` — validate code uniqueness, discount type/value, cap, dates, geo-fence format, tier level | A-20 | BE-PROMO-02 | — |
| BE-PROMO-04 | `[ ]` Create `AdminPromoController@index` — `GET /api/v1/admin/promos`: list promos with filters (active/expired/all), pagination | A-20 | BE-PROMO-01 | — |
| BE-PROMO-05 | `[ ]` Create `AdminPromoController@update` — `PUT /api/v1/admin/promos/{promo}`: update config | A-20 | BE-PROMO-02 | — |
| BE-PROMO-06 | `[ ]` Create `PromoValidationService` — validate code against all rules atomically: exists, active, within time window, within geo-fence, per-user cap, global cap, order history, tier requirement, peak/off-peak | B-13, PR-01 | BE-PROMO-01, BE-GAME-08 | Use DB transaction + row locking to prevent race-condition over-redemption; idempotency key |
| BE-PROMO-07 | `[ ]` Create `PromoController@validate` — `POST /api/v1/promos/validate`: validate promo code at checkout; return discount amount or rejection reason | PR-01 | BE-PROMO-06 | — |
| BE-PROMO-08 | `[ ]` Create `ValidatePromoFormRequest` — validate code, ride context (city, vehicle class, pickup coords) | PR-01 | BE-PROMO-07 | — |
| BE-PROMO-09 | `[ ]` Implement redemption cap enforcement: row-level locking (SELECT FOR UPDATE) on promo_codes + count check on promo_redemptions within transaction | B-14, NF-10 | BE-PROMO-06 | — |
| BE-PROMO-10 | `[ ]` Create `PromoApplicationService` — calculate discount amount, apply cap, subtract from fare, create redemption record, include in receipt breakdown | B-15 | BE-PROMO-09, BE-PRICE-05 | — |
| BE-PROMO-11 | `[ ]` Create `AdminPromoController@performance` — `GET /api/v1/admin/promos/{promo}/performance`: redemption count, total discount, revenue impact | A-23 | SETUP-22 | — |
| BE-PROMO-12 | `[ ]` Create `PromoController@available` — `GET /api/v1/promos/available`: return promos the user is eligible for (no code needed) | PR-04 | BE-PROMO-06, BE-GAME-08 | ⚠ OQ-12: Auto-apply vs. code-entry-only — confirm scope |
| BE-PROMO-13 | `[ ]` Create `PromoCodeResource`, `PromoRedemptionResource` API resources | — | BE-PROMO-01 | — |

---

## 12. SOS Telemetry & Escalation Pipeline

**PRD refs:** SOS-01–SOS-04, A-24–A-27, B-16–B-18, NF-04, NF-08

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-SOS-01 | `[ ]` Create `SosIncident`, `SosEventLog` Eloquent models with relationships | — | SETUP-27, SETUP-28 | SosEventLog is read-only; no update/delete |
| BE-SOS-02 | `[ ]` Create `SosService` — orchestrate incident lifecycle: trigger → check-in → escalate → resolve/cancel; write every event to sos_event_log | B-16–B-18 | BE-SOS-01 | — |
| BE-SOS-03 | `[ ]` Create `SosController@trigger` — `POST /api/v1/rides/{ride}/sos`: validate ride is active, capture GPS + vehicle + IDs, encrypt telemetry, create incident, dispatch check-in job | B-16, SOS-02 | BE-SOS-02, BE-RIDE-01 | 🔒 Encrypt telemetry at rest (NF-04) |
| BE-SOS-04 | `[ ]` Create `TriggerSosFormRequest` — validate ride is in active state (driver_en_route through in_progress), GPS coords | SOS-02 | BE-SOS-03 | — |
| BE-SOS-05 | `[ ]` Create `SendSosCheckInJob` — push check-in prompt to triggering user's device; update incident status to check_in_sent; dispatch escalation timeout job | B-17, SOS-03 | BE-SOS-02, SETUP-57 | ⏱ Safety-critical P0 — server-side timer, not client-dependent (NF-08) |
| BE-SOS-06 | `[ ]` Create `SosEscalationTimeoutJob` — delayed 30-second job; if check-in not acknowledged, escalate to Safety Operator queue; broadcast to admin SOS channel | A-25, B-17 | BE-SOS-05, BE-LOC-03 | — |
| BE-SOS-07 | `[ ]` Create `SosController@acknowledge` — `POST /api/v1/sos/{incident}/acknowledge`: user acknowledges check-in; update status; cancel escalation job | SOS-03 | BE-SOS-05 | — |
| BE-SOS-08 | `[ ]` Create `SosController@cancel` — `POST /api/v1/sos/{incident}/cancel`: user cancels SOS; update status; log cancellation (not silently discarded) | SOS-04, A-27 | BE-SOS-02, SETUP-28 | — |
| BE-SOS-09 | `[ ]` Create `AdminSosController@active` — `GET /api/v1/admin/sos/active`: list active/unacknowledged incidents with trip + telemetry detail | A-24 | BE-SOS-01, SETUP-52 | Safety Operator scope only |
| BE-SOS-10 | `[ ]` Create `AdminSosController@assign` — `POST /api/v1/admin/sos/{incident}/assign`: operator self-assigns | A-24 | BE-SOS-09 | — |
| BE-SOS-11 | `[ ]` Create `AdminSosController@dispatch` — `POST /api/v1/admin/sos/{incident}/dispatch`: trigger emergency-services handoff | A-26, B-18 | BE-SOS-10 | ⚠ OQ-04: Integration provider TBD; may launch as voice-outreach-only |
| BE-SOS-12 | `[ ]` Create `AdminSosController@resolve` — `POST /api/v1/admin/sos/{incident}/resolve`: resolve with notes; audit log | A-27 | BE-SOS-10 | — |
| BE-SOS-13 | `[ ]` Create `SosIncidentEscalated` broadcastable event for admin SOS channel | A-24 | BE-SOS-06, BE-LOC-03 | Real-time feed to SOS console |
| BE-SOS-14 | `[ ]` Create `SosIncidentResource` API resource | — | BE-SOS-01 | — |

---

## 13. Anti-Offline Trip Detection Engine

**PRD refs:** B-19–B-22, §9.3, OE-01–OE-03, A-28–A-30, NF-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-AO-01 | `[ ]` Create `OfflineTripFlag` Eloquent model with relationships: ride(), driver(), passenger(), resolvedBy() | — | SETUP-29 | — |
| BE-AO-02 | `[ ]` Create `OfflineTripDetectionService` — core detection logic: analyze GPS trajectories post-cancellation for collocation along intended route | B-19, B-20 | BE-AO-01 | ⚠ OQ-13: Collocation threshold TBD. ⚠ OQ-14: False-positive rate target TBD |
| BE-AO-03 | `[ ]` Create `MonitorCancellationJob` — dispatched on ride cancellation where driver was at/near pickup; starts post-cancellation GPS trajectory tracking | B-19 | BE-AO-02, BE-RIDE-06, BE-LOC-01 | — |
| BE-AO-04 | `[ ]` Create `AnalyzeOfflineTripJob` — delayed job after cancellation monitoring window; runs collocation analysis; if flagged, dispatch sanction job | B-20 | BE-AO-03 | — |
| BE-AO-05 | `[ ]` Create `SanctionService` — apply sanction tier: count existing flags within 30 days → L1=warning, L2=48h suspension, L3=permanent deactivation; create flag record, update driver compliance_status | §9.3, B-21 | BE-AO-04, SETUP-29 | L2/L3: force driver Offline, block accepting rides |
| BE-AO-06 | `[ ]` Dispatch compliance warning notification to driver on flagging | OE-01 | BE-AO-05, SETUP-57 | — |
| BE-AO-07 | `[ ]` Create `DriverFlagController@dispute` — `POST /api/v1/drivers/flags/{flag}/dispute`: driver disputes; update record; trigger manual review | OE-02, B-22 | BE-AO-01 | — |
| BE-AO-08 | `[ ]` Create `DriverComplianceController@show` — `GET /api/v1/drivers/compliance`: return compliance status and active sanctions | OE-03 | BE-AO-01 | — |
| BE-AO-09 | `[ ]` Create `AdminOfflineFlagController@index` — `GET /api/v1/admin/offline-flags`: list flagged trips with driver, sanction tier, dispute status | A-28 | BE-AO-01, SETUP-52 | — |
| BE-AO-10 | `[ ]` Create `AdminOfflineFlagController@review` — `POST /api/v1/admin/offline-flags/{flag}/review`: overturn or uphold; if overturned, reverse sanction; audit log | A-29, B-22 | BE-AO-09 | — |
| BE-AO-11 | `[ ]` Create `AdminOfflineFlagController@escalate` — `POST /api/v1/admin/offline-flags/{flag}/escalate`: manually escalate sanction tier | A-30 | BE-AO-09 | ⚠ OQ-14: Who has final authority to overturn L3? |
| BE-AO-12 | `[ ]` Create `OfflineFlagResource` API resource | — | BE-AO-01 | — |

---

## 14. EV Charging Reservation System

**PRD refs:** EV-01–EV-04, B-23–B-26, A-31–A-32

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-EV-01 | `[ ]` Create `EvChargingStation`, `EvChargingStall`, `EvReservation` Eloquent models with relationships | — | SETUP-30, SETUP-31, SETUP-32 | — |
| BE-EV-02 | `[ ]` Create `AdminEvStationController@store` — `POST /api/v1/admin/ev-stations`: create station record | A-31 | BE-EV-01, SETUP-52 | ⚠ OQ-15: Data source TBD |
| BE-EV-03 | `[ ]` Create `AdminEvStationController@index` — `GET /api/v1/admin/ev-stations`: list stations with filters | A-31 | BE-EV-01 | — |
| BE-EV-04 | `[ ]` Create `AdminEvStationController@update` — `PUT /api/v1/admin/ev-stations/{station}` | A-31 | BE-EV-02 | — |
| BE-EV-05 | `[ ]` Create `AdminEvStationController@utilisation` — `GET /api/v1/admin/ev-stations/utilisation`: real-time occupancy and queue status | A-32 | BE-EV-01 | — |
| BE-EV-06 | `[ ]` Create `EvStationController@index` — `GET /api/v1/ev-stations`: public endpoint listing active stations with availability for a city | A-32 | BE-EV-01 | Used by Driver App |
| BE-EV-07 | `[ ]` Create `StallAvailabilityService` — maintain real-time stall status based on reservations and occupancy events | B-23 | BE-EV-01 | — |
| BE-EV-08 | `[ ]` Create `ReservationService` — handle three outcomes: (a) stall available → immediate lock, (b) occupied but departure imminent → queue hold, (c) wait > 15 min → return retry delay | B-24, B-25 | BE-EV-07 | Atomic stall locking (row lock) to prevent double-reservation |
| BE-EV-09 | `[ ]` Create `EvReservationController@store` — `POST /api/v1/ev-stations/{station}/reserve`: driver requests reservation; validate EV-class driver; call ReservationService | EV-01, EV-02, EV-03 | BE-EV-08 | — |
| BE-EV-10 | `[ ]` Create `StoreReservationFormRequest` — validate station exists, driver has EV vehicle class | EV-01 | BE-EV-09 | — |
| BE-EV-11 | `[ ]` Create `TransferReservationJob` — when occupying vehicle departs, automatically transfer reservation to next queued driver and notify | B-24, EV-02 | BE-EV-08, SETUP-57 | — |
| BE-EV-12 | `[ ]` Implement tier-based fee waiver in `ReservationService`: check user tier via TierGateService; waive fee for eligible top-tier users | B-26, EV-04, G-08 | BE-EV-08, BE-GAME-08 | ⚠ OQ-16: Waiver applies to driver, passenger, or both? |
| BE-EV-13 | `[ ]` Create `EvReservationController@index` — `GET /api/v1/drivers/ev-reservations`: driver views active/past reservations | — | BE-EV-01 | — |
| BE-EV-14 | `[ ]` Create `EvStationResource`, `EvReservationResource` API resources | — | BE-EV-01 | — |

---

## 15. Notification Service

**PRD refs:** B-06

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-NOTIF-01 | `[ ]` Create `NotificationDispatchService` — centralised service accepting notification type, recipient, payload → route to push notification adapter + create in-app notification record | B-06 | SETUP-57, SETUP-33 | All features call this single service |
| BE-NOTIF-02 | `[ ]` Define `NotificationType` enum: ride_matched, ride_cancelled, driver_arriving, ride_started, ride_completed, sos_check_in, sos_escalated, compliance_warning, promo_expiring, tier_upgrade, ev_reservation_ready, dispute_update, kyc_status_changed, scheduled_ride_reminder, lost_item_report | B-06 | BE-NOTIF-01 | — |
| BE-NOTIF-03 | `[ ]` Create `Notification` Eloquent model | — | SETUP-33 | — |
| BE-NOTIF-04 | `[ ]` Create `NotificationController@index` — `GET /api/v1/notifications`: list in-app notifications for authenticated user, paginated, newest first | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-05 | `[ ]` Create `NotificationController@markRead` — `PATCH /api/v1/notifications/{notification}/read`: mark single notification as read | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-06 | `[ ]` Create `NotificationController@markAllRead` — `POST /api/v1/notifications/read-all`: mark all notifications as read | B-06 | BE-NOTIF-03 | — |
| BE-NOTIF-07 | `[ ]` Create `DeviceTokenController@store` — `POST /api/v1/device-tokens`: register device token for push | B-06 | SETUP-34 | — |
| BE-NOTIF-08 | `[ ]` Create `DeviceTokenController@destroy` — `DELETE /api/v1/device-tokens/{token}`: deactivate a device token | B-06 | SETUP-34 | — |

---

## 16. Admin Management API

**PRD refs:** A-10–A-12, A-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-ADMIN-01 | `[x]` Create `Driver` Eloquent model with relationships: user(), documents(), vehicle(); casts status→DriverStatus enum | — | SETUP-11 | Implemented in `App\Models\Driver` |
| BE-ADMIN-02 | `[x]` Create `DriverDocument` Eloquent model with `DocumentType` and `DocumentStatus` enums | — | SETUP-12 | Soft-replacement: re-uploading same type deletes previous |
| BE-ADMIN-03 | `[x]` Create `OnboardingController@uploadDocument` — `POST /api/v1/driver/documents`: upload KYC documents (driving_licence, vehicle_registration, insurance_certificate, government_id); max 5MB, jpeg/png/pdf | D-02 | BE-ADMIN-02 | Previous doc of same type is deleted on re-upload |
| BE-ADMIN-04 | `[x]` Create `OnboardingController@storeVehicle` / `updateVehicle` — `POST/PUT /api/v1/driver/vehicle`: register or update vehicle (make, model, colour, plate_number, year); vehicle class assigned by Admin | D-04 | BE-ADMIN-01 | PUT supports partial updates |
| BE-ADMIN-05 | `[x]` Create `OnboardingController@status` — `GET /api/v1/driver/onboarding/status`: return driver profile, documents, vehicle, and missing-items checklist | D-03 | BE-ADMIN-01 | — |
| BE-ADMIN-06 | `[ ]` Create `DriverController@toggleOnline` — `POST /api/v1/drivers/toggle-online`: toggle online/offline; validate approved + vehicle class assigned + not suspended | D-05 | BE-ADMIN-01 | — |
| BE-ADMIN-07 | `[x]` Create `DriverManagementController@index` — `GET /api/v1/admin/drivers`: list drivers with status filter and search (name/email/phone), paginated | A-10 | BE-ADMIN-01, SETUP-52 | Admin-only via `user.type:admin` middleware |
| BE-ADMIN-08 | `[x]` Create `DriverManagementController@show` — `GET /api/v1/admin/drivers/{driver}`: full profile with user, documents, vehicle | A-10 | BE-ADMIN-07 | — |
| BE-ADMIN-09 | `[x]` Create `DriverManagementController@review` — `POST /api/v1/admin/drivers/{driver}/review`: approve or reject with reason; wrapped in DB::transaction(); validates status transition (only pending_review → approved/rejected); audit log | A-10, A-05 | BE-ADMIN-08 | Returns 422 if driver not in reviewable state |
| BE-ADMIN-10 | `[x]` Create `ReviewDriverRequest` — validate decision (approve/reject), reason (required) | A-10 | BE-ADMIN-09 | — |
| BE-ADMIN-11 | `[x]` Create `DriverManagementController@suspend` — `POST /api/v1/admin/drivers/{driver}/suspend`: suspend driver; validates driver is in `approved` status; no request body; wrapped in DB::transaction(); audit log | A-11 | BE-ADMIN-08 | Returns 422 if not approved |
| BE-ADMIN-12 | `[x]` Create `DriverManagementController@reactivate` — `POST /api/v1/admin/drivers/{driver}/reactivate`: lift suspension; validates currently suspended; wrapped in DB::transaction(); audit log | A-11 | BE-ADMIN-11 | Returns 422 if not suspended |
| BE-ADMIN-13 | `[ ]` Create `AdminPassengerController@index` — `GET /api/v1/admin/passengers`: list passengers with filters, search, pagination | A-12 | SETUP-52 | — |
| BE-ADMIN-14 | `[ ]` Create `AdminPassengerController@show` — `GET /api/v1/admin/passengers/{user}`: profile, tier data, trip history, dispute history | A-12 | BE-ADMIN-13 | — |
| BE-ADMIN-15 | `[ ]` Create `AdminPassengerController@suspend` / `reactivate` — suspend or reactivate passenger account; audit log | A-12 | BE-ADMIN-14 | — |
| BE-ADMIN-16 | `[x]` Create `DriverResource`, `DriverDocumentResource`, `VehicleResource` API resources | — | BE-ADMIN-01 | Used by both driver onboarding and admin endpoints |

---

## 17. Admin Reporting API

**PRD refs:** A-16

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RPT-01 | `[ ]` Create `AdminReportController@rideVolume` — `GET /api/v1/admin/reports/ride-volume`: total rides, rides by status, by city, by vehicle class; date-range filters | A-16 | SETUP-52 | — |
| BE-RPT-02 | `[ ]` Create `AdminReportController@completionRate` — `GET /api/v1/admin/reports/completion-rate`: completed vs cancelled, cancellation reasons breakdown | A-16 | SETUP-52 | — |
| BE-RPT-03 | `[ ]` Create `AdminReportController@revenue` — `GET /api/v1/admin/reports/revenue`: total revenue, by city, by payment method, average fare | A-16 | SETUP-52 | — |
| BE-RPT-04 | `[ ]` Create `AdminReportController@driverUtilisation` — `GET /api/v1/admin/reports/driver-utilisation`: online hours, trips per driver, earnings per driver | A-16 | SETUP-52 | — |
| BE-RPT-05 | `[ ]` Create `ReportExportService` — generate CSV export for any report type; stream large datasets | A-16 | BE-RPT-01 through BE-RPT-04 | ⚠ Confirm export format with Client (CSV assumed) |
| BE-RPT-06 | `[ ]` Create `AdminReportController@export` — `GET /api/v1/admin/reports/export`: accept report type + filters, return CSV download | A-16 | BE-RPT-05 | — |

---

---

# PHASE 2 — Month-3 Rollout (Weeks 9–12)

> Working draft — must be reviewed and re-frozen before Week 9.

---

## 18. Phase 2 — Scheduled Rides

**PRD refs:** B-27, P-20–P-22, D-12, A-33, NF-11

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-SCH-01 | `[ ]` Create migration: `scheduled_rides` table — id, ride_id (FK), scheduled_pickup_at, reminder_sent_at (nullable), dispatched_at (nullable), status (enum: scheduled/reminded/dispatching/dispatched/cancelled), created_at | B-27 | SETUP-14 | — |
| BE2-SCH-02 | `[ ]` Create `ScheduledRide` Eloquent model with relationship: ride() | — | BE2-SCH-01 | — |
| BE2-SCH-03 | `[ ]` Extend `RideController@store` — accept optional `scheduled_pickup_at` → create ride in Scheduled state instead of Requested | P-20 | BE2-SCH-02, BE-RIDE-03 | — |
| BE2-SCH-04 | `[ ]` Create `ScheduledRideDispatchJob` — durable scheduled job; triggers at (scheduled_time − lead_time); transitions ride Scheduled → Requested → Searching; starts matching | B-27, NF-11 | BE2-SCH-03, BE-MATCH-01 | ⏱ Must survive restarts/deploys; use database-backed job queue, not in-memory timer |
| BE2-SCH-05 | `[ ]` Create `ScheduledRideReminderJob` — send push notification at Admin-configured reminder time before pickup | P-21 | BE2-SCH-04, SETUP-57 | — |
| BE2-SCH-06 | `[ ]` Create `ScheduledRideController@index` — `GET /api/v1/rides/scheduled`: list upcoming scheduled rides for passenger | P-22 | BE2-SCH-02 | — |
| BE2-SCH-07 | `[ ]` Create `ScheduledRideController@update` — `PUT /api/v1/rides/{ride}/scheduled`: edit time, pickup, destination before dispatch | P-22 | BE2-SCH-06 | Only while status=scheduled |
| BE2-SCH-08 | `[ ]` Create `ScheduledRideController@cancel` — `DELETE /api/v1/rides/{ride}/scheduled`: cancel before dispatch | P-22 | BE2-SCH-06 | — |
| BE2-SCH-09 | `[ ]` Create `AdminScheduledRideConfigController@update` — `PUT /api/v1/admin/config/scheduled-rides`: set lead-time window and reminder timing | A-33 | SETUP-52 | — |

---

## 19. Phase 2 — Third-Party Bookings

**PRD refs:** B-28, P-23, P-24, D-13, A-34

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-3P-01 | `[ ]` Create migration: add to `rides` table — booker_user_id (FK nullable), rider_name (nullable), rider_phone (nullable), is_third_party (bool default false) | B-28 | SETUP-14 | — |
| BE2-3P-02 | `[ ]` Create migration: `third_party_configs` table — id, pin_recipient (enum: booker/rider/both), tracking_recipient (enum: booker/rider/both), updated_by_admin_id, updated_at | A-34 | SETUP-06 | ⚠ OQ-18: Must be decided before build |
| BE2-3P-03 | `[ ]` Extend `RideController@store` — accept rider_name and rider_phone for third-party booking; set booker as authenticated user | P-23 | BE2-3P-01, BE-RIDE-03 | — |
| BE2-3P-04 | `[ ]` Create `ThirdPartyRoutingService` — based on Admin config, route PIN and live tracking to booker, rider, or both | P-24, D-13, B-28 | BE2-3P-02, BE-RIDE-05 | Changes §9.1 PIN flow for third-party rides |
| BE2-3P-05 | `[ ]` Create `AdminThirdPartyConfigController@update` — `PUT /api/v1/admin/config/third-party`: set PIN/tracking recipient policy | A-34 | BE2-3P-02, SETUP-52 | — |

---

## 20. Phase 2 — Lost & Found

**PRD refs:** B-29, P-25, P-26, D-14, A-35

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-LF-01 | `[ ]` Create migration: `lost_items` table — id, ride_id (FK), reported_by_user_id (FK), category (enum), description (text), status (enum: reported/driver_notified/driver_confirmed/driver_denied/resolved/unresolved), driver_response (text nullable), admin_notes (text nullable), resolved_by_admin_id (FK nullable), created_at, updated_at, resolved_at (nullable) | B-29 | SETUP-14 | — |
| BE2-LF-02 | `[ ]` Create `LostItem` Eloquent model with relationships: ride(), reportedBy(), resolvedBy() | — | BE2-LF-01 | — |
| BE2-LF-03 | `[ ]` Create `LostItemController@store` — `POST /api/v1/rides/{ride}/lost-items`: passenger reports lost item against completed trip | P-25 | BE2-LF-02 | — |
| BE2-LF-04 | `[ ]` Create `LostItemController@index` — `GET /api/v1/lost-items`: list reports for authenticated user | P-26 | BE2-LF-02 | — |
| BE2-LF-05 | `[ ]` Dispatch push notification to driver on new lost-item report | D-14 | BE2-LF-03, SETUP-57 | — |
| BE2-LF-06 | `[ ]` Create `LostItemController@respond` — `POST /api/v1/lost-items/{item}/respond`: driver confirms or denies possession | D-14 | BE2-LF-02 | Status flows back to passenger and Admin |
| BE2-LF-07 | `[ ]` Create `AdminLostItemController@index` — `GET /api/v1/admin/lost-items`: queue with trip detail, category, status | A-35 | BE2-LF-02, SETUP-52 | — |
| BE2-LF-08 | `[ ]` Create `AdminLostItemController@intervene` — `POST /api/v1/admin/lost-items/{item}/intervene`: Admin resolves or adds notes | A-35 | BE2-LF-07 | — |
| BE2-LF-09 | `[ ]` Create `LostItemResource` API resource | — | BE2-LF-02 | — |

---

## 21. Phase 2 — Advanced Disputes

**PRD refs:** B-30, P-27, A-36, A-37, NF-12

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-DISP-01 | `[ ]` Create migration: `dispute_evidence` table — id, dispute_id (FK), file_path, file_type, file_size_bytes, uploaded_by_user_id (FK), uploaded_at, created_at | B-30 | SETUP-19 | 🔒 Access restricted to authorised reviewers (NF-12) |
| BE2-DISP-02 | `[ ]` Create `DisputeEvidence` Eloquent model | — | BE2-DISP-01 | — |
| BE2-DISP-03 | `[ ]` Create `DisputeEvidenceController@store` — `POST /api/v1/disputes/{dispute}/evidence`: upload photo evidence (validate image type/size, store securely) | P-27, B-30 | BE2-DISP-02 | — |
| BE2-DISP-04 | `[ ]` Create migration: add to `disputes` table — escalation_tier (int nullable), escalated_to_admin_id (FK nullable), escalated_at (nullable) | A-37 | SETUP-19 | — |
| BE2-DISP-05 | `[ ]` Create `AdminDisputeController@escalate` — `POST /api/v1/admin/disputes/{dispute}/escalate`: escalate to higher-tier reviewer | A-37 | BE2-DISP-04, SETUP-52 | ⚠ OQ-21: Escalation tiers TBD |

---

## 22. Phase 2 — Multi-City Support

**PRD refs:** B-31, A-38

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-CITY-01 | `[ ]` Write integration tests verifying Phase 1 city-scoping model supports onboarding additional cities without schema changes | B-31 | BE-CITY-01 | Should hold if Phase 1 was built generically |
| BE2-CITY-02 | `[ ]` Document city onboarding procedure: Admin steps, pricing config, vehicle class activation, driver assignment | A-38 | BE2-CITY-01 | Flag city-specific needs (currency, language, payment method) |

---

## 23. Phase 2 — Driver Scheduling & Vehicle Maintenance

**PRD refs:** B-32, D-15, D-16, A-39

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-SHIFT-01 | `[ ]` Create migration: `driver_shifts` table — id, driver_id (FK), shift_date, start_time, end_time, status (enum: scheduled/active/completed/missed), created_by_admin_id (FK), created_at | B-32 | SETUP-11 | ⚠ OQ-20: Scope TBD |
| BE2-SHIFT-02 | `[ ]` Create `DriverShift` Eloquent model | — | BE2-SHIFT-01 | — |
| BE2-SHIFT-03 | `[ ]` Create `DriverShiftController@index` — `GET /api/v1/drivers/shifts`: driver views schedule | D-15 | BE2-SHIFT-02 | — |
| BE2-SHIFT-04 | `[ ]` Create `AdminDriverShiftController@store/update` — Admin manages shift assignments | A-39 | BE2-SHIFT-02, SETUP-52 | — |
| BE2-VEH-01 | `[ ]` Create migration: `vehicle_maintenance` table — id, driver_id (FK), maintenance_type (enum: charging/service/inspection), status (enum: scheduled/due/in_progress/completed), due_date, completed_at (nullable), notes (text nullable), created_at | B-32 | SETUP-11 | ⚠ OQ-19: EV-specific, general, or both? |
| BE2-VEH-02 | `[ ]` Create `VehicleMaintenance` Eloquent model | — | BE2-VEH-01 | — |
| BE2-VEH-03 | `[ ]` Create `DriverVehicleStatusController@show` — `GET /api/v1/drivers/vehicle/status`: charging status and/or maintenance indicators | D-16 | BE2-VEH-02 | — |
| BE2-VEH-04 | `[ ]` Create `AdminVehicleMaintenanceController@store/update` — Admin manages maintenance records | A-39 | BE2-VEH-02, SETUP-52 | — |

---

## 24. Open Questions Tracker

### Provider & Integration Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-01 | Which SMS/OTP gateway is approved? | SETUP-55, SETUP-56, BE-AUTH-13 | `[ ]` Partially resolved: `SmsGateway` contract + `LogSmsGateway` adapter in place; real provider TBD |
| OQ-02 | Which maps/geocoding provider is approved? | SETUP-59, SETUP-60 | `[ ]` Unresolved |
| OQ-03 | Which card payment gateway is approved? | SETUP-61, SETUP-62 | `[ ]` Unresolved |
| OQ-04 | Which emergency-services dispatch integration is approved? | BE-SOS-11 | `[ ]` Unresolved |
| OQ-17 | Minimum supported iOS and Android versions? | Mobile app tasks (frontend doc) | `[ ]` Unresolved |

### Business Rule Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-05 | Does Phase 1 include a cancellation fee, and under what conditions? | BE-RIDE-06 | `[ ]` Unresolved |
| OQ-06 | How and how often are driver earnings paid out? | BE-PAY (payout logic) | `[ ]` Unresolved |
| OQ-07 | Single Admin role or role-based tiers (Ops/Support/Finance/Safety)? | SETUP-35, SETUP-52 | `[x]` **Resolved:** Role-based tiers implemented — super_admin, operations, safety_operator, support; enforced via `EnsureAdminRole` middleware |
| OQ-08 | Can Admin issue refunds/fare adjustments when resolving disputes? | BE-RATE-09 | `[ ]` Unresolved |
| OQ-09 | Tier naming: Option A (Bronze → Diamond) or Option B (Seed → Forest)? | BE-GAME-11, SETUP-24 seed | `[ ]` Unresolved |
| OQ-10 | Source for baseline/per-vehicle-class emissions data? | BE-GAME-02 | `[ ]` Unresolved |
| OQ-11 | Is surge pricing in scope in Phase 1? | BE-GAME-08 | `[ ]` Unresolved |
| OQ-12 | Auto-apply promos in Phase 1, or code-entry only? | BE-PROMO-12 | `[ ]` Unresolved |

### Detection & Enforcement Thresholds

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-13 | GPS collocation threshold and jitter tolerance? | BE-AO-02 | `[ ]` Unresolved |
| OQ-14 | False-positive rate target and L3 overturn authority? | BE-AO-02, BE-AO-11 | `[ ]` Unresolved |

### EV Charging Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-15 | EV station data source? | BE-EV-02 | `[ ]` Unresolved |
| OQ-16 | Fee waiver applies to driver, passenger, or both? | BE-EV-12 | `[ ]` Unresolved |

### Phase 2 Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-18 | Who receives PIN/tracking for third-party bookings? | BE2-3P-04 | `[ ]` Unresolved |
| OQ-19 | Vehicle charging & maintenance: EV-specific, general, or both? | BE2-VEH-01 | `[ ]` Unresolved |
| OQ-20 | Driver scheduling: shift scheduling or availability planning? | BE2-SHIFT-01 | `[ ]` Unresolved |
| OQ-21 | Dispute escalation tiers and ownership? | BE2-DISP-05 | `[ ]` Unresolved |
| OQ-22 | Which cities are being activated and target date? | BE2-CITY-01 | `[ ]` Unresolved |

---

*Document generated from PRD v3.0 and Product Brief. Backend scope only — see `E-Tigo_Frontend_Task_Breakdown.md` for Passenger App, Driver App, and Admin Dashboard UI tasks. Update statuses: `[ ]` → `[~]` (in progress) → `[x]` (done).*
