# E-tiGo Backend — Technical Documentation

## Architecture Overview

The E-tiGo backend is a Laravel 13 API application serving three client applications:
- **Passenger App** (mobile) — ride booking, payments, gamification
- **Driver App** (mobile) — ride fulfillment, KYC onboarding, earnings
- **Admin Dashboard** (web) — platform operations, driver management, safety

### Stack

| Component       | Technology                                |
|-----------------|-------------------------------------------|
| Framework       | Laravel 13.x (PHP 8.5)                   |
| Database        | PostgreSQL                                |
| Cache           | Redis (planned), Database (current)       |
| Auth            | Laravel Sanctum (token-based)             |
| Social Auth     | Laravel Socialite (Google, Apple, Facebook)  |
| Testing         | Pest 5.x                                  |
| Code Style      | Laravel Pint                              |
| API Versioning  | URL prefix `/api/v1/`                     |

---

## Authentication Architecture

### Phone OTP Authentication (Passenger & Driver)

Passengers and drivers authenticate using their phone number via WhatsApp OTP, following the industry-standard pattern used by Bolt, Uber, and InDrive.

#### Flow

```
1. User → POST /auth/otp/send { phone }
2. Backend → generates 6-digit code, sends via WhatsApp Cloud API
3. User → POST /auth/otp/verify { phone, code }
4. Backend → Two outcomes:
   a) Existing user → returns Sanctum token (logged in)
   b) New user → returns is_new_user: true
5. (New user only) → POST /auth/register/complete { phone, first_name, last_name, type }
6. Backend → creates account, returns Sanctum token
```

#### OTP Security

- **Code storage:** 6-digit codes are stored as SHA-256 hashes in the `otp_codes` table — plain codes are never persisted
- **Atomic attempt tracking:** `DB::table()->increment()` ensures the attempt counter is race-condition safe
- **Timing-safe comparison:** `hash_equals()` prevents timing side-channel attacks during verification
- **5-minute expiry:** codes expire after 5 minutes
- **60-second cooldown:** prevents OTP spam
- **Max 5 attempts:** codes are invalidated after 5 failed verification attempts
- **10-minute registration window:** after OTP verification, the user has 10 minutes to complete registration

#### WhatsApp OTP Delivery

OTP delivery uses the Meta WhatsApp Cloud API via pre-approved message templates. The `SmsGateway` contract (`App\Contracts\SmsGateway`) abstracts the delivery mechanism:

- **Production:** `WhatsAppGateway` sends OTP via WhatsApp Cloud API (Graph API v21.0)
- **Development:** `LogSmsGateway` logs the OTP to Laravel's log channel

The gateway is auto-selected based on environment variables — if `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_ACCESS_TOKEN` are configured, WhatsApp is used; otherwise it falls back to logging.

See `doc/whatsapp-otp-setup.md` for client setup instructions.

#### Admin Login
- Admin accounts are seeded or created by other admins (no self-registration)
- Login via `POST /admin/auth/login` with email and password
- Each admin has an `admin_role` that determines their access scope

### Token Management

- Sanctum personal access tokens with ability scoping
- Token abilities match user type: `['passenger']`, `['driver']`, `['admin', 'super_admin']`
- Tokens persist until explicit logout or deletion
- Admin tokens expire after 480 minutes by default (configurable in `config/sanctum.php`)

### Ride-Start PIN Verification

The `OtpService` also provides 4-digit PIN generation for ride-start verification (PRD §9.1):
- A PIN is generated when a ride is accepted and shared with the passenger
- The driver must enter this PIN to confirm the ride has started
- 30-minute expiry window
- Maximum 3 verification attempts per PIN
- Previous active PINs for the same ride are invalidated when a new one is generated

### Social Login (Google, Apple & Facebook)

Mobile-first OAuth flow: the mobile app handles the provider's OAuth UI natively (Google Sign-In SDK, Apple Sign In, Facebook SDK) and sends the access token to the backend. The backend uses Laravel Socialite's `userFromToken()` to verify the token and retrieve user profile data.

#### Flow

```
1. Mobile app → Provider OAuth flow → receives access_token
2. Mobile app → POST /auth/social { provider, token, type }
3. Backend → Socialite::driver($provider)->stateless()->userFromToken($token)
4. Backend → Three possible outcomes:
   a) Existing social account → login
   b) Matching email+type but no social account → link social account to user
   c) No match → create new user + social account
5. Backend → returns Sanctum token
```

#### Implementation Details

