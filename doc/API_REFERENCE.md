# E-tiGo API Reference

**Base URL:** `{APP_URL}/api/v1`  
**Auth:** Bearer token via Laravel Sanctum (`Authorization: Bearer {token}`)  
**Content-Type:** `application/json` (except file uploads: `multipart/form-data`)  
**Rate Limiting:** Auth endpoints (`/auth/*`, `/admin/auth/*`) are limited to 5 requests/minute per IP — exceeding returns `429 Too Many Requests`

---

## Authentication — Passenger & Driver (Phone OTP + Social OAuth)

### Send OTP

```
POST /auth/otp/send
```

| Field | Type   | Required | Description                          |
|-------|--------|----------|--------------------------------------|
| phone | string | Yes      | E.164 format (e.g. `+2341234567890`) |

**Response 200:**
```json
{
  "message": "Verification code sent.",
  "expires_at": "2026-10-05T12:05:00.000000Z"
}
```

**Response 429:** `Please wait before requesting another code.` (60-second cooldown between requests)

---

### Verify OTP

```
POST /auth/otp/verify
```

| Field | Type   | Required | Description                          |
|-------|--------|----------|--------------------------------------|
| phone | string | Yes      | E.164 format (e.g. `+2341234567890`) |
| code  | string | Yes      | 6-digit verification code            |

**Response 200 (existing user — logged in):**
```json
{
  "message": "Logged in successfully.",
  "is_new_user": false,
  "user": {
    "id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "phone": "+2341234567890",
    "email": "john@example.com",
    "type": "passenger",
    "is_active": true,
    "created_at": "2026-09-29T10:00:00.000000Z"
  },
  "token": "1|abc123..."
}
```

**Response 200 (new user — needs registration):**
```json
{
  "message": "Phone verified. Please complete registration.",
  "is_new_user": true,
  "phone": "+2341234567890"
}
```

**Response 403:** `Your account has been deactivated. Contact support.`  
**Response 422:** `Invalid verification code.` or `No active verification code found.`

---

### Complete Registration

```
POST /auth/register/complete
```

Called after OTP verification when `is_new_user` is `true`. Phone must have been verified within the last 10 minutes.

| Field      | Type   | Required | Description                          |
|------------|--------|----------|--------------------------------------|
| phone      | string | Yes      | The verified phone number            |
| first_name | string | Yes      | First name                           |
| last_name  | string | Yes      | Last name                            |
| email      | string | No       | Email address (unique if provided)   |
| type       | string | Yes      | `passenger` or `driver`              |

**Response 201:**
```json
{
  "message": "Account created successfully.",
  "user": {
    "id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "phone": "+2341234567890",
    "email": "john@example.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-05T12:00:00.000000Z",
    "is_active": true,
    "created_at": "2026-10-05T12:00:30.000000Z"
  },
  "token": "1|abc123..."
}
```

Driver registration automatically creates a `Driver` record with `onboarding` status. Drivers must provide `city_id` (required, must exist in cities table).

**Response 403:** `Phone number not verified. Please verify your phone first.`  
**Response 409:** `An account with this phone number already exists.`

---

### Social Login / Register

```
POST /auth/social
```

The mobile app handles OAuth natively (Google Sign-In SDK, Apple Sign In, Facebook SDK) and sends the token to the backend for server-side verification.

| Field    | Type   | Required | Description                              |
|----------|--------|----------|------------------------------------------|
| provider | string | Yes      | `google`, `apple`, or `facebook`         |
| token    | string | Yes      | ID token (Google/Apple) or access token (Facebook) |
| type     | string | Yes      | `passenger` or `driver`                  |

**Response 200 (existing user — logged in):**
```json
{
  "message": "Logged in successfully.",
  "is_new_user": false,
  "user": { ... },
  "token": "2|def456..."
}
```

**Response 201 (new user — account created):**
```json
{
  "message": "Account created successfully.",
  "is_new_user": true,
  "needs_profile_completion": false,
  "user": { ... },
  "token": "3|ghi789..."
}
```

`needs_profile_completion` is `true` when the provider didn't return the user's name (common with Apple Sign In after first use). The client should prompt for the missing fields.

**Response 401:** `Invalid social login token.`  
**Response 403:** `Your account has been deactivated. Contact support.`

---

### Get Current User

```
GET /auth/me
```
**Auth required.** Returns the authenticated user's profile.

---

### Logout

```
POST /auth/logout
```
**Auth required.** Revokes the current access token.

**Response 200:**
```json
{
  "message": "Logged out successfully."
}
```

---

### Admin Authentication (Email/Password)

#### Admin Login
```
POST /admin/auth/login
```

| Field    | Type   | Required | Description       |
|----------|--------|----------|-------------------|
| email    | string | Yes      | Admin email       |
| password | string | Yes      | Min 8 characters  |

**Response 200:**
```json
{
  "message": "Logged in successfully.",
  "user": {
    "id": 1,
    "first_name": "Super",
    "last_name": "Admin",
    "email": "admin@etigo.com",
    "type": "admin",
    "admin_role": "super_admin",
    ...
  },
  "token": "3|ghi789..."
}
```

**Response 401:** `Invalid credentials.`  
**Response 403:** `Account deactivated.`

---

#### Admin Logout
```
POST /admin/auth/logout
```
**Auth required (admin).**

---

#### Get Current Admin
```
GET /admin/auth/me
```
**Auth required (admin).** Returns the authenticated admin's profile.

---

## Health Check

```
GET /health
```

No authentication required. Returns database and Redis connectivity status.

**Response 200 (all healthy):**
```json
{
  "status": "healthy",
  "checks": {
    "database": true,
    "redis": true
  },
  "timestamp": "2026-09-29T10:00:00.000000Z"
}
```

**Response 503 (degraded):**
```json
{
  "status": "degraded",
  "checks": {
    "database": true,
    "redis": false
  },
  "timestamp": "2026-09-29T10:00:00.000000Z"
}
```

---

## Passenger

All passenger endpoints require `Authorization: Bearer {token}` from a passenger user.

### Get Profile
```
GET /passenger/profile
```

**Response 200:**
```json
{
  "user": {
    "id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "phone": "+2341234567890",
    "email": "john@example.com",
    "type": "passenger",
    "is_active": true,
    ...
  }
}
```

---

### Update Profile
```
PUT /passenger/profile
```

| Field      | Type   | Required | Description          |
|------------|--------|----------|----------------------|
| first_name | string | No       | Updated first name   |
| last_name  | string | No       | Updated last name    |
| email      | string | No       | Email (unique)       |

