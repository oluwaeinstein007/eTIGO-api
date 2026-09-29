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
| Testing         | Pest 5.x                                  |
| Code Style      | Laravel Pint                              |
| API Versioning  | URL prefix `/api/v1/`                     |

---

## Authentication Architecture

### Dual Auth Strategy

E-tiGo uses two distinct authentication flows based on user type:

#### 1. OTP Authentication (Passengers & Drivers)
- Phone number is the primary identifier (E.164 format)
- No password stored — authentication is entirely OTP-based
- Flow: Request OTP → SMS delivery → Verify OTP → Issue Sanctum token
- First successful verification for a new phone number creates the account
- Driver accounts automatically get a `Driver` record in `pending_review` status

#### 2. Email/Password Authentication (Admins)
- Traditional credential-based login
- Admin accounts are seeded or created by other admins (no self-registration)
- Each admin has an `admin_role` that determines their access scope

### Token Management

- Sanctum personal access tokens with ability scoping
- Token abilities match user type: `['passenger']`, `['driver']`, `['admin', 'super_admin']`
- Tokens persist until explicit logout or deletion
- No token expiration configured by default (configurable in `config/sanctum.php`)

### OTP Service

The `OtpService` handles OTP generation and verification:
- 6-digit numeric codes
- 5-minute expiry window
- 60-second resend cooldown
- Maximum 5 verification attempts per code
- Previous active codes are invalidated when a new one is requested

SMS delivery is abstracted behind the `SmsGateway` contract (`App\Contracts\SmsGateway`). The current implementation logs messages (`LogSmsGateway`). To integrate a real SMS provider, implement the interface and update the binding in `AppServiceProvider`.

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

| Table                  | Purpose                                          |
|------------------------|--------------------------------------------------|
| `users`                | All user types (passenger, driver, admin)         |
| `otp_codes`            | OTP verification codes                            |
| `drivers`              | Driver-specific data, KYC status                  |
| `driver_documents`     | KYC document uploads                              |
| `vehicles`             | Driver vehicle profiles                           |
| `audit_logs`           | Immutable event log (polymorphic)                 |
| `personal_access_tokens` | Sanctum API tokens                              |

### Key Design Decisions

1. **Single `users` table** — All user types share one table with a `type` discriminator. This simplifies the shared auth service and cross-type queries. Type-specific data lives in related tables (`drivers`, `vehicles`).

2. **Enum-backed status fields** — `DriverStatus`, `DocumentStatus`, `UserType`, `AdminRole` are PHP 8.1+ backed enums, cast at the Eloquent level. This gives type safety and IDE support.

3. **Polymorphic audit log** — The `audit_logs` table uses `auditable_type`/`auditable_id` morphs so any model's state changes can be tracked without schema changes.

4. **Soft driver-document replacement** — Re-uploading a document of the same type deletes the previous version (file + record), avoiding accumulation of stale documents.

---

## Driver Onboarding Flow

```
1. Driver signs up via OTP → account created with Driver record (pending_review)
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

Logged events include: `registered`, `logged_in`, `logged_out`, `profile_updated`, `document_uploaded`, `vehicle_registered`, `driver_approved`, `driver_rejected`, `driver_suspended`, `driver_reactivated`.

IP address and user agent are captured automatically from the request.

---

## Project Structure

```
app/
├── Contracts/          # Interface contracts (SmsGateway)
├── Enums/              # PHP enums (UserType, AdminRole, DriverStatus, ...)
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/     # Versioned API controllers
│   │       ├── Auth/       # OtpAuthController, AdminAuthController
│   │       ├── Admin/      # DriverManagementController
│   │       ├── Driver/     # OnboardingController
│   │       └── Passenger/  # ProfileController
│   ├── Middleware/      # EnsureUserType, EnsureAdminRole, EnsureDriverApproved
│   ├── Requests/        # Form request validators by domain
│   └── Resources/       # API resources (UserResource, DriverResource, ...)
├── Models/             # Eloquent models
├── Providers/          # Service providers
└── Services/           # Business logic (OtpService, LogSmsGateway)

database/
├── factories/          # Model factories for testing
├── migrations/         # Database migrations
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

- **Auth/OtpAuthTest** — OTP request, verify, new user creation, existing user login, invalid/expired codes, logout
- **Auth/AdminAuthTest** — Admin login, invalid credentials, non-admin rejection, deactivated admin, me endpoint, logout
- **Driver/OnboardingTest** — Onboarding status, profile update, document upload/replace, vehicle registration, role enforcement
- **Passenger/ProfileTest** — Profile read/update, role enforcement
- **Admin/DriverManagementTest** — List/filter drivers, approve/reject, suspend/reactivate, role enforcement

---

## Environment Configuration

Key `.env` variables:

```
DB_CONNECTION=pgsql
DB_DATABASE=etigo-api

# Redis (for OTP caching, driver locations — future)
REDIS_HOST=127.0.0.1

# SMS Gateway (swap LogSmsGateway for real provider)
# SMS_PROVIDER=twilio
# TWILIO_SID=...
# TWILIO_AUTH_TOKEN=...
# TWILIO_FROM=...
```

---

## Seeded Admin Accounts (Development)

| Email              | Password   | Role             |
|--------------------|------------|------------------|
| admin@etigo.com    | password   | super_admin      |
| safety@etigo.com   | password   | safety_operator  |
| ops@etigo.com      | password   | operations       |

Run `php artisan db:seed` to create these accounts.

---

## PRD Task Coverage

This implementation covers the following PRD tasks:

| Task ID      | Description                         | Status |
|--------------|-------------------------------------|--------|
| BE-AUTH-01   | Shared OTP auth service             | Done   |
| BE-AUTH-02   | Session/token management            | Done   |
| SETUP-05     | RBAC middleware + Safety Operator    | Done   |
| SETUP-06     | Immutable audit log                 | Done   |
| PA-AUTH-01   | Phone input + OTP request           | Done   |
| PA-AUTH-02   | OTP verify + account creation       | Done   |
| PA-AUTH-03   | Session persistence + logout        | Done   |
| DA-KYC-01   | Driver sign-up via shared OTP       | Done   |
| DA-KYC-02   | Document upload                     | Done   |
| DA-KYC-03   | KYC status display + gate           | Done   |
| DA-KYC-04   | Vehicle profile                     | Done   |
| AD-DRV-01   | Review driver KYC submissions       | Done   |
| AD-DRV-02   | Suspend/reactivate driver           | Done   |