- **`SocialProvider` enum** (`app/Enums/SocialProvider.php`): `google`, `apple`, `facebook`
- **`SocialAccount` model** (`app/Models/SocialAccount.php`): stores provider, provider_id, provider_token, provider_refresh_token per user
- **`SocialAuthService`** (`app/Services/SocialAuthService.php`): uses Socialite `userFromToken()` for token verification, returns normalised user data
- Social auth is handled within `AuthController@socialAuth` — a single endpoint for all providers
- Google uses Socialite's built-in driver; Apple uses `socialiteproviders/apple` community package registered via `SocialiteWasCalled` event in `AppServiceProvider`; Facebook uses Socialite's built-in driver
- `stateless()` is required because the API has no session/cookie state
- Users table `phone` column is nullable to support social-login-only signups

---

## Global Exception Handler

All API exceptions are caught in `bootstrap/app.php` and rendered as structured JSON:

```json
{
  "message": "Human-readable description.",
  "error_code": "MACHINE_READABLE_CODE",
  "details": {}
}
```

| Exception                  | Status | Error Code         | Notes                                      |
|----------------------------|--------|--------------------|--------------------------------------------|
| `AuthenticationException`  | 401    | `UNAUTHENTICATED`  | Missing or invalid Sanctum token           |
| `ValidationException`      | 422    | `VALIDATION_ERROR`  | Includes `errors` field with per-field messages |
| `ModelNotFoundException`   | 404    | `NOT_FOUND`         | Eloquent model not found                   |
| `NotFoundHttpException`    | 404    | `NOT_FOUND`         | Route not found                            |
| `HttpException`            | varies | `HTTP_ERROR`        | Uses the exception's status code           |
| `Throwable` (fallback)     | 500    | `SERVER_ERROR`      | Debug `details` only when `APP_DEBUG=true` |

Stack traces are never exposed in production.

---

## Request Logging Middleware

The `LogRequests` middleware (`app/Http/Middleware/LogRequests.php`) is prepended to the API middleware stack and logs every request:

- HTTP method and path
- Response status code
- Duration in milliseconds
- Authenticated user ID (if available)
- Client IP address

Logs are written to the `single` channel. This provides an audit trail for debugging and performance monitoring.

---

## Health Check Endpoint

`GET /api/v1/health` — unauthenticated endpoint for load balancer and monitoring probes.

Checks:
- **Database**: attempts a PDO connection
- **Redis**: sends a `PING` command

Returns `200` with `"status": "healthy"` when all checks pass, or `503` with `"status": "degraded"` when any check fails. Individual check results are listed in the `checks` object.

---

## Role-Based Access Control (RBAC)

### User Types
Enforced at the route level via `EnsureUserType` middleware:

```
Route::middleware(['auth:sanctum', 'user.type:passenger'])  // passenger only
Route::middleware(['auth:sanctum', 'user.type:driver'])      // driver only
Route::middleware(['auth:sanctum', 'user.type:admin'])        // admin only
```

### Admin Roles
Granular admin access via `EnsureAdminRole` middleware:

| Role              | Scope                                    |
|-------------------|------------------------------------------|
| `super_admin`     | Full platform access                     |
| `operations`      | Operational management                   |
| `safety_operator` | SOS/safety console (Phase 1 §3.10)       |
| `support`         | Customer support                         |

```
Route::middleware(['auth:sanctum', 'user.type:admin', 'admin.role:safety_operator'])
```

### Driver Status Gate
The `EnsureDriverApproved` middleware blocks unapproved drivers from ride-handling endpoints:

```
Route::middleware(['auth:sanctum', 'user.type:driver', 'driver.approved'])
```

---

## Database Schema

### Core Tables

| Table                    | Purpose                                          |
|--------------------------|--------------------------------------------------|
| `users`                  | All user types (passenger, driver, admin)         |
| `otp_codes`              | Ride-start PIN verification codes                 |
| `drivers`                | Driver-specific data, KYC status                  |
| `driver_documents`       | KYC document uploads                              |
| `vehicles`               | Driver vehicle profiles                           |
| `social_accounts`        | OAuth provider links (Google, Apple, Facebook) per user |
| `audit_logs`             | Immutable event log (polymorphic)                 |
| `personal_access_tokens` | Sanctum API tokens                                |
| `cities`                 | City definitions with GeoJSON boundaries           |
| `vehicle_classes`        | Platform-wide vehicle class definitions            |
| `city_vehicle_classes`   | Pivot: which classes are available per city        |
| `pricing_configs`        | Versioned fare rates per city + vehicle class      |
| `surge_rules`            | Surge pricing rules per city (optional vehicle class) |
| `rides`                  | Core ride records (UUID PK)                       |
| `ride_state_transitions` | Append-only ride state audit trail                |
| `payments`               | Ride payment records                              |
| `user_payment_methods`   | Tokenized card storage (no raw card data)         |
| `ratings`                | Post-ride ratings (1-5 stars)                     |
| `disputes`               | Ride issue reports                                |
| `promo_codes`            | Promotional discount configurations               |
| `promo_redemptions`      | Promo usage tracking per user per ride            |
| `gamification_profiles`  | User tier, points, carbon score aggregates        |
| `tier_configs`           | Tier level thresholds and benefits                |
| `point_multiplier_configs` | Condition-based point multipliers               |
| `trip_carbon_scores`     | Per-trip carbon/emissions calculations            |
| `sos_incidents`          | SOS emergency incident records                    |
| `sos_event_log`          | Append-only SOS incident audit trail              |
| `offline_trip_flags`     | Anti-offline-trip detection flags                 |
| `ev_charging_stations`   | EV charging station locations                     |
| `ev_charging_stalls`     | Individual stalls per station                     |
| `ev_reservations`        | Stall reservation and queue records               |
| `notifications`          | In-app notification records                       |
| `device_tokens`          | Push notification device registrations            |