**Response 200:**
```json
{
  "message": "Profile updated successfully.",
  "user": { ... }
}
```

---

## Driver

All driver endpoints require `Authorization: Bearer {token}` from a driver user.

### Onboarding Status
```
GET /driver/onboarding/status
```

**Response 200:**
```json
{
  "driver": {
    "id": 1,
    "status": "onboarding",
    "licence_number": null,
    "is_online": false,
    "documents": [],
    "vehicle": null,
    ...
  },
  "onboarding_complete": false,
  "missing_documents": ["driving_licence", "vehicle_registration", "insurance_certificate", "government_id"],
  "has_vehicle": false,
  "has_licence_number": false,
  "kyc_status": "not_started",
  "kyc_verified_at": null
}
```

Documents with `rejected` status are excluded from the uploaded count — a rejected document must be re-uploaded before it counts toward onboarding completion.

---

### Update Driver Profile
```
PUT /driver/profile
```

| Field          | Type   | Required | Description          |
|----------------|--------|----------|----------------------|
| licence_number | string | No       | Driving licence no.  |

---

### Upload KYC Document
```
POST /driver/documents
Content-Type: multipart/form-data
```

| Field    | Type   | Required | Description                                                                        |
|----------|--------|----------|------------------------------------------------------------------------------------|
| type     | string | Yes      | `driving_licence`, `vehicle_registration`, `insurance_certificate`, `government_id` |
| document | file   | Yes      | JPG, PNG, or PDF. Max 10MB.                                                        |

Re-uploading a document of the same type replaces the existing one.

**Response 201:**
```json
{
  "message": "Document uploaded successfully.",
  "document": {
    "id": 1,
    "type": "driving_licence",
    "original_filename": "licence.pdf",
    "status": "pending",
    "created_at": "2026-09-29T10:00:00.000000Z"
  }
}
```

---

### List Documents
```
GET /driver/documents
```

---

### Register Vehicle
```
POST /driver/vehicle
```

| Field        | Type    | Required | Description              |
|--------------|---------|----------|--------------------------|
| make         | string  | Yes      | Vehicle make (e.g. Toyota)|
| model        | string  | Yes      | Vehicle model            |
| colour       | string  | Yes      | Vehicle colour           |
| plate_number | string  | Yes      | Plate number (unique)    |
| year         | integer | No       | Year of manufacture      |

**Response 201:**
```json
{
  "message": "Vehicle registered successfully. Plate verification initiated.",
  "vehicle": {
    "id": 1,
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "ABC-1234",
    "year": 2022,
    "vehicle_class": null,
    "vehicle_class_approved": false
  }
}
```

**Response 409:** Vehicle already registered.

---

### Update Vehicle
```
PUT /driver/vehicle
```
Same fields as register, all optional (partial update supported). Resets vehicle class approval.

---

### Get Vehicle
```
GET /driver/vehicle
```

---

## Driver — KYC Verification

All KYC endpoints require `Authorization: Bearer {token}` from a driver user.

KYC verification uses QoreID as the identity verification provider. Drivers must complete NIN verification, driver's license verification, and vehicle plate verification before their `kyc_status` becomes `verified`. Liveness verification is supported but optional for the `verified` status.

Vehicle plate verification auto-triggers when a vehicle is registered or the plate number is updated.

### KYC Status
```
GET /driver/kyc/status
```

Returns the driver's aggregate KYC status and per-type verification summary.

**Response 200:**
```json
{
  "kyc_status": "in_progress",
  "kyc_verified_at": null,
  "verifications": {
    "nin": {
      "status": "verified",
      "verified_at": "2026-10-05T10:00:00.000000Z",
      "failure_reason": null
    },
    "drivers_license": {
      "status": "not_started",
      "verified_at": null,
      "failure_reason": null
    },
    "vehicle_plate": {
      "status": "not_started",
      "verified_at": null,
      "failure_reason": null
    },
    "liveness": {
      "status": "not_started",
      "verified_at": null,
      "failure_reason": null
    }
  }
}
```

**KYC Status values:**

| Value          | Description                                        |
|----------------|----------------------------------------------------|
| `not_started`  | No verifications submitted yet                     |
| `in_progress`  | At least one verification submitted, not all done  |
| `verified`     | NIN + License + Vehicle Plate all verified         |
| `failed`       | At least one required verification failed          |

---

### Verify NIN
```
POST /driver/kyc/verify-nin
```

| Field      | Type   | Required | Description               |
|------------|--------|----------|---------------------------|
| nin_number | string | Yes      | 11-digit NIN number       |

**Response 200 (verified):**
```json
{
  "message": "NIN verified successfully.",
  "verification": {
    "id": 1,
    "type": "nin",
    "status": "verified",
    "verified_at": "2026-10-05T10:00:00.000000Z",
    "failure_reason": null,
    "created_at": "2026-10-05T10:00:00.000000Z"
  }
}
```

**Response 422 (failed):**
```json
{
  "message": "NIN verification failed: Name mismatch.",
  "verification": {
    "id": 1,
    "type": "nin",
    "status": "failed",
    "verified_at": null,
    "failure_reason": "Name mismatch",
    "created_at": "2026-10-05T10:00:00.000000Z"
  }
}
```

**Response 409:** `"A NIN verification is already completed or in progress."` — duplicate prevention.

---

### Verify Driver's License
```
POST /driver/kyc/verify-license
```

| Field          | Type   | Required | Description                   |
|----------------|--------|----------|-------------------------------|
| license_number | string | Yes      | License number (6–20 chars)   |

**Response 200 (verified):**
```json
{
  "message": "Driver's license verified successfully.",
  "verification": {
    "id": 2,
    "type": "drivers_license",
    "status": "verified",
    "verified_at": "2026-10-05T10:00:00.000000Z",
    "failure_reason": null,
    "created_at": "2026-10-05T10:00:00.000000Z"
  }
}
```

**Response 422:** Verification failed.  
**Response 409:** Already completed or in progress.

---

### Verify Vehicle Plate
```
POST /driver/kyc/verify-vehicle
```

| Field        | Type   | Required | Description                   |
|--------------|--------|----------|-------------------------------|
| plate_number | string | Yes      | Plate number (3–20 chars)     |

Requires a registered vehicle. Returns 422 with `"Register a vehicle before verifying its plate."` if no vehicle exists.

