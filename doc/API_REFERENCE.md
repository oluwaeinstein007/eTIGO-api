# E-tiGo API Reference

**Base URL:** `{APP_URL}/api/v1`  
**Auth:** Bearer token via Laravel Sanctum (`Authorization: Bearer {token}`)  
**Content-Type:** `application/json` (except file uploads: `multipart/form-data`)

---

## Authentication

### OTP Authentication (Passenger & Driver)

#### Request OTP
```
POST /auth/otp/request
```

| Field | Type   | Required | Description                              |
|-------|--------|----------|------------------------------------------|
| phone | string | Yes      | E.164 format (e.g. `+2341234567890`)     |
| type  | string | Yes      | `passenger` or `driver`                  |

**Response 200:**
```json
{
  "message": "Verification code sent successfully."
}
```

---

#### Verify OTP
```
POST /auth/otp/verify
```

| Field      | Type   | Required              | Description                       |
|------------|--------|-----------------------|-----------------------------------|
| phone      | string | Yes                   | E.164 format                      |
| code       | string | Yes                   | 6-digit OTP code                  |
| type       | string | Yes                   | `passenger` or `driver`           |
| first_name | string | Yes (new users only)  | First name                        |
| last_name  | string | Yes (new users only)  | Last name                         |

**Response 201 (new user):**
```json
{
  "message": "Account created successfully.",
  "user": {
    "id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "phone": "+2341234567890",
    "type": "passenger",
    "phone_verified_at": "2026-09-29T10:00:00.000000Z",
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

**Response 422 (invalid OTP):**
```json
{
  "message": "Invalid verification code."
}
```

---

#### Logout
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
    "email": null,
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
Same fields as register. Resets vehicle class approval.

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

---

### Suspend Driver
```
POST /admin/drivers/{driver_id}/suspend
```
Force-sets driver offline and blocks them from accepting requests.

---

### Reactivate Driver
```
POST /admin/drivers/{driver_id}/reactivate
```
Restores driver to approved status.

---

## Error Responses

All errors follow a consistent format:

```json
{
  "message": "Description of the error."
}
```

Validation errors (422):
```json
{
  "message": "The phone field is required.",
  "errors": {
    "phone": ["The phone field is required."]
  }
}
```

| Code | Description            |
|------|------------------------|
| 401  | Unauthenticated        |
| 403  | Forbidden / wrong role |
| 404  | Resource not found     |
| 409  | Conflict               |
| 422  | Validation error       |
| 500  | Server error           |

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