### Key Design Decisions

1. **Single `users` table** — All user types share one table with a `type` discriminator. This simplifies the shared auth service and cross-type queries. Type-specific data lives in related tables (`drivers`, `vehicles`).

2. **Enum-backed status fields** — `DriverStatus`, `DocumentStatus`, `UserType`, `AdminRole` are PHP 8.1+ backed enums, cast at the Eloquent level. This gives type safety and IDE support.

3. **Polymorphic audit log** — The `audit_logs` table uses `auditable_type`/`auditable_id` morphs so any model's state changes can be tracked without schema changes.

4. **Soft driver-document replacement** — Re-uploading a document of the same type deletes the previous version (file + record), avoiding accumulation of stale documents.

5. **UUID primary key on `rides`** — Ride IDs use UUIDs for external shareability (share tokens, receipts). Foreign keys referencing rides use `uuid('ride_id')` with explicit `->references('id')->on('rides')`.

6. **JSONB columns** — `pricing_snapshot` on rides freezes the pricing config at booking time. `boundary` on cities stores GeoJSON. `telemetry_data` on SOS incidents, `detection_data` on offline flags, and `data` on notifications store structured payloads.

7. **Append-only audit tables** — `ride_state_transitions` and `sos_event_log` are immutable audit trails with no UPDATE or DELETE operations.

8. **Composite indexes** — High-frequency queries are indexed: rides by `(status, city_id)`, `(passenger_id, created_at)`, `(driver_id, created_at)`; notifications by `(user_id, is_read)` and `(user_id, created_at)`.

---

## Driver Onboarding Flow

```
1. Driver registers via OTP verification + POST /auth/register/complete → account created with Driver record (onboarding)
2. Driver uploads KYC documents:
   - driving_licence
   - vehicle_registration
   - insurance_certificate
   - government_id
3. Driver sets licence number
4. Driver registers vehicle (make, model, colour, plate)
5. Admin reviews KYC → approves or rejects (with reason)
6. If approved: Admin assigns vehicle class → driver can go online
```

The `/driver/onboarding/status` endpoint returns a checklist of what's missing.

### Driver Status Transitions

Admin actions enforce valid state transitions and return `422 Unprocessable Entity` for invalid ones:

| Action | Valid From | Transitions To | Invalid Response |
|--------|-----------|----------------|------------------|
| Submit for review | `onboarding`, `rejected` | `pending_review` | 422 "Application can only be submitted from onboarding or rejected status." |
| Review (approve) | `pending_review` | `approved` | 422 "Driver can only be reviewed when in pending review status." |
| Review (reject) | `pending_review` | `rejected` | 422 "Driver can only be reviewed when in pending review status." |
| Suspend | `approved` | `suspended` | 422 "Only approved drivers can be suspended." |
| Reactivate | `suspended` | `approved` | 422 "Only suspended drivers can be reactivated." |

All admin driver management actions are wrapped in `DB::transaction()` to ensure the status change and audit log are atomic.

### Vehicle Updates

- `POST /driver/vehicle` creates a new vehicle (one per driver)
- `PUT /driver/vehicle` supports **partial updates** — only provided fields are changed
- Re-uploading a document of the same type deletes the previous file and record

### Rejected Document Handling

When a driver is rejected, they can re-upload documents and their status remains `rejected` until an admin re-reviews. The rejection reason is stored on the `drivers` record.

---

## City & Vehicle-Class Management

Cities are the top-level scoping entity for the platform. Each city has a name, slug, GeoJSON boundary, timezone, and currency code. Vehicle classes (e.g. Economy, Premium, SUV) are platform-wide definitions that get enabled per city via the `city_vehicle_classes` pivot table.

### Admin Endpoints