**Response 200 (verified):**
```json
{
  "message": "Vehicle plate verified successfully.",
  "verification": {
    "id": 3,
    "type": "vehicle_plate",
    "status": "verified",
    "verified_at": "2026-10-05T10:00:00.000000Z",
    "failure_reason": null,
    "created_at": "2026-10-05T10:00:00.000000Z"
  }
}
```

**Response 422:** Verification failed or no vehicle registered.  
**Response 409:** Already completed or in progress.

**Auto-trigger:** Vehicle plate verification is automatically dispatched as a background job when:
- A new vehicle is registered (`POST /driver/vehicle`)
- A vehicle's plate number is updated (`PUT /driver/vehicle`)

When a plate number changes, existing plate verifications are expired and a new one is dispatched.

---

### Create Liveness Session
```
POST /driver/kyc/liveness-session
```

No request body. Creates a QoreID liveness session for the mobile SDK.

**Response 201:**
```json
{
  "message": "Liveness session created. Use the SDK token in the mobile app.",
  "session_id": "etigo_kyc_1_1696500000",
  "sdk_token": "eyJhbGciOi...",
  "expires_at": "2026-10-05T11:00:00.000000Z",
  "verification": {
    "id": 4,
    "type": "liveness",
    "status": "processing",
    "verified_at": null,
    "failure_reason": null,
    "created_at": "2026-10-05T10:00:00.000000Z"
  }
}
```

The mobile app uses the `sdk_token` to initialize the QoreID SDK. Results are delivered via webhook.

**Response 409:** Already completed or in progress.

---

### List Verifications
```
GET /driver/kyc/verifications
```

Returns all KYC verifications for the authenticated driver, newest first.

**Response 200:**
```json
{
  "verifications": [
    {
      "id": 1,
      "type": "nin",
      "status": "verified",
      "verified_at": "2026-10-05T10:00:00.000000Z",
      "failure_reason": null,
      "created_at": "2026-10-05T10:00:00.000000Z"
    }
  ]
}
```

---

### KYC Webhook (QoreID)
```
POST /webhooks/qoreid
```

**No authentication** — verified by HMAC-SHA256 signature (`X-QoreID-Signature` header) using the configured webhook secret. Receives liveness verification results from QoreID.

Handled events: `verification_completed`, `step_verification_completed`, `identity`.

**Response 200:** `{"message": "Webhook processed."}`  
**Response 401:** Invalid signature.  
**Response 400:** Missing session ID.

---

## Admin — Driver Management

All admin endpoints require `Authorization: Bearer {token}` from an admin user.

### List All Drivers
```
GET /admin/drivers
```

| Query Param | Type   | Required | Description                                          |
|-------------|--------|----------|------------------------------------------------------|
| status      | string | No       | Filter: `onboarding`, `pending_review`, `approved`, `rejected`, `suspended` |
| kyc_status  | string | No       | Filter: `not_started`, `in_progress`, `verified`, `failed`    |

**Response 200:**
```json
{
  "drivers": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
}
```

---

### List Pending Review Drivers
```
GET /admin/drivers/pending
```

---

### Get Single Driver
```
GET /admin/drivers/{driver_id}
```

---

### Review Driver (Approve/Reject)
```
POST /admin/drivers/{driver_id}/review
```

| Field            | Type   | Required                | Description         |
|------------------|--------|-------------------------|---------------------|
| action           | string | Yes                     | `approve` or `reject`|
| rejection_reason | string | Yes (when rejecting)    | Reason for rejection |

**Response 422:** `"Driver can only be reviewed when in pending review status."` — returned when the driver's status is not `pending_review`.

---

### Toggle Fleet Vehicle
```
PATCH /admin/drivers/{driver_id}/vehicle/fleet
```

Toggles a driver's vehicle as fleet-owned (company vehicle). Fleet vehicles skip plate verification in the KYC flow — only NIN and Driver's License are required.

No request body required.

**Response 200 (marked as fleet):**
```json
{
  "message": "Vehicle marked as fleet. Plate verification skipped.",
  "vehicle": {
    "id": 1,
    "make": "Toyota",
    "model": "Corolla",
    "plate_number": "FLEET-001",
    "is_fleet": true,
    ...
  }
}
```

**Response 200 (unmarked):**
```json
{
  "message": "Vehicle unmarked as fleet. Plate verification now required.",
  "vehicle": { ... }
}
```

**Response 422:** `"Driver has no registered vehicle."`

After toggling, the driver's `kyc_status` is automatically recalculated. If the vehicle is marked fleet and NIN + License are already verified, `kyc_status` becomes `verified` without plate verification.

---

### Suspend Driver
```
POST /admin/drivers/{driver_id}/suspend
```
Force-sets driver offline and blocks them from accepting requests.

No request body required.

**Response 422:** `"Only approved drivers can be suspended."` — returned when the driver is not in `approved` status.

---

### Reactivate Driver
```
POST /admin/drivers/{driver_id}/reactivate
```
Restores driver to approved status.

**Response 422:** `"Only suspended drivers can be reactivated."` — returned when the driver is not in `suspended` status.

---

## Public — Cities

### List Active Cities
```
GET /cities
```
Returns all active cities. No authentication required.

**Response 200:**
```json
{
  "cities": [
    {
      "id": 1,
      "name": "Lagos",
      "slug": "lagos",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 30 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "created_at": "2026-09-29T10:00:00.000000Z",
      "updated_at": "2026-09-29T10:00:00.000000Z"
    }
  ]
}
```

---

### List City Vehicle Classes
```
GET /cities/{city_id}/vehicle-classes
```
Returns active vehicle classes available in a city. No authentication required. Filters by both pivot `is_active` and global `is_active`.

**Response 200:**
```json
{
  "vehicle_classes": [
    {
      "id": 1,
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon_url": null,
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "created_at": "2026-09-29T10:00:00.000000Z",
      "updated_at": "2026-09-29T10:00:00.000000Z"
    }
  ]
}
```

---

## Admin — City Management

**Middleware:** `auth:sanctum`, `user.type:admin`

### List Cities
```
GET /admin/cities
```
| Query Param | Type    | Description                       |
|-------------|---------|-----------------------------------|
| is_active   | boolean | Filter by active/inactive status  |
| search      | string  | Filter by city name (partial)     |
| per_page    | integer | Results per page (default: 20)    |

**Response 200:** Paginated list of cities with `meta`.

---

