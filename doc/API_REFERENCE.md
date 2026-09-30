# E-tiGo API Reference

**Base URL:** `{APP_URL}/api/v1`  
**Auth:** Bearer token via Laravel Sanctum (`Authorization: Bearer {token}`)  
**Content-Type:** `application/json` (except file uploads: `multipart/form-data`)  
**Rate Limiting:** Auth endpoints (`/auth/*`, `/admin/auth/*`) are limited to 5 requests/minute per IP — exceeding returns `429 Too Many Requests`

---

## Authentication

### Register (Passenger & Driver)

```
POST /auth/register
```

| Field                 | Type   | Required | Description                          |
|-----------------------|--------|----------|--------------------------------------|
| first_name            | string | Yes      | First name                           |
| last_name             | string | Yes      | Last name                            |
| phone                 | string | Yes      | E.164 format (e.g. `+2341234567890`) |
| email                 | string | Yes      | Unique email address                 |
| password              | string | Yes      | Min 8 characters                     |
| password_confirmation | string | Yes      | Must match password                  |
| type                  | string | Yes      | `passenger` or `driver`              |

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
    "is_active": true,
    "created_at": "2026-09-29T10:00:00.000000Z"
  },
  "token": "1|abc123..."
}
```

Driver registration automatically creates a `Driver` record with `pending_review` status.

---

### Login (Passenger & Driver)

```
POST /auth/login
```

| Field    | Type   | Required | Description             |
|----------|--------|----------|-------------------------|
| email    | string | Yes      | Registered email        |
| password | string | Yes      | Account password        |
| type     | string | Yes      | `passenger` or `driver` |

**Response 200:**
```json
{
  "message": "Logged in successfully.",
  "user": { ... },
  "token": "2|def456..."
}
```

**Response 401:** `Invalid credentials.`  
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

### Social Login (Google & Apple)

```
POST /auth/social-login
```

Mobile-first flow: the mobile app handles the OAuth flow and sends the provider access token to this endpoint.

| Field        | Type   | Required | Description                    |
|--------------|--------|----------|--------------------------------|
| provider     | string | Yes      | `google` or `apple`            |
| access_token | string | Yes      | OAuth access token from provider |
| type         | string | Yes      | `passenger` or `driver`        |

**Response 201 (new user):**
```json
{
  "message": "Account created successfully.",
  "user": {
    "id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "type": "passenger",
    "is_active": true,
    "created_at": "2026-09-29T10:00:00.000000Z"
  },
  "token": "1|abc123...",
  "is_new_user": true
}
```

**Response 200 (existing user):**
```json
{
  "message": "Logged in successfully.",
  "user": { ... },
  "token": "2|def456...",
  "is_new_user": false
}
```

**Response 409:** `User type mismatch. This account is registered as a different type.`
**Response 403:** `Your account has been deactivated. Contact support.`
**Response 422:** Invalid provider token or validation error.

Notes:
- If the provider email matches an existing user of the same type, the social account is linked automatically.
- Driver registration via social login creates a `Driver` record with `pending_review` status.
- The `phone` field is not required for social-login-only users.

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
    "status": "pending_review",
    "licence_number": null,
    "is_online": false,
    "documents": [],
    "vehicle": null,
    ...
  },
  "onboarding_complete": false,
  "missing_documents": ["driving_licence", "vehicle_registration", "insurance_certificate", "government_id"],
  "has_vehicle": false,
  "has_licence_number": false
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
  "message": "Vehicle registered successfully.",
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

## Admin — Driver Management

All admin endpoints require `Authorization: Bearer {token}` from an admin user.

### List All Drivers
```
GET /admin/drivers
```

| Query Param | Type   | Required | Description                                          |
|-------------|--------|----------|------------------------------------------------------|
| status      | string | No       | Filter: `pending_review`, `approved`, `rejected`, `suspended` |

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
      "fare_estimate": "4200.00",
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