- **Cities:** Full CRUD (`POST/GET/PUT /admin/cities`) plus `PATCH /admin/cities/{id}/status` to toggle active/inactive
- **Vehicle Classes:** Full CRUD (`POST/GET/PUT /admin/vehicle-classes`)
- **City-Vehicle-Class Pivot:** `PUT /admin/cities/{id}/vehicle-classes` syncs which vehicle classes are available in a city, with per-entry `is_active` control

### Public Endpoints

- `GET /cities` — Returns all active cities (no auth required)
- `GET /cities/{id}/vehicle-classes` — Returns vehicle classes active in a city (filters by both the pivot `is_active` and the global `is_active` on vehicle_classes)

All admin mutations are wrapped in `DB::transaction()` and create audit log entries.

---

## Pricing Engine

The Pricing Engine manages versioned fare configurations per city + vehicle class, and provides fare estimates for ride requests.

### Pricing Configurations

Pricing configs are **append-only and versioned** — creating a new config for the same city + vehicle class combination auto-increments the version number. The currently effective config is the one with the latest `effective_from` timestamp that is not in the future.

Each config contains:
- `base_fare` — fixed charge per trip
- `per_km_rate` — charge per kilometre of distance
- `per_minute_rate` — charge per minute of trip duration
- `minimum_fare` — floor amount (fare can never be lower)
- `waiting_time_rate` — per-minute charge when driver waits beyond free period (nullable)
- `free_waiting_minutes` — grace period before waiting charges apply (default: 5 minutes)

### Fare Estimation Formula

```
base_fare = max(minimum_fare, base_fare + (distance_km × per_km_rate) + (duration_minutes × per_minute_rate) + waiting_charge)
waiting_charge = max(0, actual_wait_minutes - free_waiting_minutes) × waiting_time_rate
final_fare = base_fare × surge_multiplier
```

The first 5 minutes of waiting (configurable per pricing config) are free. After that, each additional minute is charged at the `waiting_time_rate`. If no `waiting_time_rate` is set, waiting time is always free. The surge multiplier is applied after the base fare calculation (including minimum fare enforcement).

The `FareEstimationService` orchestrates the calculation:
1. Accepts pickup/destination coordinates and pricing config
2. Queries the `MapsGateway` contract for distance and duration
3. Applies the base fare formula (waiting time is added to the final fare at ride completion, not the estimate)
4. Queries `SurgePricingService` for the current surge multiplier for the city + vehicle class
5. Applies the surge multiplier to the base fare
6. Returns the estimate with pricing snapshot, waiting time policy, and surge info for booking-time rate preservation

### Maps Gateway Contract

Distance and duration are obtained through the `MapsGateway` contract (`App\Contracts\MapsGateway`). The production implementation (`GoogleMapsGateway`) uses the Google Maps Distance Matrix API for accurate road distances and real-time traffic-aware durations. The contract also provides `geocode()` and `reverseGeocode()` methods. Configure via `GOOGLE_MAPS_API_KEY` in `.env`.

### Pricing Snapshot

When a ride is created, the active pricing config is serialised as a JSONB snapshot on the ride record (`pricing_snapshot` column). This ensures in-progress trips retain the booking-time rate even if pricing changes.

### Admin Endpoints

- `POST /admin/pricing` — Create a new pricing config (version auto-incremented)
- `GET /admin/pricing` — List pricing configs with filters (`city_id`, `vehicle_class_id`, `current_only`), paginated
- `GET /admin/pricing/current` — Get the currently effective config for a city + vehicle class
- `GET /admin/pricing/{id}` — Get a specific pricing config

### Public Endpoints

- `POST /rides/estimate` — Returns fare estimates for all active vehicle classes in a city. Requires authentication. Accepts pickup/destination coordinates and city_id.

### Edge Case Handling

The fare estimation system handles several pricing edge cases:

**Cross-City Rides:**
When a passenger's destination is in a different city than their pickup, the system detects this using the `CityDetectionService` (reverse geocoding + boundary matching). Pickup city pricing always applies — this is the industry standard (Uber, Bolt). The response includes a `cross_city` object with destination city details and a `warnings` array with a human-readable message for the mobile app to display.

**Destination Outside Service Area:**
If the destination coordinates don't match any active E-tiGo city, the estimate still proceeds using pickup city pricing, but a warning is returned indicating the driver may not find return trips from the destination.

**Long-Distance Rides:**
Routes exceeding 100 km trigger a warning that the fare is estimated and may vary. The fare is still calculated normally using the full route distance.

**Inactive City Validation:**
The `EstimateRideRequest` validates that the specified `city_id` references an active city. Inactive cities return a 422 validation error.