### Create City
```
POST /admin/cities
```
| Field         | Type   | Required | Description                             |
|---------------|--------|----------|-----------------------------------------|
| name          | string | Yes      | Unique city name                        |
| boundary      | object | No       | GeoJSON with `type`, `coordinates`, optional `radius_km` |
| timezone      | string | Yes      | Valid PHP timezone (e.g. `Africa/Lagos`) |
| currency_code | string | Yes      | 3-letter currency code (e.g. `NGN`)     |

**Response 201:**
```json
{
  "message": "City created successfully.",
  "city": { "id": 1, "name": "Lagos", "slug": "lagos", "..." : "..." }
}
```

---

### Get City
```
GET /admin/cities/{city_id}
```
Returns city with loaded vehicle classes.

---

### Update City
```
PUT /admin/cities/{city_id}
```
Same fields as create, all optional (partial update supported). Slug auto-regenerated if name changes.

---

### Toggle City Status
```
PATCH /admin/cities/{city_id}/status
```
Toggles `is_active` between true and false. Creates audit log entry.

---

### Update City Vehicle Classes
```
PUT /admin/cities/{city_id}/vehicle-classes
```
| Field                              | Type    | Required | Description                    |
|------------------------------------|---------|----------|--------------------------------|
| vehicle_classes                    | array   | Yes      | Array of vehicle class entries |
| vehicle_classes.*.vehicle_class_id | integer | Yes      | Must exist in vehicle_classes  |
| vehicle_classes.*.is_active        | boolean | Yes      | Enable/disable in this city    |
| vehicle_classes.*.sort_order       | integer | No       | Display order (0–999, default 0)|

Syncs the pivot table — entries not included are removed.

**Response 200:**
```json
{
  "message": "City vehicle classes updated successfully.",
  "city": { "id": 1, "name": "Lagos", "vehicle_classes": ["..."] }
}
```

---

## Admin — Vehicle Class Management

**Middleware:** `auth:sanctum`, `user.type:admin`

### List Vehicle Classes
```
GET /admin/vehicle-classes
```
| Query Param | Type    | Description                      |
|-------------|---------|----------------------------------|
| is_active   | boolean | Filter by active/inactive status |
| per_page    | integer | Results per page (default: 20)   |

**Response 200:** Paginated list of vehicle classes with `meta`.

---

### Create Vehicle Class
```
POST /admin/vehicle-classes
```
| Field        | Type    | Required | Description                          |
|--------------|---------|----------|--------------------------------------|
| name         | string  | Yes      | Unique internal name (e.g. `economy`) |
| display_name | string  | Yes      | User-facing name (e.g. `Economy`)    |
| capacity     | integer | Yes      | Passenger capacity (1–20)            |
| icon_url     | string  | No       | URL to vehicle class icon            |
| description  | string  | No       | Description text (max 1000 chars)    |

**Response 201:**
```json
{
  "message": "Vehicle class created successfully.",
  "vehicle_class": { "id": 1, "name": "economy", "display_name": "Economy", "..." : "..." }
}
```

---

### Get Vehicle Class
```
GET /admin/vehicle-classes/{vehicle_class_id}
```

---

### Update Vehicle Class
```
PUT /admin/vehicle-classes/{vehicle_class_id}
```
Same fields as create, all optional (partial update supported).

---

## City Detection

### Detect City by Location
```
GET /cities/detect
```

No authentication required. Detects which active city a set of coordinates falls within, using reverse geocoding followed by boundary matching.

| Query Param | Type   | Required | Description                           |
|-------------|--------|----------|---------------------------------------|
| lat         | number | Yes      | Latitude (-90 to 90)                  |
| lng         | number | Yes      | Longitude (-180 to 180)               |

**Response 200:**
```json
{
  "city": {
    "id": 1,
    "name": "Lagos",
    "slug": "lagos",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 30 },
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "created_at": "2026-09-29T10:00:00.000000Z",
    "updated_at": "2026-09-29T10:00:00.000000Z"
  },
  "resolved_address": "123 Herbert Macaulay Way, Yaba, Lagos, Nigeria"
}
```

**Response 404:**
```json
{
  "message": "No active city found for this location.",
  "resolved_address": "123 Main Street, Unknown Town, Nigeria"
}
```

---

## Ride Estimates

### Get Fare Estimates
```
POST /rides/estimate
```

**Auth required.** Returns fare estimates for all active vehicle classes in the specified city. Detects cross-city rides and long-distance trips, returning warnings when applicable. Pickup city pricing always applies.

| Field           | Type   | Required | Description                |
|-----------------|--------|----------|----------------------------|
| city_id         | integer| Yes      | Must exist in cities table and be active |
| pickup_lat      | number | Yes      | Pickup latitude (-90 to 90)|
| pickup_lng      | number | Yes      | Pickup longitude (-180 to 180)|
| destination_lat | number | Yes      | Destination latitude       |
| destination_lng | number | Yes      | Destination longitude      |

**Validation rules:**
- `city_id` must reference an **active** city — inactive cities return 422
- Pickup and destination coordinates cannot be identical — returns 422

**Response 200:**
```json
{
  "estimates": [
    {
      "vehicle_class": {
        "id": 1,
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4,
        "icon_url": null
      },
      "fare_estimate": "6150.00",
      "distance_km": 10.0,
      "duration_minutes": 25.0,
      "currency": "NGN",
      "pickup_address": "123 Wuse 2, Abuja, Nigeria",
      "destination_address": "456 Garki, Abuja, Nigeria",
      "pricing_snapshot": {
        "pricing_config_id": 1,
        "version": 1,
        "base_fare": "600.00",
        "per_km_rate": "250.00",
        "per_minute_rate": "40.00",
        "minimum_fare": "1500.00",
        "waiting_time_rate": "50.00",
        "free_waiting_minutes": 5,
        "effective_from": "2026-09-30T00:00:00+00:00",
        "captured_at": "2026-09-30T10:00:00+00:00"
      },
      "waiting_time_policy": {
        "free_minutes": 5,
        "per_minute_rate": "50.00"
      },
      "surge": {
        "active": true,
        "multiplier": 1.5,
        "rule_name": "Morning Rush Hour"
      }
    }
  ],
  "warnings": [
    "This is a cross-city ride from Abuja to Lagos. Pickup city (Abuja) pricing applies for this trip."
  ],
  "cross_city": {
    "destination_city_id": 2,
    "destination_city_name": "Lagos",
    "pickup_city_name": "Abuja",
    "warning": "This is a cross-city ride from Abuja to Lagos. Pickup city (Abuja) pricing applies for this trip."
  }
}
```

