# E-tiGo API Reference

**Base URL:** `{APP_URL}/api/v1`  
**Auth:** Bearer token via Laravel Sanctum (`Authorization: Bearer {token}`)  
**Content-Type:** `application/json` (except file uploads: `multipart/form-data`)  
**Rate Limiting:** Auth endpoints (`/auth/*`, `/admin/auth/*`) are limited to 5 requests/minute per IP — exceeding returns `429 Too Many Requests`  
**Resource IDs:** All resource IDs (users, drivers, cities, vehicle classes, rides, documents, etc.) are UUIDs (e.g. `"9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b"`). Parameters referencing an ID field accept `string (UUID)` format.

---

## Quick Start

### Seeded Test Accounts

All seeded accounts use the password: **`Qwer!234`**

Run `php artisan db:seed` to populate the database with the test data below.

#### Admin Accounts (email/password login)

| Email | Role | Description |
|-------|------|-------------|
| `admin@etigo.com` | Super Admin | Default super admin |
| `ops@etigo.com` | Operations | Operations manager |
| `safety@etigo.com` | Safety Operator | SOS/safety console |
| `support@etigo.com` | Support | Customer support |

#### Passenger Accounts (OTP login)

| Phone | Email | Name |
|-------|-------|------|
| `+2348100000001` | `ade@demo.etigo.com` | Ade Ogunleye |
| `+2348100000002` | `ngozi@demo.etigo.com` | Ngozi Okafor |
| `+2348100000003` | `emeka@demo.etigo.com` | Emeka Nwosu |
| `+2348100000004` | `funmi@demo.etigo.com` | Funmi Adeyemi |
| `+2348100000005` | `chidi@demo.etigo.com` | Chidi Eze |

#### Driver Accounts (OTP login)

| Phone | Email | Name | Status | Vehicle |
|-------|-------|------|--------|---------|
| `+2348200000001` | `bayo@demo.etigo.com` | Bayo Akinola | Approved (Online) | Toyota Corolla — Economy |
| `+2348200000002` | `kemi@demo.etigo.com` | Kemi Bakare | Approved (Offline) | Honda Accord — Comfort |
| `+2348200000003` | `segun@demo.etigo.com` | Segun Obaseki | Approved (Online) | Mercedes E-Class — Premium |
| `+2348200000004` | `amara@demo.etigo.com` | Amara Nnamdi | Pending Review | Toyota Highlander — SUV |
| `+2348200000005` | `tunde@demo.etigo.com` | Tunde Fashola | Suspended | Nissan Sentra — Economy |
| `+2348200000006` | `ify@demo.etigo.com` | Ify Okoro | Rejected | — |

### Seeded Reference Data

**Cities:** Lagos (active, 40km), Abuja (active, 30km), Port Harcourt (active, 20km), Ibadan (inactive, 20km)

**Vehicle Classes:** Economy (4 pax), Comfort (4 pax), Premium (4 pax), SUV (6 pax)

**Pricing:** Configured per city per vehicle class (base fare ₦600–₦1,200; per-km ₦250–₦500; 5 min free waiting)

**Promo Codes:** `WELCOME50` (50% off, max ₦2,000), `RIDE500` (₦500 flat), `OFFPEAK20` (20% off-peak only)

### Authentication Flow

**Passengers & Drivers:** `POST /auth/otp/send` → `POST /auth/otp/verify` → (if new) `POST /auth/register/complete` → use bearer token

**Social Login (Deep Link Flow — Recommended):** `POST /auth/social/redirect` (with `type` + `state`) → user completes OAuth in browser → OAuth callback redirects to `etigo-{type}:///auth/callback?code={code}&state={state}` → app verifies `state` matches → `POST /auth/social/exchange` with the one-time `code` → use bearer token

**Social Login (Direct Token — Alternative):** `POST /auth/social/redirect` → user completes OAuth in browser/webview → app sends token to `POST /auth/social`

**Admins:** `POST /admin/auth/login` with email & password → use bearer token

### Static OTP (Non-Production Only)

In **local**, **staging**, and **testing** environments, all OTP codes are set to **`123456`**. SMS delivery is skipped entirely — no WhatsApp messages are sent. This allows developers and testers to create and verify accounts freely without OTP costs or delivery delays.

**How to use:**
1. `POST /auth/otp/send` with any valid phone number → returns success immediately
2. `POST /auth/otp/verify` with the same phone and code `123456` → logs in or prompts registration

This static OTP is **disabled in production** — production always generates random codes and sends via SMS.

### Dual Account Support

A single phone number or email can be associated with **both** a passenger account and a driver account — like Uber and Bolt. A user who registers as a passenger with `+234...` can also create a driver account with the same phone number. Each account type has its own auth token, profile, and lifecycle.

**How it works:**
- The `type` field (`passenger` or `driver`) is sent with every auth request (OTP verify, registration, social login) to identify which account the user is accessing.
- The same phone/email produces separate User records — one per type.
- Social accounts (Google, Apple, Facebook) can also be linked to both account types independently.
- Uniqueness is enforced **per type**: two passengers cannot share the same phone, but a passenger and a driver can.

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
  "expires_at": "2026-10-06T14:05:00.000000Z"
}
```

**Response 429:**
```json
{
  "message": "Please wait before requesting another code."
}
```

---

### Verify OTP

```
POST /auth/otp/verify
```

| Field | Type   | Required | Description                          |
|-------|--------|----------|--------------------------------------|
| phone | string | Yes      | E.164 format (e.g. `+2341234567890`) |
| code  | string | Yes      | 6-digit verification code            |
| type  | string | Yes      | `passenger` or `driver`              |

**Response 200 (existing user — logged in):**
```json
{
  "message": "Logged in successfully.",
  "is_new_user": false,
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade@demo.etigo.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  },
  "token": "1|abc123def456ghi789jkl012mno345pqr678stu901"
}
```

**Response 200 (new user — needs registration):**
```json
{
  "message": "Phone verified. Please complete registration.",
  "is_new_user": true,
  "phone": "+2341234567890",
  "type": "passenger"
}
```

**Response 403:**
```json
{
  "message": "Your account has been deactivated. Contact support."
}
```

**Response 422:**
```json
{
  "message": "Invalid verification code.",
  "error_code": "VALIDATION_ERROR"
}
```

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
| email      | string | No       | Email address (unique per account type) |
| type       | string | Yes      | `passenger` or `driver`              |
| city_id    | string (UUID) | Conditional | Required for drivers. Must exist in cities table |

**Response 201 (passenger):**
```json
{
  "message": "Account created successfully.",
  "user": {
    "id": "e5f6a7b8-9c0d-1e2f-3a4b-5c6d7e8f9a0b",
    "first_name": "Yusuf",
    "last_name": "Ibrahim",
    "phone": "+2341234567890",
    "email": "yusuf@example.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-06T14:00:30.000000Z"
  },
  "token": "2|xyz789abc012def345ghi678jkl901mno234pqr567"
}
```

**Response 201 (driver):**
```json
{
  "message": "Account created successfully.",
  "user": {
    "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
    "first_name": "Chinedu",
    "last_name": "Obi",
    "phone": "+2349087654321",
    "email": null,
    "type": "driver",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-06T14:00:30.000000Z"
  },
  "token": "3|mno345pqr678stu901vwx234yza567bcd890efg123"
}
```

Driver registration automatically creates a `Driver` record with `onboarding` status.

**Response 403:**
```json
{
  "message": "Phone number not verified. Please verify your phone first."
}
```

**Response 409:**
```json
{
  "message": "A passenger account with this phone number already exists."
}
```

---

### Social Login — Get Redirect URL (Backend-Initiated)

```
POST /auth/social/redirect
```

Returns the OAuth provider's authorization URL. The mobile app opens this URL in a system browser. After the user authenticates, the OAuth callback redirects to a deep link (`etigo-{type}:///auth/callback?code={code}&state={state}`), returning the user to the app with a one-time authorization code.

This approach keeps OAuth credentials on the server — updating credentials (e.g. rotating a client secret) doesn't require a new app build.

| Field    | Type   | Required | Description                              |
|----------|--------|----------|------------------------------------------|
| provider | string | Yes      | `google`, `apple`, or `facebook`         |
| type     | string | Yes      | `passenger` or `driver`                  |
| state    | string | Yes      | CSRF protection value (16–128 chars). The app generates this randomly, sends it here, and verifies the same value comes back in the deep link callback |

**Response 200:**
```json
{
  "redirect_url": "https://accounts.google.com/o/oauth2/v2/auth?client_id=...&redirect_uri=...&scope=openid+profile+email&response_type=code&state=...",
  "provider": "google"
}
```

**Response 422:**
```json
{
  "message": "The provider field is required.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "provider": ["The provider field is required."]
  }
}
```

---

### Social Login / Register

```
POST /auth/social
```

The mobile app can either:
1. Use the backend-initiated flow (recommended): call `POST /auth/social/redirect` first, then send the obtained token here.
2. Handle OAuth natively (Google Sign-In SDK, Apple Sign In, Facebook SDK) and send the token directly.

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
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade@demo.etigo.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  },
  "token": "4|abc123def456ghi789jkl012mno345pqr678stu901"
}
```

**Response 201 (new user — account created):**
```json
{
  "message": "Account created successfully.",
  "is_new_user": true,
  "needs_profile_completion": false,
  "user": {
    "id": "a7b8c9d0-1e2f-3a4b-5c6d-7e8f9a0b1c2d",
    "first_name": "Ada",
    "last_name": "Nwosu",
    "phone": null,
    "email": "ada.nwosu@gmail.com",
    "type": "passenger",
    "phone_verified_at": null,
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-06T14:01:00.000000Z"
  },
  "token": "5|pqr678stu901vwx234yza567bcd890efg123hij456"
}
```

`needs_profile_completion` is `true` when the provider didn't return the user's name (common with Apple Sign In after first use). The client should prompt for the missing fields.

**Response 401:**
```json
{
  "message": "Invalid social login token."
}
```

**Response 403:**
```json
{
  "message": "Your account has been deactivated. Contact support."
}
```

The same social account (e.g. Google) can be used with both a passenger and driver account independently.

---

### Social Login — Exchange Code (Deep Link Flow)

```
POST /auth/social/exchange
```

Exchanges a one-time authorization code (received via the deep link callback) for a session token. The code is generated by the OAuth callback handler and expires after **5 minutes**. Each code can only be used once.

**Deep link callback format:** `etigo-{type}:///auth/callback?code={code}&state={state}`