**Same Pickup and Destination:**
Identical pickup and destination coordinates are rejected with a 422 validation error before fare calculation.

**Very Short Distance:**
When the calculated fare (base + distance + time) falls below the minimum fare, the minimum fare applies. This protects driver earnings on short trips.

**City Detection Service:**
The `CityDetectionService` extracts city-from-coordinates detection into a reusable service, used by both the public `GET /cities/detect` endpoint and the cross-city detection in fare estimation. Detection uses reverse geocoding first, then falls back to boundary matching (haversine distance within city radius).

---

## Surge Pricing

The Surge Pricing system applies dynamic fare multipliers during high-demand periods. Rules are configured per city (optionally per vehicle class) and evaluated in real-time during fare estimation.

### Surge Rule Types

| Type           | Trigger                                                    | Use Case                      |
|----------------|------------------------------------------------------------|-------------------------------|
| `manual`       | Admin toggles `is_active`                                  | Rain, events, emergencies     |
| `time_based`   | Current time matches schedule (days_of_week + time window) | Rush hours, weekday peaks     |
| `demand_based` | Demand/supply ratio exceeds threshold                      | Real-time demand surges       |

### Rule Evaluation

The `SurgePricingService` evaluates surge rules with this logic:

1. Query all active rules for the given city + vehicle class (including city-wide rules with `vehicle_class_id = null`)
2. Filter to rules within their `effective_from`/`effective_until` window
3. Evaluate each rule's conditions:
   - **Manual:** Always matches when active
   - **Time-based:** Matches when current day and time fall within the schedule (supports overnight spans like 22:00–06:00)
   - **Demand-based:** Matches when real-time demand/supply ratio exceeds `min_demand_supply_ratio` (placeholder — returns 0.0 until wired to driver availability data)
4. From matching rules, select the one with highest `priority` (ties broken by highest `multiplier`)
5. Return the multiplier (default 1.0 when no rules match)

### Multiplier Constraints

- Minimum: 1.00 (no discount via surge)
- Maximum: 5.00 (admin-enforced cap at creation)
- Applied after the base fare formula: `final_fare = base_fare × surge_multiplier`

### Conditions Schema

**Time-based:**
```json
{
  "days_of_week": [1, 2, 3, 4, 5],
  "start_time": "07:00",
  "end_time": "09:00"
}
```
Days use ISO numbering: 1 = Monday, 7 = Sunday. Overnight spans (start > end) are supported.

**Demand-based:**
```json
{
  "min_demand_supply_ratio": 2.0
}
```

**Manual:**
```json
{}
```

### Admin Endpoints

- `POST /admin/surge-rules` — Create a surge rule
- `GET /admin/surge-rules` — List surge rules with filters (`city_id`, `vehicle_class_id`, `active_only`), paginated
- `GET /admin/surge-rules/{id}` — Get a specific surge rule
- `PUT /admin/surge-rules/{id}` — Update a surge rule
- `PATCH /admin/surge-rules/{id}/status` — Toggle active/inactive
- `GET /admin/surge-rules/current-multiplier` — Get the live surge multiplier for a city

### Surge in Fare Estimates

Fare estimate responses now include a `surge` object:

```json
{
  "surge": {
    "active": true,
    "multiplier": 1.5,
    "rule_name": "Morning Rush Hour"
  }
}
```

The `fare_estimate` field already includes the surge multiplier. The `pricing_snapshot` preserves the base rates, and the surge info is separate so clients can display "1.5x surge" to passengers.

---

## Ride State Machine & Core Engine

### State Machine

The `RideStateMachine` service enforces a strict directed graph of ride state transitions. Every transition is atomic (DB transaction), writes an immutable audit record to `ride_state_transitions`, and auto-sets timestamps (`matched_at`, `started_at`, `completed_at`).

```
                    ┌──────────────────────────────────────────────────────────┐
                    │                      CANCELLED                          │
                    └──────────────────────────────────────────────────────────┘
                      ↑        ↑         ↑           ↑          ↑
REQUESTED → SEARCHING → MATCHED → DRIVER_EN_ROUTE → DRIVER_ARRIVED → IN_PROGRESS → COMPLETED
                 ↓
          NO_DRIVER_FOUND
```

Invalid transitions throw `\InvalidArgumentException`. Terminal states (completed, cancelled, no_driver_found) have no outgoing transitions.

### Ride Lifecycle

1. **Passenger creates ride** (`POST /rides`) — status transitions: `requested` → `searching`
   - Pricing snapshot captured from active `PricingConfig`
   - 4-digit PIN generated via `OtpService` (SHA-256 hashed, 30-min expiry, 3 max attempts)
   - Share token generated for public tracking link
   - One active ride per passenger enforced (409 if duplicate)