The `surge` object is always included. When no surge is active, `active` is `false` and `multiplier` is `1`. The `fare_estimate` already includes the surge multiplier applied.

**Edge case fields (conditional):**

| Field       | Included When                                     | Description                                                       |
|-------------|---------------------------------------------------|-------------------------------------------------------------------|
| `warnings`  | Cross-city ride, destination outside service area, or distance > 100 km | Array of human-readable warning strings for the mobile app to display |
| `cross_city`| Destination is in a different city or outside all service areas | Object with pickup/destination city details                        |

**Warning scenarios:**
- **Cross-city ride:** Destination is in a different E-tiGo service city. Pickup city pricing applies.
- **Outside service area:** Destination is not in any active E-tiGo city. Pickup city pricing applies, but the driver may not find return trips.
- **Long-distance ride:** Route exceeds 100 km. Fare is estimated and may vary.

---

## Rides

### Create Ride
```
POST /rides
```

**Auth required. Middleware:** `user.type:passenger`

Creates a new ride, snapshots pricing, generates a 4-digit PIN for driver verification, and transitions the ride to `searching` state. Only one active ride per passenger is allowed.

| Field              | Type    | Required | Description                         |
|--------------------|---------|----------|-------------------------------------|
| city_id            | integer | Yes      | Must exist and be active            |
| vehicle_class_id   | integer | Yes      | Must be active in the specified city |
| pickup_lat         | number  | Yes      | Pickup latitude (-90 to 90)         |
| pickup_lng         | number  | Yes      | Pickup longitude (-180 to 180)      |
| pickup_address     | string  | Yes      | Human-readable pickup address       |
| destination_lat    | number  | Yes      | Destination latitude                |
| destination_lng    | number  | Yes      | Destination longitude               |
| destination_address| string  | Yes      | Human-readable destination address  |
| payment_method     | string  | Yes      | `cash` or `card`                    |

**Validation rules:**
- City must be active
- Pickup and destination cannot be identical coordinates
- Vehicle class must be available and active in the city

**Response 201:**
```json
{
  "message": "Ride created successfully.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "city_id": 1,
    "vehicle_class_id": 1,
    "passenger_id": 1,
    "driver_id": null,
    "pickup": {
      "lat": "6.5244000",
      "lng": "3.3792000",
      "address": "123 Test Street"
    },
    "destination": {
      "lat": "6.4541000",
      "lng": "3.3947000",
      "address": "456 Dest Street"
    },
    "status": "searching",
    "status_label": "Searching for Driver",
    "pin_code": "7249",
    "share_token": "abc123xyz...",
    "fare_estimate_amount": "3750.00",
    "final_fare_amount": null,
    "fare_currency": "NGN",
    "payment_method": "cash",
    "payment_status": "pending",
    "created_at": "2026-10-06T10:00:00.000000Z"
  },
  "pin_code": "7249"
}
```

**Response 409:** `You already have an active ride. Please complete or cancel it first.`
**Response 422:** Validation errors

---

### List Rides
```
GET /rides
```

**Auth required.** Passengers see their own rides, drivers see rides assigned to them, and admins see all rides. Supports filtering and pagination.

| Query Param | Type    | Description                          |
|-------------|---------|--------------------------------------|
| status      | string  | Filter by ride status enum value     |
| city_id     | integer | Filter by city                       |
| from_date   | date    | Filter rides from this date          |
| to_date     | date    | Filter rides until this date         |
| per_page    | integer | Results per page (default: 15)       |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "...",
      "status": "completed",
      "status_label": "Completed",
      "pickup": { "lat": "6.52", "lng": "3.37", "address": "..." },
      "destination": { "lat": "6.45", "lng": "3.39", "address": "..." },
      "fare_estimate_amount": "3750.00",
      "final_fare_amount": "3900.00",
      "fare_currency": "NGN",
      "payment_method": "cash",
      "created_at": "2026-10-06T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

---

### Show Ride
```
GET /rides/{ride}
```

**Auth required.** Returns full ride details with state transition history. Passengers see their rides, drivers see assigned rides, admins see any ride. PIN code is only visible to the passenger (and admins).

**Response 200:**
```json
{
  "ride": {
    "id": "...",
    "status": "in_progress",
    "status_label": "In Progress",
    "pickup": { "lat": "6.52", "lng": "3.37", "address": "..." },
    "destination": { "lat": "6.45", "lng": "3.39", "address": "..." },
    "pricing_snapshot": { "..." },
    "state_transitions": [
      {
        "id": 1,
        "from_state": "requested",
        "to_state": "searching",
        "triggered_by_type": "passenger",
        "created_at": "2026-10-06T10:00:00.000000Z"
      }
    ],
    "matched_at": "2026-10-06T10:01:00.000000Z",
    "started_at": "2026-10-06T10:05:00.000000Z"
  }
}
```

**Response 403:** `Unauthorized.`

---

### Cancel Ride
```
POST /rides/{ride}/cancel
```

**Auth required.** Cancels a ride that is in a cancellable state (requested, searching, matched, driver_en_route, driver_arrived). In-progress and completed rides cannot be cancelled.

| Field          | Type   | Required | Description                                    |
|----------------|--------|----------|------------------------------------------------|
| reason         | string | No       | Cancellation reason (see `CancellationReason` enum) |
| reason_details | string | No       | Additional details (max 500 chars)             |

**Response 200:**
```json
{
  "message": "Ride cancelled successfully.",
  "ride": { "..." }
}
```

**Response 403:** `You are not authorized to cancel this ride.`
**Response 422:** `This ride cannot be cancelled in its current state.`

---

### Driver Arrived
```
POST /rides/{ride}/driver-arrived
```

**Auth required. Middleware:** `user.type:driver`

Driver marks arrival at pickup location. Only valid when ride status is `driver_en_route` and the driver is the one assigned to the ride.

**Response 200:**
```json
{
  "message": "Driver arrival confirmed. Waiting for passenger PIN verification.",
  "ride": { "..." }
}
```

**Response 403:** `You are not assigned to this ride.`
**Response 422:** `Cannot mark arrival in current ride state.`

---

### Verify PIN
```
POST /rides/{ride}/verify-pin
```

**Auth required. Middleware:** `user.type:driver`

Driver submits the 4-digit PIN received from the passenger. On success, the ride transitions to `in_progress`. Maximum 3 attempts before lockout.

| Field    | Type   | Required | Description         |
|----------|--------|----------|---------------------|
| pin_code | string | Yes      | 4-digit numeric PIN |