| Field | Type   | Required | Description                                    |
|-------|--------|----------|------------------------------------------------|
| code  | string | Yes      | One-time authorization code (exactly 64 chars)  |

**Response 200 (existing user):**
```json
{
  "message": "Logged in successfully.",
  "is_new_user": false,
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "email": "ade@example.com",
    "type": "passenger"
  },
  "token": "1|abc123..."
}
```

**Response 201 (new user):**
```json
{
  "message": "Account created successfully.",
  "is_new_user": true,
  "user": { ... },
  "token": "2|def456..."
}
```

**Response 401:**
```json
{
  "message": "Invalid or expired authorization code."
}
```

---

### Get Current User

```
GET /auth/me
```
**Auth required.** Returns the authenticated user's profile.

**Response 200:**
```json
{
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade@demo.etigo.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": "https://etigo-bucket.s3.amazonaws.com/profile-photos/1/abc123.jpg",
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Super",
    "last_name": "Admin",
    "phone": null,
    "email": "admin@etigo.com",
    "type": "admin",
    "admin_role": "super_admin",
    "phone_verified_at": null,
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  },
  "token": "6|ghi789jkl012mno345pqr678stu901vwx234yza567"
}
```

**Response 401:**
```json
{
  "message": "Invalid credentials."
}
```

**Response 403:**
```json
{
  "message": "Your account has been deactivated. Contact a system administrator."
}
```

---

#### Admin Logout
```
POST /admin/auth/logout
```
**Auth required (admin).**

**Response 200:**
```json
{
  "message": "Logged out successfully."
}
```

---

#### Get Current Admin
```
GET /admin/auth/me
```
**Auth required (admin).** Returns the authenticated admin's profile.

**Response 200:**
```json
{
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Super",
    "last_name": "Admin",
    "phone": null,
    "email": "admin@etigo.com",
    "type": "admin",
    "admin_role": "super_admin",
    "phone_verified_at": null,
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

---

#### Verify Invitation Token
```
GET /admin/auth/invite/verify/{token}
```

**Response 200:**
```json
{
  "message": "Valid invitation.",
  "invitation": {
    "email": "newadmin@etigo.com",
    "admin_role": "operations",
    "expires_at": "2026-10-08T14:00:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "message": "Invalid invitation token."
}
```

**Response 422:**
```json
{
  "message": "This invitation has already been accepted."
}
```

---

#### Accept Invitation
```
POST /admin/auth/invite/accept
```

| Field      | Type   | Required | Description              |
|------------|--------|----------|--------------------------|
| token      | string | Yes      | Invitation token         |
| first_name | string | Yes      | First name               |
| last_name  | string | Yes      | Last name                |
| phone      | string | No       | Phone number             |
| password   | string | Yes      | Min 8 chars, confirmed   |
| password_confirmation | string | Yes | Must match password |

**Response 201:**
```json
{
  "message": "Account created successfully.",
  "user": {
    "id": "b8c9d0e1-2f3a-4b5c-6d7e-8f9a0b1c2d3e",
    "first_name": "Tobi",
    "last_name": "Adeniyi",
    "phone": "+2349012345678",
    "email": "newadmin@etigo.com",
    "type": "admin",
    "admin_role": "operations",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-06T14:00:00.000000Z"
  },
  "token": "7|vwx234yza567bcd890efg123hij456klm789nop012"
}
```

**Response 409:**
```json
{
  "message": "A user with this email already exists."
}
```

**Response 422:**
```json
{
  "message": "Invalid, expired, or already accepted invitation."
}
```

---

#### Forgot Password
```
POST /admin/auth/forgot-password
```

| Field | Type   | Required | Description    |
|-------|--------|----------|----------------|
| email | string | Yes      | Admin email    |

**Response 200:**
```json
{
  "message": "If the email exists in our system, a password reset link has been sent."
}
```

---

#### Reset Password
```
POST /admin/auth/reset-password
```

| Field                  | Type   | Required | Description              |
|------------------------|--------|----------|--------------------------|
| email                  | string | Yes      | Admin email              |
| token                  | string | Yes      | Reset token from email   |
| password               | string | Yes      | New password (min 8)     |
| password_confirmation  | string | Yes      | Must match password      |

**Response 200:**
```json
{
  "message": "Password has been reset successfully."
}
```

**Response 422:**
```json
{
  "message": "Invalid or expired reset token."
}
```

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
  "timestamp": "2026-10-06T14:00:00.000000Z"
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
  "timestamp": "2026-10-06T14:00:00.000000Z"
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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade@demo.etigo.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": "https://etigo-bucket.s3.amazonaws.com/profile-photos/1/abc123.jpg",
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

---

### Update Profile
```
POST /passenger/profile
Content-Type: multipart/form-data
```

| Field         | Type   | Required | Description                          |
|---------------|--------|----------|--------------------------------------|
| first_name    | string | No       | Updated first name                   |
| last_name     | string | No       | Updated last name                    |
| email         | string | No       | Email (unique)                       |
| profile_photo | file   | No       | JPG or PNG. Max 5MB.                 |

**Response 200:**
```json
{
  "message": "Profile updated successfully.",
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade.updated@example.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": "https://etigo-bucket.s3.amazonaws.com/profile-photos/1/def456.jpg",
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "The profile photo field must be an image.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "profile_photo": ["The profile photo field must be an image."]
  }
}
```

---

### Delete Profile Photo
```
DELETE /passenger/profile/photo
```

**Response 200:**
```json
{
  "message": "Profile photo removed successfully.",
  "user": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade@demo.etigo.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-06T14:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "No profile photo to remove."
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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "user": {
      "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
      "first_name": "Bayo",
      "last_name": "Akinola",
      "phone": "+2348200000001",
      "email": "bayo@demo.etigo.com",
      "type": "driver",
      "phone_verified_at": "2026-10-01T10:00:00.000000Z",
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    },
    "city": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    "status": "onboarding",
    "kyc_status": "not_started",
    "kyc_verified_at": null,
    "licence_number": null,
    "is_online": false,
    "approved_at": null,
    "documents": [],
    "vehicle": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  },
  "onboarding_complete": false,
  "can_submit": false,
  "missing_documents": ["driving_licence", "vehicle_registration", "insurance_certificate", "government_id"],
  "has_vehicle": false,
  "has_licence_number": false,
  "has_city": true,
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

| Field          | Type    | Required | Description          |
|----------------|---------|----------|----------------------|
| licence_number | string  | No       | Driving licence no.  |
| city_id        | string (UUID) | No       | Must exist in cities table |