2. **Driver matched** (via matching engine) — `searching` → `matched` → `driver_en_route`

3. **Driver arrives** (`POST /rides/{id}/driver-arrived`) — `driver_en_route` → `driver_arrived`

4. **PIN verification** (`POST /rides/{id}/verify-pin`) — `driver_arrived` → `in_progress`
   - Hard gate: ride cannot start without valid PIN
   - Constant-time comparison via `hash_equals()`

5. **Ride completion** (`POST /rides/{id}/complete`) — `in_progress` → `completed`
   - Dispatches `FinalFareCalculationJob` (queued, 3 retries, 30s backoff)
   - Final fare = `max(minimum_fare, base + distance×per_km + duration×per_min + waiting_charge)`

6. **Cancellation** (`POST /rides/{id}/cancel`) — any cancellable state → `cancelled`
   - Cancellable states: requested, searching, matched, driver_en_route, driver_arrived
   - In-progress rides cannot be cancelled (must be completed)
   - Records `cancelled_by` user and `cancellation_reason`

### Key Services

| Service | Responsibility |
|---------|---------------|
| `RideStateMachine` | Enforces transition graph, writes audit trail, sets timestamps |
| `RideService` | Orchestrates ride lifecycle (create, cancel, arrive, verify, complete, final fare) |
| `RidePinService` | Delegates to `OtpService` for 4-digit PIN generation and verification |
| `FinalFareCalculationJob` | Calculates actual fare post-completion using maps distance/duration + waiting time |

### Enums

| Enum | Values |
|------|--------|
| `RideStatus` | requested, searching, matched, driver_en_route, driver_arrived, in_progress, completed, cancelled, no_driver_found |
| `PaymentMethod` | cash, card |
| `PaymentStatus` | pending, authorized, captured, settled, refunded, failed, pending_collection, collected |
| `CancellationReason` | changed_mind, driver_too_far, wait_too_long, wrong_pickup, wrong_destination, price_changed, found_alternative, emergency, driver_no_show, passenger_no_show, vehicle_mismatch, safety_concern, other, system_timeout |

---

## Audit Logging

All significant state changes are logged to the `audit_logs` table via `AuditLog::record()`:

```php
AuditLog::record(
    $model,          // The model that changed
    'event_name',    // What happened
    $actor,          // Who did it (nullable for system events)
    $oldValues,      // Previous state (optional)
    $newValues,      // New state (optional)
);
```

Logged events include: `registered`, `logged_in`, `logged_out`, `profile_updated`, `document_uploaded`, `vehicle_registered`, `driver_approved`, `driver_rejected`, `driver_suspended`, `driver_reactivated`, `city_created`, `city_updated`, `city_activated`, `city_deactivated`, `city_vehicle_classes_updated`, `vehicle_class_created`, `vehicle_class_updated`, `pricing_config_created`, `surge_rule_created`, `surge_rule_updated`, `surge_rule_toggled`, `ride_created`, `ride_cancelled`, `ride_completed`.

IP address and user agent are captured automatically from the request.

---

## Project Structure

```
app/
├── Contracts/          # Interface contracts (SmsGateway, MapsGateway)
├── Enums/              # PHP enums (UserType, AdminRole, DriverStatus, SocialProvider, ...)
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/     # Versioned API controllers
│   │       ├── Auth/       # AuthController, AdminAuthController, AdminInvitationController
│   │       ├── Admin/      # DriverManagementController, AdminCityController, AdminVehicleClassController, AdminCityVehicleClassController, AdminPricingController, AdminSurgeRuleController
│   │       ├── Driver/     # OnboardingController
│   │       ├── Passenger/  # ProfileController
│   │       ├── CityController         # Public city listing
│   │       ├── CityVehicleClassController  # Public city vehicle classes
│   │       ├── RideController         # Ride CRUD + lifecycle (store, show, index, cancel, arrive, verify, complete)
│   │       ├── RideEstimateController  # Fare estimation (invokable)
│   │       ├── RideShareController    # Public ride tracking via share token
│   │       └── HealthController  # Health check (invokable)
│   ├── Middleware/      # EnsureUserType, EnsureAdminRole, EnsureDriverApproved, LogRequests
│   ├── Requests/        # Form request validators by domain
│   └── Resources/       # API resources (UserResource, DriverResource, CityResource, VehicleClassResource, PricingResource, SurgeRuleResource, ...)
├── Models/             # Eloquent models (User, Driver, City, VehicleClass, PricingConfig, SurgeRule, SocialAccount, ...)
├── Providers/          # Service providers
└── Services/           # Business logic (OtpService, SocialAuthService, FareEstimationService, SurgePricingService, CityDetectionService, LogSmsGateway, HaversineMapsGateway)

database/
├── factories/          # Model factories for testing
├── migrations/         # Database migrations (25+ migration files)
└── seeders/            # Admin user seeders

doc/                    # API docs, Postman collection, this file
routes/api.php          # All API route definitions
tests/Feature/          # Feature tests by domain
```

