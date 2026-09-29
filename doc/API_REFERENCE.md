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