**Response 200:**
```json
{
  "message": "Driver profile updated successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "onboarding",
    "kyc_status": "not_started",
    "kyc_verified_at": null,
    "licence_number": "DL-12345678",
    "is_online": false,
    "approved_at": null,
    "documents": [],
    "vehicle": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

---

### Upload Profile Photo (Driver)
```
POST /driver/profile/photo
Content-Type: multipart/form-data
```

| Field         | Type | Required | Description        |
|---------------|------|----------|--------------------|
| profile_photo | file | Yes      | JPG or PNG. Max 5MB. |

**Response 200:**
```json
{
  "message": "Profile photo updated successfully.",
  "user": {
    "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
    "first_name": "Bayo",
    "last_name": "Akinola",
    "phone": "+2348200000001",
    "email": "bayo@demo.etigo.com",
    "type": "driver",
    "phone_verified_at": "2026-10-01T10:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": "https://etigo-bucket.s3.amazonaws.com/profile-photos/7/ghi789.jpg",
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

---

### Delete Profile Photo (Driver)
```
DELETE /driver/profile/photo
```

**Response 200:**
```json
{
  "message": "Profile photo removed successfully.",
  "user": {
    "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
    "first_name": "Bayo",
    "last_name": "Akinola",
    "phone": "+2348200000001",
    "email": "bayo@demo.etigo.com",
    "type": "driver",
    "phone_verified_at": "2026-10-01T10:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "No profile photo to remove."
}
```

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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "type": "driving_licence",
    "original_filename": "licence.pdf",
    "expires_at": null,
    "status": "pending",
    "created_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

---

### List Documents
```
GET /driver/documents
```

**Response 200:**
```json
{
  "documents": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "type": "driving_licence",
      "original_filename": "licence.pdf",
      "expires_at": null,
      "status": "approved",
      "reviewed_at": "2026-10-06T15:00:00.000000Z",
      "created_at": "2026-10-06T14:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "type": "vehicle_registration",
      "original_filename": "reg.jpg",
      "expires_at": null,
      "status": "pending",
      "reviewed_at": null,
      "created_at": "2026-10-06T14:02:00.000000Z"
    }
  ]
}
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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "ABC-1234",
    "year": 2022,
    "is_fleet": false,
    "vehicle_class_id": null,
    "vehicle_class": null,
    "created_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

**Response 409:**
```json
{
  "message": "Vehicle already registered. Use the update endpoint."
}
```

---

### Update Vehicle
```
PUT /driver/vehicle
```
Same fields as register, all optional (partial update supported). Resets vehicle class approval. If plate number changes, plate verification is re-dispatched.

**Response 200:**
```json
{
  "message": "Vehicle updated successfully.",
  "vehicle": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "make": "Toyota",
    "model": "Camry",
    "colour": "Black",
    "plate_number": "ABC-1234",
    "year": 2023,
    "is_fleet": false,
    "vehicle_class_id": 1,
    "vehicle_class": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon": null,
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    "created_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

**Response 200 (plate changed):**
```json
{
  "message": "Vehicle updated successfully. Plate re-verification initiated.",
  "vehicle": { "..." }
}
```

---

### Get Vehicle
```
GET /driver/vehicle
```

**Response 200:**
```json
{
  "vehicle": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "ABC-1234",
    "year": 2022,
    "is_fleet": false,
    "vehicle_class_id": 1,
    "vehicle_class": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon": null,
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    "created_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "message": "Vehicle not found."
}
```

---

### Submit for Review
```
POST /driver/onboarding/submit
```

Submits the driver application for admin review. Only valid when driver status is `onboarding` or `rejected` and all requirements are met (all documents uploaded, vehicle registered, licence number set, city selected).

**Response 200:**
```json
{
  "message": "Application submitted for review.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "pending_review",
    "kyc_status": "verified",
    "kyc_verified_at": "2026-10-06T13:00:00.000000Z",
    "licence_number": "DL-12345678",
    "is_online": false,
    "approved_at": null,
    "documents": [ "..." ],
    "vehicle": { "..." },
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 422 (incomplete):**
```json
{
  "message": "Cannot submit application. Please complete all required steps.",
  "errors": [
    "Missing documents: insurance_certificate, government_id",
    "Licence number is required."
  ]
}
```

**Response 422 (wrong status):**
```json
{
  "message": "Application can only be submitted from onboarding or rejected status."
}
```

---

## Driver — Online Status

### Toggle Online
```
POST /driver/toggle-online
```
Toggle driver online/offline status. No request body required.

**Requirements:** Driver must be `approved`, have a registered vehicle with an assigned vehicle class, not be `suspended`, and have completed KYC verification (`kyc_status` must be `verified`).

**Response 200 (went online):**
```json
{
  "message": "You are now online.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "approved",
    "kyc_status": "verified",
    "kyc_verified_at": "2026-10-06T13:00:00.000000Z",
    "licence_number": "DL-12345678",
    "is_online": true,
    "approved_at": "2026-10-05T10:00:00.000000Z",
    "vehicle": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "make": "Toyota",
      "model": "Corolla",
      "colour": "White",
      "plate_number": "ABC-1234",
      "year": 2022,
      "is_fleet": false,
      "vehicle_class_id": 1,
      "vehicle_class": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4,
        "icon": null,
        "description": "Affordable rides for everyday trips.",
        "is_active": true,
        "created_at": "2026-10-01T10:00:00.000000Z",
        "updated_at": "2026-10-01T10:00:00.000000Z"
      },
      "created_at": "2026-10-06T14:00:00.000000Z"
    },
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 200 (went offline):**
```json
{
  "message": "You are now offline.",
  "driver": { "...": "(same structure, is_online: false)" }
}
```

**Response 422:**
```json
{
  "message": "Cannot go online.",
  "reasons": [
    "Driver account is not approved.",
    "No vehicle registered.",
    "KYC verification is not complete."
  ]
}
```

---

### Update Driver Location
```
POST /driver/location
```

**Auth required. Middleware:** `user.type:driver`

Send the driver's current GPS position. Rate-limited to 1 request per second per driver. Only accepted from online, approved drivers. The location is stored in Redis (GEOADD) and broadcast via WebSocket to the active ride channel (if any) and the admin rides channel.

**Request body:**
```json
{
  "lat": 9.0579,
  "lng": 7.4951,
  "heading": 45.0,
  "speed": 30.0,
  "timestamp": "2026-10-06T14:15:00Z"
}
```

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `lat` | float | Yes | Between -90 and 90 |
| `lng` | float | Yes | Between -180 and 180 |
| `heading` | float | No | Between 0 and 360 (degrees from north) |
| `speed` | float | No | >= 0 (km/h) |
| `timestamp` | string | No | Valid date/time |

**Response 200:**
```json
{
  "message": "Location updated.",
  "eta": {
    "distance_km": 3.2,
    "duration_minutes": 8.0
  }
}
```

`eta` is `null` when the driver has no active ride. When present, ETA is throttled to recalculate every ~30 seconds to control maps API cost.

**Response 403:**
```json
{
  "message": "Only online, approved drivers can update location."
}
```

**Response 429:**
```json
{
  "message": "Location updates limited to once per second."
}
```

**WebSocket broadcast:** Each location update dispatches a `driver.location.updated` event to:
- `private-ride.{rideId}` — when the driver has an active ride (status: `driver_en_route`, `driver_arrived`, or `in_progress`)
- `private-admin.rides` — always (for admin dashboard live tracking)

**Broadcast payload:**
```json
{
  "driver_id": 1,
  "lat": 9.0579,
  "lng": 7.4951,
  "heading": 45.0,
  "speed": 30.0,
  "timestamp": 1728223200,
  "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
  "eta": {
    "distance_km": 3.2,
    "duration_minutes": 8.0
  }
}
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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "type": "nin",
    "type_label": "National Identification Number",
    "status": "verified",
    "match_data": {},
    "failure_reason": null,
    "verified_at": "2026-10-06T14:00:00+00:00",
    "expires_at": null,
    "created_at": "2026-10-06T14:00:00+00:00"
  }
}
```

**Response 422 (failed):**
```json
{
  "message": "NIN verification failed: Name mismatch.",
  "verification": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "type": "nin",
    "type_label": "National Identification Number",
    "status": "failed",
    "match_data": {},
    "failure_reason": "Name mismatch",
    "verified_at": null,
    "expires_at": null,
    "created_at": "2026-10-06T14:00:00+00:00"
  }
}
```

**Response 409:**
```json
{
  "message": "A NIN verification is already completed or in progress."
}
```

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
    "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
    "type": "drivers_license",
    "type_label": "Driver's License",
    "status": "verified",
    "match_data": {},
    "failure_reason": null,
    "verified_at": "2026-10-06T14:01:00+00:00",
    "expires_at": null,
    "created_at": "2026-10-06T14:01:00+00:00"
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

Requires a registered vehicle.

**Response 200 (verified):**
```json
{
  "message": "Vehicle plate verified successfully.",
  "verification": {
    "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
    "type": "vehicle_plate",
    "type_label": "Vehicle Plate Number",
    "status": "verified",
    "match_data": {},
    "failure_reason": null,
    "verified_at": "2026-10-06T14:02:00+00:00",
    "expires_at": null,
    "created_at": "2026-10-06T14:02:00+00:00"
  }
}
```

**Response 422:**
```json
{
  "message": "Register a vehicle before verifying its plate."
}
```

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
  "expires_at": "2026-10-06T15:00:00.000000Z",
  "verification": {
    "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "type": "liveness",
    "type_label": "Facial Liveness Check",
    "status": "processing",
    "match_data": {},
    "failure_reason": null,
    "verified_at": null,
    "expires_at": "2026-10-06T15:00:00+00:00",
    "created_at": "2026-10-06T14:00:00+00:00"
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
      "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
      "type": "vehicle_plate",
      "type_label": "Vehicle Plate Number",
      "status": "verified",
      "match_data": {},
      "failure_reason": null,
      "verified_at": "2026-10-06T14:02:00+00:00",
      "expires_at": null,
      "created_at": "2026-10-06T14:02:00+00:00"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "type": "drivers_license",
      "type_label": "Driver's License",
      "status": "verified",
      "match_data": {},
      "failure_reason": null,
      "verified_at": "2026-10-06T14:01:00+00:00",
      "expires_at": null,
      "created_at": "2026-10-06T14:01:00+00:00"
    },
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "type": "nin",
      "type_label": "National Identification Number",
      "status": "verified",
      "match_data": {},
      "failure_reason": null,
      "verified_at": "2026-10-06T14:00:00+00:00",
      "expires_at": null,
      "created_at": "2026-10-06T14:00:00+00:00"
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

**Response 200:**
```json
{
  "message": "Webhook processed."
}
```

**Response 401:**
```json
{
  "message": "Invalid signature."
}
```

**Response 400:**
```json
{
  "message": "Missing session ID."
}
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
| status      | string | No       | Filter: `onboarding`, `pending_review`, `approved`, `rejected`, `suspended` |
| kyc_status  | string | No       | Filter: `not_started`, `in_progress`, `verified`, `failed`    |
| search      | string | No       | Search by driver name, email, or phone               |

**Response 200:**
```json
{
  "drivers": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "user": {
        "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
        "first_name": "Bayo",
        "last_name": "Akinola",
        "phone": "+2348200000001",
        "email": "bayo@demo.etigo.com",
        "type": "driver",
        "phone_verified_at": "2026-10-01T10:00:00.000000Z",
        "is_active": true,
        "profile_photo_url": null,
        "created_at": "2026-10-01T10:00:00.000000Z"
      },
      "city": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "Lagos",
        "slug": "lagos",
        "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
        "timezone": "Africa/Lagos",
        "currency_code": "NGN",
        "is_active": true,
        "created_at": "2026-10-01T10:00:00.000000Z",
        "updated_at": "2026-10-01T10:00:00.000000Z"
      },
      "status": "approved",
      "kyc_status": "verified",
      "kyc_verified_at": "2026-10-03T10:00:00.000000Z",
      "licence_number": "DL-12345678",
      "is_online": true,
      "approved_at": "2026-10-04T10:00:00.000000Z",
      "documents": [
        {
          "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
          "type": "driving_licence",
          "original_filename": "licence.pdf",
          "expires_at": null,
          "status": "approved",
          "reviewed_at": "2026-10-04T10:00:00.000000Z",
          "created_at": "2026-10-02T10:00:00.000000Z"
        }
      ],
      "vehicle": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "make": "Toyota",
        "model": "Corolla",
        "colour": "White",
        "plate_number": "ABC-1234",
        "year": 2022,
        "is_fleet": false,
        "vehicle_class_id": 1,
        "vehicle_class": {
          "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
          "name": "economy",
          "display_name": "Economy",
          "capacity": 4,
          "icon": null,
          "description": "Affordable rides for everyday trips.",
          "is_active": true,
          "created_at": "2026-10-01T10:00:00.000000Z",
          "updated_at": "2026-10-01T10:00:00.000000Z"
        },
        "created_at": "2026-10-02T10:00:00.000000Z"
      },
      "created_at": "2026-10-01T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 6
  }
}
```

---

### List Pending Review Drivers
```
GET /admin/drivers/pending
```

Same response structure as List All Drivers, filtered to `pending_review` status.

---

### Get Single Driver
```
GET /admin/drivers/{driver_id}
```

**Response 200:**
```json
{
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "user": { "..." },
    "city": { "..." },
    "status": "approved",
    "kyc_status": "verified",
    "kyc_verified_at": "2026-10-03T10:00:00.000000Z",
    "licence_number": "DL-12345678",
    "is_online": true,
    "approved_at": "2026-10-04T10:00:00.000000Z",
    "documents": [ "..." ],
    "vehicle": { "..." },
    "kyc_verifications": [
      {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "type": "nin",
        "type_label": "National Identification Number",
        "status": "verified",
        "match_data": {},
        "failure_reason": null,
        "verified_at": "2026-10-03T09:00:00+00:00",
        "expires_at": null,
        "created_at": "2026-10-03T09:00:00+00:00"
      }
    ],
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
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

> **KYC Requirement:** A driver cannot be approved unless their KYC verification is complete (`kyc_status` = `verified`). If KYC is incomplete, the approval request will be rejected with a 422 error.

**Response 200 (approved):**
```json
{
  "message": "Driver approved successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "approved",
    "approved_at": "2026-10-06T14:00:00.000000Z",
    "...": "..."
  }
}
```

**Response 200 (rejected):**
```json
{
  "message": "Driver rejected.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "rejected",
    "rejection_reason": "Blurry document photos. Please re-upload clear copies.",
    "...": "..."
  }
}
```

**Response 422:**
```json
{
  "message": "Driver can only be reviewed when in pending review status."
}
```

**Response 422 (KYC incomplete):**
```json
{
  "message": "Driver cannot be approved without completed KYC verification."
}
```

---

### Review Document
```
POST /admin/drivers/{driver_id}/documents/{document_id}/review
```

| Field            | Type   | Required              | Description          |
|------------------|--------|-----------------------|----------------------|
| action           | string | Yes                   | `approve` or `reject` |
| rejection_reason | string | Yes (when rejecting)  | Reason for rejection |

**Response 200:**
```json
{
  "message": "Document approved successfully.",
  "document": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "type": "driving_licence",
    "original_filename": "licence.pdf",
    "expires_at": null,
    "status": "approved",
    "reviewed_at": "2026-10-06T14:00:00.000000Z",
    "created_at": "2026-10-02T10:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "Only pending documents can be reviewed."
}
```

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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "FLEET-001",
    "year": 2022,
    "is_fleet": true,
    "vehicle_class_id": 1,
    "vehicle_class": null,
    "created_at": "2026-10-02T10:00:00.000000Z"
  }
}
```

**Response 200 (unmarked):**
```json
{
  "message": "Vehicle unmarked as fleet. Plate verification now required.",
  "vehicle": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "FLEET-001",
    "year": 2022,
    "is_fleet": false,
    "vehicle_class_id": 1,
    "vehicle_class": null,
    "created_at": "2026-10-02T10:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "Driver has no registered vehicle."
}
```

After toggling, the driver's `kyc_status` is automatically recalculated. If the vehicle is marked fleet and NIN + License are already verified, `kyc_status` becomes `verified` without plate verification.

---

### Suspend Driver
```
POST /admin/drivers/{driver_id}/suspend
```
Force-sets driver offline and blocks them from accepting requests.

No request body required.

**Response 200:**
```json
{
  "message": "Driver suspended successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "suspended",
    "is_online": false,
    "...": "..."
  }
}
```

**Response 422:**
```json
{
  "message": "Only approved drivers can be suspended."
}
```

---

### Reactivate Driver
```
POST /admin/drivers/{driver_id}/reactivate
```
Restores driver to approved status.

**Response 200:**
```json
{
  "message": "Driver reactivated successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "approved",
    "...": "..."
  }
}
```

**Response 422:**
```json
{
  "message": "Only suspended drivers can be reactivated."
}
```

---

## Admin — Passenger Management

All passenger management endpoints require `Authorization: Bearer {token}` from an admin user.

### List Passengers
```
GET /admin/passengers
```

| Parameter | Type   | Required | Description                           |
|-----------|--------|----------|---------------------------------------|
| search    | string | No       | Search by name, email, or phone       |
| is_active | bool   | No       | Filter by active/inactive status      |
| page      | int    | No       | Pagination page number                |

**Response 200:**
```json
{
  "passengers": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "phone": "+2348100000001",
      "email": "ade@demo.etigo.com",
      "type": "passenger",
      "phone_verified_at": "2026-10-01T10:00:00.000000Z",
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 5
  }
}
```

---

### Get Passenger
```
GET /admin/passengers/{passenger_id}
```
Returns passenger profile with ride statistics.

**Response 200:**
```json
{
  "passenger": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "first_name": "Ade",
    "last_name": "Ogunleye",
    "phone": "+2348100000001",
    "email": "ade@demo.etigo.com",
    "type": "passenger",
    "phone_verified_at": "2026-10-01T10:00:00.000000Z",
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  },
  "statistics": {
    "total_rides": 15,
    "completed_rides": 12,
    "cancelled_rides": 3
  }
}
```

**Response 404:**
```json
{
  "message": "User is not a passenger."
}
```

---

### Suspend Passenger
```
POST /admin/passengers/{passenger_id}/suspend
```
Suspends passenger account and revokes all active tokens. No request body required.

**Response 200:**
```json
{
  "message": "Passenger suspended successfully."
}
```

**Response 422:**
```json
{
  "message": "Passenger account is already suspended."
}
```

---

### Reactivate Passenger
```
POST /admin/passengers/{passenger_id}/reactivate
```
Reactivates a suspended passenger account. No request body required.

**Response 200:**
```json
{
  "message": "Passenger reactivated successfully."
}
```

**Response 422:**
```json
{
  "message": "Passenger account is already active."
}
```

---

## Admin — Admin Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:super_admin`