---

## Testing

Run the test suite:

```bash
php artisan test --compact
```

Tests use PostgreSQL (configured in `phpunit.xml`) with `RefreshDatabase` trait. The suite covers:

- **Auth/AuthTest** — OTP send/verify (existing/new user), invalid code, deactivated user, complete registration (passenger/driver), unverified phone, social auth, me endpoint, logout
- **Auth/AdminAuthTest** — Admin login, invalid credentials, non-admin rejection, deactivated admin, me endpoint, logout
- **Driver/OnboardingTest** — Onboarding status, profile update, document upload/replace, vehicle registration, role enforcement
- **Passenger/ProfileTest** — Profile read/update, role enforcement
- **Admin/DriverManagementTest** — List/filter drivers, approve/reject, suspend/reactivate, role enforcement
- **Admin/CityManagementTest** — City CRUD, toggle status, vehicle class pivot sync, validation, role enforcement
- **Admin/VehicleClassManagementTest** — Vehicle class CRUD, filtering, validation, role enforcement
- **Admin/PricingManagementTest** — Create pricing config (validation, version auto-increment, audit log), list/filter by city/vehicle class, show, current effective, role enforcement
- **Admin/SurgeRuleManagementTest** — Create surge rules (manual, time-based, demand-based), validation (multiplier bounds, type enum, conditions), list/filter, show, update, toggle status, current multiplier, role enforcement
- **RideEstimateTest** — Fare estimates for all classes, empty estimates when no pricing, coordinate validation, minimum fare enforcement, effective pricing selection, auth enforcement
- **FareEstimationServiceTest** — Fare formula correctness, minimum fare application, estimate response structure, surge multiplier integration, zero/very-short distance minimum fare enforcement
- **PricingEdgeCaseTest** — Inactive city rejection, same pickup/destination rejection, cross-city ride detection and warnings, destination outside service area, long-distance ride warnings, pickup city pricing for cross-city rides, minimum fare for very short rides, multiple simultaneous warnings
- **SurgePricingServiceTest** — Rule evaluation (manual, time-based, demand-based), inactive/expired/future rules, priority resolution, same-priority multiplier tiebreak, city-wide vs vehicle-class-specific rules, cross-city isolation, overnight schedules, surge math
- **CityPublicTest** — Public city listing (active only), city vehicle classes (active pivot + global filter)

---

## Environment Configuration

Key `.env` variables:

```
DB_CONNECTION=pgsql
DB_DATABASE=etigo-api

# Redis (for driver locations, caching — future)
REDIS_HOST=127.0.0.1

# WhatsApp Cloud API (OTP delivery)
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_OTP_TEMPLATE=otp_verification

# Social OAuth
GOOGLE_CLIENT_ID=
APPLE_CLIENT_ID=
FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=

# Google Maps
GOOGLE_MAPS_API_KEY=

# Mail (Resend SMTP)
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_USERNAME=resend
MAIL_PASSWORD=<resend-api-key>
MAIL_FROM_ADDRESS="noreply@etigo.com"
MAIL_FROM_NAME="${APP_NAME}"

# Firebase (push notifications)
FIREBASE_CREDENTIALS_PATH=
FIREBASE_PROJECT_ID=

# Flutterwave (payments)
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_PUBLIC_KEY=
FLUTTERWAVE_ENCRYPTION_KEY=
```

---

## Seeded Admin Accounts (Development)

| Email              | Password   | Role             |
|--------------------|------------|------------------|
| admin@etigo.com    | password   | super_admin      |
| safety@etigo.com   | password   | safety_operator  |
| ops@etigo.com      | password   | operations       |

Run `php artisan db:seed` to create these accounts. The `AdminSeeder` includes a production guard — it skips seeding when `app()->isProduction()` returns true.

---

## Rate Limiting

Auth routes (`/auth/*` and `/admin/auth/*`) are rate-limited to **5 requests per minute** per IP via Laravel's `throttle:5,1` middleware. Exceeding the limit returns `429 Too Many Requests`.

---

## Notifications & Email

### Push Notifications (FCM)

Push notifications are sent via Firebase Cloud Messaging through the `PushNotificationGateway` contract. Two implementations exist:
- **`FirebasePushGateway`** — production adapter using `kreait/firebase-php`; auto-deactivates invalid device tokens
- **`LogPushGateway`** — dev/test adapter that logs notifications instead of sending