**Response 200:**
```json
{
  "message": "PIN verified. Ride started.",
  "verified": true,
  "ride": { "..." }
}
```

**Response 422:** `Invalid PIN code.` (or `Maximum PIN verification attempts exceeded.`)

---

### Complete Ride
```
POST /rides/{ride}/complete
```

**Auth required. Middleware:** `user.type:driver`

Driver completes the ride. Only valid when ride status is `in_progress`. Dispatches a background job to calculate the final fare based on actual distance/duration.

**Response 200:**
```json
{
  "message": "Ride completed successfully.",
  "ride": { "..." }
}
```

**Response 403:** `You are not assigned to this ride.`
**Response 422:** `Only in-progress rides can be completed.`

---

### Ride Share Link (Public)
```
GET /rides/{ride}/share/{token}
```

**No auth required.** Public endpoint for sharing ride tracking. Returns limited ride info (status, location, vehicle class, driver first name). Share token expires 1 hour after ride completion (returns 410).

**Response 200:**
```json
{
  "ride": {
    "id": "...",
    "status": "in_progress",
    "status_label": "In Progress",
    "pickup": { "lat": "6.52", "lng": "3.37", "address": "..." },
    "destination": { "lat": "6.45", "lng": "3.39", "address": "..." },
    "vehicle_class": { "name": "economy", "display_name": "Economy" },
    "driver": { "first_name": "John" }
  }
}
```

**Response 404:** `Invalid or expired share link.`
**Response 410:** `This share link has expired.`

---

## Admin — Pricing Management

**Middleware:** `auth:sanctum`, `user.type:admin`

### List Pricing Configs
```
GET /admin/pricing
```
| Query Param      | Type    | Description                                    |
|------------------|---------|------------------------------------------------|
| city_id          | integer | Filter by city                                 |
| vehicle_class_id | integer | Filter by vehicle class                        |
| current_only     | boolean | Only show currently effective configs          |
| per_page         | integer | Results per page (default: 20)                 |

**Response 200:** Paginated list of pricing configs with `meta`.

---

### Create Pricing Config
```
POST /admin/pricing
```
| Field               | Type    | Required | Description                              |
|---------------------|---------|----------|------------------------------------------|
| city_id             | integer | Yes      | Must exist in cities table               |
| vehicle_class_id    | integer | Yes      | Must exist in vehicle_classes table      |
| base_fare           | number  | Yes      | Base fare amount (≥0)                    |
| per_km_rate         | number  | Yes      | Rate per kilometre (≥0)                  |
| per_minute_rate     | number  | Yes      | Rate per minute (≥0)                     |
| minimum_fare        | number  | Yes      | Minimum fare charged (≥0)               |
| waiting_time_rate   | number  | No       | Per-minute waiting charge (≥0)           |
| free_waiting_minutes| integer | No       | Free waiting minutes (default: 5)        |
| effective_from      | string  | Yes      | ISO 8601 datetime                        |

Version is auto-incremented per city + vehicle class combination.

**Response 201:**
```json
{
  "message": "Pricing configuration created successfully.",
  "pricing_config": {
    "id": 1,
    "city_id": 1,
    "vehicle_class_id": 1,
    "base_fare": 500.00,
    "per_km_rate": 100.00,
    "per_minute_rate": 20.00,
    "minimum_fare": 700.00,
    "waiting_time_rate": 15.00,
    "free_waiting_minutes": 5,
    "version": 1,
    "effective_from": "2026-10-01T00:00:00.000000Z",
    "city": { "id": 1, "name": "Lagos" },
    "vehicle_class": { "id": 1, "name": "economy" }
  }
}
```

---

### Get Pricing Config
```
GET /admin/pricing/{pricing_config_id}
```
Returns a specific pricing config with city, vehicle class, and creator details.

---

### Get Current Pricing Config
```
GET /admin/pricing/current
```
| Query Param      | Type    | Required | Description          |
|------------------|---------|----------|----------------------|
| city_id          | integer | Yes      | City ID              |
| vehicle_class_id | integer | Yes      | Vehicle class ID     |

Returns the currently effective pricing config for the given city + vehicle class. **Response 404** if none exists.

---

## Admin — Surge Pricing

**Middleware:** `auth:sanctum`, `user.type:admin`

Surge pricing applies a multiplier to fares during high-demand periods (rush hour, rain, events). Three rule types are supported:

| Type           | How It Works                                                         |
|----------------|----------------------------------------------------------------------|
| `manual`       | Admin toggles on/off — for weather, events, emergencies              |
| `time_based`   | Fires on a schedule (days of week + time window) — for rush hours    |
| `demand_based` | Fires when demand/supply ratio exceeds threshold — wired for future  |

**Fare formula with surge:** `fare = max(minimum_fare, base + distance + time + waiting) × surge_multiplier`

### List Surge Rules
```
GET /admin/surge-rules
```
| Query Param      | Type    | Description                         |
|------------------|---------|-------------------------------------|
| city_id          | integer | Filter by city                      |
| vehicle_class_id | integer | Filter by vehicle class             |
| active_only      | boolean | Only show currently active rules    |
| per_page         | integer | Results per page (default: 20)      |

**Response 200:** Paginated list of surge rules with `meta`.

---

### Create Surge Rule
```
POST /admin/surge-rules
```
| Field                                | Type    | Required                      | Description                              |
|--------------------------------------|---------|-------------------------------|------------------------------------------|
| city_id                              | integer | Yes                           | Must exist in cities table               |
| vehicle_class_id                     | integer | No                            | Scope to vehicle class (null = all)      |
| name                                 | string  | Yes                           | Rule name (e.g. "Morning Rush Hour")     |
| type                                 | string  | Yes                           | `manual`, `time_based`, or `demand_based`|
| multiplier                           | number  | Yes                           | Surge multiplier (1.00–5.00)             |
| conditions                           | object  | Depends on type               | Type-specific conditions (see below)     |
| conditions.days_of_week              | array   | Yes (time_based)              | ISO day numbers: 1=Mon, 7=Sun            |
| conditions.start_time                | string  | Yes (time_based)              | HH:mm format (e.g. "07:00")             |
| conditions.end_time                  | string  | Yes (time_based)              | HH:mm format (e.g. "09:00")             |
| conditions.min_demand_supply_ratio   | number  | Yes (demand_based)            | Minimum ratio to trigger (≥1.0)          |
| priority                             | integer | No                            | Higher wins when rules conflict (0–100)  |
| is_active                            | boolean | No                            | Default: true                            |
| effective_from                       | string  | Yes                           | ISO 8601 datetime                        |
| effective_until                      | string  | No                            | ISO 8601 datetime (null = no expiry)     |