Only super admins can access these endpoints.

### List Admins
```
GET /admin/admins
```

**Response 200:**
```json
{
  "admins": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "first_name": "Super",
      "last_name": "Admin",
      "phone": null,
      "email": "admin@etigo.com",
      "type": "admin",
      "admin_role": "super_admin",
      "phone_verified_at": null,
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "first_name": "Ops",
      "last_name": "Manager",
      "phone": null,
      "email": "ops@etigo.com",
      "type": "admin",
      "admin_role": "operations",
      "phone_verified_at": null,
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "total": 4
  }
}
```

---

### Get Admin
```
GET /admin/admins/{admin_id}
```

**Response 200:**
```json
{
  "admin": {
    "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
    "first_name": "Ops",
    "last_name": "Manager",
    "phone": null,
    "email": "ops@etigo.com",
    "type": "admin",
    "admin_role": "operations",
    "phone_verified_at": null,
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "message": "User is not an admin."
}
```

---

### Invite Admin
```
POST /admin/admins/invite
```

| Field      | Type   | Required | Description                                      |
|------------|--------|----------|--------------------------------------------------|
| email      | string | Yes      | Email for the invitation                         |
| admin_role | string | Yes      | `operations`, `safety_operator`, or `support`    |

**Response 201:**
```json
{
  "message": "Invitation sent successfully.",
  "invitation": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "email": "newadmin@etigo.com",
    "admin_role": "operations",
    "expires_at": "2026-10-08T14:00:00.000000Z"
  }
}
```

**Response 409:**
```json
{
  "message": "A pending invitation already exists for this email."
}
```

---

### List Invitations
```
GET /admin/admins/invitations
```

**Response 200:**
```json
{
  "invitations": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "email": "newadmin@etigo.com",
      "admin_role": "operations",
      "invited_by": 1,
      "accepted_at": null,
      "expires_at": "2026-10-08T14:00:00.000000Z",
      "created_at": "2026-10-06T14:00:00.000000Z",
      "inviter": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "first_name": "Super",
        "last_name": "Admin"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "total": 1
  }
}
```

---

### Resend Invitation
```
POST /admin/admins/invitations/{invitation_id}/resend
```

**Response 200:**
```json
{
  "message": "Invitation resent successfully."
}
```

**Response 422:**
```json
{
  "message": "This invitation has already been accepted."
}
```

---

### Revoke Invitation
```
DELETE /admin/admins/invitations/{invitation_id}
```

**Response 200:**
```json
{
  "message": "Invitation revoked successfully."
}
```

**Response 422:**
```json
{
  "message": "This invitation has already been accepted."
}
```

---

### Update Admin Role
```
PUT /admin/admins/{admin_id}
```

| Field      | Type   | Required | Description                                      |
|------------|--------|----------|--------------------------------------------------|
| admin_role | string | Yes      | `operations`, `safety_operator`, or `support`    |

Cannot modify super admin accounts.

**Response 200:**
```json
{
  "message": "Admin updated successfully.",
  "admin": {
    "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
    "first_name": "Ops",
    "last_name": "Manager",
    "phone": null,
    "email": "ops@etigo.com",
    "type": "admin",
    "admin_role": "safety_operator",
    "phone_verified_at": null,
    "is_active": true,
    "profile_photo_url": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 403:**
```json
{
  "message": "Cannot modify a super admin account."
}
```

---

### Deactivate Admin
```
POST /admin/admins/{admin_id}/deactivate
```

Deactivates the admin account and revokes all tokens. Cannot deactivate super admins or yourself.

**Response 200:**
```json
{
  "message": "Admin deactivated successfully."
}
```

**Response 403:**
```json
{
  "message": "Cannot deactivate a super admin account."
}
```

---

### Reactivate Admin
```
POST /admin/admins/{admin_id}/reactivate
```

**Response 200:**
```json
{
  "message": "Admin reactivated successfully."
}
```

---

### Delete Admin

```
DELETE /admin/admins/{admin_id}
```

Permanently deletes an admin account. Cannot delete yourself or a super admin.

**Response 200:**
```json
{
  "message": "Admin deleted successfully."
}
```

**Response 403 (super admin or self):**
```json
{
  "message": "Cannot delete a super admin."
}
```

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
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "name": "Abuja",
      "slug": "abuja",
      "boundary": { "type": "Point", "coordinates": [7.4951, 9.0579], "radius_km": 30 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    {
      "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
      "name": "Port Harcourt",
      "slug": "port-harcourt",
      "boundary": { "type": "Point", "coordinates": [7.0498, 4.8156], "radius_km": 20 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
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
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon": "lite",
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "pivot": {
        "is_active": true,
        "sort_order": 0
      },
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "name": "comfort",
      "display_name": "Comfort",
      "capacity": 4,
      "icon": "comfort",
      "description": "Premium comfort for a smoother ride.",
      "is_active": true,
      "pivot": {
        "is_active": true,
        "sort_order": 1
      },
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    }
  ]
}
```

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
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "Lagos",
    "slug": "lagos",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-01T10:00:00.000000Z"
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

