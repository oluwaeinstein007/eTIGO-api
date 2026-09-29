# E-tiGo — Detailed Task Breakdown

**Source:** PRD v3.0 (Phase 1 & Phase 2) + Product Brief  
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
2. [Backend — Authentication Service](#2-backend--authentication-service)
3. [Backend — City & Vehicle-Class Management](#3-backend--city--vehicle-class-management)
4. [Backend — Pricing Engine](#4-backend--pricing-engine)
5. [Backend — Ride State Machine & Core Engine](#5-backend--ride-state-machine--core-engine)
6. [Backend — Real-Time Location Layer](#6-backend--real-time-location-layer)
7. [Backend — Proximity-Based Matching](#7-backend--proximity-based-matching)
8. [Backend — Payment & Settlement](#8-backend--payment--settlement)
9. [Backend — Gamification & Carbon Scoring Engine](#9-backend--gamification--carbon-scoring-engine)
10. [Backend — Promo / Discount Engine](#10-backend--promo--discount-engine)
11. [Backend — SOS Telemetry & Escalation Pipeline](#11-backend--sos-telemetry--escalation-pipeline)
12. [Backend — Anti-Offline Trip Detection Engine](#12-backend--anti-offline-trip-detection-engine)
13. [Backend — EV Charging Reservation System](#13-backend--ev-charging-reservation-system)
14. [Backend — Notification Service](#14-backend--notification-service)
15. [Passenger App — Authentication & Onboarding](#15-passenger-app--authentication--onboarding)
16. [Passenger App — Booking Flow](#16-passenger-app--booking-flow)
17. [Passenger App — In-Ride Experience](#17-passenger-app--in-ride-experience)
18. [Passenger App — Payment, Ratings & History](#18-passenger-app--payment-ratings--history)
19. [Passenger App — Gamification & Tier Status](#19-passenger-app--gamification--tier-status)
20. [Passenger App — Promo & Discounts](#20-passenger-app--promo--discounts)
21. [Passenger App — Emergency SOS](#21-passenger-app--emergency-sos)
22. [Driver App — Onboarding & KYC](#22-driver-app--onboarding--kyc)
23. [Driver App — Availability & Ride Handling](#23-driver-app--availability--ride-handling)
24. [Driver App — Earnings & History](#24-driver-app--earnings--history)
25. [Driver App — Anti-Offline Compliance](#25-driver-app--anti-offline-compliance)
26. [Driver App — EV Charging Reservation](#26-driver-app--ev-charging-reservation)
27. [Admin Dashboard — Authentication & Layout](#27-admin-dashboard--authentication--layout)
28. [Admin Dashboard — City Management](#28-admin-dashboard--city-management)
29. [Admin Dashboard — Vehicle-Class Management](#29-admin-dashboard--vehicle-class-management)
30. [Admin Dashboard — Pricing Configuration](#30-admin-dashboard--pricing-configuration)
31. [Admin Dashboard — Live Monitoring](#31-admin-dashboard--live-monitoring)
32. [Admin Dashboard — Driver Management](#32-admin-dashboard--driver-management)
33. [Admin Dashboard — Passenger Management](#33-admin-dashboard--passenger-management)
34. [Admin Dashboard — Ride Disputes](#34-admin-dashboard--ride-disputes)
35. [Admin Dashboard — Manual Assignment Fallback](#35-admin-dashboard--manual-assignment-fallback)
36. [Admin Dashboard — Operational Reporting](#36-admin-dashboard--operational-reporting)
37. [Admin Dashboard — Gamification & Tier Management](#37-admin-dashboard--gamification--tier-management)
38. [Admin Dashboard — Promo Engine Management](#38-admin-dashboard--promo-engine-management)
39. [Admin Dashboard — Safety & SOS Operations Console](#39-admin-dashboard--safety--sos-operations-console)
40. [Admin Dashboard — Anti-Offline Compliance Management](#40-admin-dashboard--anti-offline-compliance-management)
41. [Admin Dashboard — EV Charging Station Management](#41-admin-dashboard--ev-charging-station-management)

### Phase 2 — Month-3 Rollout (Weeks 9–12)

42. [Phase 2 — Backend: Scheduled Rides](#42-phase-2--backend-scheduled-rides)
43. [Phase 2 — Backend: Third-Party Bookings](#43-phase-2--backend-third-party-bookings)
44. [Phase 2 — Backend: Lost & Found](#44-phase-2--backend-lost--found)
45. [Phase 2 — Backend: Advanced Disputes](#45-phase-2--backend-advanced-disputes)
46. [Phase 2 — Backend: Multi-City & Driver Scheduling](#46-phase-2--backend-multi-city--driver-scheduling)
47. [Phase 2 — Passenger App](#47-phase-2--passenger-app)
48. [Phase 2 — Driver App](#48-phase-2--driver-app)
49. [Phase 2 — Admin Dashboard](#49-phase-2--admin-dashboard)

### Tracking

50. [Open Questions Tracker](#50-open-questions-tracker)

---

# PHASE 1 — Must-Launch Core (Weeks 1–8)

---

## 1. Infrastructure & Project Setup

**PRD refs:** B-01, B-02, B-03, §8.4, NF-01–NF-07

### 1.1 Repository & CI/CD

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-01 | `[ ]` Initialize monorepo structure with workspace directories: `backend/`, `passenger-app/`, `driver-app/`, `admin-dashboard/`, `shared/` | — | — | — |
| SETUP-02 | `[ ]` Configure linting (ESLint/Prettier or equivalent) with shared config across all workspaces | — | SETUP-01 | Enforce consistent code style from day one |
| SETUP-03 | `[ ]` Set up CI pipeline: lint → type-check → unit tests → integration tests → build | — | SETUP-01 | Branch protection on `main`; require passing CI before merge |
| SETUP-04 | `[ ]` Configure staging and production deployment pipelines with environment-specific config | — | SETUP-03 | Include database migration step in deploy pipeline |
| SETUP-05 | `[ ]` Set up shared TypeScript types / contract package (`shared/`) for API request/response shapes, enums, and constants used across apps | — | SETUP-01 | Single source of truth for ride states, vehicle classes, tier names, etc. |

### 1.2 Database & Data Layer

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-06 | `[ ]` Provision PostgreSQL instance (staging + production) and configure connection pooling | B-02 | SETUP-04 | — |
| SETUP-07 | `[x]` Set up database migration tooling (Laravel migrations) with version-controlled migration files | B-02 | SETUP-06 | Using Laravel's built-in migration system |
| SETUP-08 | `[x]` Design and create `users` table: id, first_name, last_name, phone (unique, E.164), email (unique), type (passenger/driver/admin), admin_role, password, phone_verified_at, is_active, profile_photo_path, created_at, updated_at | B-02 | SETUP-07 | Shared table for all user types; role-specific data in separate tables |
| SETUP-09 | `[ ]` Design and create `cities` table: id, name, slug, boundary (GeoJSON polygon or point+radius), timezone, currency_code, is_active, created_at, updated_at | B-02 | SETUP-07 | Top-level scoping entity for pricing, vehicle classes, and drivers |
| SETUP-10 | `[ ]` Design and create `vehicle_classes` table: id, name, display_name, capacity, icon_url, description, is_active, created_at | B-02 | SETUP-07 | Platform-wide definitions |
| SETUP-11 | `[ ]` Design and create `city_vehicle_classes` join table: city_id, vehicle_class_id, is_active | B-02 | SETUP-09, SETUP-10 | Controls which classes are available in which cities |
| SETUP-12 | `[x]` Design and create `drivers` table: id, user_id (FK), status (enum), licence_number, rejection_reason, is_online, approved_at, suspended_at, created_at, updated_at | B-02 | SETUP-08, SETUP-10 | Vehicle data in separate `vehicles` table |
| SETUP-13 | `[x]` Design and create `driver_documents` table: id, driver_id (FK), type (enum), file_path, original_filename, mime_type, file_size, status (enum), rejection_reason, reviewed_by, reviewed_at, created_at, updated_at | B-02, NF-04 | SETUP-12 | 🔒 Files encrypted at rest; access restricted to Admin KYC review flow |
| SETUP-14 | `[ ]` Design and create `rides` table: id, city_id (FK), vehicle_class_id (FK), passenger_id (FK), driver_id (FK, nullable), pickup_lat, pickup_lng, pickup_address, destination_lat, destination_lng, destination_address, status (enum: requested/searching/matched/driver_en_route/driver_arrived/in_progress/completed/cancelled/no_driver_found), pin_code, fare_estimate, final_fare, fare_currency, pricing_snapshot (JSONB), payment_method (cash/card), payment_status, cancelled_by, cancellation_reason, started_at, completed_at, created_at, updated_at | B-02, §8.2, §8.5 | SETUP-09, SETUP-11, SETUP-08, SETUP-12 | Every ride scoped to city + vehicle class from creation |
| SETUP-15 | `[ ]` Design and create `ride_state_transitions` audit table: id, ride_id (FK), from_state, to_state, triggered_by (user_id or system), metadata (JSONB), created_at | B-09, NF-06 | SETUP-14 | Append-only; no UPDATE or DELETE permissions on this table |
| SETUP-16 | `[ ]` Design and create `payments` table: id, ride_id (FK), amount, currency, method (cash/card), gateway_transaction_id, tip_amount, status (pending/captured/settled/refunded/failed), created_at, updated_at | B-02 | SETUP-14 | — |
| SETUP-17 | `[ ]` Design and create `ratings` table: id, ride_id (FK), rated_by_user_id, rated_user_id, score (1–5), comment (optional), created_at | B-02 | SETUP-14 | — |
| SETUP-18 | `[ ]` Design and create `disputes` table: id, ride_id (FK), reported_by_user_id, category, description, status (open/under_review/resolved/dismissed), resolution_notes, resolved_by_admin_id, created_at, updated_at, resolved_at | B-02 | SETUP-14 | — |
| SETUP-19 | `[x]` Design and create `audit_logs` table: id, auditable_type, auditable_id (polymorphic), event, actor_type, actor_id, old_values (JSONB), new_values (JSONB), ip_address, user_agent, created_at | B-09, NF-06 | SETUP-08 | Append-only polymorphic audit log; covers all model state changes |
| SETUP-20 | `[ ]` Design and create `promo_codes` table: id, code (unique), discount_type (percentage/flat), discount_value, max_discount_cap (for percentage), total_redemption_limit, per_user_limit, starts_at, expires_at, geo_fence (GeoJSON, nullable), min_order_count, max_order_count, min_tier_level, peak_only, off_peak_only, is_active, created_at, updated_at | B-02 | SETUP-07 | — |
| SETUP-21 | `[ ]` Design and create `promo_redemptions` table: id, promo_code_id (FK), user_id (FK), ride_id (FK), discount_amount, redeemed_at | B-02 | SETUP-20, SETUP-14 | — |
| SETUP-22 | `[ ]` Design and create `gamification_profiles` table: id, user_id (FK), total_carbon_score, total_ranking_points, current_tier (enum), tier_upgraded_at, created_at, updated_at | B-02 | SETUP-08 | — |
| SETUP-23 | `[ ]` Design and create `trip_carbon_scores` table: id, ride_id (FK), user_id (FK), distance_km, baseline_emission, vehicle_emission, co2_saved, base_points, multiplier_applied, multiplier_reason, final_points, created_at | B-02 | SETUP-14 | — |
| SETUP-24 | `[ ]` Design and create `sos_incidents` table: id, ride_id (FK), triggered_by_user_id, trigger_type (passenger/driver), status (triggered/check_in_sent/acknowledged/escalated/operator_assigned/dispatched/resolved/cancelled), gps_lat, gps_lng, vehicle_details (JSONB), telemetry_data (JSONB, encrypted), check_in_sent_at, check_in_acknowledged_at, escalated_at, operator_id, operator_notes, resolved_at, created_at | B-02 | SETUP-14 | 🔒 Telemetry encrypted at rest |
| SETUP-25 | `[ ]` Design and create `sos_event_log` audit table: id, incident_id (FK), event_type, actor_id, metadata (JSONB), created_at | NF-06 | SETUP-24 | Append-only immutable audit trail for every SOS lifecycle event |
| SETUP-26 | `[ ]` Design and create `offline_trip_flags` table: id, ride_id (FK), driver_id (FK), passenger_id (FK), detection_data (JSONB — GPS trajectories, route similarity score), sanction_tier (1/2/3), sanction_action, is_disputed, dispute_notes, dispute_resolved_by, dispute_outcome (upheld/overturned), flag_created_at, resolved_at | B-02 | SETUP-14 | — |
| SETUP-27 | `[ ]` Design and create `ev_charging_stations` table: id, name, city_id (FK), lat, lng, address, total_stalls, status (active/inactive/maintenance), created_at, updated_at | B-02 | SETUP-09 | — |
| SETUP-28 | `[ ]` Design and create `ev_charging_stalls` table: id, station_id (FK), stall_number, status (available/occupied/reserved/out_of_service), current_vehicle_driver_id (FK, nullable), occupied_since, estimated_departure_at | B-02 | SETUP-27 | — |
| SETUP-29 | `[ ]` Design and create `ev_reservations` table: id, stall_id (FK), station_id (FK), driver_id (FK), status (reserved/queued/active/completed/expired/cancelled), queue_position, estimated_available_at, fee_amount, fee_waived, reserved_at, activated_at, completed_at | B-02 | SETUP-28 | — |
| SETUP-30 | `[ ]` Create database indexes for high-frequency queries: rides by status/city, drivers by city/online status/location, promo lookups by code, gamification by user | — | SETUP-14 through SETUP-29 | ⏱ Profile queries during load testing |

### 1.3 Redis Configuration

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-31 | `[ ]` Provision Redis instance (staging + production) | B-03 | SETUP-04 | — |
| SETUP-32 | `[ ]` Configure Redis namespace for OTP storage with TTL-based auto-expiry | B-03 | SETUP-31 | Key pattern: `otp:{phone_number}` with configurable TTL |
| SETUP-33 | `[ ]` Configure Redis namespace for session/token state | B-03 | SETUP-31 | Key pattern: `session:{user_id}` |
| SETUP-34 | `[ ]` Configure Redis namespace for live driver-location cache with geo-indexing (GEOADD/GEORADIUS) | B-03 | SETUP-31 | Key pattern: `driver_locations` (sorted set with geo) |
| SETUP-35 | `[ ]` Configure Redis namespace for general application caching (city configs, pricing, vehicle classes) | B-03 | SETUP-31 | Short TTL with cache-invalidation on Admin updates |

### 1.4 API Server Bootstrap

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-36 | `[x]` Bootstrap API server with `/v1/` versioned routing | B-01 | SETUP-01 | Laravel 13 with `api/v1` prefix in `bootstrap/app.php` |
| SETUP-37 | `[x]` Implement request validation (Laravel FormRequest classes) | B-01 | SETUP-36 | Validate all incoming payloads; return structured 422 errors |
| SETUP-38 | `[ ]` Implement global error handling middleware with structured error responses | B-01 | SETUP-36 | Consistent error format: `{ error: { code, message, details } }` |
| SETUP-39 | `[ ]` Implement request logging middleware (method, path, status, duration, user_id) | B-09 | SETUP-36 | — |
| SETUP-40 | `[ ]` Implement rate limiting middleware (per IP and per authenticated user) | NF-01 | SETUP-36 | Protect auth endpoints (OTP request) more aggressively |
| SETUP-41 | `[ ]` Implement CORS configuration for Admin Dashboard and mobile app origins | B-01 | SETUP-36 | — |
| SETUP-42 | `[ ]` Implement health check endpoint (`/v1/health`) for load balancer and monitoring | NF-01 | SETUP-36 | Returns DB and Redis connectivity status |

### 1.5 Access Control

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-43 | `[x]` Admin roles stored in `users` table via `admin_role` enum column (super_admin, operations, safety_operator, support) | §8.4 | SETUP-08 | Using single users table with type + admin_role columns; admin seeder creates default accounts |
| SETUP-44 | `[x]` Implement RBAC middleware: `EnsureUserType` (comma-separated types), `EnsureAdminRole` (comma-separated roles), `EnsureDriverApproved` | §8.4 | SETUP-43 | Safety Operator scope restricts access to SOS console only |
| SETUP-45 | `[x]` Implement Sanctum token authentication middleware | B-01 | SETUP-36 | Shared between Passenger, Driver, and Admin clients |
| SETUP-46 | `[x]` Implement route-level permission guards for Admin-only, Driver-only, and Passenger-only endpoints | §8.4 | SETUP-44, SETUP-45 | — |

### 1.6 Third-Party Integrations Setup

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| SETUP-47 | `[x]` Create `SmsGateway` contract interface for SMS delivery with swappable provider pattern | B-05 | SETUP-36 | Adapter pattern: `App\Contracts\SmsGateway` interface |
| SETUP-48 | `[x]` Implement `LogSmsGateway` adapter (logs instead of sending); bind in `AppServiceProvider` | B-05 | SETUP-47 | Swap for real provider (Twilio, etc.) by implementing interface |
| SETUP-49 | `[ ]` Create an abstraction layer / adapter interface for push notifications (send to device, send to topic) | B-06 | SETUP-36 | ⚠ Provider TBD (FCM/APNs assumed). Store device tokens per user |
| SETUP-50 | `[ ]` Implement concrete push notification adapter (FCM + APNs) with device token registration API | B-06 | SETUP-49 | — |
| SETUP-51 | `[ ]` Design and create `device_tokens` table: id, user_id (FK), platform (ios/android/web), token, is_active, created_at, updated_at | B-06 | SETUP-08 | — |
| SETUP-52 | `[ ]` Create an abstraction layer / adapter interface for maps (geocode, reverse-geocode, autocomplete, directions/ETA, distance matrix) | B-07 | SETUP-36 | ⚠ OQ-02: Provider TBD. Track per-call cost |
| SETUP-53 | `[ ]` Implement concrete maps adapter for the confirmed provider | B-07 | SETUP-52 | — |
| SETUP-54 | `[ ]` Create an abstraction layer / adapter interface for payment gateway (create charge, capture, refund, tokenise card) | B-08 | SETUP-36 | ⚠ OQ-03: Provider TBD. 🔒 No raw card data stored; tokenisation only (NF-05) |
| SETUP-55 | `[ ]` Implement concrete payment gateway adapter for the confirmed provider | B-08, NF-05 | SETUP-54 | — |

---

## 2. Backend — Authentication Service

**PRD refs:** P-01, P-02, P-03, D-01, B-01, B-03

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-AUTH-01 | `[x]` `POST /v1/auth/register` — accept first_name, last_name, phone (E.164), email, password, type (passenger/driver); create user; auto-create Driver record for drivers; issue Sanctum token | P-01, D-01 | SETUP-08, SETUP-12 | Validates unique email + phone, password min 8 chars with confirmation |
| BE-AUTH-02 | `[x]` `POST /v1/auth/login` — validate email + password + type, check user is active, issue Sanctum token | P-01, P-02 | BE-AUTH-01 | Rejects wrong user type and deactivated accounts |
| BE-AUTH-03 | `[x]` `POST /v1/admin/auth/login` — admin email/password login with role-scoped token abilities | §8.4 | SETUP-43 | Abilities include admin role (e.g. `['admin', 'super_admin']`) |
| BE-AUTH-04 | `[x]` Implement Sanctum personal access token generation with ability scoping per user type | P-03 | BE-AUTH-01 | — |
| BE-AUTH-05 | `[x]` `POST /v1/auth/logout` and `POST /v1/admin/auth/logout` — revoke current access token | P-03 | BE-AUTH-04 | — |
| BE-AUTH-06 | `[x]` `GET /v1/auth/me` and `GET /v1/admin/auth/me` — return current user profile from access token | P-03 | BE-AUTH-04 | Used by all three apps on app launch to verify session |
| BE-AUTH-07 | `[x]` `PUT /v1/passenger/profile` — update passenger profile fields (first_name, last_name, email) | P-02 | BE-AUTH-06 | — |

---

## 3. Backend — City & Vehicle-Class Management

**PRD refs:** A-01–A-05, §8.2

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-CITY-01 | `[ ]` `POST /v1/admin/cities` — create a new city with name, boundary, timezone, currency | A-01 | SETUP-09, SETUP-44 | Admin-only; audit log the action |
| BE-CITY-02 | `[ ]` `GET /v1/admin/cities` — list all cities with pagination and filters (active/inactive) | A-01 | BE-CITY-01 | — |
| BE-CITY-03 | `[ ]` `PUT /v1/admin/cities/:id` — update city details including boundary | A-01 | BE-CITY-01 | — |
| BE-CITY-04 | `[ ]` `PATCH /v1/admin/cities/:id/status` — activate or deactivate a city | A-01 | BE-CITY-01 | Deactivation: confirm whether in-progress rides in this city are affected |
| BE-CITY-05 | `[ ]` `GET /v1/cities` — public endpoint listing active cities with boundaries (for Passenger/Driver apps) | A-01 | BE-CITY-01 | Cacheable; invalidate on Admin update |
| BE-CITY-06 | `[ ]` `POST /v1/admin/vehicle-classes` — create a vehicle class (name, capacity, icon) | A-03 | SETUP-10, SETUP-44 | — |
| BE-CITY-07 | `[ ]` `GET /v1/admin/vehicle-classes` — list all vehicle classes | A-03 | BE-CITY-06 | — |
| BE-CITY-08 | `[ ]` `PUT /v1/admin/vehicle-classes/:id` — edit a vehicle class | A-03 | BE-CITY-06 | — |
| BE-CITY-09 | `[ ]` `PUT /v1/admin/cities/:cityId/vehicle-classes` — enable/disable vehicle classes for a specific city | A-04 | BE-CITY-01, BE-CITY-06 | — |
| BE-CITY-10 | `[ ]` `GET /v1/cities/:cityId/vehicle-classes` — public endpoint returning active vehicle classes for a city | A-04 | BE-CITY-09 | Used by Passenger App vehicle selector |

---

## 4. Backend — Pricing Engine

**PRD refs:** A-06, A-07, P-06, P-07

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PRICE-01 | `[ ]` Design and create `pricing_configs` table: id, city_id (FK), vehicle_class_id (FK), base_fare, per_km_rate, per_minute_rate, minimum_fare, waiting_time_rate (nullable), version, effective_from, created_by_admin_id, created_at | A-06, A-07 | SETUP-09, SETUP-11 | Versioned with effective timestamp; ⚠ OQ: Confirm whether waiting-time charges apply |
| BE-PRICE-02 | `[ ]` `POST /v1/admin/pricing` — create a new pricing config for a city + vehicle class combination with an effective-from timestamp | A-06 | BE-PRICE-01, SETUP-44 | Audit log the change; does not affect in-progress rides |
| BE-PRICE-03 | `[ ]` `GET /v1/admin/pricing` — list pricing configs with filters (city, vehicle class) showing current and historical versions | A-06 | BE-PRICE-02 | — |
| BE-PRICE-04 | `[ ]` Implement fare estimation service: accept pickup/destination coordinates, city, vehicle class → query maps adapter for distance + duration → apply pricing formula → return fare estimate | P-06 | BE-PRICE-01, SETUP-52 | Formula: `max(minimum_fare, base_fare + (distance_km × per_km_rate) + (duration_min × per_minute_rate))` |
| BE-PRICE-05 | `[ ]` `POST /v1/rides/estimate` — public endpoint accepting pickup, destination, city_id → return fare estimates for all active vehicle classes in that city | P-06 | BE-PRICE-04 | — |
| BE-PRICE-06 | `[ ]` Implement pricing snapshot: when a ride is created, capture the active pricing config as a JSONB snapshot on the ride record so in-progress trips retain the booking-time rate | A-07 | BE-PRICE-01 | — |

---

## 5. Backend — Ride State Machine & Core Engine

**PRD refs:** §8.2, §8.5, §9.1, §9.4, P-08–P-10, P-14, D-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-RIDE-01 | `[ ]` Implement ride state machine as a service with explicit transition rules: define valid transitions and reject invalid ones | §8.5, §9.4 | SETUP-14, SETUP-15 | Valid transitions: Requested→Searching, Searching→Matched/No_Driver_Found, Matched→Driver_En_Route/Cancelled, Driver_En_Route→Driver_Arrived/Cancelled, Driver_Arrived→In_Progress/Cancelled, In_Progress→Completed. Every transition writes to `ride_state_transitions` |
| BE-RIDE-02 | `[ ]` `POST /v1/rides` — create a ride request: validate pickup/destination within city boundary, set status=Requested, snapshot pricing, generate PIN, transition to Searching, trigger matching | P-08 | BE-RIDE-01, BE-PRICE-06, BE-CITY-10 | — |
| BE-RIDE-03 | `[ ]` `POST /v1/rides/:id/cancel` — cancel a ride: validate cancellation is allowed for current state (pre-match or shortly post-match), transition to Cancelled, notify driver if matched | P-10 | BE-RIDE-01 | ⚠ OQ-05: Cancellation fee logic TBD |
| BE-RIDE-04 | `[ ]` Implement ride-start PIN generation: generate a random 4-digit numeric code on ride creation, store on ride record, expose to passenger after driver marks arrival | §9.1, P-14 | BE-RIDE-02 | — |
| BE-RIDE-05 | `[ ]` `POST /v1/rides/:id/verify-pin` — driver submits PIN, validate against ride record, on match transition to In_Progress, on mismatch increment attempt counter | §9.1, D-09 | BE-RIDE-04 | Hard gate — trip cannot start without valid PIN. Max retries: confirm with PM; on max exceeded, escalate to Admin |
| BE-RIDE-06 | `[ ]` `POST /v1/rides/:id/driver-arrived` — driver marks arrival at pickup, transition to Driver_Arrived state, notify passenger to show PIN | D-09 | BE-RIDE-01 | — |
| BE-RIDE-07 | `[ ]` `POST /v1/rides/:id/complete` — driver marks trip complete, transition to Completed, trigger final fare calculation, trigger payment capture, trigger carbon score calculation, trigger rating prompt | D-09 | BE-RIDE-01 | — |
| BE-RIDE-08 | `[ ]` `GET /v1/rides/:id` — return full ride details (state, driver, passenger, fare, route, timeline) | — | BE-RIDE-02 | Scoped by role: passenger sees their ride, driver sees assigned ride, admin sees any ride |
| BE-RIDE-09 | `[ ]` `GET /v1/rides` — list rides with filters (status, date range, city) and pagination | P-19, D-11 | BE-RIDE-02 | Passenger: their rides; Driver: their rides; Admin: all rides |
| BE-RIDE-10 | `[ ]` Implement final fare calculation on trip completion: use actual distance/duration from maps adapter (or GPS trace) × pricing snapshot | P-06 | BE-RIDE-07, BE-PRICE-04 | — |
| BE-RIDE-11 | `[ ]` Implement trip-sharing endpoint: `GET /v1/rides/:id/share/:token` — no-auth, read-only, returns live driver location, ETA, trip status | P-13 | BE-RIDE-02 | Generate a unique share token per ride; token expires after trip completion + 1 hour |

---

## 6. Backend — Real-Time Location Layer

**PRD refs:** §8.3, B-04, A-08, A-09, NF-03

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-LOC-01 | `[ ]` `POST /v1/drivers/location` — receive driver location update (lat, lng, heading, speed, timestamp), store in Redis geo-index | §8.3 | SETUP-34, SETUP-45 | Only accept from Online drivers; rate-limit to max 1 update per second |
| BE-LOC-02 | `[ ]` Set up WebSocket server (Socket.IO or ws) with JWT-authenticated connections | B-04 | SETUP-36, SETUP-45 | Separate WS namespace/rooms for: ride tracking, admin monitoring |
| BE-LOC-03 | `[ ]` Implement ride-tracking WS room: when a ride is in Matched→In_Progress states, fan out driver location updates to the matched passenger in real time | B-04 | BE-LOC-02, BE-RIDE-01 | ⏱ Target latency: server-publish to client-render (NF-03) |
| BE-LOC-04 | `[ ]` Implement admin-monitoring WS room: fan out all online driver locations and active ride updates to connected Admin clients | A-08 | BE-LOC-02 | — |
| BE-LOC-05 | `[ ]` Implement ETA recalculation: on each driver location update during an active ride, recompute ETA to pickup/destination using maps adapter and push to passenger | B-04 | BE-LOC-01, SETUP-52 | Throttle ETA recalculation to avoid excessive maps API calls (e.g. every 30 seconds) |
| BE-LOC-06 | `[ ]` Implement WebSocket reconnection / fallback polling: if WS connection drops, clients can poll `GET /v1/rides/:id/location` as fallback | B-04 | BE-LOC-02 | — |

---

## 7. Backend — Proximity-Based Matching

**PRD refs:** §8.6, §9.5, P-09, D-06, D-07, A-15

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-MATCH-01 | `[ ]` Implement driver search: query Redis GEORADIUS for Online, Approved drivers of the requested vehicle class within initial radius of pickup point | §8.6 | SETUP-34, BE-RIDE-02 | ⚠ Open: Initial radius value — Engineering to propose, PM to confirm |
| BE-MATCH-02 | `[ ]` Implement ride request dispatch to candidate drivers: push ride request via WS/push notification with pickup location, estimated fare, response countdown | D-06 | BE-MATCH-01, BE-LOC-02, SETUP-49 | — |
| BE-MATCH-03 | `[ ]` `POST /v1/rides/:id/accept` — driver accepts a dispatched ride request; transition ride to Matched → Driver_En_Route; reject further acceptances | D-07 | BE-MATCH-02, BE-RIDE-01 | First-accept wins; concurrent acceptances handled atomically |
| BE-MATCH-04 | `[ ]` `POST /v1/rides/:id/reject` — driver rejects a dispatched ride request; mark driver as rejected for this ride; re-dispatch to next candidate | D-07 | BE-MATCH-02 | — |
| BE-MATCH-05 | `[ ]` Implement response window timeout: if dispatched driver does not accept/reject within the window, treat as rejection and re-dispatch | D-07 | BE-MATCH-02 | ⚠ Open: Response window duration — confirm with PM |
| BE-MATCH-06 | `[ ]` Implement radius expansion: after exhausting candidates within the initial radius, expand search radius in configurable steps and re-broadcast | §8.6 | BE-MATCH-01 | ⚠ Open: Expansion step sizes and max radius |
| BE-MATCH-07 | `[ ]` Implement matching timeout: if no driver matched after all expansion steps within the timeout window, transition ride to No_Driver_Found and notify passenger | P-09 | BE-MATCH-06, BE-RIDE-01 | Log no-match events for Admin reporting |
| BE-MATCH-08 | `[ ]` `POST /v1/admin/rides/:id/assign` — Admin manually assigns an online driver to a ride request; same state machine transitions as automated matching | A-15 | BE-MATCH-03, SETUP-44 | Must use the same ride state machine — not a parallel path |

---

## 8. Backend — Payment & Settlement

**PRD refs:** P-15, P-16, B-08, NF-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PAY-01 | `[ ]` `POST /v1/users/payment-methods` — tokenise and store a card via the payment gateway adapter; return a payment method ID | P-15 | SETUP-54, SETUP-45 | 🔒 No raw card numbers stored; gateway handles PCI scope |
| BE-PAY-02 | `[ ]` `GET /v1/users/payment-methods` — list saved payment methods for the authenticated user | P-15 | BE-PAY-01 | Return masked card details only (last 4, brand, expiry) |
| BE-PAY-03 | `[ ]` `DELETE /v1/users/payment-methods/:id` — remove a saved payment method | P-15 | BE-PAY-01 | — |
| BE-PAY-04 | `[ ]` Implement cash payment flow: on trip completion with method=cash, record payment as "pending collection," update to "collected" on driver confirmation | P-15 | BE-RIDE-07, SETUP-16 | — |
| BE-PAY-05 | `[ ]` Implement card payment flow: on trip completion with method=card, capture the authorised charge via gateway adapter, record payment | P-15 | BE-RIDE-07, SETUP-54 | — |
| BE-PAY-06 | `[ ]` `POST /v1/rides/:id/tip` — add a tip after trip completion; capture additional charge for card, log for cash | P-16 | BE-PAY-05 | ⚠ Confirm whether cash-tip logging is in scope |
| BE-PAY-07 | `[ ]` `GET /v1/rides/:id/receipt` — generate and return a receipt: fare breakdown (base, distance, time, discount, tip), date/time, driver, vehicle, payment method | P-19 | BE-PAY-05 | — |

---

## 9. Backend — Gamification & Carbon Scoring Engine

**PRD refs:** G-01–G-08, B-10–B-12, A-17–A-19

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-GAME-01 | `[ ]` Design and create `tier_configs` table: id, tier_level (1–5), tier_name, min_points_required, booking_fee_discount_pct, ev_reservation_fee_waived (bool), priority_matching_enabled (bool), created_at, updated_at | A-17 | SETUP-07 | ⚠ OQ-09: Tier naming (Bronze–Diamond vs. Seed–Forest) — PM to confirm |
| BE-GAME-02 | `[ ]` Design and create `point_multiplier_configs` table: id, condition_type (ev_ride/shared_journey/off_peak), multiplier_value (1.5–2.0), is_stackable (bool), created_at, updated_at | A-18 | SETUP-07 | ⚠ OQ: Confirm exact multiplier values and stacking |
| BE-GAME-03 | `[ ]` Implement per-trip carbon score calculation on trip completion: CO₂ saved = distance_km × (baseline_emission − vehicle_class_emission) | G-02, B-10 | BE-RIDE-07, SETUP-23 | ⚠ OQ-10: Baseline/vehicle-class emission data source TBD |
| BE-GAME-04 | `[ ]` Implement point multiplier application: check ride conditions (is EV, is shared, is off-peak), look up multiplier config, apply to base points, handle stacking logic | G-03, B-11 | BE-GAME-03, BE-GAME-02 | — |
| BE-GAME-05 | `[ ]` Implement cumulative score update and tier evaluation: after each trip, update `gamification_profiles.total_ranking_points`, compare against `tier_configs` thresholds, promote tier if crossed | B-11 | BE-GAME-04, BE-GAME-01 | — |
| BE-GAME-06 | `[ ]` Implement tier-change event emission: on tier upgrade, emit event for (a) celebration animation push notification, (b) unlocking tier-gated features | G-04, B-11 | BE-GAME-05, SETUP-49 | — |
| BE-GAME-07 | `[ ]` `GET /v1/users/gamification` — return user's current tier, total points, carbon score, progress to next tier | G-04, B-12 | BE-GAME-05 | — |
| BE-GAME-08 | `[ ]` `GET /v1/admin/gamification/users` — list all users with tier and score data, with filters and pagination | A-19 | BE-GAME-05, SETUP-44 | — |
| BE-GAME-09 | `[ ]` `GET /v1/admin/gamification/aggregate` — aggregate tier distribution, average carbon scores, top users | A-19 | BE-GAME-08 | — |
| BE-GAME-10 | `[ ]` `PUT /v1/admin/gamification/tiers` — Admin updates tier thresholds and point requirements | A-17 | BE-GAME-01, SETUP-44 | Audit log the change |
| BE-GAME-11 | `[ ]` `PUT /v1/admin/gamification/multipliers` — Admin updates point multiplier values | A-18 | BE-GAME-02, SETUP-44 | Audit log the change |
| BE-GAME-12 | `[ ]` Implement tier-gated feature hooks: expose utility functions checking user tier for (a) priority matching eligibility (G-05), (b) booking fee discount (G-06), (c) exclusive promo access (G-07), (d) EV reservation fee waiver (G-08) | B-12 | BE-GAME-05 | ⚠ OQ-11: G-05 (priority matching during surge) depends on surge pricing — not in Phase 1 core scope. Implement hook but flag as inactive |

---

## 10. Backend — Promo / Discount Engine

**PRD refs:** PR-01–PR-05, A-20–A-23, B-13–B-15, NF-10

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-PROMO-01 | `[ ]` `POST /v1/admin/promos` — create a promo code with discount type, value, cap, usage limits, eligibility rules | A-20, A-21, A-22 | SETUP-20, SETUP-44 | — |
| BE-PROMO-02 | `[ ]` `GET /v1/admin/promos` — list all promo codes with filters (active/expired/all), pagination | A-20 | BE-PROMO-01 | — |
| BE-PROMO-03 | `[ ]` `PUT /v1/admin/promos/:id` — update promo configuration (limits, eligibility, status) | A-20 | BE-PROMO-01 | — |
| BE-PROMO-04 | `[ ]` `POST /v1/promos/validate` — validate a promo code at checkout: check code exists, is active, within time window, within geo-fence, user hasn't exceeded per-user cap, global cap not exceeded, user meets order-history requirements, user meets minimum tier requirement, peak/off-peak condition matches | B-13, PR-01 | BE-PROMO-01, BE-GAME-12 | Atomic validation — use database transaction + idempotency key to prevent race-condition over-redemption |
| BE-PROMO-05 | `[ ]` Implement redemption cap enforcement with strong consistency: use row-level locking or atomic counter increment to prevent exceeding global/per-user limits under concurrent requests | B-14, NF-10 | BE-PROMO-04 | — |
| BE-PROMO-06 | `[ ]` Implement discount application to fare: calculate discount amount from promo config, apply cap, subtract from fare estimate, create `promo_redemptions` record, include in receipt breakdown | B-15 | BE-PROMO-05, BE-PRICE-04 | — |
| BE-PROMO-07 | `[ ]` `GET /v1/admin/promos/:id/performance` — return redemption count, total discount amount, revenue impact for a promo | A-23 | SETUP-21 | — |
| BE-PROMO-08 | `[ ]` `GET /v1/promos/available` — return promos the authenticated user is eligible for (for auto-apply or display without code entry) | PR-04 | BE-PROMO-04, BE-GAME-12 | ⚠ OQ-12: Auto-apply vs. code-entry-only in Phase 1 — confirm scope |

---

## 11. Backend — SOS Telemetry & Escalation Pipeline

**PRD refs:** SOS-01–SOS-04, A-24–A-27, B-16–B-18, NF-04, NF-08

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-SOS-01 | `[ ]` `POST /v1/rides/:id/sos` — trigger SOS: validate ride is in active state, capture GPS + vehicle + driver/passenger IDs, encrypt telemetry, create incident record, emit escalation event | B-16, SOS-02 | SETUP-24, BE-RIDE-01 | 🔒 Encrypt telemetry at rest and in transit (NF-04) |
| BE-SOS-02 | `[ ]` Implement automated check-in: after SOS trigger, push a check-in prompt to the triggering user's device; start a server-side 30-second timer | B-17, SOS-03 | BE-SOS-01, SETUP-49 | ⏱ Must be server-side timer, not client-dependent (NF-08). Safety-critical P0 path |
| BE-SOS-03 | `[ ]` `POST /v1/sos/:id/acknowledge` — user acknowledges check-in prompt; update incident status; do not escalate | SOS-03 | BE-SOS-02 | — |
| BE-SOS-04 | `[ ]` Implement escalation on non-acknowledgement: if check-in unacknowledged within 30 seconds, escalate incident to Safety Operator queue; push to Admin SOS console via WS | A-25, B-17 | BE-SOS-02, BE-LOC-02 | — |
| BE-SOS-05 | `[ ]` `POST /v1/sos/:id/cancel` — user cancels self-triggered SOS before or during operator review; update status; log cancellation to immutable audit trail | SOS-04, A-27 | BE-SOS-01, SETUP-25 | Cancellation is logged, not silently discarded |
| BE-SOS-06 | `[ ]` `GET /v1/admin/sos/active` — return list of active/unacknowledged SOS incidents with trip and telemetry detail | A-24 | BE-SOS-01, SETUP-44 | Safety Operator scope only |
| BE-SOS-07 | `[ ]` `POST /v1/admin/sos/:id/assign` — Safety Operator self-assigns an incident | A-24 | BE-SOS-06 | — |
| BE-SOS-08 | `[ ]` `POST /v1/admin/sos/:id/dispatch` — operator triggers emergency-services dispatch handoff where integration is available | A-26, B-18 | BE-SOS-07 | ⚠ OQ-04: Integration provider TBD. May launch as voice-outreach-only |
| BE-SOS-09 | `[ ]` `POST /v1/admin/sos/:id/resolve` — operator resolves incident with notes; update status; log to audit trail | A-27 | BE-SOS-07, SETUP-25 | — |
| BE-SOS-10 | `[ ]` Ensure every SOS event (trigger, check-in sent, acknowledged, escalated, assigned, dispatched, resolved, cancelled) is written to the immutable `sos_event_log` | A-27, NF-06 | SETUP-25 | Append-only audit trail |

---

## 12. Backend — Anti-Offline Trip Detection Engine

**PRD refs:** B-19–B-22, §9.3, OE-01–OE-03, A-28–A-30, NF-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-AO-01 | `[ ]` Implement background job that monitors ride cancellations: on each cancellation where driver was at/near pickup, start post-cancellation GPS trajectory tracking for both driver and passenger | B-19 | BE-RIDE-03, BE-LOC-01 | — |
| BE-AO-02 | `[ ]` Implement GPS trajectory collocation analysis: compare driver and passenger post-cancellation GPS traces against the originally intended route; flag if similarity exceeds threshold | B-20 | BE-AO-01 | ⚠ OQ-13: Collocation threshold and GPS-jitter tolerance TBD. ⚠ OQ-14: Target false-positive rate TBD |
| BE-AO-03 | `[ ]` Implement automatic sanction tier logic on confirmed flag: Level 1 = warning, Level 2 = 48h suspension, Level 3 = permanent deactivation; check existing flags within 30 days for escalation | §9.3, B-21 | BE-AO-02, SETUP-26 | — |
| BE-AO-04 | `[ ]` On flagging: create `offline_trip_flags` record, update driver `compliance_status`, send compliance warning notification to driver, add to Admin queue | OE-01, A-28 | BE-AO-03, SETUP-49 | Level 2/3: integrate with driver account suspension (force Offline, block accepting rides) |
| BE-AO-05 | `[ ]` `POST /v1/drivers/flags/:id/dispute` — driver disputes a flag; update flag record; trigger manual review workflow | OE-02, B-22 | BE-AO-04 | — |
| BE-AO-06 | `[ ]` `GET /v1/admin/offline-flags` — list flagged trips with sanction tier, dispute status, driver details | A-28 | BE-AO-04, SETUP-44 | — |
| BE-AO-07 | `[ ]` `POST /v1/admin/offline-flags/:id/review` — Admin reviews a dispute: overturn or uphold; if overturned, reverse sanction and restore driver status | A-29, B-22 | BE-AO-06 | Audit log the decision |
| BE-AO-08 | `[ ]` `POST /v1/admin/offline-flags/:id/escalate` — Admin manually escalates sanction tier | A-30 | BE-AO-06 | ⚠ OQ-14: Who has final authority to overturn Level 3? |
| BE-AO-09 | `[ ]` `GET /v1/drivers/compliance` — driver views their compliance status (clear/warned/suspended) and active sanctions | OE-03 | BE-AO-04 | — |

---

## 13. Backend — EV Charging Reservation System

**PRD refs:** EV-01–EV-04, B-23–B-26, A-31–A-32

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-EV-01 | `[ ]` `POST /v1/admin/ev-stations` — create a charging station record (location, stall count, status) | A-31 | SETUP-27, SETUP-44 | ⚠ OQ-15: Data source TBD (Admin-managed, third-party API, or both) |
| BE-EV-02 | `[ ]` `GET /v1/admin/ev-stations` — list all stations with filters (city, status) | A-31 | BE-EV-01 | — |
| BE-EV-03 | `[ ]` `PUT /v1/admin/ev-stations/:id` — update station details (stall count, status) | A-31 | BE-EV-01 | — |
| BE-EV-04 | `[ ]` `GET /v1/ev-stations` — public endpoint listing active stations with availability for a city | A-32 | BE-EV-01 | Used by Driver App for station selection |
| BE-EV-05 | `[ ]` `GET /v1/admin/ev-stations/utilisation` — real-time station utilisation and queue status across the pilot city | A-32 | BE-EV-01, SETUP-28 | — |
| BE-EV-06 | `[ ]` Implement real-time stall availability tracking: maintain `ev_charging_stalls` status based on reservations and occupancy events | B-23 | SETUP-28 | — |
| BE-EV-07 | `[ ]` `POST /v1/ev-stations/:stationId/reserve` — driver requests a reservation: if stall available, immediately lock (status=reserved, create reservation); if occupied but departure imminent, place in queue hold | B-24, EV-01, EV-02 | BE-EV-06 | Atomic stall locking to prevent double-reservation |
| BE-EV-08 | `[ ]` Implement queue-hold transfer: when an occupying vehicle departs a stall, automatically transfer the reservation to the next queued driver and notify them | B-24, EV-02 | BE-EV-07 | — |
| BE-EV-09 | `[ ]` Implement retry delay calculation: if queue wait > 15 minutes, compute a specific retry delay and return it instead of placing in queue | B-25, EV-03 | BE-EV-07 | e.g. "Station at capacity — check back in 8 mins" |
| BE-EV-10 | `[ ]` Implement tier-based fee waiver: at reservation time, check user's tier via gamification engine; apply fee waiver for eligible top-tier users | B-26, EV-04, G-08 | BE-EV-07, BE-GAME-12 | ⚠ OQ-16: Waiver applies to driver, passenger, or both? |
| BE-EV-11 | `[ ]` `GET /v1/drivers/ev-reservations` — driver views their active/past reservations | — | BE-EV-07 | — |

---

## 14. Backend — Notification Service

**PRD refs:** B-06

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE-NOTIF-01 | `[ ]` Implement notification service layer: accept notification type, recipient, payload → route to push + in-app based on type | B-06 | SETUP-49 | Centralised notification dispatch — all features call this service |
| BE-NOTIF-02 | `[ ]` Define notification types enum: ride_matched, ride_cancelled, driver_arriving, ride_started, ride_completed, sos_check_in, sos_escalated, compliance_warning, promo_expiring, tier_upgrade, ev_reservation_ready, dispute_update | B-06 | BE-NOTIF-01 | — |
| BE-NOTIF-03 | `[ ]` Implement in-app notification storage: create `notifications` table (id, user_id, type, title, body, data JSONB, is_read, created_at) with list/mark-read endpoints | B-06 | BE-NOTIF-01 | — |

---

## 15. Passenger App — Authentication & Onboarding

**PRD refs:** P-01, P-02, P-03

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-AUTH-01 | `[ ]` Build phone number input screen: country code selector (dropdown with flag), phone number field with formatting, "Send OTP" button | P-01 | — | — |
| PA-AUTH-02 | `[ ]` Implement OTP request flow: call `POST /v1/auth/otp/request`, handle rate-limit errors, show resend countdown timer, show max-attempts lockout message | P-01 | PA-AUTH-01, BE-AUTH-01 | — |
| PA-AUTH-03 | `[ ]` Build OTP entry screen: N-digit code input with auto-focus, auto-submit on complete, resend button with cooldown, error states | P-01 | PA-AUTH-02 | — |
| PA-AUTH-04 | `[ ]` Implement OTP verification flow: call `POST /v1/auth/otp/verify`, store access + refresh tokens in secure storage | P-01 | PA-AUTH-03, BE-AUTH-02 | — |
| PA-AUTH-05 | `[ ]` Build profile completion screen (shown once for new users): name (required), email (optional), profile photo upload (optional) | P-02 | PA-AUTH-04 | Confirm minimum required fields with PM |
| PA-AUTH-06 | `[ ]` Implement session persistence: store refresh token in secure storage, refresh access token on app launch / token expiry, redirect to login if refresh fails | P-03 | PA-AUTH-04, BE-AUTH-04 | — |
| PA-AUTH-07 | `[ ]` Implement logout: call `POST /v1/auth/logout`, clear stored tokens, navigate to login screen | P-03 | PA-AUTH-06, BE-AUTH-05 | — |
| PA-AUTH-08 | `[ ]` Register device token for push notifications on login/launch | B-06 | PA-AUTH-06, SETUP-50 | — |

---

## 16. Passenger App — Booking Flow

**PRD refs:** P-04–P-10

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-BOOK-01 | `[ ]` Build map view screen: display interactive map centered on user's current location with location permission handling | P-04 | PA-AUTH-06, SETUP-52 | Handle location permission denied gracefully |
| PA-BOOK-02 | `[ ]` Implement pickup selection: tap-to-place pin on map with address reverse-geocoding, or type-to-search with address autocomplete | P-04 | PA-BOOK-01 | — |
| PA-BOOK-03 | `[ ]` Implement destination selection: same UX as pickup (pin drop or search), show route preview line on map after both points set | P-04 | PA-BOOK-02 | — |
| PA-BOOK-04 | `[ ]` Build vehicle class selector: horizontal scrollable cards showing each available class (icon, name, capacity, fare estimate); call `GET /v1/cities/:cityId/vehicle-classes` | P-05 | PA-BOOK-03, BE-CITY-10 | Only show classes active in the passenger's city |
| PA-BOOK-05 | `[ ]` Implement fare estimate display: call `POST /v1/rides/estimate`, show estimate per vehicle class, highlight selected class | P-06 | PA-BOOK-04, BE-PRICE-05 | — |
| PA-BOOK-06 | `[ ]` Implement automatic fare recalculation: on pickup or destination change, re-call estimate endpoint and update displayed fares | P-07 | PA-BOOK-05 | Debounce recalculation on pin drag |
| PA-BOOK-07 | `[ ]` Build payment method selector: show saved cards + cash option; allow adding a new card; pre-select last used method | P-15 | PA-BOOK-05, BE-PAY-01 | — |
| PA-BOOK-08 | `[ ]` Build booking confirmation screen: summary (pickup, destination, class, fare, payment method), "Confirm Booking" button | P-08 | PA-BOOK-07 | — |
| PA-BOOK-09 | `[ ]` Implement booking submission: call `POST /v1/rides`, transition to searching state UI | P-08 | PA-BOOK-08, BE-RIDE-02 | — |
| PA-BOOK-10 | `[ ]` Build searching/matching state UI: animated progress indicator, estimated wait, cancel button | P-08 | PA-BOOK-09 | — |
| PA-BOOK-11 | `[ ]` Implement no-driver-found handling: listen for matching timeout event, show "No drivers available" message with retry and cancel options | P-09 | PA-BOOK-10, BE-MATCH-07 | — |
| PA-BOOK-12 | `[ ]` Implement ride cancellation: call `POST /v1/rides/:id/cancel`, confirm with dialog if post-match, show cancellation confirmation | P-10 | PA-BOOK-10, BE-RIDE-03 | ⚠ OQ-05: Cancellation fee display if applicable |

---

## 17. Passenger App — In-Ride Experience

**PRD refs:** P-11–P-14

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-RIDE-01 | `[ ]` Build matched/en-route screen: map with driver marker animating in real time, ETA to pickup, route line | P-11 | PA-BOOK-10, BE-LOC-03 | — |
| PA-RIDE-02 | `[ ]` Implement WebSocket connection for ride tracking: connect to ride room, listen for driver location updates, update map marker and ETA | P-11, B-04 | PA-RIDE-01, BE-LOC-02 | Handle reconnection on network change / app background-foreground |
| PA-RIDE-03 | `[ ]` Build driver info panel: driver name, photo, star rating, plate number, vehicle make/model/colour | P-12 | PA-RIDE-01 | Tappable to expand with more details |
| PA-RIDE-04 | `[ ]` Build driver-arrived screen: show ride-start PIN prominently; instruct passenger to share with driver | P-14, §9.1 | PA-RIDE-01, BE-RIDE-06 | PIN display triggered when ride state transitions to Driver_Arrived |
| PA-RIDE-05 | `[ ]` Build in-progress screen: map with live route tracking, elapsed time, projected fare | — | PA-RIDE-04, BE-RIDE-05 | — |
| PA-RIDE-06 | `[ ]` Implement trip sharing: generate shareable link (call backend or construct URL), share via system share sheet | P-13 | PA-RIDE-01, BE-RIDE-11 | Read-only shared view; no login required for viewer |
| PA-RIDE-07 | `[ ]` Implement WebSocket fallback: detect connection drop, fall back to polling `GET /v1/rides/:id/location`, show "reconnecting" indicator | B-04 | PA-RIDE-02, BE-LOC-06 | — |

---

## 18. Passenger App — Payment, Ratings & History

**PRD refs:** P-15–P-19

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-PAY-01 | `[ ]` Build trip completion screen: fare summary, payment status (cash: "Pay driver", card: "Card charged"), navigation to rating | P-15 | PA-RIDE-05, BE-RIDE-07 | — |
| PA-PAY-02 | `[ ]` Build add-card screen: card number, expiry, CVV input → tokenise via gateway → call `POST /v1/users/payment-methods` | P-15 | BE-PAY-01 | 🔒 Use gateway's client-side SDK for card data capture |
| PA-PAY-03 | `[ ]` Build tip screen: optional tip amount selection (preset amounts or custom), submit via `POST /v1/rides/:id/tip` | P-16 | PA-PAY-01, BE-PAY-06 | Show after trip completion, before rating |
| PA-PAY-04 | `[ ]` Build rating screen: 1–5 star selector with optional text comment, submit via API | P-17 | PA-PAY-03 | — |
| PA-PAY-05 | `[ ]` Build dispute/issue report screen: category selector, description field, submit via API → routes to Admin disputes queue | P-18 | PA-PAY-01, SETUP-18 | Accessible from trip completion screen and trip history |
| PA-PAY-06 | `[ ]` Build trip history screen: paginated list of past trips with date, route summary, fare, status | P-19 | BE-RIDE-09 | — |
| PA-PAY-07 | `[ ]` Build receipt view: detailed fare breakdown, date/time, driver, vehicle, payment method; support download/share as PDF or image | P-19 | PA-PAY-06, BE-PAY-07 | — |

---

## 19. Passenger App — Gamification & Tier Status

**PRD refs:** G-01–G-08

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-GAME-01 | `[ ]` Build tier status section on passenger profile: tier badge, tier name, exclusive UI colour theme applied per tier | G-01, G-04 | BE-GAME-07 | ⚠ OQ-09: Tier naming — build asset/copy for both options, deploy confirmed one |
| PA-GAME-02 | `[ ]` Build tier progress bar: visual indicator showing current points vs. next tier threshold | G-04 | PA-GAME-01 | — |
| PA-GAME-03 | `[ ]` Implement level-up celebration animation: triggered on tier upgrade push notification | G-04 | PA-GAME-02, BE-GAME-06 | Full-screen animation (confetti, badge reveal, etc.) |
| PA-GAME-04 | `[ ]` Build carbon score display: per-trip carbon score on trip completion screen, cumulative score on profile | G-02 | BE-GAME-03 | — |
| PA-GAME-05 | `[ ]` Build multiplier indicators in booking flow: show applicable multiplier badges (EV, shared, off-peak) next to fare estimate | G-03 | PA-BOOK-05, BE-GAME-04 | — |
| PA-GAME-06 | `[ ]` Build tier benefits section on profile: list unlocked and locked benefits per tier (priority matching, discounted fees, exclusive promos, EV fee waiver) | G-05–G-08 | PA-GAME-01, BE-GAME-12 | — |

---

## 20. Passenger App — Promo & Discounts

**PRD refs:** PR-01–PR-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-PROMO-01 | `[ ]` Build promo code entry field on checkout/booking confirmation screen with "Apply" button | PR-01 | PA-BOOK-08 | — |
| PA-PROMO-02 | `[ ]` Implement real-time promo validation: on "Apply", call `POST /v1/promos/validate`, show success/error inline, update fare display | PR-01 | PA-PROMO-01, BE-PROMO-04 | Show specific error messages: expired, max redeemed, ineligible tier, wrong area, etc. |
| PA-PROMO-03 | `[ ]` Build visual fare breakdown showing original fare, discount line item, and final fare when a promo is applied | PR-02 | PA-PROMO-02 | — |
| PA-PROMO-04 | `[ ]` Build countdown timer on active/expiring promos displayed in the app | PR-03 | PA-PROMO-01 | — |
| PA-PROMO-05 | `[ ]` Build eligible promos display: show promos the user qualifies for without needing a code, with "Apply" button | PR-04 | BE-PROMO-08 | ⚠ OQ-12: Auto-apply vs. code-entry-only — scope confirmation needed |
| PA-PROMO-06 | `[ ]` Implement tier-gated promo restriction: if user is below the qualifying tier, show "Upgrade to [Tier] to unlock this promo" message | PR-05 | PA-PROMO-02, BE-GAME-12 | — |

---

## 21. Passenger App — Emergency SOS

**PRD refs:** SOS-01–SOS-04

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA-SOS-01 | `[ ]` Build SOS/panic button on in-ride screen: persistent, prominent, high-contrast; reachable within 1–2 taps; confirm-to-trigger to prevent accidental activation | SOS-01 | PA-RIDE-05 | Always visible during active ride; does not interfere with normal ride UI |
| PA-SOS-02 | `[ ]` Implement SOS trigger flow: on confirmed trigger, immediately call `POST /v1/rides/:id/sos` with current GPS, enter SOS-active state in UI | SOS-02 | PA-SOS-01, BE-SOS-01 | — |
| PA-SOS-03 | `[ ]` Build SOS-active screen: show "Help is on the way" message, live status updates, check-in prompt | SOS-03 | PA-SOS-02, BE-SOS-02 | — |
| PA-SOS-04 | `[ ]` Implement check-in acknowledgement: show check-in prompt, on tap call `POST /v1/sos/:id/acknowledge` | SOS-03 | PA-SOS-03, BE-SOS-03 | — |
| PA-SOS-05 | `[ ]` Build SOS cancellation flow: "Cancel SOS" button with confirmation dialog, call `POST /v1/sos/:id/cancel`, return to normal ride UI | SOS-04 | PA-SOS-03, BE-SOS-05 | — |

---

## 22. Driver App — Onboarding & KYC

**PRD refs:** D-01–D-04

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| DA-AUTH-01 | `[ ]` Build driver sign-up flow: phone number + OTP (same mechanism as Passenger App) | D-01 | BE-AUTH-01 | Reuse auth UI components where possible |
| DA-AUTH-02 | `[ ]` Build driver profile completion screen: name, email, phone, profile photo | D-01 | DA-AUTH-01 | — |
| DA-AUTH-03 | `[ ]` Implement session persistence and logout (same token mechanism as Passenger App) | D-01 | DA-AUTH-02, BE-AUTH-04 | — |
| DA-KYC-01 | `[ ]` Build vehicle profile form: make, model, colour, plate number | D-04 | DA-AUTH-02 | Vehicle-class assignment is Admin-approved; driver submits vehicle details, Admin assigns class |
| DA-KYC-02 | `[ ]` Build KYC document upload screen: driving licence, vehicle registration, insurance, government ID; show upload progress, file preview | D-02 | DA-KYC-01 | ⚠ Confirm exact document set and file-type/size limits with Client |
| DA-KYC-03 | `[ ]` Implement document upload: compress image if needed, upload to backend, store reference | D-02 | DA-KYC-02 | — |
| DA-KYC-04 | `[ ]` Build KYC status screen: show current status (Pending Review / Approved / Rejected) with rejection reason if applicable | D-03 | DA-KYC-03 | — |
| DA-KYC-05 | `[ ]` Implement KYC status polling/push: periodically check status or receive push notification on approval/rejection | D-03 | DA-KYC-04, SETUP-49 | — |
| DA-KYC-06 | `[ ]` Implement gating: block access to Online toggle and ride features until KYC is Approved | D-03 | DA-KYC-04 | Show "Account pending review" message |
| DA-KYC-07 | `[ ]` Register device token for push notifications on login/launch | B-06 | DA-AUTH-03, SETUP-50 | — |

---

## 23. Driver App — Availability & Ride Handling

**PRD refs:** D-05–D-09, §9.1

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| DA-RIDE-01 | `[ ]` Build Online/Offline toggle on main screen: prominent switch, only enabled for Approved drivers with assigned vehicle class | D-05 | DA-KYC-06 | Suspended drivers see toggle disabled with explanation |
| DA-RIDE-02 | `[ ]` Implement online status: on toggle Online, start sending location updates to backend at configured interval; on Offline, stop sending | D-05 | DA-RIDE-01, BE-LOC-01 | Handle battery optimisation, background location permissions |
| DA-RIDE-03 | `[ ]` Build incoming ride request notification: overlay/modal showing pickup location on mini-map, estimated distance/fare, response countdown timer | D-06 | DA-RIDE-02, BE-MATCH-02 | Must be attention-grabbing; audio alert |
| DA-RIDE-04 | `[ ]` Implement accept ride: call `POST /v1/rides/:id/accept`, transition to navigation mode | D-07 | DA-RIDE-03, BE-MATCH-03 | — |
| DA-RIDE-05 | `[ ]` Implement reject ride: call `POST /v1/rides/:id/reject`, dismiss notification, return to waiting state | D-07 | DA-RIDE-03, BE-MATCH-04 | — |
| DA-RIDE-06 | `[ ]` Implement response timeout: if countdown reaches zero, auto-dismiss (treated as rejection by backend) | D-07 | DA-RIDE-03, BE-MATCH-05 | — |
| DA-RIDE-07 | `[ ]` Build navigation-to-pickup screen: show route to pickup with turn-by-turn guidance or deep-link to maps app; passenger info panel; "I've Arrived" button | D-08 | DA-RIDE-04, SETUP-52 | ⚠ Deep-link to third-party maps vs. in-app navigation — confirm approach |
| DA-RIDE-08 | `[ ]` Implement "Arrived at Pickup": call `POST /v1/rides/:id/driver-arrived`, transition to PIN entry screen | D-09 | DA-RIDE-07, BE-RIDE-06 | — |
| DA-RIDE-09 | `[ ]` Build PIN entry screen: numeric keypad, 4-digit input field, "Start Trip" button | §9.1, D-09 | DA-RIDE-08 | — |
| DA-RIDE-10 | `[ ]` Implement PIN verification: call `POST /v1/rides/:id/verify-pin`, on success transition to in-trip screen, on failure show error with retry | §9.1, D-09 | DA-RIDE-09, BE-RIDE-05 | — |
| DA-RIDE-11 | `[ ]` Build in-trip screen: navigation to destination, elapsed time, distance, projected fare, "Complete Trip" button | D-09 | DA-RIDE-10 | — |
| DA-RIDE-12 | `[ ]` Implement trip completion: call `POST /v1/rides/:id/complete`, show trip summary with earnings | D-09 | DA-RIDE-11, BE-RIDE-07 | — |

---

## 24. Driver App — Earnings & History

**PRD refs:** D-10, D-11

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| DA-EARN-01 | `[ ]` Build earnings dashboard: today's earnings total, trip count, online hours | D-10 | DA-RIDE-12 | — |
| DA-EARN-02 | `[ ]` Build period earnings summary: daily/weekly breakdown with earnings chart | D-10 | DA-EARN-01 | ⚠ OQ-06: Payout cadence and settlement mechanism TBD |
| DA-EARN-03 | `[ ]` Build ride history screen: paginated list of past trips with date, route, fare, payment status (cash collected / card settled) | D-11 | DA-RIDE-12 | — |
| DA-EARN-04 | `[ ]` Build trip detail screen: full fare breakdown, route on map, passenger rating given/received | D-11 | DA-EARN-03 | — |

---

## 25. Driver App — Anti-Offline Compliance

**PRD refs:** OE-01–OE-03

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| DA-AO-01 | `[ ]` Build compliance warning notification: prominent in-app alert when a Level 1 flag is raised; show reason, next steps, and dispute option | OE-01 | BE-AO-04 | — |
| DA-AO-02 | `[ ]` Build flag dispute screen: accessible from the compliance warning; form with description/reason for dispute; submit via `POST /v1/drivers/flags/:id/dispute` | OE-02 | DA-AO-01, BE-AO-05 | Must be accessible from the app, not just via support contact |
| DA-AO-03 | `[ ]` Build compliance status view: show current status (clear/warned/suspended) and any active sanction details (tier, duration, expiry) | OE-03 | DA-AO-01, BE-AO-09 | — |
| DA-AO-04 | `[ ]` Implement suspension enforcement in UI: if compliance_status=suspended, disable Online toggle, show suspension banner with reason and duration | OE-03 | DA-AO-03, DA-RIDE-01 | — |

---

## 26. Driver App — EV Charging Reservation

**PRD refs:** EV-01–EV-04

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| DA-EV-01 | `[ ]` Build EV charging station map/list: show nearby stations with availability indicators; only accessible for EV-class drivers | EV-01 | BE-EV-04 | — |
| DA-EV-02 | `[ ]` Build station detail screen: stall count, current availability, queue length, reserve button | EV-01 | DA-EV-01 | — |
| DA-EV-03 | `[ ]` Implement reservation request: call `POST /v1/ev-stations/:stationId/reserve`, handle three outcomes: (a) immediate lock, (b) queue hold, (c) retry delay | EV-01, EV-02, EV-03 | DA-EV-02, BE-EV-07 | — |
| DA-EV-04 | `[ ]` Build reservation confirmation screen: show reserved stall number, estimated available time, navigation to station | EV-01 | DA-EV-03 | — |
| DA-EV-05 | `[ ]` Build queue hold screen: show queue position, estimated wait time, auto-update on transfer notification | EV-02 | DA-EV-03, BE-EV-08 | — |
| DA-EV-06 | `[ ]` Build retry delay screen: show "Station at capacity — check back in X mins" message with countdown and refresh button | EV-03 | DA-EV-03, BE-EV-09 | — |
| DA-EV-07 | `[ ]` Build fee waiver confirmation: show top-tier fee waiver applied at reservation for eligible drivers | EV-04 | DA-EV-04, BE-EV-10 | ⚠ OQ-16: Waiver applies to driver, passenger, or both — confirm |

---

## 27. Admin Dashboard — Authentication & Layout

**PRD refs:** §8.4

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-AUTH-01 | `[ ]` Build Admin login screen: email + password (or OTP — confirm with PM); call auth endpoint, store session | §8.4 | SETUP-43 | — |
| AD-AUTH-02 | `[ ]` Build main dashboard layout: sidebar navigation, top bar with user info and logout, content area | — | AD-AUTH-01 | — |
| AD-AUTH-03 | `[ ]` Implement route guards: restrict navigation items and routes based on authenticated admin's role/permissions | §8.4 | AD-AUTH-02, SETUP-44 | Safety Operator sees only SOS console |
| AD-AUTH-04 | `[ ]` Build dashboard home/overview: summary cards (active rides, online drivers, pending KYC, open disputes, active SOS) | — | AD-AUTH-02 | — |

---

## 28. Admin Dashboard — City Management

**PRD refs:** A-01, A-02

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-CITY-01 | `[ ]` Build city list page: table of cities with name, status (active/inactive), actions (edit, toggle status) | A-01 | AD-AUTH-02, BE-CITY-02 | — |
| AD-CITY-02 | `[ ]` Build city create/edit form: name, timezone, currency, boundary definition | A-01 | AD-CITY-01, BE-CITY-01 | — |
| AD-CITY-03 | `[ ]` Build map-based boundary editor: draw polygon or set center+radius on an interactive map to define serviceable area | A-02 | AD-CITY-02, SETUP-52 | ⚠ Polygon vs. radius — confirm with Engineering; affects matching |
| AD-CITY-04 | `[ ]` Implement city activation/deactivation toggle with confirmation dialog | A-01 | AD-CITY-01, BE-CITY-04 | — |

---

## 29. Admin Dashboard — Vehicle-Class Management

**PRD refs:** A-03, A-04, A-05

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-VC-01 | `[ ]` Build vehicle class list page: table of all classes with name, capacity, icon, actions | A-03 | AD-AUTH-02, BE-CITY-07 | — |
| AD-VC-02 | `[ ]` Build vehicle class create/edit form: name, display name, capacity, icon upload | A-03 | AD-VC-01, BE-CITY-06 | — |
| AD-VC-03 | `[ ]` Build per-city vehicle class toggle: on city detail page, checkboxes to enable/disable each vehicle class | A-04 | AD-CITY-02, AD-VC-01, BE-CITY-09 | — |

---

## 30. Admin Dashboard — Pricing Configuration

**PRD refs:** A-06, A-07

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-PRICE-01 | `[ ]` Build pricing configuration page: select city + vehicle class → show current pricing; form to set base fare, per-km rate, per-minute rate, minimum fare | A-06 | AD-CITY-01, AD-VC-01, BE-PRICE-03 | ⚠ Confirm whether waiting-time charges apply |
| AD-PRICE-02 | `[ ]` Implement pricing submission: set effective-from timestamp, call `POST /v1/admin/pricing`, show success confirmation | A-06 | AD-PRICE-01, BE-PRICE-02 | — |
| AD-PRICE-03 | `[ ]` Build pricing history view: list of past pricing versions for a city + vehicle class combination with effective dates | A-07 | AD-PRICE-01, BE-PRICE-03 | — |

---

## 31. Admin Dashboard — Live Monitoring

**PRD refs:** A-08, A-09

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-LIVE-01 | `[ ]` Build live ride map: full-screen map with pins for all active rides, colour-coded by status | A-08 | AD-AUTH-02, BE-LOC-04 | — |
| AD-LIVE-02 | `[ ]` Implement real-time updates on live map: connect to Admin WS room, update ride pins and driver markers on each location event | A-08 | AD-LIVE-01, BE-LOC-04 | — |
| AD-LIVE-03 | `[ ]` Build ride detail popover: click a ride pin → show status, driver, passenger, ETA, timeline of state transitions | A-08 | AD-LIVE-02 | — |
| AD-LIVE-04 | `[ ]` Build online driver layer: toggle to show all online drivers (not just those on active rides) on the map | A-09 | AD-LIVE-02, BE-LOC-04 | ⚠ OQ: Confirm driver-location-while-idle policy with Client (privacy/consent) |
| AD-LIVE-05 | `[ ]` Build ride/driver count summary bar: total active rides, online drivers, rides by status | A-08 | AD-LIVE-01 | — |

---

## 32. Admin Dashboard — Driver Management

**PRD refs:** A-10, A-11

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-DRV-01 | `[ ]` Build driver list page: table with name, phone, vehicle, KYC status, compliance status, online status, filters, search, pagination | A-10 | AD-AUTH-02, SETUP-12 | — |
| AD-DRV-02 | `[ ]` Build driver detail page: profile info, vehicle info, KYC documents, compliance history, ride history, earnings summary | A-10 | AD-DRV-01 | — |
| AD-DRV-03 | `[ ]` Build KYC review panel: document viewer (zoom, rotate for images), approve/reject buttons, mandatory rejection reason text field | A-10 | AD-DRV-02 | On approval, allow Admin to assign vehicle class |
| AD-DRV-04 | `[ ]` Build vehicle class assignment on KYC approval: dropdown to select and assign the driver's vehicle class | A-05 | AD-DRV-03, BE-CITY-07 | — |
| AD-DRV-05 | `[ ]` Build driver suspension controls: suspend with reason, reactivation; show suspension history | A-11 | AD-DRV-02 | Suspension force-sets driver Offline and blocks accepting rides |

---

## 33. Admin Dashboard — Passenger Management

**PRD refs:** A-12

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-PAX-01 | `[ ]` Build passenger list page: table with name, phone, status, tier, trip count, filters, search, pagination | A-12 | AD-AUTH-02 | — |
| AD-PAX-02 | `[ ]` Build passenger detail page: profile info, tier/gamification data, trip history, dispute history | A-12 | AD-PAX-01 | — |
| AD-PAX-03 | `[ ]` Build passenger suspension controls: suspend/reactivate with reason | A-12 | AD-PAX-02 | — |

---

## 34. Admin Dashboard — Ride Disputes

**PRD refs:** A-13, A-14

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-DISP-01 | `[ ]` Build disputes queue page: table of flagged/disputed rides with trip details, reported by, category, status, date, sortable columns | A-13 | AD-AUTH-02, SETUP-18 | — |
| AD-DISP-02 | `[ ]` Build dispute detail page: full trip details, route on map, passenger/driver info, dispute description, state timeline | A-13 | AD-DISP-01 | — |
| AD-DISP-03 | `[ ]` Build dispute resolution panel: status change dropdown, resolution notes text area, save button; audit log the resolution | A-14 | AD-DISP-02 | ⚠ OQ-08: Confirm whether refund/fare adjustment actions are in scope |

---

## 35. Admin Dashboard — Manual Assignment Fallback

**PRD refs:** A-15

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-MATCH-01 | `[ ]` Build manual assignment interface: on a ride in "Searching" or "No Driver Found" state, show list of nearby online drivers, allow Admin to select and assign | A-15 | AD-LIVE-01, BE-MATCH-08 | — |
| AD-MATCH-02 | `[ ]` Implement manual assignment submission: call `POST /v1/admin/rides/:id/assign`, confirm success, ride enters Matched state | A-15 | AD-MATCH-01 | Must use the same ride state machine — not a parallel path |

---

## 36. Admin Dashboard — Operational Reporting

**PRD refs:** A-16

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-RPT-01 | `[ ]` Build report page with date-range picker and city/vehicle-class filters | A-16 | AD-AUTH-02 | — |
| AD-RPT-02 | `[ ]` Implement ride volume report: total rides, rides by status, rides by city, rides by vehicle class | A-16 | AD-RPT-01 | — |
| AD-RPT-03 | `[ ]` Implement completion/cancellation rate report: completed vs cancelled, cancellation reasons breakdown | A-16 | AD-RPT-01 | — |
| AD-RPT-04 | `[ ]` Implement revenue report: total revenue, revenue by city, revenue by payment method, average fare | A-16 | AD-RPT-01 | — |
| AD-RPT-05 | `[ ]` Implement driver utilisation report: online hours, trips per driver, earnings per driver | A-16 | AD-RPT-01 | — |
| AD-RPT-06 | `[ ]` Implement CSV export for all report types | A-16 | AD-RPT-02 through AD-RPT-05 | ⚠ Confirm export format with Client (CSV assumed) |

---

## 37. Admin Dashboard — Gamification & Tier Management

**PRD refs:** A-17, A-18, A-19

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-GAME-01 | `[ ]` Build tier configuration page: table of all 5 tiers with name, point threshold, benefits; editable form | A-17 | AD-AUTH-02, BE-GAME-10 | — |
| AD-GAME-02 | `[ ]` Build point multiplier configuration page: table of conditions (EV/shared/off-peak) with multiplier values; editable form | A-18 | AD-AUTH-02, BE-GAME-11 | ⚠ Confirm stacking behaviour |
| AD-GAME-03 | `[ ]` Build gamification reporting page: tier distribution chart, average carbon score, top users table, per-user detail drill-down | A-19 | AD-AUTH-02, BE-GAME-08, BE-GAME-09 | — |

---

## 38. Admin Dashboard — Promo Engine Management

**PRD refs:** A-20–A-23

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-PROMO-01 | `[ ]` Build promo list page: table of promo codes with code, type, value, usage (redeemed/limit), status, expiry, actions | A-20 | AD-AUTH-02, BE-PROMO-02 | — |
| AD-PROMO-02 | `[ ]` Build promo create form: code, discount type (percentage/flat), value, max cap (for percentage) | A-20 | AD-PROMO-01, BE-PROMO-01 | — |
| AD-PROMO-03 | `[ ]` Build usage limits form section: global total redemptions, per-user limit, start date/time, expiry date/time | A-21 | AD-PROMO-02 | — |
| AD-PROMO-04 | `[ ]` Build eligibility rules form section: geo-fence (draw on map or enter coordinates), rider order history filter (first-time, min orders, max orders), peak/off-peak toggle, minimum tier dropdown | A-22 | AD-PROMO-03 | Minimum-tier eligibility depends on gamification tier data |
| AD-PROMO-05 | `[ ]` Build promo performance view: redemption count over time, total discount given, revenue impact | A-23 | AD-PROMO-01, BE-PROMO-07 | — |

---

## 39. Admin Dashboard — Safety & SOS Operations Console

**PRD refs:** A-24–A-27

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-SOS-01 | `[ ]` Build SOS console page: restricted to Safety Operator role; live queue of active/unacknowledged SOS incidents | A-24 | AD-AUTH-03, BE-SOS-06 | — |
| AD-SOS-02 | `[ ]` Implement real-time SOS feed: connect to Admin WS room, new incidents appear immediately, sorted by severity/time | A-24 | AD-SOS-01, BE-LOC-02 | — |
| AD-SOS-03 | `[ ]` Build SOS incident detail panel: trip details, live map with driver/passenger location, encrypted telemetry data, timeline of events | A-24 | AD-SOS-02 | — |
| AD-SOS-04 | `[ ]` Implement 30-second escalation indicator: visual countdown when check-in is pending; highlight escalated incidents | A-25 | AD-SOS-03, BE-SOS-04 | — |
| AD-SOS-05 | `[ ]` Build operator action buttons: "Assign to me", "Initiate voice call", "Dispatch emergency services" (where integrated), "Resolve" with notes | A-26 | AD-SOS-03, BE-SOS-07, BE-SOS-08, BE-SOS-09 | ⚠ OQ-04: Emergency-services dispatch integration TBD |
| AD-SOS-06 | `[ ]` Build SOS audit trail view: immutable log of all events for an incident, viewable on incident detail | A-27 | AD-SOS-03, BE-SOS-10 | — |

---

## 40. Admin Dashboard — Anti-Offline Compliance Management

**PRD refs:** A-28–A-30, §9.3

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-AO-01 | `[ ]` Build offline-flag queue page: table of flagged trips with driver, trip details, sanction tier, dispute status | A-28 | AD-AUTH-02, BE-AO-06 | — |
| AD-AO-02 | `[ ]` Build flag detail page: original route vs. post-cancellation trajectories on map, route similarity score, GPS trace visualization, driver dispute notes | A-29 | AD-AO-01 | — |
| AD-AO-03 | `[ ]` Build audio-log review integration: playback of trip audio (if captured) alongside route review | A-29 | AD-AO-02 | — |
| AD-AO-04 | `[ ]` Build review action panel: "Uphold flag" or "Overturn flag" buttons with reason; uphold applies/escalates sanction, overturn reverses it | A-29, A-30 | AD-AO-02, BE-AO-07 | — |
| AD-AO-05 | `[ ]` Build sanction management controls: apply, escalate, or reverse sanctions from the flag detail page; L3 ties into driver account suspension | A-30 | AD-AO-04, AD-DRV-05, BE-AO-08 | ⚠ OQ-14: Who has final authority to overturn Level 3? |

---

## 41. Admin Dashboard — EV Charging Station Management

**PRD refs:** A-31, A-32

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD-EV-01 | `[ ]` Build EV station list page: table of stations with name, city, stall count, current utilisation, status | A-31 | AD-AUTH-02, BE-EV-02 | — |
| AD-EV-02 | `[ ]` Build station create/edit form: name, city, location (map pin), stall count, status | A-31 | AD-EV-01, BE-EV-01 | — |
| AD-EV-03 | `[ ]` Build real-time utilisation dashboard: map view of stations with occupancy indicators, queue lengths, stall status per station | A-32 | AD-EV-01, BE-EV-05 | — |

---

---

# PHASE 2 — Month-3 Rollout (Weeks 9–12)

> Working draft — must be reviewed and re-frozen before Week 9.

---

## 42. Phase 2 — Backend: Scheduled Rides

**PRD refs:** B-27, P-20–P-22, D-12, A-33, NF-11

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-SCH-01 | `[ ]` Design and create `scheduled_rides` table: id, ride_id (FK), scheduled_pickup_at, reminder_sent_at, dispatched_at, status (scheduled/reminded/dispatching/dispatched/cancelled), created_at | B-27 | SETUP-14 | — |
| BE2-SCH-02 | `[ ]` Implement scheduled ride creation: `POST /v1/rides` with `scheduled_pickup_at` parameter → create ride in Scheduled state instead of Requested | P-20 | BE2-SCH-01, BE-RIDE-02 | — |
| BE2-SCH-03 | `[ ]` Implement durable scheduler: at (scheduled_time − lead_time), trigger matching by transitioning ride from Scheduled → Requested → Searching | B-27, NF-11 | BE2-SCH-02, BE-MATCH-01 | ⏱ Must survive backend restarts and deploys (NF-11). Use durable scheduler (e.g. database-backed job queue), not in-memory timer |
| BE2-SCH-04 | `[ ]` Implement pre-ride reminder: send push notification to passenger at Admin-configured reminder time before scheduled pickup | P-21 | BE2-SCH-03, SETUP-49 | — |
| BE2-SCH-05 | `[ ]` `GET /v1/rides/scheduled` — list upcoming scheduled rides for authenticated passenger | P-22 | BE2-SCH-02 | — |
| BE2-SCH-06 | `[ ]` `PUT /v1/rides/:id/scheduled` — edit scheduled ride (change time, pickup, destination) before dispatch | P-22 | BE2-SCH-05 | Only allowed while status=Scheduled |
| BE2-SCH-07 | `[ ]` `DELETE /v1/rides/:id/scheduled` — cancel a scheduled ride before dispatch | P-22 | BE2-SCH-05 | — |
| BE2-SCH-08 | `[ ]` `PUT /v1/admin/config/scheduled-rides` — Admin configures matching lead-time window and reminder timing | A-33 | SETUP-44 | — |

---

## 43. Phase 2 — Backend: Third-Party Bookings

**PRD refs:** B-28, P-23, P-24, D-13, A-34

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-3P-01 | `[ ]` Extend `rides` table: add booker_user_id (FK, nullable), rider_name, rider_phone, is_third_party (bool) | B-28 | SETUP-14 | Distinguishes booker and actual rider |
| BE2-3P-02 | `[ ]` `POST /v1/rides` extended: accept `rider_name` and `rider_phone` for third-party booking; set booker as authenticated user | P-23 | BE2-3P-01, BE-RIDE-02 | — |
| BE2-3P-03 | `[ ]` Design and create `third_party_config` table: id, pin_recipient (booker/rider/both), tracking_recipient (booker/rider/both), updated_by_admin_id, updated_at | A-34 | SETUP-07 | ⚠ OQ-18: Must be decided before build |
| BE2-3P-04 | `[ ]` Implement configurable PIN/tracking routing: based on Admin config, route ride-start PIN and live tracking to booker, rider, or both | P-24, D-13, B-28 | BE2-3P-03, BE-RIDE-04 | Changes the §9.1 PIN flow for third-party rides |
| BE2-3P-05 | `[ ]` `PUT /v1/admin/config/third-party` — Admin configures the PIN/tracking recipient policy | A-34 | BE2-3P-03, SETUP-44 | — |

---

## 44. Phase 2 — Backend: Lost & Found

**PRD refs:** B-29, P-25, P-26, D-14, A-35

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-LF-01 | `[ ]` Design and create `lost_items` table: id, ride_id (FK), reported_by_user_id, category, description, status (reported/driver_notified/driver_confirmed/driver_denied/resolved/unresolved), driver_response, admin_notes, resolved_by, created_at, updated_at, resolved_at | B-29 | SETUP-14 | — |
| BE2-LF-02 | `[ ]` `POST /v1/rides/:id/lost-items` — passenger reports a lost item against a completed trip | P-25 | BE2-LF-01 | — |
| BE2-LF-03 | `[ ]` `GET /v1/lost-items` — list lost-item reports for authenticated user (passenger: their reports; driver: reports against their trips) | P-26 | BE2-LF-02 | — |
| BE2-LF-04 | `[ ]` Notify driver of a new lost-item report via push notification | D-14 | BE2-LF-02, SETUP-49 | — |
| BE2-LF-05 | `[ ]` `POST /v1/lost-items/:id/respond` — driver confirms or denies possession | D-14 | BE2-LF-04 | Status update flows back to passenger and Admin |
| BE2-LF-06 | `[ ]` `GET /v1/admin/lost-items` — Admin queue of lost-item reports with trip detail, category, status | A-35 | BE2-LF-02, SETUP-44 | — |
| BE2-LF-07 | `[ ]` `POST /v1/admin/lost-items/:id/intervene` — Admin resolves or adds notes to a lost-item case | A-35 | BE2-LF-06 | — |

---

## 45. Phase 2 — Backend: Advanced Disputes

**PRD refs:** B-30, P-27, A-36, A-37, NF-12

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-DISP-01 | `[ ]` Design and create `dispute_evidence` table: id, dispute_id (FK), file_url, file_type, uploaded_by_user_id, uploaded_at | B-30 | SETUP-18 | 🔒 Files stored securely; access restricted to authorised reviewers (NF-12) |
| BE2-DISP-02 | `[ ]` `POST /v1/disputes/:id/evidence` — upload photo evidence (accept image file, store securely, create record) | P-27, B-30 | BE2-DISP-01 | — |
| BE2-DISP-03 | `[ ]` Extend `disputes` table: add escalation_tier, escalated_to_admin_id, escalated_at | A-37 | SETUP-18 | — |
| BE2-DISP-04 | `[ ]` `POST /v1/admin/disputes/:id/escalate` — escalate a dispute to a higher-tier reviewer | A-37 | BE2-DISP-03, SETUP-44 | ⚠ OQ-21: Escalation tiers and ownership TBD |

---

## 46. Phase 2 — Backend: Multi-City & Driver Scheduling

**PRD refs:** B-31, B-32, D-15, D-16, A-38, A-39

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| BE2-CITY-01 | `[ ]` Verify and test that Phase 1 city-scoping model supports onboarding additional cities without schema changes | B-31 | BE-CITY-01 | Should hold if Phase 1 was built generically — verify with integration test |
| BE2-CITY-02 | `[ ]` Document city onboarding procedure: Admin steps, pricing config, vehicle class activation, driver assignment | A-38 | BE2-CITY-01 | Flag city-specific technical needs (currency, language, payment method) |
| BE2-SHIFT-01 | `[ ]` Design and create `driver_shifts` table: id, driver_id (FK), shift_date, start_time, end_time, status (scheduled/active/completed/missed), created_by_admin_id, created_at | B-32 | SETUP-12 | ⚠ OQ-20: Is "driver scheduling" shift scheduling, availability planning, or both? |
| BE2-SHIFT-02 | `[ ]` `GET /v1/drivers/shifts` — driver views their assigned shift schedule | D-15 | BE2-SHIFT-01 | — |
| BE2-SHIFT-03 | `[ ]` `PUT /v1/admin/drivers/:id/shifts` — Admin manages shift assignments | A-39 | BE2-SHIFT-01, SETUP-44 | — |
| BE2-VEH-01 | `[ ]` Design and create `vehicle_maintenance` table: id, driver_id (FK), maintenance_type (charging/service/inspection), status, due_date, completed_at, notes | B-32 | SETUP-12 | ⚠ OQ-19: EV-specific tracking, general fleet maintenance, or both? |
| BE2-VEH-02 | `[ ]` `GET /v1/drivers/vehicle/status` — driver views charging status and/or maintenance indicators | D-16 | BE2-VEH-01 | May integrate with Phase 1 EV reservation system if EV-specific |
| BE2-VEH-03 | `[ ]` `PUT /v1/admin/drivers/:id/vehicle/maintenance` — Admin manages maintenance/charging tracking records | A-39 | BE2-VEH-01, SETUP-44 | — |

---

## 47. Phase 2 — Passenger App

**PRD refs:** P-20–P-27

### Ride Scheduling

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA2-SCH-01 | `[ ]` Build date/time picker on booking screen: option to switch between "Ride Now" and "Schedule for Later" | P-20 | PA-BOOK-08 | — |
| PA2-SCH-02 | `[ ]` Implement scheduled ride creation: submit booking with future date/time via API | P-20 | PA2-SCH-01, BE2-SCH-02 | — |
| PA2-SCH-03 | `[ ]` Build upcoming rides screen: list of scheduled rides with date, route, status | P-22 | PA2-SCH-02, BE2-SCH-05 | — |
| PA2-SCH-04 | `[ ]` Implement edit/cancel scheduled ride: edit pickup/destination/time or cancel before dispatch | P-22 | PA2-SCH-03, BE2-SCH-06, BE2-SCH-07 | — |
| PA2-SCH-05 | `[ ]` Implement pre-ride reminder notification handling: receive and display reminder push notification | P-21 | PA2-SCH-02, BE2-SCH-04 | — |

### Third-Party Booking

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA2-3P-01 | `[ ]` Build "Book for someone else" toggle on booking screen with rider name and phone number fields | P-23 | PA-BOOK-08 | — |
| PA2-3P-02 | `[ ]` Implement third-party ride submission via API | P-23 | PA2-3P-01, BE2-3P-02 | — |
| PA2-3P-03 | `[ ]` Implement PIN/tracking display per Admin-configured policy: show tracking and/or PIN to the booker based on backend config | P-24 | PA2-3P-02, BE2-3P-04 | ⚠ OQ-18: Who receives PIN/tracking — must be decided before build |

### Lost & Found

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA2-LF-01 | `[ ]` Build lost-item report form: select completed trip, choose item category, enter description, submit | P-25 | PA-PAY-06, BE2-LF-02 | — |
| PA2-LF-02 | `[ ]` Build lost-item status tracker: show status progression (reported → driver notified → confirmed/denied → resolved) | P-26 | PA2-LF-01, BE2-LF-03 | — |

### Advanced Disputes

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| PA2-DISP-01 | `[ ]` Extend dispute report screen: add photo attachment capability (camera or gallery) | P-27 | PA-PAY-05, BE2-DISP-02 | — |

---

## 48. Phase 2 — Driver App

**PRD refs:** D-12–D-16

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| DA2-SCH-01 | `[ ]` Build scheduled ride notification: receive dispatch notification at configured lead time, enter normal ride flow | D-12 | DA-RIDE-03, BE2-SCH-03 | — |
| DA2-3P-01 | `[ ]` Adapt PIN entry and tracking for third-party rides per Admin-configured policy | D-13 | DA-RIDE-09, BE2-3P-04 | ⚠ OQ-18: Depends on PIN/tracking policy decision |
| DA2-LF-01 | `[ ]` Build lost-item notification and response screen: show report details, confirm/deny possession buttons | D-14 | BE2-LF-04, BE2-LF-05 | — |
| DA2-SHIFT-01 | `[ ]` Build shift schedule view: calendar or list view of assigned shifts with date, time, status | D-15 | BE2-SHIFT-02 | ⚠ OQ-20: Scope confirmation needed |
| DA2-VEH-01 | `[ ]` Build vehicle status indicator: show charging status and/or maintenance-due badge on main screen or vehicle profile | D-16 | BE2-VEH-02 | ⚠ OQ-19: EV-specific, general maintenance, or both |

---

## 49. Phase 2 — Admin Dashboard

**PRD refs:** A-33–A-39

| ID | Task | PRD Ref | Deps | Notes |
|----|------|---------|------|-------|
| AD2-SCH-01 | `[ ]` Build scheduled rides config page: set matching lead-time window and reminder notification timing | A-33 | AD-AUTH-02, BE2-SCH-08 | — |
| AD2-3P-01 | `[ ]` Build third-party booking policy config: toggle PIN/tracking recipient (booker, rider, or both) | A-34 | AD-AUTH-02, BE2-3P-05 | — |
| AD2-LF-01 | `[ ]` Build lost-and-found queue page: table of reports with trip detail, category, status; intervention controls | A-35 | AD-AUTH-02, BE2-LF-06, BE2-LF-07 | — |
| AD2-DISP-01 | `[ ]` Extend dispute detail page: display attached photo evidence with zoom/gallery viewer | A-36 | AD-DISP-02, BE2-DISP-02 | — |
| AD2-DISP-02 | `[ ]` Build dispute escalation controls: escalate to higher-tier owner with notes | A-37 | AD2-DISP-01, BE2-DISP-04 | ⚠ OQ-21: Escalation tiers and ownership TBD |
| AD2-CITY-01 | `[ ]` Test and document city activation workflow using Phase 1 city management (no new engineering if model is generic) | A-38 | AD-CITY-01, BE2-CITY-01, BE2-CITY-02 | Flag city-specific needs (currency, language, payment method) |
| AD2-SHIFT-01 | `[ ]` Build driver shift management page: assign/edit shifts, view schedule per driver | A-39 | AD-DRV-02, BE2-SHIFT-03 | ⚠ OQ-20: Scope depends on D-15/D-16 decision |
| AD2-VEH-01 | `[ ]` Build vehicle maintenance/charging tracking management page | A-39 | AD-DRV-02, BE2-VEH-03 | ⚠ OQ-19: Scope depends on vehicle-charging decision |

---

---

## 50. Open Questions Tracker

These questions from PRD §16 must be resolved before the affected tasks can be built.

### Provider & Integration Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-01 | Which SMS/OTP gateway is approved? | SETUP-47, SETUP-48, BE-AUTH-01 | `[ ]` Unresolved |
| OQ-02 | Which maps/geocoding provider is approved? | SETUP-52, SETUP-53 | `[ ]` Unresolved |
| OQ-03 | Which card payment gateway is approved? | SETUP-54, SETUP-55 | `[ ]` Unresolved |
| OQ-04 | Which emergency-services dispatch integration is approved (if any)? | BE-SOS-08, AD-SOS-05 | `[ ]` Unresolved |
| OQ-17 | Minimum supported iOS and Android versions? | All mobile tasks | `[ ]` Unresolved |

### Business Rule Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-05 | Does Phase 1 include a cancellation fee, and under what conditions? | PA-BOOK-12, BE-RIDE-03 | `[ ]` Unresolved |
| OQ-06 | How and how often are driver earnings paid out? | DA-EARN-02 | `[ ]` Unresolved |
| OQ-07 | Single Admin role or role-based tiers (Ops/Support/Finance/Safety)? | SETUP-43, SETUP-44 | `[ ]` Unresolved |
| OQ-08 | Can Admin issue refunds/fare adjustments when resolving disputes? | AD-DISP-03 | `[ ]` Unresolved |
| OQ-09 | Tier naming: Option A (Bronze → Diamond) or Option B (Seed → Forest)? | PA-GAME-01, BE-GAME-01 | `[ ]` Unresolved |
| OQ-10 | Source for baseline/per-vehicle-class emissions data (carbon scoring)? | BE-GAME-03 | `[ ]` Unresolved |
| OQ-11 | Is surge pricing in scope in Phase 1 (G-05 depends on it)? | BE-GAME-12 | `[ ]` Unresolved |
| OQ-12 | Auto-apply promos in Phase 1, or code-entry only? | PA-PROMO-05, BE-PROMO-08 | `[ ]` Unresolved |

### Detection & Enforcement Thresholds

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-13 | GPS collocation/route-similarity threshold and jitter tolerance for anti-offline flagging? | BE-AO-02 | `[ ]` Unresolved |
| OQ-14 | Acceptable false-positive rate for anti-offline flags, and who has final authority to overturn Level 3 (permanent deactivation)? | BE-AO-02, AD-AO-05 | `[ ]` Unresolved |

### EV Charging Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-15 | EV station data source — E-tiGo-operated, third-party API, or both? | BE-EV-01, AD-EV-02 | `[ ]` Unresolved |
| OQ-16 | Does the top-tier EV reservation fee waiver apply to driver, passenger, or both? | BE-EV-10, DA-EV-07 | `[ ]` Unresolved |

### Phase 2 Decisions

| # | Question | Affects | Status |
|---|----------|---------|--------|
| OQ-18 | Phase 2: Who receives the PIN and live tracking for third-party bookings — booker, rider, or both? | PA2-3P-03, DA2-3P-01, AD2-3P-01, BE2-3P-04 | `[ ]` Unresolved |
| OQ-19 | Phase 2: Is "vehicle charging & maintenance" EV-specific, general fleet maintenance, or both? | DA2-VEH-01, AD2-VEH-01, BE2-VEH-01 | `[ ]` Unresolved |
| OQ-20 | Phase 2: Is "driver scheduling" shift scheduling distinct from ride scheduling, or the same mechanism? | DA2-SHIFT-01, AD2-SHIFT-01, BE2-SHIFT-01 | `[ ]` Unresolved |
| OQ-21 | Phase 2: What are the dispute escalation tiers and who owns each tier? | AD2-DISP-02, BE2-DISP-04 | `[ ]` Unresolved |
| OQ-22 | Phase 2: Which city/cities are being activated and what is the target date? | AD2-CITY-01 | `[ ]` Unresolved |

---

*Document generated from PRD v3.0 and Product Brief. Update task statuses by replacing `[ ]` with `[~]` (in progress) or `[x]` (done). Resolve open questions in §50 before starting affected tasks.*