`RideNotificationService` dispatches push notifications on every ride state transition, integrated into `RideStateMachine::transitionTo()`. Failures are caught silently so push issues never break the ride flow.

| Event | Recipients | Push Type |
|-------|-----------|-----------|
| Driver matched | Passenger + Driver | `ride_matched` / `ride_assigned` |
| Driver en route | Passenger | `driver_en_route` |
| Driver arrived | Passenger | `driver_arrived` |
| Ride started | Passenger | `ride_started` |
| Ride completed | Passenger + Driver | `ride_completed` |
| Ride cancelled | Other party | `ride_cancelled` |
| No driver found | Passenger | `no_driver_found` |

### Email Notifications

Transactional emails are sent via Resend SMTP (`smtps://smtp.resend.com:465`). All email notifications implement `ShouldQueue` for async delivery.

| Notification | Trigger | Recipient |
|-------------|---------|-----------|
| `AdminInvitationNotification` | Super admin invites new admin | Invitee email |
| `AdminPasswordResetNotification` | Admin forgot password | Admin email |
| `AdminWelcomeNotification` | Admin accepts invitation | New admin email |
| `RideCompletedNotification` | Ride completed | Passenger (if email on file) |

Email templates are customised in `resources/views/vendor/mail/` with Etigo branding (logo, colours, footer). Test emails can be sent via `php artisan mail:test {email} --type={invitation|reset|welcome|receipt|all}`.

> **Note:** The `etigo.com` domain must be verified on [Resend](https://resend.com/domains) with SPF, DKIM, and DMARC DNS records before production emails will deliver from `noreply@etigo.com`.

---

## PRD Task Coverage

This implementation covers the following PRD tasks:

| Task ID      | Description                         | Status |
|--------------|-------------------------------------|--------|
| BE-AUTH-01   | Phone OTP auth service (WhatsApp)   | Done   |
| BE-AUTH-02   | Session/token management            | Done   |
| BE-AUTH-14   | Social login (Google, Apple, Facebook) | Done |
| SETUP-05     | RBAC middleware + Safety Operator    | Done   |
| SETUP-06     | Immutable audit log                 | Done   |
| SETUP-08–34  | All Phase 1 database migrations     | Done   |
| SETUP-36     | Database indexes for key queries    | Done   |
| SETUP-45     | Global exception handler            | Done   |
| SETUP-46     | Request logging middleware          | Done   |
| SETUP-49     | Health check endpoint               | Done   |
| PA-AUTH-01   | Passenger OTP registration          | Done   |
| PA-AUTH-02   | Passenger OTP login + session       | Done   |
| PA-AUTH-03   | Session persistence + logout        | Done   |
| DA-KYC-01   | Driver registration                 | Done   |
| DA-KYC-02   | Document upload                     | Done   |
| DA-KYC-03   | KYC status display + gate           | Done   |
| DA-KYC-04   | Vehicle profile                     | Done   |
| AD-DRV-01   | Review driver KYC submissions       | Done   |
| AD-DRV-02   | Suspend/reactivate driver           | Done   |
| BE-CITY-01–15 | City & Vehicle-Class Management API | Done   |
| BE-PRICE-01  | PricingConfig model with relationships | Done   |
| BE-PRICE-02  | Admin pricing config creation         | Done   |
| BE-PRICE-03  | StorePricingFormRequest validation     | Done   |
| BE-PRICE-04  | Admin pricing config listing          | Done   |
| BE-PRICE-05  | FareEstimationService                 | Done   |
| BE-PRICE-06  | Ride fare estimate endpoint           | Done   |
| BE-PRICE-07  | EstimateRideFormRequest validation    | Done   |
| BE-PRICE-08  | Pricing snapshot capture              | Done   |
| BE-PRICE-09  | PricingResource API resource          | Done   |
| BE-SURGE-01  | SurgeRule model + migration + factory | Done   |
| BE-SURGE-02  | SurgePricingService (manual/time/demand rules) | Done |
| BE-SURGE-03  | Admin surge rule CRUD + toggle        | Done   |
| BE-SURGE-04  | Surge integration into FareEstimation | Done   |
| BE-SURGE-05  | Current multiplier endpoint           | Done   |
| BE-SURGE-06  | SurgeRuleResource + audit logging     | Done   |
| BE-PRICE-11  | Cross-city ride detection + warnings  | Done   |
| BE-PRICE-12  | Inactive city validation              | Done   |
| BE-PRICE-13  | Same pickup/destination rejection     | Done   |
| BE-PRICE-14  | Long-distance ride warnings           | Done   |
| BE-PRICE-15  | CityDetectionService extraction       | Done   |