**Response 200:**
```json
{
  "cities": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "vehicle_classes": [ "..." ],
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 4
  }
}
```

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
  "city": {
    "id": "d4e5f6a7-8b9c-0d1e-2f3a-4b5c6d7e8f9a",
    "name": "Kano",
    "slug": "kano",
    "boundary": { "type": "Point", "coordinates": [8.5167, 12.0022], "radius_km": 25 },
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

---

### Get City
```
GET /admin/cities/{city_id}
```
Returns city with loaded vehicle classes.

**Response 200:**
```json
{
  "city": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "Lagos",
    "slug": "lagos",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "vehicle_classes": [
      {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4,
        "icon": "lite",
        "description": "Affordable rides for everyday trips.",
        "is_active": true,
        "pivot": {
          "is_active": true,
          "sort_order": 0
        },
        "created_at": "2026-10-01T10:00:00.000000Z",
        "updated_at": "2026-10-01T10:00:00.000000Z"
      }
    ],
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

---

### Update City
```
PUT /admin/cities/{city_id}
```
Same fields as create, all optional (partial update supported). Slug auto-regenerated if name changes.

**Response 200:**
```json
{
  "message": "City updated successfully.",
  "city": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "Lagos",
    "slug": "lagos",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 45 },
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

---

### Toggle City Status
```
PATCH /admin/cities/{city_id}/status
```
Toggles `is_active` between true and false. Creates audit log entry.

**Response 200:**
```json
{
  "message": "City deactivated successfully.",
  "city": {
    "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "name": "Ibadan",
    "slug": "ibadan",
    "is_active": false,
    "...": "..."
  }
}
```

---

### Update City Vehicle Classes
```
PUT /admin/cities/{city_id}/vehicle-classes
```
| Field                              | Type    | Required | Description                    |
|------------------------------------|---------|----------|--------------------------------|
| vehicle_classes                    | array   | Yes      | Array of vehicle class entries |
| vehicle_classes.*.vehicle_class_id | string (UUID) | Yes      | Must exist in vehicle_classes  |
| vehicle_classes.*.is_active        | boolean | Yes      | Enable/disable in this city    |
| vehicle_classes.*.sort_order       | integer | No       | Display order (0–999, default 0)|

Syncs the pivot table — entries not included are removed.

**Response 200:**
```json
{
  "message": "City vehicle classes updated successfully.",
  "city": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "Lagos",
    "slug": "lagos",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "vehicle_classes": [
      {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4,
        "icon": "lite",
        "description": "Affordable rides for everyday trips.",
        "is_active": true,
        "pivot": {
          "is_active": true,
          "sort_order": 0
        },
        "created_at": "2026-10-01T10:00:00.000000Z",
        "updated_at": "2026-10-01T10:00:00.000000Z"
      },
      {
        "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
        "name": "comfort",
        "display_name": "Comfort",
        "capacity": 4,
        "icon": "comfort",
        "description": "Premium comfort for a smoother ride.",
        "is_active": true,
        "pivot": {
          "is_active": true,
          "sort_order": 1
        },
        "created_at": "2026-10-01T10:00:00.000000Z",
        "updated_at": "2026-10-01T10:00:00.000000Z"
      }
    ],
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  }
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

**Response 200:**
```json
{
  "vehicle_classes": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon": "lite",
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "enabled_cities": [
        { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos" },
        { "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d", "name": "Abuja" },
        { "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e", "name": "Port Harcourt" }
      ],
      "drivers_count": 37,
      "active_rides_count": 184,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "name": "comfort",
      "display_name": "Comfort",
      "capacity": 4,
      "icon": "comfort",
      "description": "Premium comfort for a smoother ride.",
      "is_active": true,
      "enabled_cities": [
        { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos" },
        { "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d", "name": "Abuja" }
      ],
      "drivers_count": 58,
      "active_rides_count": 92,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 4
  }
}
```

---

### Create Vehicle Class
```
POST /admin/vehicle-classes
```
| Field        | Type    | Required | Description                                  |
|--------------|---------|----------|----------------------------------------------|
| name         | string  | Yes      | Unique internal name (e.g. `economy`)        |
| display_name | string  | Yes      | User-facing name (e.g. `Economy`)            |
| capacity     | integer | Yes      | Passenger capacity (1–20)                    |
| icon         | string  | No       | Icon slug — see **Icon Slugs** below         |
| description  | string  | No       | Description text (max 1000 chars)            |
| is_active    | boolean | No       | Active status (default: true)                |
| city_ids     | array   | No       | Array of city IDs to enable this class in    |

**Icon Slugs:**

| Slug      | Display       |
|-----------|---------------|
| `lite`    | tiGO Lite     |
| `comfort` | tiGO Comfort |
| `xl`      | tiGO XL       |

**Response 201:**
```json
{
  "message": "Vehicle class created successfully.",
  "vehicle_class": {
    "id": "d4e5f6a7-8b9c-0d1e-2f3a-4b5c6d7e8f9a",
    "name": "luxury",
    "display_name": "Luxury",
    "capacity": 4,
    "icon": null,
    "description": "Top-tier luxury experience.",
    "is_active": true,
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

---

### Get Vehicle Class
```
GET /admin/vehicle-classes/{vehicle_class_id}
```

**Response 200:**
```json
{
  "vehicle_class": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "economy",
    "display_name": "Economy",
    "capacity": 4,
    "icon": "lite",
    "description": "Affordable rides for everyday trips.",
    "is_active": true,
    "enabled_cities": [
      { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos" },
      { "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d", "name": "Abuja" },
      { "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e", "name": "Port Harcourt" }
    ],
    "drivers_count": 37,
    "active_rides_count": 184,
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

---

### Update Vehicle Class
```
PUT /admin/vehicle-classes/{vehicle_class_id}
```
| Field        | Type    | Required | Description                                                |
|--------------|---------|----------|------------------------------------------------------------|
| name         | string  | No       | Unique internal name (e.g. `economy`)                      |
| display_name | string  | No       | User-facing name (e.g. `Economy`)                          |
| capacity     | integer | No       | Passenger capacity (1–20)                                  |
| icon         | string  | No       | Icon slug — see **Icon Slugs** above                       |
| description  | string  | No       | Description text (max 1000 chars)                          |
| is_active    | boolean | No       | Toggle active/inactive status globally                     |
| city_ids     | array   | No       | Full set of city IDs to enable — replaces previous cities  |

All fields are optional (partial update supported). Omitting `city_ids` leaves city assignments unchanged; passing it replaces the full set.

**Response 200:**
```json
{
  "message": "Vehicle class updated successfully.",
  "vehicle_class": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "economy",
    "display_name": "tiGO Lite",
    "capacity": 4,
    "icon": "lite",
    "description": "Affordable rides for everyday trips.",
    "is_active": false,
    "enabled_cities": [
      { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos" },
      { "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d", "name": "Abuja" }
    ],
    "drivers_count": 37,
    "active_rides_count": 184,
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  }
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
| city_id         | string (UUID) | Yes      | Must exist in cities table and be active |
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
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4,
        "icon": "lite"
      },
      "fare_estimate": "6150.00",
      "distance_km": 10.0,
      "duration_minutes": 25.0,
      "currency": "NGN",
      "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos, Nigeria",
      "destination_address": "456 Broad Street, Lagos Island, Lagos, Nigeria",
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
        "captured_at": "2026-10-06T14:00:00+00:00"
      },
      "waiting_time_policy": {
        "free_minutes": 5,
        "per_minute_rate": "50.00"
      },
      "surge": {
        "active": false,
        "multiplier": 1,
        "rule_name": null
      }
    },
    {
      "vehicle_class": {
        "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
        "name": "comfort",
        "display_name": "Comfort",
        "capacity": 4,
        "icon": "comfort"
      },
      "fare_estimate": "8500.00",
      "distance_km": 10.0,
      "duration_minutes": 25.0,
      "currency": "NGN",
      "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos, Nigeria",
      "destination_address": "456 Broad Street, Lagos Island, Lagos, Nigeria",
      "pricing_snapshot": {
        "pricing_config_id": 2,
        "version": 1,
        "base_fare": "800.00",
        "per_km_rate": "350.00",
        "per_minute_rate": "50.00",
        "minimum_fare": "2000.00",
        "waiting_time_rate": "60.00",
        "free_waiting_minutes": 5,
        "effective_from": "2026-09-30T00:00:00+00:00",
        "captured_at": "2026-10-06T14:00:00+00:00"
      },
      "waiting_time_policy": {
        "free_minutes": 5,
        "per_minute_rate": "60.00"
      },
      "surge": {
        "active": true,
        "multiplier": 1.5,
        "rule_name": "Morning Rush Hour"
      }
    }
  ]
}
```

**Response 200 (with cross-city warning):**
```json
{
  "estimates": [ "..." ],
  "warnings": [
    "This is a cross-city ride from Abuja to Lagos. Pickup city (Abuja) pricing applies for this trip."
  ],
  "cross_city": {
    "destination_city_id": 1,
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

---

## Rides

### Create Ride
```
POST /rides
```

**Auth required. Middleware:** `user.type:passenger`

Creates a new ride, snapshots pricing, generates a 4-digit PIN for driver verification, and transitions the ride to `searching` state. Only one active ride per passenger is allowed.

On creation, the **matching engine** is automatically triggered: `DispatchRideRequestJob` searches for nearby eligible drivers via Redis GEOSEARCH and dispatches the ride to the nearest candidate. A `MatchingTimeoutJob` is also scheduled as a safety net (default 180s). See [Matching Engine — How It Works](#matching-engine--how-it-works) for details.

| Field              | Type    | Required | Description                         |
|--------------------|---------|----------|-------------------------------------|
| city_id            | string (UUID) | Yes      | Must exist and be active            |
| vehicle_class_id   | string (UUID) | Yes      | Must be active in the specified city |
| pickup_lat         | number  | Yes      | Pickup latitude (-90 to 90)         |
| pickup_lng         | number  | Yes      | Pickup longitude (-180 to 180)      |
| pickup_address     | string  | Yes      | Human-readable pickup address       |
| destination_lat    | number  | Yes      | Destination latitude                |
| destination_lng    | number  | Yes      | Destination longitude               |
| destination_address| string  | Yes      | Human-readable destination address  |
| payment_method     | string  | Yes      | `cash` or `card`                    |

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
      "address": "123 Herbert Macaulay Way, Yaba, Lagos"
    },
    "destination": {
      "lat": "6.4541000",
      "lng": "3.3947000",
      "address": "456 Broad Street, Lagos Island, Lagos"
    },
    "status": "searching",
    "status_label": "Searching for Driver",
    "share_token": "abc123xyz789def456ghi012jkl345mno",
    "fare_estimate_amount": "3750.00",
    "final_fare_amount": null,
    "fare_currency": "NGN",
    "payment_method": "cash",
    "payment_status": "pending",
    "cancellation_reason": null,
    "city": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    "vehicle_class": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon": "lite",
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    "passenger": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "phone": "+2348100000001",
      "email": "ade@demo.etigo.com",
      "type": "passenger",
      "phone_verified_at": "2026-10-01T10:00:00.000000Z",
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    },
    "driver": null,
    "cancelled_by": null,
    "matched_at": null,
    "started_at": null,
    "completed_at": null,
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  },
  "pin_code": "7249"
}
```

**Response 409:**
```json
{
  "message": "You already have an active ride. Please complete or cancel it first."
}
```

**Response 422:**
```json
{
  "message": "The city id field is required.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "city_id": ["The city id field is required."]
  }
}
```

---

### List Rides
```
GET /rides
```

**Auth required.** Passengers see their own rides, drivers see rides assigned to them, and admins see all rides. Supports filtering and pagination.

| Query Param | Type    | Description                          |
|-------------|---------|--------------------------------------|
| status      | string  | Filter by ride status enum value     |
| city_id     | string (UUID) | Filter by city                       |
| from_date   | date    | Filter rides from this date          |
| to_date     | date    | Filter rides until this date         |
| per_page    | integer | Results per page (default: 15)       |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
      "city_id": 1,
      "vehicle_class_id": 1,
      "passenger_id": 1,
      "driver_id": 7,
      "pickup": {
        "lat": "6.5244000",
        "lng": "3.3792000",
        "address": "123 Herbert Macaulay Way, Yaba, Lagos"
      },
      "destination": {
        "lat": "6.4541000",
        "lng": "3.3947000",
        "address": "456 Broad Street, Lagos Island, Lagos"
      },
      "status": "completed",
      "status_label": "Completed",
      "share_token": "abc123xyz789def456ghi012jkl345mno",
      "fare_estimate_amount": "3750.00",
      "final_fare_amount": "3900.00",
      "fare_currency": "NGN",
      "payment_method": "cash",
      "payment_status": "collected",
      "cancellation_reason": null,
      "city": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "Lagos",
        "slug": "lagos",
        "...": "..."
      },
      "vehicle_class": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "...": "..."
      },
      "matched_at": "2026-10-06T14:01:00.000000Z",
      "started_at": "2026-10-06T14:05:00.000000Z",
      "completed_at": "2026-10-06T14:30:00.000000Z",
      "created_at": "2026-10-06T14:00:00.000000Z",
      "updated_at": "2026-10-06T14:30:00.000000Z"
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
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "city_id": 1,
    "vehicle_class_id": 1,
    "passenger_id": 1,
    "driver_id": 7,
    "pickup": {
      "lat": "6.5244000",
      "lng": "3.3792000",
      "address": "123 Herbert Macaulay Way, Yaba, Lagos"
    },
    "destination": {
      "lat": "6.4541000",
      "lng": "3.3947000",
      "address": "456 Broad Street, Lagos Island, Lagos"
    },
    "status": "in_progress",
    "status_label": "In Progress",
    "share_token": "abc123xyz789def456ghi012jkl345mno",
    "fare_estimate_amount": "3750.00",
    "final_fare_amount": null,
    "fare_currency": "NGN",
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
      "captured_at": "2026-10-06T14:00:00+00:00"
    },
    "payment_method": "cash",
    "payment_status": "pending",
    "cancellation_reason": null,
    "city": { "...": "..." },
    "vehicle_class": { "...": "..." },
    "passenger": { "...": "..." },
    "driver": {
      "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
      "first_name": "Bayo",
      "last_name": "Akinola",
      "phone": "+2348200000001",
      "email": "bayo@demo.etigo.com",
      "type": "driver",
      "phone_verified_at": "2026-10-01T10:00:00.000000Z",
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    },
    "cancelled_by": null,
    "state_transitions": [
      {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "from_state": "requested",
        "to_state": "searching",
        "triggered_by_type": "passenger",
        "triggered_by_id": 1,
        "metadata": null,
        "created_at": "2026-10-06T14:00:00.000000Z"
      },
      {
        "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
        "from_state": "searching",
        "to_state": "matched",
        "triggered_by_type": "system",
        "triggered_by_id": null,
        "metadata": null,
        "created_at": "2026-10-06T14:01:00.000000Z"
      },
      {
        "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
        "from_state": "matched",
        "to_state": "driver_en_route",
        "triggered_by_type": "driver",
        "triggered_by_id": 7,
        "metadata": null,
        "created_at": "2026-10-06T14:01:30.000000Z"
      },
      {
        "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
        "from_state": "driver_en_route",
        "to_state": "driver_arrived",
        "triggered_by_type": "driver",
        "triggered_by_id": 7,
        "metadata": null,
        "created_at": "2026-10-06T14:04:00.000000Z"
      },
      {
        "id": "d4e5f6a7-8b9c-0d1e-2f3a-4b5c6d7e8f9a",
        "from_state": "driver_arrived",
        "to_state": "in_progress",
        "triggered_by_type": "driver",
        "triggered_by_id": 7,
        "metadata": null,
        "created_at": "2026-10-06T14:05:00.000000Z"
      }
    ],
    "matched_at": "2026-10-06T14:01:00.000000Z",
    "started_at": "2026-10-06T14:05:00.000000Z",
    "completed_at": null,
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:05:00.000000Z"
  }
}
```