**Response 201:**
```json
{
  "message": "Surge rule created successfully.",
  "surge_rule": {
    "id": 1,
    "city_id": 1,
    "vehicle_class_id": null,
    "name": "Morning Rush Hour",
    "type": "time_based",
    "multiplier": "1.50",
    "conditions": {
      "days_of_week": [1, 2, 3, 4, 5],
      "start_time": "07:00",
      "end_time": "09:00"
    },
    "priority": 5,
    "is_active": true,
    "effective_from": "2026-09-30T00:00:00.000000Z",
    "effective_until": null,
    "city": { "id": 1, "name": "Abuja" },
    "created_at": "2026-09-30T10:00:00.000000Z"
  }
}
```

---

### Get Surge Rule
```
GET /admin/surge-rules/{surge_rule_id}
```
Returns a specific surge rule with city, vehicle class, and creator details.

---

### Update Surge Rule
```
PUT /admin/surge-rules/{surge_rule_id}
```
Same fields as create, all optional (partial update). City and vehicle class cannot be changed after creation.

**Response 200:**
```json
{
  "message": "Surge rule updated successfully.",
  "surge_rule": { ... }
}
```

---

### Toggle Surge Rule Status
```
PATCH /admin/surge-rules/{surge_rule_id}/status
```
Toggles `is_active` between true and false. No request body required.

**Response 200:**
```json
{
  "message": "Surge rule activated.",
  "surge_rule": { ... }
}
```

---

### Get Current Surge Multiplier
```
GET /admin/surge-rules/current-multiplier
```
| Query Param      | Type    | Required | Description                    |
|------------------|---------|----------|--------------------------------|
| city_id          | integer | Yes      | City ID                        |
| vehicle_class_id | integer | No       | Vehicle class ID (null = all)  |

Returns the currently active surge multiplier for the given city (and optionally vehicle class).

**Response 200 (surge active):**
```json
{
  "surge": {
    "active": true,
    "multiplier": 1.5,
    "rule_name": "Morning Rush Hour",
    "rule": { ... }
  }
}
```

**Response 200 (no surge):**
```json
{
  "surge": {
    "active": false,
    "multiplier": 1,
    "rule_name": null,
    "rule": null
  }
}
```

---

## Device Tokens

Push notification token registration for mobile apps.

**Middleware:** `auth:sanctum`

### Register Device Token
```
POST /device-tokens
```
| Field    | Type   | Required | Description                         |
|----------|--------|----------|-------------------------------------|
| token    | string | Yes      | FCM/APNs device token (max 500)     |
| platform | string | Yes      | `ios`, `android`, or `web`          |

Uses `updateOrCreate` — re-registering the same token is a no-op.

**Response 201:**
```json
{
  "message": "Device token registered."
}
```

---

### Remove Device Token
```
DELETE /device-tokens
```
| Field    | Type   | Required | Description                         |
|----------|--------|----------|-------------------------------------|
| token    | string | Yes      | FCM/APNs device token               |
| platform | string | Yes      | `ios`, `android`, or `web`          |

**Response 200:**
```json
{
  "message": "Device token removed."
}
```

---

## Notifications

**Middleware:** `auth:sanctum`

### List Notifications
```
GET /notifications
```
Returns paginated notifications for the authenticated user, newest first. Default 20 per page.

**Response 200:** Standard Laravel pagination envelope with `data`, `current_page`, `last_page`, `per_page`, `total`.

---

### Get Unread Count
```
GET /notifications/unread-count
```

**Response 200:**
```json
{
  "unread_count": 5
}
```

---

### Mark Notification as Read
```
PATCH /notifications/{notification_id}/read
```
Only the notification owner can mark it as read. **Response 403** if the notification belongs to another user.

**Response 200:**
```json
{
  "message": "Notification marked as read."
}
```

---

### Mark All Notifications as Read
```
POST /notifications/read-all
```

**Response 200:**
```json
{
  "message": "All notifications marked as read."
}
```

---

## Payment Methods

**Middleware:** `auth:sanctum`

### List Payment Methods
```
GET /payment-methods
```
Returns the authenticated user's saved payment methods, default method first.

**Response 200:**
```json
{
  "payment_methods": [
    {
      "id": 1,
      "user_id": 1,
      "card_last_four": "4081",
      "card_brand": "visa",
      "is_default": true,
      "created_at": "2026-09-30T10:00:00.000000Z",
      "updated_at": "2026-09-30T10:00:00.000000Z"
    }
  ]
}
```

---

### Initialize Payment
```
POST /payments/initialize
```
| Field        | Type   | Required | Description                       |
|--------------|--------|----------|-----------------------------------|
| amount       | number | Yes      | Amount (≥1)                       |
| currency     | string | Yes      | 3-letter currency code (e.g. `NGN`) |
| redirect_url | string | Yes      | URL to redirect after payment     |

**Response 200:**
```json
{
  "payment_link": "https://checkout.flutterwave.com/v3/hosted/pay/...",
  "tx_ref": "ETIGO-ABCDEFGHIJKL"
}
```

---

### Verify Payment
```
GET /payments/{transactionId}/verify
```
Verifies a payment transaction with the gateway. If successful and card details are present, automatically saves the card as a payment method. First saved card becomes the default.

**Response 200:**
```json
{
  "status": "successful",
  "tx_ref": "ETIGO-ABCDEFGHIJKL",
  "amount": 100.00,
  "currency": "NGN",
  "card_last_four": "4081",
  "card_brand": "visa"
}
```

---

### Set Default Payment Method
```
PATCH /payment-methods/{payment_method_id}/default
```
Sets the specified payment method as default. Only the owner can change it. **Response 403** if it belongs to another user.

**Response 200:**
```json
{
  "message": "Default payment method updated."
}
```

---

### Delete Payment Method
```
DELETE /payment-methods/{payment_method_id}
```
Only the owner can delete it. **Response 403** if it belongs to another user.

**Response 200:**
```json
{
  "message": "Payment method removed."
}
```

---

## Payment Webhooks

### Flutterwave Webhook
```
POST /webhooks/flutterwave
```
**No authentication** — verified by `verif-hash` header matching `FLUTTERWAVE_ENCRYPTION_KEY`. Receives payment status updates from Flutterwave and updates the corresponding Payment record.