**Response 403:**
```json
{
  "message": "Unauthorized."
}
```

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
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "cancelled",
    "status_label": "Cancelled",
    "cancellation_reason": "changed_mind",
    "cancelled_by": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "...": "..."
    },
    "...": "..."
  }
}
```

**Response 403:**
```json
{
  "message": "You are not authorized to cancel this ride."
}
```

**Response 422:**
```json
{
  "message": "This ride cannot be cancelled in its current state.",
  "current_status": "in_progress"
}
```

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
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "driver_arrived",
    "status_label": "Driver Arrived",
    "...": "..."
  }
}
```

**Response 403:**
```json
{
  "message": "You are not assigned to this ride."
}
```

**Response 422:**
```json
{
  "message": "Cannot mark arrival in current ride state.",
  "current_status": "matched"
}
```

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
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "in_progress",
    "status_label": "In Progress",
    "started_at": "2026-10-06T14:05:00.000000Z",
    "...": "..."
  }
}
```

**Response 422 (wrong PIN):**
```json
{
  "message": "Invalid PIN code.",
  "verified": false
}
```

**Response 422 (max attempts):**
```json
{
  "message": "Maximum PIN verification attempts exceeded.",
  "verified": false
}
```

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
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "completed",
    "status_label": "Completed",
    "fare_estimate_amount": "3750.00",
    "final_fare_amount": null,
    "completed_at": "2026-10-06T14:30:00.000000Z",
    "...": "..."
  }
}
```

Note: `final_fare_amount` is initially `null` and populated asynchronously by the fare calculation job.

**Response 403:**
```json
{
  "message": "You are not assigned to this ride."
}
```

**Response 422:**
```json
{
  "message": "Only in-progress rides can be completed.",
  "current_status": "driver_arrived"
}
```

---

### Accept Ride
```
POST /rides/{ride}/accept
```

**Auth required. Middleware:** `user.type:driver`

Driver accepts a ride that was dispatched to them by the matching engine. Only valid when ride status is `searching` and the ride was dispatched to this specific driver. Uses row-level locking (`SELECT FOR UPDATE`) to prevent concurrent acceptances — first-accept-wins.

On acceptance, the ride transitions through `matched` → `driver_en_route` atomically. The matching engine cache (rejected drivers, dispatch state) is cleaned up.

**Response 200:**
```json
{
  "message": "Ride accepted.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "city_id": 1,
    "vehicle_class_id": 1,
    "passenger_id": 1,
    "driver_id": 2,
    "pickup": {
      "lat": "6.5244000",
      "lng": "3.3792000",
      "address": "123 Herbert Macaulay Way, Yaba, Lagos"
    },
    "destination": {
      "lat": "6.4541000",
      "lng": "3.3947000",
      "address": "456 Broad Street, Lagos Island, Lagos"
    },
    "status": "driver_en_route",
    "status_label": "Driver En Route",
    "share_token": "abc123xyz789def456ghi012jkl345mno",
    "fare_estimate_amount": "3750.00",
    "final_fare_amount": null,
    "fare_currency": "NGN",
    "payment_method": "cash",
    "payment_status": "pending",
    "cancellation_reason": null,
    "city": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "...": "..."
    },
    "vehicle_class": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "...": "..."
    },
    "passenger": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "phone": "+2348100000001",
      "email": "ade@demo.etigo.com",
      "...": "..."
    },
    "driver": {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "first_name": "Chidi",
      "last_name": "Okonkwo",
      "phone": "+2348100000002",
      "email": "chidi@demo.etigo.com",
      "...": "..."
    },
    "cancelled_by": null,
    "matched_at": "2026-10-06T14:01:15.000000Z",
    "started_at": null,
    "completed_at": null,
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:01:15.000000Z"
  }
}
```

**Response 403 (not dispatched to this driver):**
```json
{
  "message": "This ride was not dispatched to you."
}
```

**Response 403 (no driver profile):**
```json
{
  "message": "Driver profile not found."
}
```

**Response 409 (concurrent acceptance — another driver already accepted):**
```json
{
  "message": "Ride is no longer available."
}
```

**Response 422 (ride no longer searching):**
```json
{
  "message": "This ride is no longer available for acceptance.",
  "current_status": "cancelled"
}
```

---

### Reject Ride
```
POST /rides/{ride}/reject
```

**Auth required. Middleware:** `user.type:driver`

Driver rejects a dispatched ride request. The driver is added to the rejected list for this ride and the matching engine automatically re-dispatches to the next nearest eligible driver. No request body required.

**Response 200:**
```json
{
  "message": "Ride request rejected. It will be dispatched to another driver."
}
```

**Response 403 (no driver profile):**
```json
{
  "message": "Driver profile not found."
}
```

**Response 422 (ride no longer searching):**
```json
{
  "message": "This ride is no longer available."
}
```

---

### Matching Engine — How It Works

When a ride is created (`POST /rides`), the system automatically:

1. **Dispatches** `DispatchRideRequestJob` — searches for the nearest eligible driver via Redis GEOSEARCH within the initial radius (default 3km)
2. **Broadcasts** `RideRequestDispatched` event on `driver.{userId}` WebSocket channel + sends push notification to the selected driver
3. **Sets timeout** — `DriverResponseTimeoutJob` fires after the response window (default 20s). If the driver hasn't responded, it's treated as a rejection and the ride re-dispatches to the next candidate
4. **Expands radius** — if no candidates found in current radius, expands by `radius_step_km` (default 2km) up to `max_radius_km` (default 15km)
5. **Final timeout** — `MatchingTimeoutJob` transitions to `no_driver_found` after `matching_timeout` (default 180s) if no match is found

**Eligibility criteria** — a driver must be:
- Online (`is_online = true`)
- Approved (`status = approved`)
- Have a vehicle matching the requested vehicle class
- Not already on another active ride
- Not have previously rejected this specific ride

**WebSocket event — `ride.request.dispatched`** on channel `driver.{userId}`:
```json
{
  "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
  "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
  "destination_address": "456 Broad Street, Lagos Island, Lagos",
  "pickup_lat": 6.5244,
  "pickup_lng": 3.3792,
  "destination_lat": 6.4541,
  "destination_lng": 3.3947,
  "fare_estimate": 3750.00,
  "currency": "NGN",
  "vehicle_class": "Economy",
  "response_timeout_seconds": 20
}
```

**Configuration** (via environment variables):

| Variable | Default | Description |
|----------|---------|-------------|
| `MATCHING_INITIAL_RADIUS_KM` | `3.0` | Starting search radius |
| `MATCHING_RADIUS_STEP_KM` | `2.0` | Expansion per failed dispatch round |
| `MATCHING_MAX_RADIUS_KM` | `15.0` | Maximum search radius |
| `MATCHING_DRIVER_RESPONSE_TIMEOUT` | `20` | Seconds before auto-reject |
| `MATCHING_TIMEOUT` | `180` | Overall matching deadline (seconds) |

---

### Ride Location (Polling Fallback)
```
GET /rides/{ride}/location
```

**Auth required.** Fallback polling endpoint for when WebSocket connection drops. Returns the driver's latest cached location from Redis and a computed ETA. Accessible by the ride's passenger, the assigned driver, or any admin.

**Response 200 (driver location available):**
```json
{
  "location": {
    "lat": 9.0579,
    "lng": 7.4951,
    "heading": 45.0,
    "speed": 30.0,
    "timestamp": 1728223200
  },
  "eta": {
    "distance_km": 3.2,
    "duration_minutes": 8.0
  },
  "ride_status": "driver_en_route"
}
```

ETA target: pickup location when status is `driver_en_route`/`matched`/`driver_arrived`, destination when `in_progress`.

**Response 200 (no driver assigned):**
```json
{
  "message": "No driver assigned to this ride.",
  "location": null,
  "eta": null
}
```

**Response 200 (driver location unavailable):**
```json
{
  "message": "Driver location unavailable.",
  "location": null,
  "eta": null
}
```

**Response 403:**
```json
{
  "message": "Unauthorized."
}
```

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
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "in_progress",
    "status_label": "In Progress",
    "pickup": {
      "lat": "6.5244000",
      "lng": "3.3792000",
      "address": "123 Herbert Macaulay Way, Yaba, Lagos"
    },
    "destination": {
      "lat": "6.4541000",
      "lng": "3.3947000",
      "address": "456 Broad Street, Lagos Island, Lagos"
    },
    "vehicle_class": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "capacity": 4,
      "icon": "lite",
      "description": "Affordable rides for everyday trips.",
      "is_active": true,
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-01T10:00:00.000000Z"
    },
    "driver": {
      "first_name": "Bayo"
    },
    "started_at": "2026-10-06T14:05:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "message": "Invalid or expired share link."
}
```

**Response 410:**
```json
{
  "message": "This share link has expired."
}
```

---

## Admin — Ride Management

**Middleware:** `auth:sanctum`, `user.type:admin`

### Manually Assign Driver to Ride
```
POST /admin/rides/{ride}/assign
```

Manually assigns an online driver to a ride. The ride must be in `searching` or `requested` state. The driver must meet all eligibility criteria. This bypasses the automated matching engine — useful for support escalations or edge cases where automated matching fails.

| Field     | Type    | Required | Description                           |
|-----------|---------|----------|---------------------------------------|
| driver_id | string (UUID) | Yes      | User ID of the driver to assign       |

**Validation rules (checked server-side):**
- Driver must exist and have a driver profile
- Driver must be `approved` status
- Driver must be online (`is_online = true`)
- Driver's vehicle class must match the ride's vehicle class
- Driver must not have another active ride

**Response 200:**
```json
{
  "message": "Driver assigned successfully.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "city_id": 1,
    "vehicle_class_id": 1,
    "passenger_id": 1,
    "driver_id": 2,
    "pickup": {
      "lat": "6.5244000",
      "lng": "3.3792000",
      "address": "123 Herbert Macaulay Way, Yaba, Lagos"
    },
    "destination": {
      "lat": "6.4541000",
      "lng": "3.3947000",
      "address": "456 Broad Street, Lagos Island, Lagos"
    },
    "status": "driver_en_route",
    "status_label": "Driver En Route",
    "share_token": "abc123xyz789def456ghi012jkl345mno",
    "fare_estimate_amount": "3750.00",
    "final_fare_amount": null,
    "fare_currency": "NGN",
    "payment_method": "cash",
    "payment_status": "pending",
    "cancellation_reason": null,
    "city": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "...": "..."
    },
    "vehicle_class": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "economy",
      "display_name": "Economy",
      "...": "..."
    },
    "passenger": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "...": "..."
    },
    "driver": {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "first_name": "Chidi",
      "last_name": "Okonkwo",
      "...": "..."
    },
    "cancelled_by": null,
    "matched_at": "2026-10-06T14:05:00.000000Z",
    "started_at": null,
    "completed_at": null,
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:05:00.000000Z"
  }
}
```

**Response 404 (driver not found):**
```json
{
  "message": "Driver not found."
}
```

**Response 422 (ride not assignable):**
```json
{
  "message": "This ride cannot be assigned in its current state.",
  "current_status": "in_progress"
}
```

**Response 422 (validation failures):**
```json
{
  "message": "The driver id field is invalid.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "driver_id": ["Driver must be online.", "Driver vehicle class does not match the ride request."]
  }
}
```

---

## Admin — Pricing Management

**Middleware:** `auth:sanctum`, `user.type:admin`

### List Pricing Configs
```
GET /admin/pricing
```
| Query Param      | Type    | Description                                    |
|------------------|---------|------------------------------------------------|
| city_id          | string (UUID) | Filter by city                                 |
| vehicle_class_id | string (UUID) | Filter by vehicle class                        |
| current_only     | boolean | Only show currently effective configs          |
| per_page         | integer | Results per page (default: 20)                 |

**Response 200:**
```json
{
  "pricing_configs": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "city_id": 1,
      "vehicle_class_id": 1,
      "base_fare": "600.00",
      "per_km_rate": "250.00",
      "per_minute_rate": "40.00",
      "minimum_fare": "1500.00",
      "waiting_time_rate": "50.00",
      "free_waiting_minutes": 5,
      "version": 1,
      "effective_from": "2026-09-30T00:00:00.000000Z",
      "city": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "Lagos",
        "slug": "lagos",
        "...": "..."
      },
      "vehicle_class": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "...": "..."
      },
      "created_by": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "first_name": "Super",
        "last_name": "Admin",
        "...": "..."
      },
      "created_at": "2026-09-30T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 12
  }
}
```

---

### Create Pricing Config
```
POST /admin/pricing
```
| Field               | Type    | Required | Description                              |
|---------------------|---------|----------|------------------------------------------|
| city_id             | string (UUID) | Yes      | Must exist in cities table               |
| vehicle_class_id    | string (UUID) | Yes      | Must exist in vehicle_classes table      |
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
    "id": "d0e1f2a3-4b5c-6d7e-8f9a-0b1c2d3e4f5a",
    "city_id": 1,
    "vehicle_class_id": 1,
    "base_fare": "700.00",
    "per_km_rate": "280.00",
    "per_minute_rate": "45.00",
    "minimum_fare": "1600.00",
    "waiting_time_rate": "55.00",
    "free_waiting_minutes": 5,
    "version": 2,
    "effective_from": "2026-11-01T00:00:00.000000Z",
    "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos", "...": "..." },
    "vehicle_class": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "economy", "...": "..." },
    "created_by": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "first_name": "Super", "last_name": "Admin", "...": "..." },
    "created_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

---

### Get Pricing Config
```
GET /admin/pricing/{pricing_config_id}
```
Returns a specific pricing config with city, vehicle class, and creator details.

**Response 200:**
```json
{
  "pricing_config": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "city_id": 1,
    "vehicle_class_id": 1,
    "base_fare": "600.00",
    "per_km_rate": "250.00",
    "per_minute_rate": "40.00",
    "minimum_fare": "1500.00",
    "waiting_time_rate": "50.00",
    "free_waiting_minutes": 5,
    "version": 1,
    "effective_from": "2026-09-30T00:00:00.000000Z",
    "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos", "...": "..." },
    "vehicle_class": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "economy", "...": "..." },
    "created_by": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "first_name": "Super", "last_name": "Admin", "...": "..." },
    "created_at": "2026-09-30T10:00:00.000000Z"
  }
}
```

---

### Get Current Pricing Config
```
GET /admin/pricing/current
```
| Query Param      | Type    | Required | Description          |
|------------------|---------|----------|----------------------|
| city_id          | string (UUID) | Yes      | City ID              |
| vehicle_class_id | string (UUID) | Yes      | Vehicle class ID     |

Returns the currently effective pricing config for the given city + vehicle class.

**Response 200:**
```json
{
  "pricing_config": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "city_id": 1,
    "vehicle_class_id": 1,
    "base_fare": "600.00",
    "per_km_rate": "250.00",
    "per_minute_rate": "40.00",
    "minimum_fare": "1500.00",
    "waiting_time_rate": "50.00",
    "free_waiting_minutes": 5,
    "version": 1,
    "effective_from": "2026-09-30T00:00:00.000000Z",
    "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos", "...": "..." },
    "vehicle_class": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "economy", "...": "..." },
    "created_by": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "first_name": "Super", "last_name": "Admin", "...": "..." },
    "created_at": "2026-09-30T10:00:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "message": "No pricing configuration found for the specified city and vehicle class."
}
```

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
| city_id          | string (UUID) | Filter by city                      |
| vehicle_class_id | string (UUID) | Filter by vehicle class             |
| active_only      | boolean | Only show currently active rules    |
| per_page         | integer | Results per page (default: 20)      |

**Response 200:**
```json
{
  "surge_rules": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
      "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos", "...": "..." },
      "created_by": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "first_name": "Super", "last_name": "Admin", "...": "..." },
      "created_at": "2026-09-30T10:00:00.000000Z",
      "updated_at": "2026-09-30T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
}
```

---

### Create Surge Rule
```
POST /admin/surge-rules
```
| Field                                | Type    | Required                      | Description                              |
|--------------------------------------|---------|-------------------------------|------------------------------------------|
| city_id                              | string (UUID) | Yes                           | Must exist in cities table               |
| vehicle_class_id                     | string (UUID) | No                            | Scope to vehicle class (null = all)      |
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
    "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "city_id": 2,
    "vehicle_class_id": null,
    "name": "Evening Rush Hour",
    "type": "time_based",
    "multiplier": "1.30",
    "conditions": {
      "days_of_week": [1, 2, 3, 4, 5],
      "start_time": "17:00",
      "end_time": "19:30"
    },
    "priority": 5,
    "is_active": true,
    "effective_from": "2026-10-07T00:00:00.000000Z",
    "effective_until": null,
    "city": { "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d", "name": "Abuja", "...": "..." },
    "created_by": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "first_name": "Super", "last_name": "Admin", "...": "..." },
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:00:00.000000Z"
  }
}
```

---

### Get Surge Rule
```
GET /admin/surge-rules/{surge_rule_id}
```
Returns a specific surge rule with city, vehicle class, and creator details.

**Response 200:**
```json
{
  "surge_rule": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
    "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos", "...": "..." },
    "vehicle_class": null,
    "created_by": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "first_name": "Super", "last_name": "Admin", "...": "..." },
    "created_at": "2026-09-30T10:00:00.000000Z",
    "updated_at": "2026-09-30T10:00:00.000000Z"
  }
}
```

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
  "surge_rule": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "city_id": 1,
    "vehicle_class_id": null,
    "name": "Morning Rush Hour",
    "type": "time_based",
    "multiplier": "1.75",
    "conditions": {
      "days_of_week": [1, 2, 3, 4, 5],
      "start_time": "06:30",
      "end_time": "09:30"
    },
    "...": "..."
  }
}
```

---

### Toggle Surge Rule Status
```
PATCH /admin/surge-rules/{surge_rule_id}/status
```
Toggles `is_active` between true and false. No request body required.

**Response 200 (activated):**
```json
{
  "message": "Surge rule activated.",
  "surge_rule": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "is_active": true,
    "...": "..."
  }
}
```

**Response 200 (deactivated):**
```json
{
  "message": "Surge rule deactivated.",
  "surge_rule": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "is_active": false,
    "...": "..."
  }
}
```

---

### Get Current Surge Multiplier
```
GET /admin/surge-rules/current-multiplier
```
| Query Param      | Type    | Required | Description                    |
|------------------|---------|----------|--------------------------------|
| city_id          | string (UUID) | Yes      | City ID                        |
| vehicle_class_id | string (UUID) | No       | Vehicle class ID (null = all)  |

Returns the currently active surge multiplier for the given city (and optionally vehicle class).

**Response 200 (surge active):**
```json
{
  "surge": {
    "active": true,
    "multiplier": 1.5,
    "rule_name": "Morning Rush Hour",
    "rule": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
      "...": "..."
    }
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

**Response 200:**
```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "type": "ride_matched",
      "title": "Driver Found!",
      "body": "Your driver Bayo is on the way.",
      "data": {
        "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36"
      },
      "read_at": null,
      "created_at": "2026-10-06T14:01:00.000000Z"
    },
    {
      "id": "550e8400-e29b-41d4-a716-446655440001",
      "type": "ride_completed",
      "title": "Ride Complete",
      "body": "Your trip has ended. Total fare: ₦3,900.",
      "data": {
        "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36"
      },
      "read_at": "2026-10-06T15:00:00.000000Z",
      "created_at": "2026-10-06T14:30:00.000000Z"
    }
  ],
  "current_page": 1,
  "last_page": 1,
  "per_page": 20,
  "total": 2
}
```

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
Only the notification owner can mark it as read.

**Response 200:**
```json
{
  "message": "Notification marked as read."
}
```

**Response 403:**
```json
{
  "message": "This notification does not belong to you."
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
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "user_id": 1,
      "card_last_four": "4081",
      "card_brand": "visa",
      "is_default": true,
      "created_at": "2026-09-30T10:00:00.000000Z",
      "updated_at": "2026-09-30T10:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "user_id": 1,
      "card_last_four": "5432",
      "card_brand": "mastercard",
      "is_default": false,
      "created_at": "2026-10-02T10:00:00.000000Z",
      "updated_at": "2026-10-02T10:00:00.000000Z"
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
  "payment_link": "https://checkout.flutterwave.com/v3/hosted/pay/flwlnk-mock-abc123def456",
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

**Response 200 (failed):**
```json
{
  "status": "failed",
  "tx_ref": "ETIGO-ABCDEFGHIJKL",
  "amount": 100.00,
  "currency": "NGN",
  "card_last_four": null,
  "card_brand": null
}
```

---

### Set Default Payment Method
```
PATCH /payment-methods/{payment_method_id}/default
```
Sets the specified payment method as default. Only the owner can change it.

**Response 200:**
```json
{
  "message": "Default payment method updated."
}
```

**Response 403:**
```json
{
  "message": "This payment method does not belong to you."
}
```

---

### Delete Payment Method
```
DELETE /payment-methods/{payment_method_id}
```
Only the owner can delete it.

**Response 200:**
```json
{
  "message": "Payment method removed."
}
```

**Response 403:**
```json
{
  "message": "This payment method does not belong to you."
}
```

---

## Payment Webhooks

### Flutterwave Webhook
```
POST /webhooks/flutterwave
```
**No authentication** — verified by `verif-hash` header matching `FLUTTERWAVE_ENCRYPTION_KEY`. Receives payment status updates from Flutterwave and updates the corresponding Payment record.

**Response 200:**
```json
{
  "status": "ok"
}
```

**Response 200 (no transaction ID):**
```json
{
  "status": "ignored"
}
```

**Response 401:**
```json
{
  "message": "Invalid signature."
}
```

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
| `onboarding`     | New driver, completing onboarding steps  |
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
| Value      | Description                        |
|------------|------------------------------------|
| `google`   | Google OAuth 2.0                   |
| `apple`    | Apple Sign In                      |
| `facebook` | Facebook Login                     |

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

---

## Push Notifications (FCM)

Push notifications are sent automatically on ride state transitions. The mobile app must register a device token via `POST /device-tokens` to receive them.

| Event              | Recipient  | Notification Type    | Description                                    |
|--------------------|------------|----------------------|------------------------------------------------|
| Driver matched     | Passenger  | `ride_matched`       | Driver found, includes driver name             |
| Driver matched     | Driver     | `ride_assigned`      | New ride assigned, includes pickup address     |
| Driver en route    | Passenger  | `driver_en_route`    | Driver is on the way to pickup                 |
| Driver arrived     | Passenger  | `driver_arrived`     | Driver is waiting at pickup location           |
| Ride started       | Passenger  | `ride_started`       | Trip has begun, includes destination           |
| Ride completed     | Passenger  | `ride_completed`     | Trip finished, includes total fare             |
| Ride completed     | Driver     | `ride_completed`     | Trip finished                                  |
| Ride cancelled     | Other party| `ride_cancelled`     | Cancellation by passenger/driver/admin         |
| No driver found    | Passenger  | `no_driver_found`    | No nearby drivers available                    |

**Payload format:**
```json
{
  "title": "Driver Found!",
  "body": "Your driver Bayo is on the way.",
  "data": {
    "type": "ride_matched",
    "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36"
  }
}
```

---

## Email Notifications

Transactional emails sent via Resend HTTP API. Emails are queued and sent asynchronously.

| Email                 | Recipient | Trigger                          |
|-----------------------|-----------|----------------------------------|
| Admin Invitation      | Invitee   | `POST /admin/admins/invite`      |
| Admin Password Reset  | Admin     | `POST /admin/auth/forgot-password` |
| Admin Welcome         | Admin     | `POST /admin/auth/invite/accept` |
| Ride Receipt          | Passenger | Ride completed (has email on file) |

---

## Real-Time Broadcasting (WebSocket)

Real-time location tracking and ride updates are delivered via WebSocket using **Laravel Reverb**. The mobile app and admin dashboard connect to the Reverb WebSocket server and subscribe to private channels authenticated via Sanctum.

### Server Setup

Start the Reverb WebSocket server:
```bash
php artisan reverb:start
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `BROADCAST_CONNECTION` | Broadcasting driver | `reverb` |
| `REVERB_APP_ID` | Reverb application ID | `etigo` |
| `REVERB_APP_KEY` | Reverb app key (used by client) | `etigo-key` |
| `REVERB_APP_SECRET` | Reverb app secret | `etigo-secret` |
| `REVERB_HOST` | WebSocket server host | `localhost` |
| `REVERB_PORT` | WebSocket server port | `8080` |
| `REVERB_SCHEME` | WebSocket scheme (http/https) | `http` |

### Channel Authorization

Private channels require authentication. The client must send an authorization request to `POST /broadcasting/auth` with a valid Sanctum bearer token.

### Channels

| Channel | Auth | Subscribers | Events |
|---------|------|-------------|--------|
| `private-ride.{rideId}` | Passenger or assigned driver | Ride participant | `driver.location.updated` |
| `private-admin.rides` | Admin users only | Admin dashboard | `driver.location.updated` |

### Events

#### `driver.location.updated`

Dispatched on every driver location update (max 1/second). Includes live GPS coordinates and ETA when an active ride exists.

```json
{
  "driver_id": 1,
  "lat": 9.0579,
  "lng": 7.4951,
  "heading": 45.0,
  "speed": 30.0,
  "timestamp": 1728223200,
  "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
  "eta": {
    "distance_km": 3.2,
    "duration_minutes": 8.0
  }
}
```

`ride_id` and `eta` are `null` when the driver has no active ride (admin channel only in that case).