**Response 401:** Invalid signature.  
**Response 200:** `{"status": "ok"}` on success, `{"status": "ignored"}` if no transaction ID.

---

## Error Responses

All API errors return structured JSON with a machine-readable `error_code`:

```json
{
  "message": "Description of the error.",
  "error_code": "ERROR_CODE"
}
```

Validation errors (422) include field-level details:
```json
{
  "message": "The phone field is required.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "phone": ["The phone field is required."]
  }
}
```

Server errors (500) include debug details only when `APP_DEBUG=true`:
```json
{
  "message": "Internal server error.",
  "error_code": "SERVER_ERROR",
  "details": {
    "exception": "RuntimeException",
    "file": "/app/Http/Controllers/...",
    "line": 42
  }
}
```

| Code | Error Code         | Description            |
|------|--------------------|------------------------|
| 401  | `UNAUTHENTICATED`  | Missing or invalid token |
| 403  | `HTTP_ERROR`       | Forbidden / wrong role |
| 404  | `NOT_FOUND`        | Resource or endpoint not found |
| 409  | `HTTP_ERROR`       | Conflict               |
| 422  | `VALIDATION_ERROR` | Validation error       |
| 429  | `HTTP_ERROR`       | Too many requests      |
| 500  | `SERVER_ERROR`     | Server error (no stack traces in production) |

---

## Enums

### User Types
| Value       | Description                    |
|-------------|--------------------------------|
| `passenger` | Passenger mobile app user      |
| `driver`    | Driver mobile app user         |
| `admin`     | Admin dashboard user           |

### Admin Roles
| Value             | Description                              |
|-------------------|------------------------------------------|
| `super_admin`     | Full platform access                     |
| `operations`      | Operational management access            |
| `safety_operator` | SOS/safety console access                |
| `support`         | Customer support access                  |

### Driver Status
| Value            | Description                              |
|------------------|------------------------------------------|
| `pending_review` | Awaiting admin KYC review                |
| `approved`       | KYC approved, can go online              |
| `rejected`       | KYC rejected (reason provided)           |
| `suspended`      | Account suspended by admin               |

### Document Types
| Value                    | Description            |
|--------------------------|------------------------|
| `driving_licence`        | Driving licence         |
| `vehicle_registration`   | Vehicle registration    |
| `insurance_certificate`  | Insurance certificate   |
| `government_id`          | Government-issued ID    |

### Document Status
| Value      | Description                        |
|------------|------------------------------------|
| `pending`  | Awaiting admin review              |
| `approved` | Approved by admin                  |
| `rejected` | Rejected (reason provided)         |

### Social Providers
| Value    | Description                        |
|----------|------------------------------------|
| `google` | Google OAuth 2.0                   |
| `apple`  | Apple Sign In                      |

### Surge Types
| Value          | Description                                      |
|----------------|--------------------------------------------------|
| `manual`       | Admin-toggled surge (weather, events, emergencies)|
| `time_based`   | Scheduled surge by day of week + time window     |
| `demand_based` | Dynamic surge based on demand/supply ratio       |

### KYC Status (Driver-level)
| Value          | Description                                      |
|----------------|--------------------------------------------------|
| `not_started`  | No verifications submitted yet                   |
| `in_progress`  | At least one verification submitted              |
| `verified`     | All required verifications passed                |
| `failed`       | At least one required verification failed        |

### KYC Verification Type
| Value             | Description                                   |
|-------------------|-----------------------------------------------|
| `nin`             | National Identification Number                |
| `drivers_license` | Driver's license                              |
| `vehicle_plate`   | Vehicle plate number                          |
| `liveness`        | Facial liveness check (via QoreID SDK)        |

### KYC Verification Status
| Value        | Description                                      |
|--------------|--------------------------------------------------|
| `pending`    | Created, not yet sent to provider                |
| `processing` | Sent to provider, awaiting result                |
| `verified`   | Identity confirmed                               |
| `failed`     | Verification failed (reason in failure_reason)   |
| `expired`    | Superseded by a newer verification (e.g. plate change) |

### Ride Status
| Value             | Description                                      |
|-------------------|--------------------------------------------------|
| `requested`       | Ride requested by passenger                      |
| `searching`       | Searching for available drivers                  |
| `matched`         | Driver matched to ride                           |
| `driver_en_route` | Driver is heading to pickup                      |
| `driver_arrived`  | Driver arrived at pickup, waiting for PIN        |
| `in_progress`     | Ride is actively in progress                     |
| `completed`       | Ride completed                                   |
| `cancelled`       | Ride cancelled by passenger, driver, or system   |
| `no_driver_found` | No driver could be matched                       |

**State transitions:**
- `requested` -> `searching`, `cancelled`
- `searching` -> `matched`, `no_driver_found`, `cancelled`
- `matched` -> `driver_en_route`, `cancelled`
- `driver_en_route` -> `driver_arrived`, `cancelled`
- `driver_arrived` -> `in_progress`, `cancelled`
- `in_progress` -> `completed`
- `completed`, `cancelled`, `no_driver_found` -> (terminal states)

### Payment Method
| Value  | Description |
|--------|-------------|
| `cash` | Cash payment on delivery |
| `card` | Card payment via gateway |

### Payment Status
| Value                | Description                              |
|----------------------|------------------------------------------|
| `pending`            | Payment not yet processed                |
| `authorized`         | Card payment authorized                  |
| `captured`           | Card payment captured                    |
| `settled`            | Payment settled                          |
| `refunded`           | Payment refunded                         |
| `failed`             | Payment failed                           |
| `pending_collection` | Cash payment awaiting driver collection  |
| `collected`          | Cash payment collected by driver         |

### Cancellation Reason
| Value                | Description                              |
|----------------------|------------------------------------------|
| `changed_mind`       | Passenger changed their mind             |
| `driver_too_far`     | Driver is too far away                   |
| `wait_too_long`      | Wait time is too long                    |
| `wrong_pickup`       | Wrong pickup location                    |
| `wrong_destination`  | Wrong destination                        |
| `price_changed`      | Price changed                            |
| `found_alternative`  | Found alternative transport              |
| `emergency`          | Emergency                                |
| `driver_no_show`     | Driver did not show up                   |
| `passenger_no_show`  | Passenger did not show up                |
| `vehicle_mismatch`   | Vehicle does not match                   |
| `safety_concern`     | Safety concern                           |
| `other`              | Other reason                             |
| `system_timeout`     | System timeout                           |
