# E-tiGo API Reference

**Base URL:** `{APP_URL}/api/v1`  
**Auth:** Bearer token via Laravel Sanctum (`Authorization: Bearer {token}`)  
**Content-Type:** `application/json` (except file uploads: `multipart/form-data`)  
**Rate Limiting:** Auth endpoints (`/auth/*`, `/admin/auth/*`) are limited to 5 requests/minute per IP — exceeding returns `429 Too Many Requests` with a `Retry-After` header (seconds)  
**Resource IDs:** All resource IDs (users, drivers, cities, vehicle classes, rides, documents, etc.) are UUIDs (e.g. `"9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b"`). Parameters referencing an ID field accept `string (UUID)` format; a malformed id in a filter returns `422`.  
**Pagination:** List endpoints accept `per_page`; it is clamped to the endpoint's range (minimum 1), so zero, negative or oversized values never disable pagination.  
**Sessions:** Admin dashboard tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes (default 480 — 8 hours). Passenger and driver app tokens expire after `SANCTUM_APP_TOKEN_EXPIRATION` minutes (default 43,200 — 30 days). Expired tokens return `401 UNAUTHENTICATED`; sign in again.
**What changed for the apps:** `FRONTEND_UPDATE_PAYMENTS_EV_SURGE.md` summarises the October 2026 changes (payouts, tips, EV, fuel type, surge) per app, with the breaking ones first.  

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
| `finance@etigo.com` | Finance | Finance & payments manager |

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
| `+2348200000003` | `segun@demo.etigo.com` | Segun Obaseki | Approved (Online) | Mercedes-Benz EQE (electric) — Premium |
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

In **local**, **staging**, and **testing** environments, all OTP codes are set to **`123456`**. Message delivery is skipped entirely. This allows developers and testers to create and verify accounts freely without OTP costs or delivery delays.

**How to use:**
1. `POST /auth/otp/send` with any valid phone number → returns success immediately
2. `POST /auth/otp/verify` with the same phone and code `123456` → logs in or prompts registration

This static OTP is **disabled in production** — production always generates random codes and sends via WhatsApp (with SMS fallback).

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

Called after OTP verification when `is_new_user` is `true`. The phone must have been verified within the last 10 minutes **for the same `type`** sent to `POST /auth/otp/verify`, and each verification completes exactly one registration. To open the other account type for the same phone (e.g. a passenger who also wants to drive), verify the OTP again with that `type`.

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

**Response 403** — no unused verification for this phone and `type` in the last 10 minutes:
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
    "profile_photo_url": "https://res.cloudinary.com/etigo/image/upload/profile-photos/1/abc123.jpg",
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

## App Configuration

```
GET /config
```

No authentication required. Returns app configuration including minimum version requirements for force-update enforcement.

**Response 200:**
```json
{
  "min_app_version": {
    "ios": "1.0.0",
    "android": "1.0.0"
  },
  "force_update": false,
  "emergency_number": "112",
  "support_phone": null,
  "support_email": null
}
```

**Usage:** Mobile clients should check `min_app_version` on every launch. If the installed version is below the minimum, show a force-update screen with a link to the App Store / Play Store. When `force_update` is `true`, block usage entirely until updated.

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
    "profile_photo_url": "https://res.cloudinary.com/etigo/image/upload/profile-photos/1/abc123.jpg",
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
    "profile_photo_url": "https://res.cloudinary.com/etigo/image/upload/profile-photos/1/def456.jpg",
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

### Saved Places

Passengers can save frequently used locations (home, work, custom named places). Home and work are limited to one each; saving again overwrites the existing entry. Custom places require a name and allow unlimited entries.

### List Saved Places
```
GET /passenger/saved-places
```

Returns all saved places for the authenticated passenger, ordered: home first, then work, then custom places by creation date.

**Response 200:**
```json
{
  "saved_places": [
    {
      "id": "a1b2c3d4-e5f6-7890-abcd-111111111111",
      "type": "home",
      "name": "Home",
      "address": "12 Ahmadu Bello Way, Victoria Island, Lagos",
      "latitude": 6.4281000,
      "longitude": 3.4219000,
      "created_at": "2026-10-12T10:00:00.000000Z",
      "updated_at": "2026-10-12T10:00:00.000000Z"
    },
    {
      "id": "a1b2c3d4-e5f6-7890-abcd-222222222222",
      "type": "work",
      "name": "Work",
      "address": "Eko Atlantic City, Lagos",
      "latitude": 6.4100000,
      "longitude": 3.4050000,
      "created_at": "2026-10-12T10:01:00.000000Z",
      "updated_at": "2026-10-12T10:01:00.000000Z"
    },
    {
      "id": "a1b2c3d4-e5f6-7890-abcd-333333333333",
      "type": "custom",
      "name": "Gym",
      "address": "Eko Hotel & Suites, Adetokunbo Ademola St, Victoria Island",
      "latitude": 6.4310000,
      "longitude": 3.4200000,
      "created_at": "2026-10-12T10:02:00.000000Z",
      "updated_at": "2026-10-12T10:02:00.000000Z"
    }
  ]
}
```

---

### Save a Place
```
POST /passenger/saved-places
```

| Field     | Type   | Required | Description                                      |
|-----------|--------|----------|--------------------------------------------------|
| type      | string | Yes      | `home`, `work`, or `custom`                      |
| name      | string | Custom   | Required for `custom` type. Max 100 characters.  |
| address   | string | Yes      | Full address text. Max 500 characters.            |
| latitude  | number | Yes      | Latitude (-90 to 90)                              |
| longitude | number | Yes      | Longitude (-180 to 180)                           |

**Behavior:** If `type` is `home` or `work` and one already exists, the existing entry is updated (returns 200). Otherwise a new entry is created (returns 201).

**Response 201 — Created:**
```json
{
  "message": "Place saved.",
  "saved_place": {
    "id": "a1b2c3d4-e5f6-7890-abcd-111111111111",
    "type": "home",
    "name": "Home",
    "address": "12 Ahmadu Bello Way, Victoria Island, Lagos",
    "latitude": 6.4281000,
    "longitude": 3.4219000,
    "created_at": "2026-10-12T10:00:00.000000Z",
    "updated_at": "2026-10-12T10:00:00.000000Z"
  }
}
```

**Response 200 — Updated (home/work already existed):**
```json
{
  "message": "Home address updated.",
  "saved_place": {
    "id": "a1b2c3d4-e5f6-7890-abcd-111111111111",
    "type": "home",
    "name": "Home",
    "address": "New address here",
    "latitude": 6.5000000,
    "longitude": 3.5000000,
    "created_at": "2026-10-12T10:00:00.000000Z",
    "updated_at": "2026-10-12T10:05:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "A name is required for custom places.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "name": ["A name is required for custom places."]
  }
}
```

---

### Get Saved Place
```
GET /passenger/saved-places/{savedPlace}
```

**Response 200:**
```json
{
  "saved_place": {
    "id": "a1b2c3d4-e5f6-7890-abcd-333333333333",
    "type": "custom",
    "name": "Gym",
    "address": "Eko Hotel & Suites, Adetokunbo Ademola St, Victoria Island",
    "latitude": 6.4310000,
    "longitude": 3.4200000,
    "created_at": "2026-10-12T10:02:00.000000Z",
    "updated_at": "2026-10-12T10:02:00.000000Z"
  }
}
```

---

### Update Saved Place
```
PUT /passenger/saved-places/{savedPlace}
```

| Field     | Type   | Required | Description                           |
|-----------|--------|----------|---------------------------------------|
| name      | string | No       | Updated name. Max 100 characters.     |
| address   | string | No       | Updated address. Max 500 characters.  |
| latitude  | number | No       | Updated latitude (-90 to 90)          |
| longitude | number | No       | Updated longitude (-180 to 180)       |

**Response 200:**
```json
{
  "message": "Place updated.",
  "saved_place": {
    "id": "a1b2c3d4-e5f6-7890-abcd-333333333333",
    "type": "custom",
    "name": "New Gym",
    "address": "Updated address",
    "latitude": 6.5000000,
    "longitude": 3.5000000,
    "created_at": "2026-10-12T10:02:00.000000Z",
    "updated_at": "2026-10-12T10:10:00.000000Z"
  }
}
```

---

### Delete Saved Place
```
DELETE /passenger/saved-places/{savedPlace}
```

**Response 200:**
```json
{
  "message": "Place removed."
}
```

**Response 404:**
```json
{
  "message": "Not found."
}
```

---

### Get Active Ride (Passenger)

```
GET /passenger/active-ride
Authorization: Bearer {passenger_token}
```

Returns the passenger's current active ride (status: `searching`, `driver_en_route`, `driver_arrived`, or `in_progress`). Returns `null` if no active ride. Used by the frontend to restore the ride screen on app relaunch.

**Response 200:**
```json
{
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "driver_en_route",
    "status_label": "Driver En Route",
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
    "fare_estimate_amount": "3750.00",
    "fare_currency": "NGN",
    "pin": "4821",
    "...": "full ride detail object"
  }
}
```

**Response 200 (no active ride):**
```json
{
  "ride": null
}
```

---

## Driver

All driver endpoints require `Authorization: Bearer {token}` from a driver user.

### Onboarding Status
```
GET /driver/onboarding/status
```

Drivers have two onboarding paths based on `vehicle_ownership_type`:
- **`own_vehicle`** — driver registers their own vehicle, must upload `vehicle_registration` and `insurance_certificate` documents, and KYC includes vehicle plate verification.
- **`fleet_vehicle`** — admin assigns a fleet vehicle after approval; only `driving_licence` and `government_id` documents are required, KYC skips vehicle plate verification.

The `vehicle_ownership_type` must be set (via `POST /driver/onboarding/vehicle-ownership`) before submitting for review.

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
    "vehicle_ownership_type": null,
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
  "has_vehicle_ownership_type": false,
  "vehicle_ownership_type": null,
  "has_vehicle": false,
  "needs_vehicle_registration": true,
  "has_licence_number": false,
  "has_city": true,
  "kyc_status": "not_started",
  "kyc_verified_at": null
}
```

**Notes:**
- Documents with `rejected` status are excluded from the uploaded count — a rejected document must be re-uploaded before it counts toward onboarding completion.
- `needs_vehicle_registration` is `true` only for `own_vehicle` drivers. Fleet drivers do not register vehicles.
- `missing_documents` is conditional: fleet drivers only need `driving_licence` and `government_id`.
- Onboarding is considered complete when: `vehicle_ownership_type` is set, all required documents uploaded, licence number set, city selected, and vehicle registered (own_vehicle drivers only).

---

### Set Vehicle Ownership
```
POST /driver/onboarding/vehicle-ownership
```

Sets the driver's vehicle ownership path during onboarding. This determines which documents and KYC steps are required.

| Field                   | Type   | Required | Description                                  |
|-------------------------|--------|----------|----------------------------------------------|
| vehicle_ownership_type  | string | Yes      | `own_vehicle` or `fleet_vehicle`             |

**Response 200:**
```json
{
  "message": "Vehicle ownership type set successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "onboarding",
    "vehicle_ownership_type": "fleet_vehicle",
    "kyc_status": "not_started",
    "kyc_verified_at": null,
    "licence_number": null,
    "is_online": false,
    "approved_at": null,
    "documents": [],
    "vehicle": null,
    "created_at": "2026-10-01T10:00:00.000000Z"
  }
}
```

**Response 422 (wrong status):**
```json
{
  "message": "Vehicle ownership type can only be set during onboarding."
}
```

Can only be set when driver status is `onboarding` or `rejected`. Can be changed freely during those states (e.g., if the driver changes their mind).

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
    "profile_photo_url": "https://res.cloudinary.com/etigo/image/upload/profile-photos/7/ghi789.jpg",
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

Only available for drivers with `vehicle_ownership_type: "own_vehicle"`. Fleet vehicle drivers cannot register their own vehicle — a fleet vehicle is assigned by admin after approval.

| Field            | Type          | Required | Description              |
|------------------|---------------|----------|--------------------------|
| make             | string        | Yes      | Vehicle make (e.g. Toyota)|
| model            | string        | Yes      | Vehicle model            |
| colour           | string        | Yes      | Vehicle colour           |
| plate_number     | string        | Yes      | Plate number (unique)    |
| year             | integer       | Yes      | Year of manufacture (2000 – next year) |
| vehicle_class_id | string (UUID) | Yes      | Preferred vehicle class (confirmed by E-tiGo at approval) |
| fuel_type        | string        | Yes      | `petrol`, `diesel`, `cng`, `hybrid`, `plug_in_hybrid` or `electric`. Only `electric` and `plug_in_hybrid` vehicles can use EV charging stalls |

**Response 201:**
```json
{
  "message": "Vehicle registered successfully. Plate verification initiated.",
  "vehicle": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "driver_id": "4c1d2e3f-5a6b-7c8d-9e0f-1a2b3c4d5e6f",
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "ABC-1234",
    "year": 2022,
    "fuel_type": "petrol",
    "can_charge": false,
    "is_fleet": false,
    "vehicle_class_id": "7b8c9d0e-1f2a-3b4c-5d6e-7f8a9b0c1d2e",
    "vehicle_class": {
      "id": "7b8c9d0e-1f2a-3b4c-5d6e-7f8a9b0c1d2e",
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

**Response 422 (fleet driver):**
```json
{
  "message": "Fleet vehicle drivers cannot register their own vehicle. A vehicle will be assigned by admin."
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
Same fields as register, all optional (partial update supported). If the plate number changes, plate verification is re-dispatched.

- **Vehicle class:** drivers can change `vehicle_class_id` only while their status is `onboarding` or `rejected`. After the application is submitted, the class is set by E-tiGo (see **Update Driver Vehicle Class** in Admin — Driver Management); a different `vehicle_class_id` returns `422` with `errors.vehicle_class_id`.
- **Fuel type:** same rule, except that a vehicle whose `fuel_type` was never set can declare it once at any time (existing drivers). Changing a declared fuel type after submission returns `422` with `errors.fuel_type` — E-tiGo corrects it with **Update Driver Vehicle Class**.
- **Fleet vehicles** belong to E-tiGo and cannot be edited by the driver (`403`).

**Response 200:**
```json
{
  "message": "Vehicle updated successfully.",
  "vehicle": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "driver_id": "4c1d2e3f-5a6b-7c8d-9e0f-1a2b3c4d5e6f",
    "make": "Toyota",
    "model": "Camry",
    "colour": "Black",
    "plate_number": "ABC-1234",
    "year": 2023,
    "fuel_type": "electric",
    "can_charge": true,
    "is_fleet": false,
    "vehicle_class_id": "7b8c9d0e-1f2a-3b4c-5d6e-7f8a9b0c1d2e",
    "vehicle_class": {
      "id": "7b8c9d0e-1f2a-3b4c-5d6e-7f8a9b0c1d2e",
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

**Response 422 (class change after submission):**
```json
{
  "message": "Your vehicle class can only be changed by E-tiGo after your application is submitted. Contact support.",
  "errors": { "vehicle_class_id": ["Vehicle class changes require admin approval."] }
}
```

**Response 403 (fleet vehicle):**
```json
{
  "message": "Fleet vehicles are managed by E-tiGo. Contact support to update vehicle details."
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
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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

Submits the driver application for admin review. Only valid when driver status is `onboarding` or `rejected` and all requirements are met.

**Requirements vary by ownership type:**
- **All drivers:** `vehicle_ownership_type` set, required documents uploaded, licence number set, city selected.
- **`own_vehicle` only:** vehicle registered, with its `fuel_type` set (vehicles registered before fuel type was required must set it first: `"Your vehicle's fuel type is required."`).
- **`fleet_vehicle`:** no vehicle required at submission time (assigned by admin after approval).

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
    "Vehicle ownership type must be selected.",
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

**Going online requires:** status `approved` (not `suspended`), a registered vehicle with an assigned vehicle class, completed KYC (`kyc_status` = `verified`), and an available earnings balance no lower than the negative-balance threshold (`max_negative_balance`, default −₦5,000 — see Wallet Settings).

**Going offline** is refused with `409` while the driver has an active ride (`matched` → `in_progress`), so passenger tracking and location updates continue until the trip ends.

Drivers whose app stops sending locations for 2 minutes are no longer offered rides or shown on maps, even if they forgot to go offline.

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
      "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
    "KYC verification is not complete.",
    "Outstanding negative balance exceeds the allowed threshold."
  ]
}
```

**Response 409 (going offline mid-trip):**
```json
{
  "message": "You cannot go offline during an active ride. Complete or cancel the ride first."
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
  "driver_id": "3b2a1f0e-9d8c-4b7a-8f6e-5d4c3b2a1f0e",
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

### Get Driver Location (Cached)

```
GET /driver/location
Authorization: Bearer {driver_token}
```

Returns the driver's most recent cached GPS position from Redis. The driver must be online and have updated their location within the last 5 minutes.

**Response 200:**
```json
{
  "location": {
    "lat": 6.5244,
    "lng": 3.3792,
    "heading": 45,
    "speed": 25.3,
    "timestamp": 1729000000
  },
  "city_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
  "vehicle_class_id": "uuid",
  "is_online": true
}
```

**Response 404:** No recent location available (stale > 5 minutes or never updated).
**Response 409:** Driver is offline.

---

### Get Active Ride (Driver)

```
GET /driver/active-ride
Authorization: Bearer {driver_token}
```

Returns the driver's current active ride (status: `searching`, `driver_en_route`, `driver_arrived`, or `in_progress`). Returns `null` if no active ride.

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
    "fare_estimate_amount": "3750.00",
    "fare_currency": "NGN",
    "pin": "4821",
    "...": "full ride detail object"
  }
}
```

**Response 200 (no active ride):**
```json
{
  "ride": null
}
```

---

## Driver — KYC Verification

All KYC endpoints require `Authorization: Bearer {token}` from a driver user.

KYC verification uses QoreID as the identity verification provider. Required verifications depend on the driver's vehicle ownership type:

- **`own_vehicle` drivers:** NIN + Driver's License + Vehicle Plate verification required.
- **`fleet_vehicle` drivers:** NIN + Driver's License only. Vehicle plate verification is skipped since the fleet vehicle is managed by admin.
- **Liveness verification** is supported but optional for the `verified` status.

Vehicle plate verification auto-triggers when a vehicle is registered or the plate number is updated (own_vehicle drivers only).

> **Testing / QA Sandbox Mode (`KYC_SKIP_VERIFICATION`):**
> When `KYC_SKIP_VERIFICATION=true` is set in `.env`, all calls to the external QoreID provider are skipped and simulated as successful via `FakeKycGateway`. All format validations (11-digit NIN, driver's license length/format, plate length) and duplicate prevention remain fully active.

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
      "failure_reason": null,
      "required": true
    },
    "drivers_license": {
      "status": "not_started",
      "verified_at": null,
      "failure_reason": null,
      "required": true
    },
    "vehicle_plate": {
      "status": "not_started",
      "verified_at": null,
      "failure_reason": null,
      "required": true
    },
    "liveness": {
      "status": "not_started",
      "verified_at": null,
      "failure_reason": null,
      "required": false
    }
  }
}
```

**KYC Status values:**

| Value          | Description                                        |
|----------------|----------------------------------------------------|
| `not_started`  | No verifications submitted yet                     |
| `in_progress`  | At least one verification submitted, not all done  |
| `verified`     | All required verifications passed                  |
| `failed`       | At least one required verification failed          |

Each verification type includes a `required` field indicating whether it's needed for this driver. Fleet vehicle drivers will see `vehicle_plate.required: false`.

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

Requires a registered vehicle, and `plate_number` must match that vehicle's plate (case, spaces and hyphens are ignored) — verifying a different car's plate is refused with `422`.

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

**Response 422 (plate mismatch):**
```json
{
  "message": "The plate number must match your registered vehicle.",
  "errors": { "plate_number": ["The plate number must match your registered vehicle."] }
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
GET|POST /webhooks/qoreid
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

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,safety_operator`

**Allowed Roles:** Operations, Safety (Super Admin always has access)

### List All Drivers
```
GET /admin/drivers
```

| Query Param      | Type   | Required | Description                                          |
|------------------|--------|----------|------------------------------------------------------|
| status           | string | No       | Filter: `onboarding`, `pending_review`, `approved`, `rejected`, `suspended` |
| kyc_status       | string | No       | Filter: `not_started`, `in_progress`, `verified`, `failed`    |
| city_id          | string (UUID) | No | Filter by city                                      |
| vehicle_class_id | string (UUID) | No | Filter by vehicle class                             |
| fuel_type        | string | No       | Filter by the vehicle's fuel type; `none` lists drivers whose vehicle has no fuel type yet |
| search           | string | No       | Search by driver name, email, or phone               |
| per_page         | integer | No      | Results per page (default: 20)                       |

**Response 200:**
```json
{
  "drivers": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "reference_number": "D-000001",
      "user": {
        "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
        "reference_number": null,
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
      "vehicle_ownership_type": "own_vehicle",
      "kyc_status": "verified",
      "kyc_verified_at": "2026-10-03T10:00:00.000000Z",
      "licence_number": "DL-12345678",
      "rejection_reason": null,
      "is_online": true,
      "approved_at": "2026-10-04T10:00:00.000000Z",
      "suspended_at": null,
      "documents": [
        {
          "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
          "type": "driving_licence",
          "original_filename": "licence.pdf",
          "file_size": 245000,
          "file_url": "https://res.cloudinary.com/etigo/raw/upload/driver-documents/7/licence.pdf",
          "expires_at": null,
          "status": "approved",
          "rejection_reason": null,
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
        "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
      "bank_account": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "bank_code": "058",
        "bank_name": "GTBank",
        "account_number": "******7890",
        "account_name": "Bayo Akinola",
        "is_verified": true,
        "is_primary": true,
        "created_at": "2026-10-02T10:00:00.000000Z"
      },
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-04T10:00:00.000000Z"
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
    "reference_number": "D-000001",
    "user": { "..." },
    "city": { "..." },
    "status": "approved",
    "vehicle_ownership_type": "own_vehicle",
    "kyc_status": "verified",
    "kyc_verified_at": "2026-10-03T10:00:00.000000Z",
    "licence_number": "DL-12345678",
    "rejection_reason": null,
    "is_online": true,
    "approved_at": "2026-10-04T10:00:00.000000Z",
    "suspended_at": null,
    "documents": [ "..." ],
    "vehicle": { "..." },
    "bank_account": { "..." },
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
    "is_profile_complete": true,
    "created_at": "2026-10-01T10:00:00.000000Z",
    "updated_at": "2026-10-04T10:00:00.000000Z"
  },
  "performance": {
    "rating": 4.75,
    "total_trips": 142,
    "completed_trips": 135,
    "cancelled_trips": 7,
    "cancellation_rate": 4.9,
    "acceptance_rate": 92.5,
    "total_earnings": 285000.00
  }
}
```

**Field Notes:**
- `driver.is_profile_complete` (boolean) — `true` when the driver has: user details, city, vehicle, KYC verified, bank account, and documents uploaded.
- `performance.acceptance_rate` (float, 0–100) — Calculated from AuditLog records of `ride_accepted` and `ride_rejected` events. Defaults to `100.0` if no offers have been recorded.

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
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
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
Force-sets driver offline, removes them from the live driver map and blocks them from accepting requests.

| Field  | Type   | Required | Description                |
|--------|--------|----------|----------------------------|
| reason | string | Yes      | Reason for suspension (max 500 chars) |

**Response 200:**
```json
{
  "message": "Driver suspended successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "suspended",
    "is_online": false,
    "suspension_reason": "Multiple passenger complaints about unsafe driving.",
    "suspended_at": "2026-10-06T14:00:00.000000Z",
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
Restores driver to approved status. Clears `suspension_reason` and `suspended_at`.

**Response 200:**
```json
{
  "message": "Driver reactivated successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "approved",
    "suspension_reason": null,
    "suspended_at": null,
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

### Driver Trip History
```
GET /admin/drivers/{driver_id}/trips
```

| Query Param | Type    | Required | Description                     |
|-------------|---------|----------|---------------------------------|
| status      | string  | No       | Filter by ride status           |
| from        | date    | No       | Start date (YYYY-MM-DD)         |
| to          | date    | No       | End date (YYYY-MM-DD)           |
| per_page    | integer | No       | Results per page (default: 20)  |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "reference_number": "TRP-NG-000142",
      "status": "completed",
      "status_label": "Completed",
      "pickup": { "lat": 6.5244, "lng": 3.3792, "address": "Lekki Phase 1" },
      "destination": { "lat": 6.4541, "lng": 3.4085, "address": "Victoria Island" },
      "fare_estimate_amount": "2500.00",
      "final_fare_amount": "2800.00",
      "fare_currency": "NGN",
      "passenger": { "id": "...", "first_name": "Ade", "last_name": "Ogunleye", "..." : "..." },
      "city": { "..." },
      "vehicle_class": { "..." },
      "completed_at": "2026-10-05T15:30:00.000000Z",
      "created_at": "2026-10-05T15:00:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 42 }
}
```

---

### Update Driver Vehicle Class
```
PUT /admin/drivers/{driver_id}/vehicle-class
```
Confirms or changes the vehicle class and/or fuel type of a driver's vehicle. Drivers choose a preferred class (and fuel type) during onboarding; after submission only E-tiGo can change them — the class determines which ride requests (and fares) the driver receives, and the fuel type whether they may use EV charging stalls. Audited as `vehicle_class_changed` or `vehicle_fuel_type_changed`. A class change is refused (`409`) while the driver is on an active ride.

**Roles:** `operations`, `safety_operator` (and `super_admin`).

| Field            | Type          | Required | Description |
|------------------|---------------|----------|-------------|
| vehicle_class_id | string (UUID) | One of the two | An **active** vehicle class |
| fuel_type        | string        | One of the two | `petrol`, `diesel`, `cng`, `hybrid`, `plug_in_hybrid` or `electric` |

**Response 200:**
```json
{
  "message": "Vehicle class updated.",
  "vehicle": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "plate_number": "LAG-123AB",
    "vehicle_class_id": "1c2d3e4f-5a6b-7c8d-9e0f-1a2b3c4d5e6f",
    "vehicle_class": { "id": "1c2d3e4f-5a6b-7c8d-9e0f-1a2b3c4d5e6f", "name": "premium", "display_name": "Premium" }
  }
}
```

**Response 409:** `"The vehicle class cannot be changed while the driver is on an active ride."`
**Response 422:** No registered vehicle, or `vehicle_class_id` missing/inactive.

---

### Complete Driver Onboarding & KYC (Admin Bypass)
```
POST /admin/drivers/{driver_id}/complete-onboarding
```
Marks a driver's onboarding and KYC as completed and approved in one action without requiring external third-party KYC verification (QoreID).

> **Note:** Testing/QA only. In production (`APP_ENV=production`) it always returns `403` — `"Manual onboarding and KYC bypass is disabled in production."`

**Requirements:**
The driver must have provided all required onboarding data:
- Vehicle ownership type selected (`own_vehicle` or `fleet_vehicle`)
- Licence number provided
- City selected
- Vehicle registered (if `own_vehicle`)
- All required documents uploaded (`driving_licence`, `government_id`, plus `vehicle_registration` and `insurance_certificate` if `own_vehicle`)

**Actions performed:**
- Approves all pending or rejected documents for the driver
- Marks required KYC verifications (`nin`, `drivers_license`, and if `own_vehicle`, `vehicle_plate`) as verified with provider reference `admin_bypass`
- Sets driver `kyc_status` to `verified` and `kyc_verified_at` to current timestamp
- Sets driver `status` to `approved` and `approved_at` to current timestamp
- Records audit log entry `driver_onboarding_and_kyc_completed_by_admin`

| Field | Type   | Required | Description                                                    |
|-------|--------|----------|----------------------------------------------------------------|
| notes | string | No       | Optional audit note or justification (max 500 characters)      |
| nin   | string | No       | Optional NIN number to record on the NIN verification (max 20) |

**Response 200:**
```json
{
  "message": "Driver onboarding and KYC marked as completed successfully.",
  "driver": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "approved",
    "kyc_status": "verified",
    "kyc_verified_at": "2026-10-10T12:00:00.000000Z",
    "approved_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

**Response 422 (Missing Required Onboarding Data):**
```json
{
  "message": "Cannot complete onboarding. Driver has not provided all required data.",
  "errors": [
    "Vehicle ownership type must be selected.",
    "Licence number must be provided.",
    "City must be selected."
  ]
}
```

---

## Admin — Fleet Vehicle Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,finance`

**Allowed Roles:** Operations, Finance (Super Admin always has access)

Fleet vehicles are company-owned vehicles pre-registered in inventory (without a driver) and later assigned to approved fleet drivers. Assignment creates a fleet agreement automatically.

### List Fleet Vehicles
```
GET /admin/fleet-vehicles
```

| Parameter       | Type    | Required | Description                                      |
|-----------------|---------|----------|--------------------------------------------------|
| unassigned_only | boolean | No       | `true` to show only unassigned (available) vehicles |
| fuel_type       | string  | No       | Filter by fuel type; `none` lists vehicles with no fuel type yet |
| search          | string  | No       | Search by make, model, or plate number           |

**Response 200:**
```json
{
  "vehicles": [
    {
      "id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
      "driver_id": null,
      "make": "Toyota",
      "model": "Corolla",
      "colour": "White",
      "plate_number": "FLEET-001",
      "year": 2024,
      "is_fleet": true,
      "is_assigned": false,
      "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "vehicle_class": {
        "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4
      },
      "created_at": "2026-10-09T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

---

### Register Fleet Vehicle
```
POST /admin/fleet-vehicles
```

Creates a new fleet vehicle in inventory with no driver assigned. The vehicle is created with `is_fleet: true` and `driver_id: null`.

| Field            | Type          | Required | Description                            |
|------------------|---------------|----------|----------------------------------------|
| make             | string        | Yes      | Vehicle make (e.g. Toyota)             |
| model            | string        | Yes      | Vehicle model (e.g. Corolla)           |
| colour           | string        | Yes      | Vehicle colour                         |
| plate_number     | string        | Yes      | Plate number (unique across all vehicles) |
| year             | integer       | Yes      | Year of manufacture (2000 – next year) |
| vehicle_class_id | string (UUID) | Yes      | Must exist in vehicle_classes          |
| fuel_type        | string        | Yes      | `petrol`, `diesel`, `cng`, `hybrid`, `plug_in_hybrid` or `electric`           |

**Response 201:**
```json
{
  "message": "Fleet vehicle registered successfully.",
  "vehicle": {
    "id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "driver_id": null,
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "FLEET-001",
    "year": 2024,
    "is_fleet": true,
    "is_assigned": false,
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "vehicle_class": { "...": "..." },
    "created_at": "2026-10-09T10:00:00.000000Z"
  }
}
```

---

### Show Fleet Vehicle
```
GET /admin/fleet-vehicles/{vehicle_id}
```

Returns a single fleet vehicle with its assigned driver and fleet agreements.

**Response 200:**
```json
{
  "vehicle": {
    "id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
    "driver": {
      "id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
      "name": "Bayo Akinola"
    },
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "FLEET-001",
    "year": 2024,
    "is_fleet": true,
    "is_assigned": true,
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "vehicle_class": { "...": "..." },
    "created_at": "2026-10-09T10:00:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "message": "Vehicle is not a fleet vehicle."
}
```

---

### Update Fleet Vehicle
```
PUT /admin/fleet-vehicles/{vehicle_id}
```

Updates a fleet vehicle's details. All fields are optional (partial update).

| Field            | Type          | Required | Description                                        |
|------------------|---------------|----------|----------------------------------------------------|
| make             | string        | No       | Vehicle make                                       |
| model            | string        | No       | Vehicle model                                      |
| colour           | string        | No       | Vehicle colour                                     |
| plate_number     | string        | No       | Plate number (unique, excluding current vehicle)   |
| year             | integer       | No       | Year of manufacture                                |
| vehicle_class_id | string (UUID) | No       | Must exist in vehicle_classes                      |
| fuel_type        | string        | No       | `petrol`, `diesel`, `cng`, `hybrid`, `plug_in_hybrid` or `electric`             |

**Response 200:**
```json
{
  "message": "Fleet vehicle updated successfully.",
  "vehicle": { "...": "..." }
}
```

**Response 404:**
```json
{
  "message": "Vehicle is not a fleet vehicle."
}
```

---

### Assign Fleet Vehicle to Driver
```
POST /admin/fleet-vehicles/{vehicle_id}/assign
```

Assigns an unassigned fleet vehicle to an approved driver. This also creates a fleet agreement automatically in a single transaction.

**Prerequisites:**
- Vehicle must be a fleet vehicle (`is_fleet: true`) and unassigned (`driver_id: null`).
- Driver must be approved and not already have a vehicle assigned.

| Field                   | Type          | Required | Validation                     | Description                        |
|-------------------------|---------------|----------|--------------------------------|------------------------------------|
| driver_id               | string (UUID) | Yes      | Must exist in `drivers`         | Approved driver to assign to       |
| daily_remittance_target | number        | Yes      | min: 1000                      | Daily remittance target amount     |
| total_vehicle_cost      | number        | Yes      | min: 100000                    | Total hire-to-own vehicle price    |
| agreement_start_date    | date (Y-m-d)  | Yes      | today or future                | When remittance tracking begins    |

**Response 200:**
```json
{
  "message": "Vehicle assigned to driver successfully. Fleet agreement created.",
  "vehicle": {
    "id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
    "driver": {
      "id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
      "name": "Bayo Akinola"
    },
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "FLEET-001",
    "year": 2024,
    "is_fleet": true,
    "is_assigned": true,
    "vehicle_class": { "...": "..." },
    "created_at": "2026-10-09T10:00:00.000000Z"
  },
  "driver": { "...": "..." }
}
```

**Response 422:**
```json
{
  "message": "Vehicle is already assigned to a driver."
}
```

```json
{
  "message": "Driver must be approved before vehicle assignment."
}
```

```json
{
  "message": "Driver already has a vehicle assigned."
}
```

---

### Unassign Fleet Vehicle
```
POST /admin/fleet-vehicles/{vehicle_id}/unassign
```

Removes the driver assignment from a fleet vehicle. Cannot unassign if there is an active fleet agreement — terminate the agreement first.

**Response 200:**
```json
{
  "message": "Vehicle unassigned from driver successfully.",
  "vehicle": {
    "id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "driver_id": null,
    "make": "Toyota",
    "model": "Corolla",
    "colour": "White",
    "plate_number": "FLEET-001",
    "year": 2024,
    "is_fleet": true,
    "is_assigned": false,
    "vehicle_class": { "...": "..." },
    "created_at": "2026-10-09T10:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "Vehicle is not assigned to any driver."
}
```

```json
{
  "message": "Cannot unassign vehicle with an active fleet agreement. Terminate the agreement first."
}
```

---

### Drivers Awaiting Vehicle Assignment
```
GET /admin/fleet-vehicles/drivers-awaiting
```

Lists approved fleet drivers who do not yet have a vehicle assigned. Useful for admins to find which fleet drivers need a vehicle.

**Response 200:**
```json
{
  "drivers": [
    {
      "id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
      "user": {
        "id": "f6a7b8c9-0d1e-2f3a-4b5c-6d7e8f9a0b1c",
        "first_name": "Bayo",
        "last_name": "Akinola",
        "phone": "+2348200000001",
        "email": "bayo@demo.etigo.com",
        "type": "driver"
      },
      "status": "approved",
      "vehicle_ownership_type": "fleet_vehicle",
      "kyc_status": "verified",
      "licence_number": "DL-12345678",
      "vehicle": null,
      "created_at": "2026-10-01T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

---

## Admin — Fleet Agreement Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,finance`

**Allowed Roles:** Operations, Finance (Super Admin always has access)

Fleet agreements represent hire-to-own arrangements between E-tiGo and fleet drivers. Each agreement links a driver to a fleet vehicle with a configurable daily remittance target and tracks payment progress toward full vehicle ownership.

### List Fleet Agreements
```
GET /admin/fleet-agreements
```

| Parameter | Type   | Required | Description                          |
|-----------|--------|----------|--------------------------------------|
| status    | string | No       | Filter by status: `active`, `paused`, `completed`, `terminated` |
| driver_id | string (UUID) | No | Filter by driver |

**Response 200:**
```json
{
  "agreements": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
      "vehicle_id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
      "daily_remittance_target": "40000.00",
      "total_remitted": "1250000.00",
      "progress_percentage": 25.0,
      "remaining_amount": "3750000.00",
      "total_vehicle_cost": "5000000.00",
      "agreement_start_date": "2026-10-09",
      "status": "active",
      "shortfall_streak_days": 0,
      "shortfall_flag": null,
      "outstanding_amount": "0.00",
      "settlement_amount": "0.00",
      "driver": { "...": "..." },
      "vehicle": { "...": "..." },
      "created_at": "2026-10-09T12:00:00.000000Z",
      "updated_at": "2026-10-09T12:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

> **Note:** `total_vehicle_cost` and `remaining_amount` are visible to both admin and driver users (Q7 reversal — hire-purchase disclosure requirements). Additional admin-only fields: `shortfall_flag`, `outstanding_amount`, `settlement_amount`. Conditional fields only present when non-null: `shortfall_flagged_at`, `vehicle_return_status`, `settlement_notes`.

---

### Create Fleet Agreement
```
POST /admin/fleet-agreements
```

| Field                   | Type           | Required | Validation              | Description                                  |
|-------------------------|----------------|----------|-------------------------|----------------------------------------------|
| driver_id               | string (UUID)  | Yes      | Must exist in `drivers`  | Driver to assign the agreement to            |
| vehicle_id              | string (UUID)  | Yes      | Must exist in `vehicles` | Fleet vehicle to assign                      |
| daily_remittance_target | number         | Yes      | min: 1000               | Daily target amount (e.g., 40000)            |
| total_vehicle_cost      | number         | Yes      | min: 100000             | Total hire-to-own price of the vehicle       |
| agreement_start_date    | date (Y-m-d)   | Yes      | today or future          | When remittance tracking begins             |

**Response 201:**
```json
{
  "message": "Fleet agreement created successfully.",
  "agreement": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
    "vehicle_id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "daily_remittance_target": "40000.00",
    "total_remitted": "0.00",
    "progress_percentage": 0,
    "remaining_amount": "5000000.00",
    "total_vehicle_cost": "5000000.00",
    "agreement_start_date": "2026-10-09",
    "status": "active",
    "shortfall_streak_days": 0,
    "driver": { "...": "..." },
    "vehicle": { "...": "..." },
    "created_at": "2026-10-09T12:00:00.000000Z",
    "updated_at": "2026-10-09T12:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "Driver already has an active fleet agreement."
}
```

```json
{
  "message": "Vehicle must be marked as fleet before creating an agreement."
}
```

**Prerequisites:**
- The vehicle must be marked as fleet (`is_fleet: true`) via the [Toggle Fleet Vehicle](#toggle-fleet-vehicle) endpoint before an agreement can be created.
- A driver can only have one active agreement at a time.

---

### Show Fleet Agreement
```
GET /admin/fleet-agreements/{fleet_agreement_id}
```

Returns agreement detail with the 30 most recent daily remittance records.

**Response 200:**
```json
{
  "agreement": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
    "vehicle_id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "daily_remittance_target": "40000.00",
    "total_remitted": "1250000.00",
    "progress_percentage": 25.0,
    "remaining_amount": "3750000.00",
    "total_vehicle_cost": "5000000.00",
    "agreement_start_date": "2026-10-09",
    "status": "active",
    "shortfall_streak_days": 2,
    "shortfall_flag": "warning",
    "shortfall_flagged_at": "2026-10-08T04:00:00.000000Z",
    "outstanding_amount": "0.00",
    "settlement_amount": "0.00",
    "driver": { "...": "..." },
    "vehicle": { "...": "..." },
    "daily_remittances": [ "..." ],
    "created_at": "2026-10-09T12:00:00.000000Z",
    "updated_at": "2026-10-09T12:00:00.000000Z"
  }
}
```

---

### Update Fleet Agreement
```
PUT /admin/fleet-agreements/{fleet_agreement_id}
```

Only active agreements can be updated.

| Field                   | Type   | Required | Validation | Description                   |
|-------------------------|--------|----------|------------|-------------------------------|
| daily_remittance_target | number | No       | min: 1000  | Updated daily target amount   |

**Response 200:**
```json
{
  "message": "Fleet agreement updated successfully.",
  "agreement": { "...": "..." }
}
```

**Response 422:**
```json
{
  "message": "Only active agreements can be updated."
}
```

---

### Terminate Fleet Agreement
```
POST /admin/fleet-agreements/{fleet_agreement_id}/terminate
```

Permanently terminates an agreement. Cannot terminate already completed or terminated agreements.

| Field  | Type   | Required | Validation | Description               |
|--------|--------|----------|------------|---------------------------|
| reason | string | Yes      | max: 1000  | Reason for termination    |

**Response 200:**
```json
{
  "message": "Fleet agreement terminated.",
  "agreement": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "terminated",
    "terminated_reason": "Driver violated terms",
    "terminated_at": "2026-10-09T14:30:00.000000Z",
    "...": "..."
  }
}
```

**Response 422:**
```json
{
  "message": "Agreement is already terminated."
}
```

---

### Pause Fleet Agreement
```
POST /admin/fleet-agreements/{fleet_agreement_id}/pause
```

Pauses an active agreement (e.g., during vehicle maintenance or driver leave). Only active agreements can be paused.

**Response 200:**
```json
{
  "message": "Fleet agreement paused.",
  "agreement": {
    "status": "paused",
    "paused_at": "2026-10-09T14:30:00.000000Z",
    "...": "..."
  }
}
```

---

### Resume Fleet Agreement
```
POST /admin/fleet-agreements/{fleet_agreement_id}/resume
```

Resumes a paused agreement. Only paused agreements can be resumed.

**Response 200:**
```json
{
  "message": "Fleet agreement resumed.",
  "agreement": {
    "status": "active",
    "...": "..."
  }
}
```

---

### Swap Fleet Vehicle
```
POST /admin/fleet-agreements/{fleet_agreement_id}/swap
```

Swaps the vehicle on an active agreement. This terminates the current agreement and creates a new one with the replacement vehicle. Optionally carries over the total remitted amount from the old agreement.

| Field                   | Type           | Required | Validation                      | Description                                        |
|-------------------------|----------------|----------|---------------------------------|----------------------------------------------------|
| new_vehicle_id          | string (UUID)  | Yes      | Must exist in `vehicles`         | Replacement fleet vehicle (must be unassigned)      |
| reason                  | string         | Yes      | max: 1000                       | Reason for the swap                                |
| carry_over_remitted     | boolean        | Yes      |                                 | Whether to carry over `total_remitted` to new agreement |
| daily_remittance_target | number         | No       | min: 1000                       | New daily target (defaults to previous agreement's) |
| total_vehicle_cost      | number         | No       | min: 100000                     | New total cost (defaults to previous agreement's)  |

**Response 200:**
```json
{
  "message": "Vehicle swapped successfully. Previous agreement terminated, new agreement created.",
  "agreement": {
    "id": "b2c3d4e5-f6a7-8901-bcde-f23456789012",
    "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
    "vehicle_id": "c3d4e5f6-a7b8-9012-cdef-345678901234",
    "daily_remittance_target": "40000.00",
    "total_remitted": "500000.00",
    "progress_percentage": 10.0,
    "remaining_amount": "4500000.00",
    "total_vehicle_cost": "5000000.00",
    "status": "active",
    "shortfall_streak_days": 0,
    "driver": { "...": "..." },
    "vehicle": { "...": "..." },
    "created_at": "2026-10-10T14:00:00.000000Z",
    "updated_at": "2026-10-10T14:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "Cannot swap vehicle on a non-active agreement."
}
```

**How it works:**
1. The current agreement is terminated with the reason provided
2. The old vehicle is unassigned from the driver
3. The new vehicle is assigned to the driver
4. A new agreement is created — if `carry_over_remitted` is `true`, `total_remitted` from the old agreement carries over; otherwise starts at `0.00`
5. Audit log records the swap with both old and new agreement IDs

---

### Terminate with Settlement
```
POST /admin/fleet-agreements/{fleet_agreement_id}/terminate-settle
```

Terminates an agreement with full settlement details including vehicle return status and financial reconciliation. Use this instead of the basic terminate endpoint when you need to record settlement information.

| Field                | Type   | Required | Validation                                              | Description                                              |
|----------------------|--------|----------|---------------------------------------------------------|----------------------------------------------------------|
| reason               | string | Yes      | max: 1000                                               | Reason for termination                                   |
| vehicle_return_status| string | No       | `returned`, `pending_return`, `not_returned`, `damaged`  | Status of vehicle return                                 |
| outstanding_amount   | number | No       | min: 0                                                  | Outstanding amount owed by driver                        |
| settlement_amount    | number | No       | min: 0                                                  | Amount settled/agreed upon                               |
| settlement_notes     | string | No       | max: 2000                                               | Free-text notes about the settlement                     |

**Response 200:**
```json
{
  "message": "Fleet agreement terminated with settlement recorded.",
  "agreement": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "status": "terminated",
    "terminated_reason": "Driver quit voluntarily",
    "terminated_at": "2026-10-10T14:30:00.000000Z",
    "vehicle_return_status": "returned",
    "outstanding_amount": "150000.00",
    "settlement_amount": "50000.00",
    "settlement_notes": "Driver paid partial settlement. Remaining waived per HR decision.",
    "settled_at": "2026-10-10T14:30:00.000000Z",
    "...": "..."
  }
}
```

**Response 422:**
```json
{
  "message": "Agreement is already terminated."
}
```

---

### Excuse Remittance Day
```
POST /admin/fleet-agreements/{fleet_agreement_id}/remittances/{remittance_id}/excuse
```

Marks a settled remittance day as excused. This decrements the driver's shortfall streak by 1 (if > 0) since excused days should not count toward shortfall escalation.

| Field  | Type   | Required | Validation                                                           | Description                          |
|--------|--------|----------|----------------------------------------------------------------------|--------------------------------------|
| reason | string | Yes      | `approved_leave`, `vehicle_downtime`, `low_demand`, `payment_failure`, `other` | Category of excuse                   |
| notes  | string | No       | max: 1000                                                            | Additional context                   |

**Response 200:**
```json
{
  "message": "Remittance day marked as excused.",
  "remittance": {
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "date": "2026-10-08",
    "target_amount": "40000.00",
    "remitted_amount": "20000.00",
    "shortfall_amount": "20000.00",
    "target_met": false,
    "target_met_at": null,
    "ride_count": 5,
    "total_fares": "20000.00",
    "driver_earnings": "0.00",
    "settled": true,
    "excused_reason": "vehicle_downtime: Vehicle was in maintenance",
    "created_at": "2026-10-08T06:00:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "Remittance does not belong to this agreement."
}
```

**Excused day categories:**

| Value              | Description                                           |
|--------------------|-------------------------------------------------------|
| `approved_leave`   | Driver on approved leave                              |
| `vehicle_downtime` | Vehicle under maintenance or repair                   |
| `low_demand`       | Low ride demand day (admin discretion)                |
| `payment_failure`  | Payment system issue preventing ride completions      |
| `other`            | Other reason (requires notes)                         |

---

## Driver — Fleet Remittance

All remittance endpoints require `Authorization: Bearer {token}` from a driver user.

These endpoints provide fleet drivers with visibility into their daily remittance progress and agreement status. The remittance-first model means the first ₦X of each day's fares go to E-tiGo, and only after the daily target is met does the driver earn for themselves.

### Today's Remittance
```
GET /driver/remittance/today
```

Returns the current day's remittance progress. If the driver has no active fleet agreement, returns `null`.

**Response 200 (active agreement):**
```json
{
  "remittance": {
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "date": "2026-10-09",
    "target_amount": "40000.00",
    "remitted_amount": "28500.00",
    "shortfall_amount": "11500.00",
    "target_met": false,
    "target_met_at": null,
    "ride_count": 7,
    "total_fares": "28500.00",
    "driver_earnings": "0.00",
    "settled": false,
    "created_at": "2026-10-09T06:15:00.000000Z"
  }
}
```

**Response 200 (target met — driver earning):**
```json
{
  "remittance": {
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "date": "2026-10-09",
    "target_amount": "40000.00",
    "remitted_amount": "40000.00",
    "shortfall_amount": "0.00",
    "target_met": true,
    "target_met_at": "2026-10-09T14:22:00.000000Z",
    "ride_count": 10,
    "total_fares": "48200.00",
    "driver_earnings": "8200.00",
    "settled": false,
    "created_at": "2026-10-09T06:15:00.000000Z"
  }
}
```

**Response 200 (no active agreement):**
```json
{
  "message": "No active fleet agreement.",
  "remittance": null
}
```

---

### Remittance History
```
GET /driver/remittance/history
```

Paginated list of daily remittance records, most recent first.

**Response 200:**
```json
{
  "remittances": [
    {
      "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "date": "2026-10-08",
      "target_amount": "40000.00",
      "remitted_amount": "40000.00",
      "shortfall_amount": "0.00",
      "target_met": true,
      "target_met_at": "2026-10-08T15:10:00.000000Z",
      "ride_count": 12,
      "total_fares": "55000.00",
      "driver_earnings": "15000.00",
      "settled": true,
      "created_at": "2026-10-08T06:00:00.000000Z"
    },
    {
      "id": "b2c3d4e5-f6a7-8901-bcde-f23456789012",
      "date": "2026-10-07",
      "target_amount": "40000.00",
      "remitted_amount": "32000.00",
      "shortfall_amount": "8000.00",
      "target_met": false,
      "target_met_at": null,
      "ride_count": 8,
      "total_fares": "32000.00",
      "driver_earnings": "0.00",
      "settled": true,
      "created_at": "2026-10-07T06:30:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 45
  }
}
```

---

### Fleet Agreement (Driver View)
```
GET /driver/fleet-agreement
```

Returns the driver's active fleet agreement. **Now includes `total_vehicle_cost` and `remaining_amount`** per hire-purchase disclosure requirements (Q7 reversal). The UI should emphasise percentage progress but drivers must have access to the full financial terms.

**Response 200 (active agreement):**
```json
{
  "agreement": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "driver_id": "8e2b6d1a-0c3f-4a5e-9b7d-1e4f5a6b7c8d",
    "vehicle_id": "7d1a5c0e-9b3f-4e2d-8a6c-0f5e4d3c2b1a",
    "daily_remittance_target": "40000.00",
    "total_vehicle_cost": "5000000.00",
    "total_remitted": "1250000.00",
    "remaining_amount": "3750000.00",
    "progress_percentage": 25.0,
    "agreement_start_date": "2026-10-09",
    "status": "active",
    "shortfall_streak_days": 0,
    "vehicle": { "...": "..." },
    "created_at": "2026-10-09T12:00:00.000000Z",
    "updated_at": "2026-10-09T12:00:00.000000Z"
  }
}
```

**Response 200 (no active agreement):**
```json
{
  "message": "No active fleet agreement.",
  "agreement": null
}
```

---

## Driver — Nearby Drivers & Rides

**Middleware:** `auth:sanctum`, `user.type:driver`

### List Nearby Drivers (Driver)

```
GET /driver/nearby-drivers
Authorization: Bearer {driver_token}
```

Returns online, approved drivers near the given coordinates. The requesting driver is excluded from results. Only drivers with a recent location update (within 120 seconds) are included. Results are capped at 50 drivers.

| Query Param | Type   | Required | Description                                   |
|-------------|--------|----------|-----------------------------------------------|
| lat         | number | Yes      | Latitude (-90 to 90)                          |
| lng         | number | Yes      | Longitude (-180 to 180)                       |
| radius_km   | number | No       | Search radius in km (default: 10, max: 50)    |

**Response 200:**
```json
{
  "drivers": [
    {
      "driver_id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "lat": 6.5290,
      "lng": 3.3820,
      "heading": 270.0,
      "timestamp": 1728223195,
      "distance_km": 1.23,
      "vehicle_class": "Comfort"
    },
    {
      "driver_id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
      "lat": 6.5100,
      "lng": 3.3680,
      "heading": 90.5,
      "timestamp": 1728223190,
      "distance_km": 2.05,
      "vehicle_class": "Economy"
    }
  ]
}
```

**Response 422:** Validation error (missing `lat`/`lng`, or values out of range).

---

### List Nearby Rides

```
GET /driver/nearby-rides
Authorization: Bearer {driver_token}
```

Returns unassigned ride requests near the driver's current location. The driver must be approved, online, have a registered vehicle, and have no active ride. Only rides in the same city and matching the driver's vehicle class are included. Driver location is read from Redis (must have been updated within the last 120 seconds).

| Query Param | Type   | Required | Description                                |
|-------------|--------|-----------|--------------------------------------------|
| radius_km   | number | No        | Search radius in km (default: 10, max: 50) |

**Response 200:**
```json
{
  "rides": [
    {
      "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
      "pickup_lat": 6.5244,
      "pickup_lng": 3.3792,
      "distance_km": 0.542,
      "created_at": "2026-10-06T14:00:00.000000Z"
    },
    {
      "ride_id": "02b21f7a-56gd-835f-0b8e-94cb0ef01f47",
      "pickup_lat": 6.5180,
      "pickup_lng": 3.3750,
      "distance_km": 1.237,
      "created_at": "2026-10-06T14:01:30.000000Z"
    }
  ]
}
```

Returns an empty `rides` array when the driver is offline, not approved, has no vehicle, has an active ride, or no location is available.

---

## Driver — Statistics

**Middleware:** `auth:sanctum`, `user.type:driver`

### Driver Stats

```
GET /driver/stats
Authorization: Bearer {driver_token}
```

Returns summary statistics for the authenticated driver including lifetime completed trips, average rating, and current week's earnings.

**Response 200:**
```json
{
  "stats": {
    "completed_trips": 142,
    "average_rating": 4.73,
    "ratings_count": 128,
    "earnings_this_week": 87500.00,
    "earnings_currency": "NGN"
  }
}
```

| Field              | Type         | Description                                      |
|--------------------|--------------|--------------------------------------------------|
| completed_trips    | integer      | Total lifetime completed rides                   |
| average_rating     | number\|null | Average rating score (null if no ratings yet)    |
| ratings_count      | integer      | Total number of ratings received                 |
| earnings_this_week | number       | Sum of final fares for completed rides this week (Mon–Sun) |
| earnings_currency  | string       | Currency code (always `NGN`)                     |

---

## Admin — Passenger Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:support,safety_operator`

**Allowed Roles:** Support, Safety (Super Admin always has access)

### List Passengers
```
GET /admin/passengers
```

| Parameter | Type    | Required | Description                           |
|-----------|---------|----------|---------------------------------------|
| search    | string  | No       | Search by name, email, or phone       |
| is_active | bool    | No       | Filter by active/inactive status      |
| per_page  | integer | No       | Results per page (default: 20)        |

**Response 200:**
```json
{
  "passengers": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "reference_number": "P-000001",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "phone": "+2348100000001",
      "email": "ade@demo.etigo.com",
      "type": "passenger",
      "phone_verified_at": "2026-10-01T10:00:00.000000Z",
      "is_active": true,
      "profile_photo_url": null,
      "total_rides": 15,
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
    "reference_number": "P-000001",
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

### Passenger Trip History
```
GET /admin/passengers/{passenger_id}/trips
```

| Query Param | Type    | Required | Description                     |
|-------------|---------|----------|---------------------------------|
| status      | string  | No       | Filter by ride status           |
| from        | date    | No       | Start date (YYYY-MM-DD)         |
| to          | date    | No       | End date (YYYY-MM-DD)           |
| per_page    | integer | No       | Results per page (default: 20)  |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "reference_number": "TRP-NG-000042",
      "status": "completed",
      "pickup": { "lat": 6.5244, "lng": 3.3792, "address": "Lekki Phase 1" },
      "destination": { "lat": 6.4541, "lng": 3.4085, "address": "Victoria Island" },
      "fare_estimate_amount": "2500.00",
      "final_fare_amount": "2800.00",
      "fare_currency": "NGN",
      "driver": { "..." },
      "city": { "..." },
      "vehicle_class": { "..." },
      "completed_at": "2026-10-05T15:30:00.000000Z",
      "created_at": "2026-10-05T15:00:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 15 }
}
```

---

### Suspend Passenger
```
POST /admin/passengers/{passenger_id}/suspend
```
Suspends passenger account and revokes all active tokens.

| Field  | Type   | Required | Description                       |
|--------|--------|----------|-----------------------------------|
| reason | string | Yes      | Reason for suspension (max 500 chars) |

**Response 200:**
```json
{
  "message": "Passenger account suspended.",
  "passenger": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "is_active": false,
    "suspension_reason": "Account abuse detected.",
    "...": "..."
  }
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
Reactivates a suspended passenger account. Clears `suspension_reason`.

**Response 200:**
```json
{
  "message": "Passenger account reactivated.",
  "passenger": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "is_active": true,
    "suspension_reason": null,
    "...": "..."
  }
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
| admin_role | string | Yes      | `operations`, `safety_operator`, `support`, or `finance` |

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
| admin_role | string | Yes      | `operations`, `safety_operator`, `support`, or `finance` |

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
      "state": "Lagos",
      "region": "South-West",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
      "area_sq_km": 5026.55,
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
      "state": "FCT",
      "region": "North-Central",
      "boundary": { "type": "Point", "coordinates": [7.4951, 9.0579], "radius_km": 30 },
      "area_sq_km": 2827.43,
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
      "state": "Rivers",
      "region": "South-South",
      "boundary": { "type": "Point", "coordinates": [7.0498, 4.8156], "radius_km": 20 },
      "area_sq_km": 1256.64,
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
    "state": "Lagos",
    "region": "South-West",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
    "area_sq_km": 5026.55,
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

## Public — Lookups

### List Cancellation Reasons

```
GET /lookup/cancellation-reasons
```

No authentication required. Returns cancellation reasons used when cancelling a ride. Optionally filter by role.

| Query Param | Type   | Required | Description                                       |
|-------------|--------|----------|---------------------------------------------------|
| role        | string | No       | `passenger` or `driver` — filters to role-specific reasons. Omit for all reasons. |

When called by an authenticated user without specifying `role`, the role is inferred from the user's account type.

**Response 200:**
```json
{
  "reasons": [
    {
      "title": "Changed my mind",
      "description": "I no longer need a ride or my travel plans have changed.",
      "code": "changed_mind",
      "allows_custom_description": false
    },
    {
      "title": "Driver is too far",
      "description": "The assigned driver is located too far away from the pickup point.",
      "code": "driver_too_far",
      "allows_custom_description": false
    },
    {
      "title": "Wait time too long",
      "description": "The estimated pickup arrival time is longer than expected.",
      "code": "wait_too_long",
      "allows_custom_description": false
    },
    {
      "title": "Other",
      "description": "Provide a custom reason if none of the options above apply.",
      "code": "other",
      "allows_custom_description": true
    }
  ]
}
```

---

### List Geopolitical Regions

```
GET /lookup/regions
```

No authentication required. Returns Nigeria's six geopolitical zones with their member states.

**Response 200:**
```json
{
  "regions": [
    {
      "name": "North-Central",
      "states": ["Benue", "FCT", "Kogi", "Kwara", "Nasarawa", "Niger", "Plateau"]
    },
    {
      "name": "North-East",
      "states": ["Adamawa", "Bauchi", "Borno", "Gombe", "Taraba", "Yobe"]
    },
    {
      "name": "North-West",
      "states": ["Jigawa", "Kaduna", "Kano", "Katsina", "Kebbi", "Sokoto", "Zamfara"]
    },
    {
      "name": "South-East",
      "states": ["Abia", "Anambra", "Ebonyi", "Enugu", "Imo"]
    },
    {
      "name": "South-South",
      "states": ["Akwa Ibom", "Bayelsa", "Cross River", "Delta", "Edo", "Rivers"]
    },
    {
      "name": "South-West",
      "states": ["Ekiti", "Lagos", "Ogun", "Ondo", "Osun", "Oyo"]
    }
  ]
}
```

---

### List Nigerian States

```
GET /lookup/states
```

No authentication required. Returns all 36 Nigerian states plus the Federal Capital Territory (FCT), each with its capital and geopolitical zone.

**Response 200:**
```json
{
  "states": [
    {
      "name": "Lagos",
      "capital": "Ikeja",
      "region": "South-West"
    },
    {
      "name": "Abuja",
      "capital": "Abuja",
      "region": "North-Central"
    },
    {
      "name": "Rivers",
      "capital": "Port Harcourt",
      "region": "South-South"
    }
  ]
}
```

---

## Places / Geocoding

**Middleware:** `auth:sanctum`

Rate limited to 60 requests per minute. Results are cached server-side (search: 6 hours, reverse: 7 days).

### Place Search (Autocomplete)

```
GET /places/search
Authorization: Bearer {token}
```

| Query Param   | Type    | Required | Description                                      |
|---------------|---------|----------|--------------------------------------------------|
| q             | string  | Yes      | Search query (1–255 characters)                  |
| proximity_lat | number  | No       | Latitude to bias results toward (-90 to 90)      |
| proximity_lng | number  | No       | Longitude to bias results toward (-180 to 180)   |
| limit         | integer | No       | Max results to return (1–20, default: 10)        |

**Response 200:**
```json
{
  "places": [
    {
      "id": "place_abc123",
      "name": "Lekki Phase 1",
      "address": "Lekki Phase 1, Lekki, Lagos, Nigeria",
      "latitude": 6.4478,
      "longitude": 3.4723
    },
    {
      "id": "place_def456",
      "name": "Lekki Conservation Centre",
      "address": "Km 19 Lekki-Epe Expressway, Lagos, Nigeria",
      "latitude": 6.4382,
      "longitude": 3.5432
    }
  ]
}
```

**Response 422:** Validation error (missing or invalid `q`, out-of-range coordinates).

---

### Reverse Geocode

```
GET /places/reverse
Authorization: Bearer {token}
```

| Query Param | Type   | Required | Description                         |
|-------------|--------|----------|-------------------------------------|
| lat         | number | Yes      | Latitude (-90 to 90)               |
| lng         | number | Yes      | Longitude (-180 to 180)            |

**Response 200:**
```json
{
  "place": {
    "id": "place_xyz789",
    "place_id": "ChIJwYCC-x6LOxARy0GsFExlA3Y",
    "name": "Herbert Macaulay Way",
    "address": "123 Herbert Macaulay Way, Yaba, Lagos, Nigeria",
    "latitude": 6.5144,
    "longitude": 3.3753,
    "city": "Lagos"
  }
}
```

**Response 422:** Validation error (missing or out-of-range coordinates).

---

## Admin — City Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access)

### List Cities
```
GET /admin/cities
```
| Query Param | Type    | Description                       |
|-------------|---------|-----------------------------------|
| is_active   | boolean | Filter by active/inactive status  |
| search      | string  | Filter by city name (partial)     |
| state       | string  | Filter by state (e.g. `Lagos`)    |
| region      | string  | Filter by region (e.g. `South-West`) |
| per_page    | integer | Results per page (default: 20, max: 100) |

**Response 200:**
```json
{
  "cities": [
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "name": "Lagos",
      "slug": "lagos",
      "state": "Lagos",
      "region": "South-West",
      "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
      "area_sq_km": 5026.55,
      "timezone": "Africa/Lagos",
      "currency_code": "NGN",
      "is_active": true,
      "vehicle_classes_count": 3,
      "drivers_count": 12,
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
| Field             | Type   | Required | Description                                          |
|-------------------|--------|----------|------------------------------------------------------|
| name              | string | Yes      | Unique city name                                     |
| state             | string | Yes      | Nigerian state (e.g. `Lagos`, `FCT`, `Rivers`)       |
| boundary          | object | No       | GeoJSON with `type`, `coordinates`, optional `radius_km` |
| vehicle_class_ids | array  | No       | Array of vehicle class UUIDs to assign               |
| timezone          | string | No       | Valid PHP timezone (default: `Africa/Lagos`)          |
| currency_code     | string | No       | 3-letter currency code (default: `NGN`)              |

`region` is auto-derived from `state`. `area_sq_km` is auto-computed from the boundary (π×r² for Point, spherical excess for Polygon).

**Response 201:**
```json
{
  "message": "City created successfully.",
  "city": {
    "id": "d4e5f6a7-8b9c-0d1e-2f3a-4b5c6d7e8f9a",
    "name": "Kano",
    "slug": "kano",
    "state": "Kano",
    "region": "North-West",
    "boundary": { "type": "Point", "coordinates": [8.5167, 12.0022], "radius_km": 25 },
    "area_sq_km": 1963.5,
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "vehicle_classes": [],
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
Returns city with loaded vehicle classes and aggregate counts.

**Response 200:**
```json
{
  "city": {
    "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "name": "Lagos",
    "slug": "lagos",
    "state": "Lagos",
    "region": "South-West",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
    "area_sq_km": 5026.55,
    "timezone": "Africa/Lagos",
    "currency_code": "NGN",
    "is_active": true,
    "vehicle_classes_count": 3,
    "drivers_count": 12,
    "trips_count": 145,
    "active_rides_count": 3,
    "active_drivers_count": 8,
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
    "state": "Lagos",
    "region": "South-West",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 45 },
    "area_sq_km": 6361.73,
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
    "state": "Lagos",
    "region": "South-West",
    "boundary": { "type": "Point", "coordinates": [3.3792, 6.5244], "radius_km": 40 },
    "area_sq_km": 5026.55,
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

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access)

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

**How `fare_estimate` is calculated:** metered fare (base + distance + time, never below the minimum fare) × current surge multiplier − the passenger's loyalty-tier booking-fee discount. The discount is a percentage of the base fare and is returned separately as `booking_fee_discount` (NGN). `POST /rides` and the final fare use the same calculation, so the price shown here is the price charged (an upfront fare). Only two things can change it: a waiting charge after the free minutes, and a trip that runs significantly longer than the quoted time (more than 25% and at least 10 minutes over), which bills the actual trip time, capped at 3× the quoted time. A quicker trip keeps the quote.

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
      "fare_estimate": "4100.00",
      "distance_km": 10.0,
      "duration_minutes": 25.0,
      "currency": "NGN",
      "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos, Nigeria",
      "destination_address": "456 Broad Street, Lagos Island, Lagos, Nigeria",
      "pricing_snapshot": {
        "pricing_config_id": "0f8e7d6c-5b4a-4392-8a1b-2c3d4e5f6a7b",
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
      },
      "booking_fee_discount": 0
    },
    {
      "vehicle_class": {
        "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
        "name": "comfort",
        "display_name": "Comfort",
        "capacity": 4,
        "icon": "comfort"
      },
      "fare_estimate": "8325.00",
      "distance_km": 10.0,
      "duration_minutes": 25.0,
      "currency": "NGN",
      "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos, Nigeria",
      "destination_address": "456 Broad Street, Lagos Island, Lagos, Nigeria",
      "pricing_snapshot": {
        "pricing_config_id": "1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d",
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
      },
      "booking_fee_discount": 0
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
    "destination_city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
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

**What the quote locks in:** the `pricing_snapshot` records the rates, the surge multiplier in effect at booking (`surge_multiplier`, `surge_rule_name`) and the passenger's tier booking-fee discount (`booking_fee_discount_pct`, `tier_name`). It also records the quoted route (`distance_km`, `duration_minutes`), which the final fare reuses (upfront fare: the time charge changes only when the trip runs significantly longer), with these same values, so a surge that ends mid-trip does not change the agreed multiplier. `fare_estimate_amount` already includes surge and the tier discount (before any promo).

**PIN:** valid for 6 hours (the whole pickup phase). If the driver uses up the 3 attempts, the passenger can issue a new PIN with `POST /rides/{ride}/pin/regenerate`.

On creation, the **matching engine** is automatically triggered: `DispatchRideRequestJob` searches for nearby eligible drivers via Redis GEOSEARCH and dispatches the ride to the nearest candidate. A `MatchingTimeoutJob` is also scheduled as a safety net (default 300s). See [Matching Engine — How It Works](#matching-engine--how-it-works) for details.

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
| payment_method     | string  | Yes      | `cash`, `card`, `wallet`, or `bank_transfer` |
| promo_code         | string  | No       | Promo code to apply (max 50 chars). **All** rules are checked at booking against this ride (user eligibility, city, vehicle class, pickup geo-fence, minimum fare vs. the estimate, peak/off-peak in the city's timezone) — an invalid code returns `422` on `promo_code`. The discount is applied to the final fare at completion; the code's validity window and peak rules are honoured as they were at booking. |

**Response 201:**
```json
{
  "message": "Ride created successfully.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "passenger_id": "1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f",
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
    "discount_amount": null,
    "fare_currency": "NGN",
    "promo_code": null,
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

Other `422` validation messages: `vehicle_class_id` — `"This vehicle class is not available in the selected city."` (inactive in the city or deactivated platform-wide) or `"Pricing is not yet configured for this vehicle class in the selected city."`; `promo_code` — the promo's rejection reason (e.g. `"This promo code is not valid in your city."`); `payment_method` — wallet problems such as `"Insufficient wallet balance."`.

---

### List Rides
```
GET /rides
```

**Auth required.** Passengers see their own rides, drivers see rides assigned to them, and admins see all rides. Supports filtering and pagination.

| Query Param | Type    | Description                          |
|-------------|---------|--------------------------------------|
| status      | string  | Filter by ride status enum value (unknown values are ignored) |
| city_id     | string (UUID) | Filter by city (`422` if not a UUID) |
| from_date   | date    | Filter rides from this date (`422` if not a date) |
| to_date     | date    | Filter rides until this date (`422` if not a date) |
| per_page    | integer | Results per page (default 15, clamped to 1–50) |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
      "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
      "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "passenger_id": "1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f",
      "driver_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
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
      "discount_amount": "500.00",
      "fare_currency": "NGN",
      "promo_code": {
        "id": "9e1f2a3b-4c5d-6e7f-8901-abcdef123456",
        "code": "LAGOS20"
      },
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

**Auth required.** Returns full ride details with state transition history. Passengers see their rides, drivers see assigned rides, admins see any ride. The PIN code is only returned to the passenger.

**Response 200:**
```json
{
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "reference_number": "TRP-NG-000418",
    "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "passenger_id": "1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f",
    "driver_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
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
    "discount_amount": null,
    "fare_currency": "NGN",
    "pricing_snapshot": {
      "pricing_config_id": "0f8e7d6c-5b4a-4392-8a1b-2c3d4e5f6a7b",
      "version": 1,
      "base_fare": "600.00",
      "per_km_rate": "250.00",
      "per_minute_rate": "40.00",
      "minimum_fare": "1500.00",
      "waiting_time_rate": "50.00",
      "free_waiting_minutes": 5,
      "effective_from": "2026-09-30T00:00:00+00:00",
      "captured_at": "2026-10-06T14:00:00+00:00",
      "distance_km": 10.0,
      "duration_minutes": 25.0,
      "surge_multiplier": 1.0,
      "surge_rule_name": null,
      "booking_fee_discount_pct": 0,
      "tier_name": "Bronze"
    },
    "distance_km": 10.0,
    "duration_minutes": 25.0,
    "payment_method": "cash",
    "payment_status": "pending",
    "tip_amount": null,
    "cancellation_reason": null,
    "city": { "...": "..." },
    "vehicle_class": { "...": "..." },
    "passenger": { "...": "..." },
    "driver": {
      "id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
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
        "triggered_by_id": "1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f",
        "metadata": null,
        "created_at": "2026-10-06T14:00:00.000000Z"
      },
      {
        "id": "a1b2c3d4-5e6f-4a8b-9c0d-1e2f3a4b5c6d",
        "from_state": "searching",
        "to_state": "matched",
        "triggered_by_type": "driver",
        "triggered_by_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
        "metadata": null,
        "created_at": "2026-10-06T14:01:00.000000Z"
      },
      {
        "id": "b2c3d4e5-6f7a-4b9c-8d1e-2f3a4b5c6d7e",
        "from_state": "matched",
        "to_state": "driver_en_route",
        "triggered_by_type": "driver",
        "triggered_by_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
        "metadata": null,
        "created_at": "2026-10-06T14:01:30.000000Z"
      },
      {
        "id": "c3d4e5f6-7a8b-4c0d-9e2f-3a4b5c6d7e8f",
        "from_state": "driver_en_route",
        "to_state": "driver_arrived",
        "triggered_by_type": "driver",
        "triggered_by_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
        "metadata": null,
        "created_at": "2026-10-06T14:04:00.000000Z"
      },
      {
        "id": "d4e5f6a7-8b9c-4d1e-8f3a-4b5c6d7e8f9a",
        "from_state": "driver_arrived",
        "to_state": "in_progress",
        "triggered_by_type": "driver",
        "triggered_by_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
        "metadata": null,
        "created_at": "2026-10-06T14:05:00.000000Z"
      }
    ],
    "matched_at": "2026-10-06T14:01:00.000000Z",
    "started_at": "2026-10-06T14:05:00.000000Z",
    "completed_at": null,
    "pin_code": "4821",
    "created_at": "2026-10-06T14:00:00.000000Z",
    "updated_at": "2026-10-06T14:05:00.000000Z"
  }
}
```

`pin_code` is returned only to the ride's passenger. `promo_code` (`{ "id", "code" }`) appears only when a promo was applied, and `payment` appears once a payment record exists.

**`pricing_snapshot` keys added as the ride progresses** (all amounts in NGN):

| Key | Added | Meaning |
|-----|-------|---------|
| `distance_km` | Booking | Quoted route distance, charged on the final fare |
| `duration_minutes` | Booking, replaced at completion | Quoted travel time, then the minutes billed |
| `quoted_distance_km`, `quoted_duration_minutes` | Completion | The route quoted at booking, kept for reference |
| `actual_trip_minutes` | Completion | Trip start (PIN) to completion; `null` if either time is missing |
| `time_adjusted` | Completion | `true` when the trip ran past the tolerance and the actual time was billed |
| `time_capped` | Completion | `true` when the billed time hit the cap (3× the quoted time); worth reviewing, the trip may not have been ended on time |
| `surge_multiplier`, `surge_rule_name` | Booking | Surge locked in at booking and used for the final fare |
| `booking_fee_discount_pct`, `tier_name` | Booking | Passenger's loyalty-tier discount locked in at booking |
| `waiting_minutes`, `waiting_charge` | Completion | Chargeable waiting after the free minutes |
| `tier_discount` | Completion (if > 0) | `{ "tier", "percentage", "amount" }` actually deducted |
| `promo` | Completion (if a promo applied) | `{ "promo_code_id", "promo_code", "discount_amount", "raw_fare" }` |
| `fleet_remittance` | Settlement (fleet drivers) | `{ "agreement_id", "date", "remittance_share", "driver_share" }` |

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

**Auth required.** Cancels a ride that is in a cancellable state (`requested`, `searching`, `matched`, `driver_en_route`, `driver_arrived`, `no_driver_found`). Passengers can dismiss a ride that found no driver. In-progress and completed rides cannot be cancelled. Any wallet hold is released.

The other party is notified with who cancelled — the passenger, the driver, or E-tiGo support (admin) — and the human-readable reason.

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

**Response 409** — the ride changed state at the same moment (e.g. the trip just started):
```json
{
  "message": "Invalid ride state transition from 'in_progress' to 'cancelled'.",
  "error_code": "INVALID_STATE_TRANSITION",
  "current_status": "in_progress"
}
```

---

### Driver Arrived
```
POST /rides/{ride}/driver-arrived
```

**Auth required. Middleware:** `user.type:driver`

Driver marks arrival at pickup location. Only valid when ride status is `driver_en_route` and the driver is the one assigned to the ride. The driver's last reported location must be **within 150 m of the pickup** and less than **2 minutes** old.

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
Other 422 messages: `"Move closer to the pickup location before marking arrival."` (more than 150 m away) and `"We could not confirm your recent location. Wait for your location to update, then try again."` (no location in the last 2 minutes).

---

### Verify PIN
```
POST /rides/{ride}/verify-pin
```

**Auth required. Middleware:** `user.type:driver`

Driver submits the 4-digit PIN received from the passenger. On success, the ride transitions to `in_progress`. The PIN is valid for 6 hours from booking and allows 3 attempts; after a lockout the passenger issues a new PIN with **Regenerate Ride PIN**.

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
  "message": "Maximum PIN verification attempts exceeded. Contact support.",
  "verified": false
}
```

---

### Regenerate Ride PIN
```
POST /rides/{ride}/pin/regenerate
```

**Auth required. Middleware:** `user.type:passenger`, `throttle:5,1`

Issues a fresh 4-digit PIN with a new set of 3 attempts — for example after the driver used up the attempts. Only the ride's passenger can call it (they read it to the driver in person), and only before the trip starts (`matched`, `driver_en_route`, `driver_arrived`). The previous PIN stops working. The new PIN is also returned as `pin_code` on the passenger's ride detail.

**Response 200:**
```json
{
  "message": "A new PIN has been issued. Share it with your driver at pickup.",
  "pin_code": "4821"
}
```

**Response 403:** `"You are not authorized to manage this ride."`

**Response 422:**
```json
{
  "message": "A new PIN can only be issued before the trip starts.",
  "current_status": "in_progress"
}
```
Before a driver is assigned (`requested`, `searching`, `no_driver_found`) the message is `"A new PIN can be issued once a driver has been assigned."`.

---

### Complete Ride
```
POST /rides/{ride}/complete
```

**Auth required. Middleware:** `user.type:driver`

Driver completes the ride. Only valid when ride status is `in_progress`. Calculates the final fare: the upfront quote, plus any waiting charge, with the time charge recalculated only if the trip ran significantly longer than quoted (see the `pricing_snapshot` keys under Get Ride).

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

Driver accepts a ride that was dispatched to them by the matching engine. Only valid when ride status is `searching`, the ride was dispatched to this specific driver, and the driver is approved and online. Uses row-level locking (`SELECT FOR UPDATE`) on the ride and the driver to prevent concurrent acceptances — first-accept-wins — and a driver can never hold two active rides.

On acceptance, the ride transitions through `matched` → `driver_en_route` atomically. The matching engine cache (rejected drivers, dispatch state) is cleaned up.

**Response 200:**
```json
{
  "message": "Ride accepted.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
    "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
    "passenger_id": "1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f",
    "driver_id": "f6a7b8c9-0d1e-4f3a-8b5c-6d7e8f9a0b1c",
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

**Response 403 (negative earnings balance):**
```json
{
  "message": "You cannot accept rides while your earnings balance is below the allowed threshold. Please clear your outstanding balance."
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

**Response 403 (not approved or offline):**
```json
{
  "message": "Only approved drivers who are online can accept rides."
}
```

**Response 409 (driver already on an active ride):**
```json
{
  "message": "You already have an active ride. Complete it before accepting another."
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

When a ride is created (`POST /rides`) or re-sent (`POST /rides/{ride}/rebroadcast`), the system automatically:

1. **Dispatches** `DispatchRideRequestJob` — searches for the nearest eligible driver via Redis GEOSEARCH within the current radius tier (3 km, then 7 km, then 15 km)
2. **Offers** the ride to the closest candidate — `RideRequestDispatched` on the `driver.{userId}` WebSocket channel plus a push notification. A failed WebSocket broadcast does not stop the push or the timeout.
3. **Times out** the offer — `DriverResponseTimeoutJob` fires after the response window (default 20 s). An unanswered offer counts as a rejection and the ride moves to the next candidate (this also works if the queue runs late).
4. **Expands** to the next radius tier when no candidate is left in the current one; after the last tier it waits `auto_retry_delay_seconds` and starts a new wave (`max_auto_retries` times).
5. **Gives up** — `MatchingTimeoutJob` moves the ride to `no_driver_found` after `matching_timeout` (default 300 s) from the start of the current search. A passenger retry starts a fresh window; timers from an earlier search are ignored.

**Eligibility** — a driver is offered a ride only if they are:
- Online (`is_online = true`) and approved (`status = approved`)
- Reporting their location (last update within 2 minutes — drivers whose app stopped reporting are skipped and pruned from the geo index)
- Driving a vehicle of the requested class
- Not on another active ride (`matched` → `in_progress`) and not holding an unanswered offer for another ride
- Not a driver who already rejected or ignored this ride

**Priority matching** (Gold, Platinum and Diamond passengers — locked in at booking as `pricing_snapshot.priority_matching`):
- Distance always wins: a priority passenger is offered their nearest eligible driver first, never a farther one because of their tier.
- When drivers are scarce, a priority ride waiting for its next search (between radius tiers or retry waves) keeps the driver it would be offered next, its nearest free driver within its current radius, instead of that driver being offered to a standard ride. Only that one driver is held per waiting priority ride; every other driver stays available to everyone.
- Priority searches get one extra automatic retry wave (`MATCHING_PRIORITY_EXTRA_RETRIES`, default 1) before `no_driver_found`.

Admins can also assign a driver manually (`POST /admin/rides/{ride}/assign`), including to rides that ended in `no_driver_found`.

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

**Configuration** (`config/matching.php`, via environment variables):

| Variable | Default | Description |
|----------|---------|-------------|
| `MATCHING_RADIUS_TIER_1_KM` / `_2_KM` / `_3_KM` | `3` / `7` / `15` | Search radius tiers |
| `MATCHING_MAX_AUTO_RETRIES` | `1` | Extra waves after the last tier |
| `MATCHING_PRIORITY_EXTRA_RETRIES` | `1` | Additional waves for priority (Gold+) passengers |
| `MATCHING_AUTO_RETRY_DELAY` | `10` | Seconds between waves |
| `MATCHING_DRIVER_RESPONSE_TIMEOUT` | `20` | Seconds a driver has to respond |
| `MATCHING_TIMEOUT` | `300` | Overall matching deadline per search (seconds) |
| `MATCHING_MAX_CANDIDATES` | `20` | Candidates read per radius search |

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

**Response 200 (ride no longer active — passenger/driver view):**

The driver's position stops being shared once the ride is completed or cancelled. Admins still receive the last known location.
```json
{
  "message": "Live location is only available during an active ride.",
  "location": null,
  "eta": null,
  "ride_status": "completed"
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

### List Ride Cancellation Reasons

```
GET /rides/cancellation-reasons
Authorization: Bearer {token}
```

Returns cancellation reasons relevant to the authenticated user's role. Identical to `GET /lookup/cancellation-reasons` but scoped automatically based on the caller's account type (passenger or driver).

| Query Param | Type   | Required | Description                                       |
|-------------|--------|----------|---------------------------------------------------|
| role        | string | No       | Override with `passenger` or `driver`. Omit to auto-detect from auth token. |

**Response 200:**
```json
{
  "reasons": [
    {
      "title": "Changed my mind",
      "description": "I no longer need a ride or my travel plans have changed.",
      "code": "changed_mind",
      "allows_custom_description": false
    },
    {
      "title": "Driver is too far",
      "description": "The assigned driver is located too far away from the pickup point.",
      "code": "driver_too_far",
      "allows_custom_description": false
    },
    {
      "title": "Other",
      "description": "Provide a custom reason if none of the options above apply.",
      "code": "other",
      "allows_custom_description": true
    }
  ]
}
```

---

### Rebroadcast Ride

```
POST /rides/{ride}/rebroadcast
Authorization: Bearer {passenger_token}
```

**Auth required. Middleware:** `user.type:passenger`

Re-sends a ride request to find new drivers after the original search ended with `no_driver_found`. The ride must belong to the authenticated passenger and its status must be `no_driver_found`. Rate limited to 3 requests per minute.

**Response 200:**
```json
{
  "message": "Ride request sent again.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "searching",
    "status_label": "Searching for Driver",
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
    "fare_estimate_amount": "3750.00",
    "fare_currency": "NGN",
    "...": "..."
  }
}
```

**Response 403:**
```json
{
  "message": "You are not authorized to retry this ride."
}
```

**Response 422:**
```json
{
  "message": "This ride can only be retried after no driver was found.",
  "current_status": "in_progress"
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

**Response 404** (unknown ride, wrong token or malformed ride ID):
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

## Admin — Live Monitoring

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access)

**Common errors (every endpoint in this section and in Manual Assignment):**

| Status | `error_code` | When |
|--------|--------------|------|
| `401` | `UNAUTHENTICATED` | Missing or expired token |
| `403` | — | `"Forbidden. Insufficient admin privileges."` — the admin is not Operations or Super Admin |
| `404` | `NOT_FOUND` | `"Resource not found."` — the `{ride}` id does not exist |
| `422` | `INVALID_PARAMETER` | A filter such as `city_id` is not a valid UUID |

### WebSocket: Real-Time Events

Subscribe to the private channel `admin.rides` (Echo: `Echo.private('admin.rides')`, events are listened to with a leading dot, e.g. `.listen('.ride.status.updated', …)`). Authorise with `POST /broadcasting/auth` and the admin bearer token.

| Event | Payload | Description |
|-------|---------|-------------|
| `driver.location.updated` | `driver_id, lat, lng, heading, speed, timestamp, ride_id, eta` | A driver's position. Sent each time a driver app reports its location (about every few seconds while online). `ride_id` and `eta` (`distance_km`, `duration_minutes`) are set while the driver is on a ride. `driver_id` is the driver profile id (`drivers[].driver_id` in Online Drivers). |
| `ride.status.updated` | `ride_id, passenger_id, driver_id, previous_status, status, actor_type, actor_id, occurred_at` | Every ride state change. |

**Keeping the pending list and the dashboard current** — all three cases arrive as `ride.status.updated`; refetch the list (or patch the row by `ride_id`) when you see them:

| What happened | How to recognise it |
|---------------|---------------------|
| New ride waiting for a driver | `status` = `searching` and `previous_status` = `requested` |
| Ride found no driver (still needs attention) | `status` = `no_driver_found` |
| Ride matched (by a driver accepting, or by an admin assigning) | `status` = `matched` (an admin assignment is followed straight away by `driver_en_route`, with `actor_type` = `admin`) |
| Ride cancelled | `status` = `cancelled` |

### List Active Rides
```
GET /admin/monitoring/active-rides
```

Returns all rides in an active status (`requested`, `searching`, `matched`, `driver_en_route`, `driver_arrived`, `in_progress`) with the driver's live position. Use it to fill the map on load, then apply the WebSocket events above.

| Param      | Type    | Description |
|------------|---------|-------------|
| city_id    | string (UUID) | Filter by city |
| status     | string  | Filter by one status |
| per_page   | integer | Default 50, max 100 |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
      "reference_number": "TRP-NG-000142",
      "status": "in_progress",
      "status_label": "In Progress",
      "pickup": { "lat": "6.4474000", "lng": "3.4364000", "address": "Bourdillon Road, Ikoyi" },
      "destination": { "lat": "6.5774000", "lng": "3.3210000", "address": "Murtala Muhammed Airport, Ikeja" },
      "passenger": { "id": "...", "first_name": "Seyi", "last_name": "Adeniran", "phone": "+2348400000007" },
      "driver": { "id": "...", "first_name": "Chuka", "last_name": "Eze", "phone": "+2348300000004" },
      "city": { "id": "...", "name": "Lagos" },
      "vehicle_class": { "id": "...", "name": "economy", "display_name": "Economy" },
      "fare_estimate_amount": "4300.00",
      "fare_currency": "NGN",
      "driver_rating": 4.75,
      "driver_location": {
        "lat": 6.5014,
        "lng": 3.3892,
        "heading": 315.2,
        "speed": 28,
        "timestamp": 1792065600,
        "updated_at": "2026-10-15T14:00:00+00:00"
      },
      "eta": {
        "target": "destination",
        "distance_km": 12.4,
        "duration_minutes": 24.8
      },
      "stage_timestamps": {
        "requested_at": "2026-10-15T13:33:00+00:00",
        "matched_at": "2026-10-15T13:35:00+00:00",
        "driver_en_route_at": "2026-10-15T13:35:00+00:00",
        "driver_arrived_at": "2026-10-15T13:44:00+00:00",
        "started_at": "2026-10-15T13:46:00+00:00"
      },
      "has_active_sos": true,
      "sos": {
        "id": "5b1c…",
        "status": "triggered",
        "trigger_type": "passenger",
        "raised_by": {
          "role": "passenger",
          "user_id": "…",
          "name": "Seyi Adeniran"
        },
        "created_at": "2026-10-15T13:57:00+00:00"
      },
      "created_at": "2026-10-15T13:33:00.000000Z",
      "...": "..."
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 12 },
  "summary": {
    "total_active": 12,
    "by_status": { "requested": 2, "searching": 1, "matched": 1, "driver_en_route": 3, "driver_arrived": 1, "in_progress": 4 }
  }
}
```

**Per-ride fields:**
- `driver_rating` (float, nullable) — the driver's average rating. Only present when the ride has a driver.
- `driver_location` (object, nullable) — the driver's last reported position: `lat`, `lng`, `heading`, `speed`, `timestamp` (Unix seconds) and `updated_at` (ISO 8601). `null` when the driver app has not reported for 5 minutes. Turning it into a street name is left to the client.
- `eta` (object, nullable) — `target` is `pickup` while the ride is `matched` or `driver_en_route` (minutes for the driver to reach the passenger) and `destination` while it is `in_progress` (minutes to the drop-off). `null` in other statuses or without a driver position. It is the ETA last computed from the driver's location reports (routed, refreshed every 30 s) and otherwise a straight-line estimate at 30 km/h.
- `stage_timestamps` (object) — ISO 8601, all nullable: `requested_at`, `matched_at` (driver accepted, or admin assigned), `driver_en_route_at` (driver set off to the pickup), `driver_arrived_at`, `started_at`.
- `has_active_sos` (boolean) — whether the ride has an open SOS.
- `sos` (object) — present only when `has_active_sos` is `true`: `id`, `status`, `trigger_type`, `raised_by`, `created_at`. `trigger_type` and `raised_by.role` both say who raised it (`passenger` or `driver`); `raised_by` adds their `user_id` and `name`.

**"Needs attention" badge on the Rides tab:** use `summary.awaiting_driver` from [List Pending Rides](#list-pending-rides) — it counts unassigned `requested`, `searching` and `no_driver_found` rides. (`summary.by_status` here does not include `no_driver_found`, which is not an active status.)

### Get Ride Detail (with route)
```
GET /admin/monitoring/rides/{ride}
```

One ride in the same shape as an Active Rides row, for the ride details panel, plus:

- `driver_vehicle` (object, nullable) — `make`, `model`, `colour`, `plate_number`, `vehicle_class`.
- `route` (object, nullable) — the planned driving route from pickup to destination: `encoded_polyline` (Google encoded polyline, precision 5 — decode with `google.maps.geometry.encoding.decodePath` or `@mapbox/polyline`), `distance_km`, `duration_minutes`. Cached per ride for 24 hours. `null` if the routing provider is unavailable; draw a straight pickup–destination line in that case.

Works for any ride status.

**Response 200:**
```json
{
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "driver_en_route",
    "...": "same fields as an Active Rides row",
    "driver_vehicle": { "make": "Honda", "model": "Civic", "colour": "Grey", "plate_number": "LND-202AB", "vehicle_class": "economy" },
    "route": {
      "encoded_polyline": "ezjf@y`kSz@hBf@~@…",
      "distance_km": 9.8,
      "duration_minutes": 24.5
    }
  }
}
```

### List Online Drivers
```
GET /admin/monitoring/online-drivers
```

Returns all online, approved drivers with their live position and whether they're on a trip.

| Param      | Type    | Description |
|------------|---------|-------------|
| city_id    | string (UUID) | Filter by city |

**Response 200:**
```json
{
  "drivers": [
    {
      "driver_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "user_id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "name": "Chuka Eze",
      "phone": "+2348300000004",
      "reference_number": "D-000001",
      "rating": 4.75,
      "city": "Lagos",
      "vehicle": { "make": "Hyundai", "model": "Elantra", "plate_number": "LND-204AD", "colour": "White", "vehicle_class": "economy" },
      "location": {
        "lat": 6.5014,
        "lng": 3.3892,
        "heading": 315.2,
        "speed": 28,
        "timestamp": 1792065600,
        "updated_at": "2026-10-15T14:00:00+00:00"
      },
      "active_ride": {
        "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
        "reference_number": "TRP-NG-000142",
        "status": "in_progress",
        "pickup_address": "Bourdillon Road, Ikoyi",
        "destination_address": "Murtala Muhammed Airport, Ikeja"
      },
      "is_on_trip": true
    }
  ],
  "summary": { "total_online": 10, "on_trip": 5, "available": 5 }
}
```

`location` is `null` for a driver whose app has not reported a position in the last 5 minutes (the driver is marked online but is not sending GPS). Such drivers can't be placed on the map.

---

## Admin — Manual Assignment

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access). Common `401`/`403`/`404`/`422 INVALID_PARAMETER` errors are listed under [Admin — Live Monitoring](#admin--live-monitoring).

**Flow:** list pending rides → pick one → list available drivers for it → assign.

**Assignment is instant.** There is no request for the driver to accept or decline: the ride moves straight to `matched` → `driver_en_route`, the driver and passenger get a push notification and the ride's WebSocket channel, and admins get `ride.status.updated` on `admin.rides`. Because nothing waits on the driver, there is no answer timeout and nothing to cancel while waiting. To undo an assignment, the ride is cancelled in the usual way.

**Real-time:** keep the list current with `ride.status.updated` on `admin.rides` (see the table under [WebSocket: Real-Time Events](#websocket-real-time-events)) — a new pending ride is `searching`, a matched ride is `matched`/`driver_en_route`, a cancelled ride is `cancelled`.

### List Pending Rides
```
GET /admin/rides/pending
```

By default returns rides waiting for a driver — `requested`, `searching` or `no_driver_found` with no driver. Pass `status` to include matched rides too. Ordered **oldest first**.

`no_driver_found` rides are listed (and counted) only for **24 hours** after they were requested (`MATCHING_MANUAL_ASSIGNMENT_WINDOW_HOURS`); after that the passenger has long gone and the ride drops off.

| Param            | Type    | Description |
|------------------|---------|-------------|
| status           | string  | Comma-separated, any of `requested`, `searching`, `no_driver_found`, `matched`, `driver_en_route`. Example: `status=searching,no_driver_found,matched,driver_en_route` shows unmatched and matched rides together. Without it, only unassigned rides are returned. |
| search           | string  | Case-insensitive partial match on the trip reference (`TRP-…`), passenger full name, city name or vehicle class name |
| city_id          | string (UUID) | Filter by city |
| vehicle_class_id | string (UUID) | Filter by vehicle class |
| per_page         | integer | Default 25, max 100 |
| page             | integer | Page number |

Rides matched by an admin go straight to `driver_en_route`, so include both `matched` and `driver_en_route` for a "Matched" tab.

**Response 200:**
```json
{
  "rides": [
    {
      "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
      "reference_number": "TRP-NG-000142",
      "status": "searching",
      "status_label": "Searching for Driver",
      "pickup": { "lat": "6.6143785", "lng": "3.3577680", "address": "Ikeja City Mall, Alausa, Ikeja" },
      "destination": { "lat": "6.4231205", "lng": "3.4453480", "address": "Landmark Centre, Victoria Island" },
      "passenger": { "id": "...", "first_name": "Amaka", "last_name": "Nwankwo", "phone": "+2348400000001" },
      "driver": null,
      "city": { "id": "...", "name": "Lagos" },
      "vehicle_class": { "id": "...", "name": "economy", "display_name": "Economy" },
      "fare_estimate_amount": "4300.00",
      "fare_currency": "NGN",
      "created_at": "2026-10-15T13:58:00.000000Z"
    },
    {
      "id": "7d2e…",
      "reference_number": "TRP-NG-000139",
      "status": "driver_en_route",
      "status_label": "Driver En Route",
      "passenger": { "...": "..." },
      "driver": { "id": "...", "first_name": "Sade", "last_name": "Ogunbiyi", "phone": "+2348300000002" },
      "driver_rating": 4.6,
      "matched_at": "2026-10-15T13:54:00+00:00",
      "driver_vehicle": { "make": "Honda", "model": "Civic", "colour": "Grey", "plate_number": "LND-202AB" },
      "...": "..."
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 2 },
  "summary": {
    "awaiting_driver": 3,
    "by_status": { "requested": 0, "searching": 2, "no_driver_found": 1, "matched": 1, "driver_en_route": 1 }
  }
}
```

**Fields on a matched ride:** `driver` (name, phone), `driver_rating` (float, nullable), `driver_vehicle` (`make`, `model`, `colour`, `plate_number`) and `matched_at` (when the driver accepted, or the admin assigned).

**`summary`** ignores `status`, `search`, `vehicle_class_id` and paging but follows `city_id` and the 24-hour window. `awaiting_driver` is the number of unassigned `requested`, `searching` and `no_driver_found` rides — use it for the "needs attention" badge. `by_status` counts every status the list can show.

### List Available Drivers for a Ride
```
GET /admin/rides/{ride}/available-drivers
```

Drivers who can take the ride, **nearest first**.

| Param      | Type    | Description |
|------------|---------|-------------|
| radius_km  | number  | Search radius from the pickup, default `5` |
| limit      | integer | Max results, default 20, max 50 |

**How "available" is decided** — all of:
- online and approved;
- reported a GPS position in the last 2 minutes;
- not on a trip (no ride in `matched`, `driver_en_route`, `driver_arrived` or `in_progress`);
- in the ride's city;
- vehicle in the ride's vehicle class;
- within `radius_km` of the pickup (straight-line).

**Response 200:**
```json
{
  "drivers": [
    {
      "driver_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "user_id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "name": "Tobi Ajayi",
      "phone": "+2348300000006",
      "reference_number": "D-000014",
      "rating": 4.8,
      "vehicle": { "make": "Toyota", "model": "Yaris", "plate_number": "LND-206AF", "colour": "Blue", "vehicle_class": "economy" },
      "location": { "lat": 6.6194, "lng": 3.3629, "heading": 0, "speed": 0, "timestamp": 1792065600, "updated_at": "2026-10-15T14:00:00+00:00" },
      "distance_km": 0.8,
      "eta_minutes": 1.6
    }
  ],
  "meta": {
    "total": 2,
    "radius_km": 5,
    "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "pickup": { "lat": "6.6143785", "lng": "3.3577680", "address": "Ikeja City Mall, Alausa, Ikeja" }
  }
}
```

- `distance_km` — straight-line distance to the pickup.
- `eta_minutes` — time to the pickup, estimated from `distance_km` at 30 km/h.
- `rating` — average rating, `null` when the driver has none.

**Errors:** `422` `{"message": "Invalid radius_km.", "errors": {"radius_km": [...]}}` when `radius_km` is not a positive number.

### Manually Assign Driver to Ride
```
POST /admin/rides/{ride}/assign
```

**Request body:**

| Field     | Type    | Required | Description |
|-----------|---------|----------|-------------|
| driver_id | string (UUID) | Yes | The driver's **user** id (`user_id` from Available Drivers, not `driver_id`) |

```json
{ "driver_id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d" }
```

The ride must be `requested`, `searching` or `no_driver_found`. For a `no_driver_found` ride the assignment is refused if the passenger has since booked another ride, and a wallet ride's fare hold is placed again. The assignment is instant (see above).

**Response 200:**
```json
{
  "message": "Driver assigned successfully.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "driver_en_route",
    "status_label": "Driver En Route",
    "driver_id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
    "driver": { "id": "...", "first_name": "Tobi", "last_name": "Ajayi", "...": "..." },
    "matched_at": "2026-10-15T14:01:00.000000Z",
    "...": "full RideResource"
  }
}
```

**Errors:**

| Status | `error_code` | Message / field | Cause |
|--------|--------------|-----------------|-------|
| `422` | `VALIDATION_ERROR` | `errors.driver_id`: `The driver id field is required.` / `The driver id field must be a valid UUID.` / `The selected driver id is invalid.` | Missing, malformed, or unknown user id |
| `422` | `VALIDATION_ERROR` | `errors.driver_id`: `No driver profile found for this user.` | The id is not a driver |
| `422` | `VALIDATION_ERROR` | `errors.driver_id`: `Driver must be approved.` | |
| `422` | `VALIDATION_ERROR` | `errors.driver_id`: `Driver must be online.` | |
| `422` | `VALIDATION_ERROR` | `errors.driver_id`: `Driver vehicle class does not match the ride request.` | |
| `422` | `VALIDATION_ERROR` | `errors.driver_id`: `Driver already has an active ride.` | |
| `422` | `RIDE_NOT_ASSIGNABLE` | `This ride cannot be assigned in its current state.` + `current_status` | Ride is already matched, in progress, finished or cancelled |
| `422` | `VALIDATION_ERROR` | `errors.ride`: `The passenger already has another active ride.` | `no_driver_found` ride whose passenger has rebooked |
| `422` | `VALIDATION_ERROR` | `errors.ride`: wallet message (e.g. insufficient balance) | `no_driver_found` wallet ride whose wallet no longer covers the fare |
| `409` | `INVALID_STATE_TRANSITION` | `current_status` | The ride changed state (e.g. a driver accepted it) while it was being assigned — refresh and try again |
| `404` | `NOT_FOUND` | `Resource not found.` | Unknown ride id |

```json
{
  "message": "This ride cannot be assigned in its current state.",
  "error_code": "RIDE_NOT_ASSIGNABLE",
  "current_status": "driver_en_route"
}
```

```json
{
  "message": "Driver must be online. (and 1 more error)",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "driver_id": ["Driver must be online.", "Driver vehicle class does not match the ride request."]
  }
}
```

### Test Data (staging)

`php artisan db:seed --class=MonitoringDemoSeeder` (non-production only) creates, in Lagos:

- **Pending:** 3 rides — 2 `searching` (economy at Ikeja City Mall, comfort at Admiralty Way) and 1 `no_driver_found` (economy at UNILAG).
- **Available drivers:** 2 economy drivers near Ikeja City Mall (0.8 km and 2.3 km), 1 near UNILAG (1.5 km), and 2 comfort drivers in Lekki (1.1 km, and one at 6.5 km — outside the default radius).
- **Active:** one ride at each stage — `matched`, `driver_en_route`, `driver_arrived` and two `in_progress`; one in-progress ride has an open SOS raised by the passenger.
- **Gamification:** 6 passengers across Lagos, Abuja, Port Harcourt and Ibadan covering all 5 tiers, with completed rides and carbon scores over the last 3 months.

Every demo driver also has two rated past trips, so ratings are filled in. Demo drivers are never offered rides by automatic matching — they only get rides through manual assignment — so they don't hold up real test rides.

Demo accounts use `@ops-demo.etigo.com` emails (password `Qwer!234`). The scheduler runs `demo:simulate-drivers` every minute outside production, so demo drivers keep reporting their location and drivers on a ride move towards the pickup or destination. Re-run the seeder to reset the rides after testing assignments.

---

## Admin — Pricing Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,finance`

**Allowed Roles:** Operations, Finance (Super Admin always has access)

### List Pricing Configs
```
GET /admin/pricing
```
| Query Param      | Type    | Description                                    |
|------------------|---------|------------------------------------------------|
| search           | string  | Search by city name or vehicle class name      |
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
      "city_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "vehicle_class_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
      "base_fare": 600,
      "per_km_rate": 250,
      "per_minute_rate": 40,
      "minimum_fare": 1500,
      "waiting_time_rate": 50,
      "free_waiting_minutes": 5,
      "is_current": true,
      "version": 1,
      "effective_from": "2026-09-30T00:00:00.000000Z",
      "effective_until": null,
      "city": {
        "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
        "name": "Lagos",
        "...": "..."
      },
      "vehicle_class": {
        "id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
        "name": "economy"
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
    "city_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "vehicle_class_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
    "base_fare": 700,
    "per_km_rate": 280,
    "per_minute_rate": 45,
    "minimum_fare": 1600,
    "waiting_time_rate": 55,
    "free_waiting_minutes": 5,
    "is_current": false,
    "version": 2,
    "effective_from": "2026-11-01T00:00:00.000000Z",
    "effective_until": null,
    "city": { "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890", "name": "Lagos", "...": "..." },
    "vehicle_class": { "id": "c3d4e5f6-a7b8-9012-cdef-123456789012", "name": "economy" },
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
    "city_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "vehicle_class_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
    "base_fare": 600,
    "per_km_rate": 250,
    "per_minute_rate": 40,
    "minimum_fare": 1500,
    "waiting_time_rate": 50,
    "free_waiting_minutes": 5,
    "is_current": true,
    "version": 1,
    "effective_from": "2026-09-30T00:00:00.000000Z",
    "effective_until": null,
    "city": { "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890", "name": "Lagos", "...": "..." },
    "vehicle_class": { "id": "c3d4e5f6-a7b8-9012-cdef-123456789012", "name": "economy" },
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
    "city_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "vehicle_class_id": "c3d4e5f6-a7b8-9012-cdef-123456789012",
    "base_fare": 600,
    "per_km_rate": 250,
    "per_minute_rate": 40,
    "minimum_fare": 1500,
    "waiting_time_rate": 50,
    "free_waiting_minutes": 5,
    "is_current": true,
    "version": 1,
    "effective_from": "2026-09-30T00:00:00.000000Z",
    "effective_until": null,
    "city": { "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890", "name": "Lagos", "...": "..." },
    "vehicle_class": { "id": "c3d4e5f6-a7b8-9012-cdef-123456789012", "name": "economy" },
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

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access)

Surge pricing applies a multiplier to fares during high-demand periods (rush hour, rain, events). Three rule types are supported:

| Type           | How It Works                                                         |
|----------------|----------------------------------------------------------------------|
| `manual`       | Admin toggles on/off — for weather, events, emergencies              |
| `time_based`   | Fires on a schedule (days of week + time window) — for rush hours    |
| `demand_based` | Fires when live demand outstrips available drivers (see below)       |

**Fare formula with surge:** `fare = max(minimum_fare, base + distance + time + waiting) × surge_multiplier`

**How demand is measured** (per city, and per class when the rule names one; cached for 60 s):
- **Requests:** distinct passengers who booked a ride in the last `SURGE_DEMAND_WINDOW_MINUTES` (10).
- **Available drivers:** approved, online drivers in the city with a vehicle of that class, not on a trip, whose app reported a location in the last 2 minutes. Demo drivers are excluded.
- **Ratio:** requests ÷ available drivers (with no driver available, the ratio equals the number of requests). The exact ratio is compared with the rule; the response shows it rounded to 2 decimals.
- If driver locations cannot be read, supply is unknown: `available_drivers` and `ratio` are `null`, demand rules do not fire, and nothing is cached.
- A demand rule fires when the ratio reaches `conditions.min_demand_supply_ratio` **and** at least `conditions.min_requests` passengers are asking (default `SURGE_DEMAND_MIN_REQUESTS`, 5), so a single late-night request never surges.

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
      "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
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
| conditions.min_requests              | integer | No (demand_based)             | Minimum passengers asking before it can fire (default 5) |
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
    "city_id": "8d7c6b5a-4938-4276-8a1f-e2d3c4b5a6f7",
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
    "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
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
    "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
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

Returns the currently active surge multiplier for the given city (and optionally vehicle class), plus `demand`: the live figures demand-based rules are measured against (cached for a minute).

**Response 200 (surge active):**
```json
{
  "surge": {
    "active": true,
    "multiplier": 1.5,
    "rule_name": "Morning Rush Hour",
    "rule": {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
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
  },
  "demand": {
    "requests": 12,
    "available_drivers": 4,
    "ratio": 3.0,
    "window_minutes": 10
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
  },
  "demand": {
    "requests": 1,
    "available_drivers": 6,
    "ratio": 0.17,
    "window_minutes": 10
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
Returns paginated notifications for the authenticated user, newest first. Default 20 per page (max 50). Notifications older than `NOTIFICATION_RETENTION_DAYS` (default 90) are deleted daily.

**Query Parameters:**
| Field    | Type   | Required | Description                                                       |
|----------|--------|----------|-------------------------------------------------------------------|
| type     | string | No       | Filter by notification type (e.g. `ride_completed`, `topup_success`) |
| category | string | No       | Filter by category: `ride_updates`, `safety`, `payments`, `promotions`, `compliance`, `gamification`, `ev_charging`, `account` |
| is_read  | bool   | No       | Filter by read status: `true` or `false`                          |
| per_page | int    | No       | Items per page (1–50, default 20)                                 |

**Response 200:**
```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "type": "ride_matched",
      "type_label": "Ride Matched",
      "category": "ride_updates",
      "title": "Driver Found!",
      "body": "Your driver Bayo is on the way.",
      "data": {
        "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36"
      },
      "is_read": false,
      "read_at": null,
      "created_at": "2026-10-06T14:01:00.000000Z"
    },
    {
      "id": "550e8400-e29b-41d4-a716-446655440001",
      "type": "ride_completed",
      "type_label": "Ride Completed",
      "category": "ride_updates",
      "title": "Ride Complete",
      "body": "Your trip has ended. Total fare: ₦3,900.",
      "data": {
        "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36"
      },
      "is_read": true,
      "read_at": "2026-10-06T15:00:00.000000Z",
      "created_at": "2026-10-06T14:30:00.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 2 }
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
  "message": "Notification marked as read.",
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "type": "ride_completed",
    "type_label": "Ride Completed",
    "category": "ride_updates",
    "title": "Ride Complete",
    "body": "Your trip has ended.",
    "data": null,
    "is_read": true,
    "read_at": "2026-10-11T10:00:00.000000Z",
    "created_at": "2026-10-11T09:30:00.000000Z"
  }
}
```

**Response 403:** Notification does not belong to the authenticated user.

---

### Mark All Notifications as Read
```
POST /notifications/read-all
```

**Response 200:**
```json
{
  "message": "All notifications marked as read.",
  "updated_count": 5
}
```

---

### Delete Notification
```
DELETE /notifications/{notification_id}
```
Only the notification owner can delete it.

**Response 200:**
```json
{
  "message": "Notification deleted."
}
```

**Response 403:** Notification does not belong to the authenticated user.

---

## Notification Preferences

**Middleware:** `auth:sanctum`

Users can control which notification categories they receive via push and in-app channels. Critical categories (Safety, Compliance) cannot be disabled.

### Notification Categories

| Category       | Description                                     | Critical |
|----------------|-------------------------------------------------|----------|
| `ride_updates` | Driver matching, arrival, trip progress          | No       |
| `safety`       | SOS check-ins, escalations, safety alerts        | Yes      |
| `payments`     | Payment confirmations, receipts, refunds         | No       |
| `promotions`   | Promo codes, discounts, special offers           | No       |
| `compliance`   | Offline trip flags, sanctions, warnings          | Yes      |
| `gamification` | Tier upgrades, carbon scores, rewards            | No       |
| `ev_charging`  | EV reservation status and availability           | No       |
| `account`      | KYC status, profile changes, account alerts      | No       |

### Get Notification Preferences
```
GET /notification-preferences
```

**Response 200:**
```json
{
  "data": [
    {
      "category": "ride_updates",
      "label": "Ride Updates",
      "description": "Driver matching, arrival, trip progress and completion",
      "is_critical": false,
      "push_enabled": true,
      "in_app_enabled": true
    },
    {
      "category": "safety",
      "label": "Safety & SOS",
      "description": "SOS check-ins, escalations and safety alerts",
      "is_critical": true,
      "push_enabled": true,
      "in_app_enabled": true
    }
  ]
}
```

### Update Notification Preferences
```
PUT /notification-preferences
```

**Request Body:**
```json
{
  "preferences": [
    {
      "category": "promotions",
      "push_enabled": false,
      "in_app_enabled": true
    },
    {
      "category": "gamification",
      "push_enabled": true,
      "in_app_enabled": false
    }
  ]
}
```

> **Note:** Attempting to disable critical categories (`safety`, `compliance`) will be silently overridden — they will remain enabled.

**Response 200:**
```json
{
  "message": "Notification preferences updated.",
  "data": [
    {
      "category": "promotions",
      "label": "Promotions & Offers",
      "push_enabled": false,
      "in_app_enabled": true
    },
    {
      "category": "gamification",
      "label": "Rewards & Tiers",
      "push_enabled": true,
      "in_app_enabled": false
    }
  ]
}
```

---

## Payment Methods

**Middleware:** `auth:sanctum`

### List Payment Methods
```
GET /payment-methods
```
Returns available payment methods for the authenticated user. Cash is always included. Saved cards appear after cash. If a saved card is set as default, cash's `is_default` is `false`; otherwise cash is the default.

**Response 200:**
```json
{
  "payment_methods": [
    {
      "id": "cash",
      "type": "cash",
      "label": "Cash",
      "is_default": true
    },
    {
      "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "type": "card",
      "label": "Visa •••• 4081",
      "card_brand": "visa",
      "card_last_four": "4081",
      "is_default": false
    },
    {
      "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "type": "card",
      "label": "Mastercard •••• 5432",
      "card_brand": "mastercard",
      "card_last_four": "5432",
      "is_default": false
    }
  ]
}
```

---

### Initialize Payment
```
POST /payments/initialize
```

Starts a Paystack checkout, used to save a card for later rides (channels default to `card`; `bank_transfer` and `ussd` can be requested — OPay users pay by bank transfer to the generated account). Users without an email on their account are supported: a stable placeholder (`{user_id}@rider.etigo.ng`) is sent to Paystack and remembered with the saved card. Pass the returned `tx_ref` to **Verify Payment**.

| Field        | Type   | Required | Description                       |
|--------------|--------|----------|-----------------------------------|
| amount       | number | Yes      | Amount (≥1)                       |
| currency     | string | Yes      | 3-letter currency code (e.g. `NGN`) |
| redirect_url | string | Yes      | URL to redirect after payment     |
| channels     | array  | No       | Payment channels, default `["card"]` |

**Response 200:**
```json
{
  "payment_link": "https://checkout.paystack.com/abc123def456",
  "tx_ref": "ETIGO-ABCDEFGHIJKL"
}
```

---

### Verify Payment
```
GET /payments/{transactionId}/verify
```
Verifies a checkout started with **Initialize Payment**; `{transactionId}` is its `tx_ref`. If successful and card details are present, the card is saved as a payment method (the first saved card becomes the default). Only the user who started the checkout can verify it — anyone else gets **403** `"Transaction does not belong to this user."`.

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

## Payment & Settlement

### Payment Methods

| Method | Value | Flow |
|--------|-------|------|
| Cash | `cash` | Driver collects cash → confirms via API |
| Card | `card` | If the passenger has a saved default card, auto-charged via token at ride completion. If no saved card exists, a Paystack checkout link (card-only) is generated — the passenger completes payment via the link. Confirmed via Paystack webhook. |
| Wallet | `wallet` | Auto-debited from passenger wallet at ride completion |
| Bank Transfer | `bank_transfer` | Paystack checkout link generated at ride completion (supports bank transfer, USSD, card). Passenger completes payment via the link. OPay users transfer to the generated temporary account. Confirmed via Paystack webhook. |

When a ride completes with `payment_method=card` and the passenger has no saved card, or with `payment_method=bank_transfer`, the payment response includes a `payment_link` field. The mobile app should present this link to the passenger to complete payment. The payment status updates from `pending` to `captured` automatically when the Paystack webhook confirms the transaction. A saved card is **not** required to select `card` as the payment method — new users can pay with a one-time card entry.

---

### Confirm Cash Collection (Driver)
```
POST /rides/{ride_id}/confirm-cash
Authorization: Bearer {driver_token}
```
Driver confirms cash has been collected from passenger. Only available for completed rides with `payment_method=cash` and payment in `pending_collection` status.

| Field            | Type    | Required | Notes                                                                 |
|------------------|---------|----------|-----------------------------------------------------------------------|
| amount_collected | numeric | No       | Actual cash amount received from rider. If greater than fare, the difference is credited to the rider's wallet as change. Max overpayment: ₦2,000 (configurable). |

**Cash Change → Wallet Credit:** When `amount_collected` exceeds the fare, the overpayment is automatically credited to the rider's wallet via an internal ledger journal. The driver's earnings balance is debited by the same amount (settled the same way as commission on cash rides). The rider receives a notification. The response includes `amount_collected` and `cash_change_amount` fields.

**Response 200 (without overpayment):**
```json
{
  "message": "Cash collection confirmed.",
  "payment": {
    "id": "uuid",
    "ride_id": "uuid",
    "amount": "3500.00",
    "currency": "NGN",
    "method": "cash",
    "method_label": "Cash",
    "tip_amount": "0.00",
    "status": "collected",
    "status_label": "Collected"
  }
}
```

**Response 200 (with overpayment — cash change credited):**
```json
{
  "message": "Cash collection confirmed. ₦200.00 change credited to rider's wallet.",
  "payment": {
    "id": "uuid",
    "ride_id": "uuid",
    "amount": "1800.00",
    "amount_collected": "2000.00",
    "cash_change_amount": "200.00",
    "currency": "NGN",
    "method": "cash",
    "method_label": "Cash",
    "tip_amount": "0.00",
    "status": "collected",
    "status_label": "Collected"
  }
}
```

**Response 403:** Not the assigned driver.
**Response 422:** Ride not completed, not a cash ride, or already confirmed (`errors.ride`); or the change cannot be credited (`errors.amount_collected`):
```json
{
  "message": "Cash overpayment exceeds maximum allowed (₦2,000.00).",
  "errors": {
    "amount_collected": ["Cash overpayment exceeds maximum allowed (₦2,000.00)."]
  }
}
```
The other `amount_collected` error is `"Cash change would exceed rider wallet maximum balance (₦500,000.00)."` (the cap comes from the admin wallet settings).

---

### Get Tip Presets
```
GET /rides/tip-settings
Authorization: Bearer {token}
```

Returns admin-configured tip preset amounts, min/max limits, and currency. Frontend should display these as quick-select buttons on the tipping screen.

**Response 200:**
```json
{
  "presets": [100, 200, 500, 1000],
  "min_amount": 50,
  "max_amount": 50000,
  "currency": "NGN"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `presets` | array | Suggested tip amounts (₦) to display as buttons |
| `min_amount` | integer | Minimum allowed tip (₦) |
| `max_amount` | integer | Maximum allowed tip (₦) |
| `currency` | string | Currency code |

---

### Add Tip (Passenger)
```
POST /rides/{ride_id}/tip
Authorization: Bearer {passenger_token}
```

| Field  | Type    | Required | Notes                                              |
|--------|---------|----------|------------------------------------------------------|
| amount | numeric | Yes      | Between `min_amount` and `max_amount` from tip settings |
| source | string  | Bank-transfer rides only | How to pay the tip: `wallet`, `card` or `cash`. Defaults to the ride's payment method |

Adds a tip to a completed ride. Only one tip per ride; concurrent requests cannot both succeed. Tips carry no commission: the driver receives the full amount. The tip's source is independent of how the ride was paid, and is returned as `tip_method`.

- **Card:** charged to the passenger's default saved card. If the card is declined, the tip is not recorded and the request returns 422; the passenger can try again. If the bank does not answer (timeout or provider error), the tip stays reserved and is checked with the provider by its reference before anything is charged again: 422 `"We could not confirm your tip payment with the bank yet. Please try again in a few minutes; you will not be charged twice."`. A later attempt settles the earlier one first; if that charge went through, it returns 422 `"Your earlier tip went through, so you were not charged again."`.
- **Wallet:** paid from the passenger's available wallet balance to the driver in one ledger journal. A frozen wallet, or a balance that cannot cover the tip, returns 422 and nothing is recorded.
- **Cash:** recorded only; the driver collects it in person. Only allowed on cash rides.
- **Bank-transfer rides** were a one-off checkout, so `source` (`wallet` or `card`) is required.

The min/max limits are admin-configurable via the settings API (defaults: ₦50–₦50,000).

**Response 200:**
```json
{
  "message": "Tip added successfully.",
  "payment": {
    "id": "uuid",
    "ride_id": "uuid",
    "amount": "3500.00",
    "currency": "NGN",
    "method": "cash",
    "method_label": "Cash",
    "tip_amount": "500.00",
    "tip_method": "cash",
    "status": "collected",
    "status_label": "Collected"
  }
}
```

**Response 403:** Not the ride's passenger.
**Response 422:** Ride not completed, amount out of range, or one of:
- `"A tip has already been added to this ride."`
- `"Choose how to pay the tip: wallet or card."` (`errors.source`, bank-transfer ride without `source`)
- `"Cash tips can only be recorded on cash rides."` (`errors.source`)
- `"Your wallet balance is too low for this tip."` / `"Wallet is frozen or closed."` (wallet rides)
- `"No default payment method on file to charge tip."`
- `"Failed to charge tip amount."` (card declined or gateway unavailable — nothing is recorded)
- `"No payment record found for this ride."`
- `"Cannot add a tip to a failed payment."`

---

### Get Ride Receipt
```
GET /rides/{ride_id}/receipt
Authorization: Bearer {token}
```
Returns a detailed receipt for a completed ride. Accessible by the ride's passenger, driver, or any admin. Includes vehicle details, card info, fare breakdown with tip and tier discount, and carbon emissions data.

`duration_minutes` is the time billed. `trip_time` explains it: `quoted_minutes` (the booking quote), `actual_minutes` (trip start to completion, may be `null`), `billed_minutes` and `adjusted` (`true` when the trip ran significantly longer and the actual time, up to 3× the quote, was billed). Show the passenger a note when `adjusted` is `true`. `trip_time` is `null` for rides completed before upfront fares.

The `payment` object carries one of two totals, depending on whether the payment has settled:
- `total_charged` (number) — when `status` is `captured`, `collected` or `settled`. Equals `amount + tip_amount`.
- `amount_due` (string) — for any other status (e.g. `pending`, `pending_collection` for cash not yet collected, `failed`). The fare the passenger still owes, without the tip.

`payment` is `null` if the ride has no payment record.

**Response 200 (settled payment):**
```json
{
  "receipt": {
    "ride_id": "uuid",
    "reference_number": "TRP-NG-000142",
    "date": "2026-10-08T14:30:00+01:00",
    "pickup": {
      "address": "Ikeja City Mall - Gate 2",
      "lat": "6.6180000",
      "lng": "3.3420000"
    },
    "destination": {
      "address": "Eko Atlantic City",
      "lat": "6.4280000",
      "lng": "3.4220000"
    },
    "distance_km": 12.6,
    "duration_minutes": 28,
    "trip_time": {
      "quoted_minutes": 28,
      "actual_minutes": 33.4,
      "billed_minutes": 28,
      "adjusted": false
    },
    "vehicle_class": "tiGO Lite",
    "driver": {
      "name": "Tunde Adeyemi",
      "profile_photo_url": "https://res.cloudinary.com/etigo/image/upload/profile-photos/5/driver-123.jpg"
    },
    "vehicle": {
      "make": "BYD",
      "model": "Dolphin",
      "colour": "Blue",
      "plate_number": "LAG 482 EV",
      "year": 2025
    },
    "fare_breakdown": {
      "base_fare": 600.0,
      "per_km_rate": 250.0,
      "per_minute_rate": 40.0,
      "distance_charge": 3150.0,
      "time_charge": 1120.0,
      "waiting_charge": 0,
      "tip": 600.0,
      "tier_discount": {
        "tier": "Gold",
        "percentage": 5,
        "amount": 63.0
      },
      "promo_discount": {
        "code": "LAGOS20",
        "discount_amount": 500.0,
        "raw_fare": 4000.0
      },
      "minimum_fare": 1500.0,
      "surge_multiplier": null
    },
    "fare_estimate": "5400.00",
    "final_fare": "5350.00",
    "currency": "NGN",
    "payment": {
      "method": "card",
      "method_label": "Card",
      "status": "captured",
      "status_label": "Captured",
      "tip_amount": "600.00",
      "card_brand": "visa",
      "card_last_four": "4242",
      "total_charged": 5950.0
    },
    "carbon": {
      "estimated_emissions_kg": 0.4,
      "carbon_credit_accrued_kg": 0.4,
      "green_points_earned": 12
    },
    "city": "Lagos"
  }
}
```

**Response 200 (unsettled payment — cash not yet collected):**
```json
{
  "receipt": {
    "...": "same fields as above",
    "payment": {
      "method": "cash",
      "method_label": "Cash",
      "status": "pending_collection",
      "status_label": "Pending Collection",
      "tip_amount": "0.00",
      "amount_due": "3500.00"
    }
  }
}
```

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `reference_number` | string | Unique ride reference (e.g. `TRP-NG-000142`) |
| `driver.profile_photo_url` | string\|null | Driver's profile photo URL |
| `vehicle` | object\|null | Driver's vehicle details (make, model, colour, plate, year) |
| `fare_breakdown.tip` | number | Tip amount added by passenger |
| `fare_breakdown.tier_discount` | object\|null | Tier-based discount (tier name, percentage, amount) |
| `fare_breakdown.promo_discount` | object\|null | Promo code discount details — `null` when no promo was applied. Contains `code` (the promo code string), `discount_amount` (discount applied), and `raw_fare` (pre-discount fare amount). |
| `payment.card_brand` | string\|null | Card brand (visa, mastercard, etc.) — only for card payments |
| `payment.card_last_four` | string\|null | Last 4 digits of card — only for card payments |
| `payment` | object\|null | Payment summary — `null` if the ride has no payment record |
| `payment.total_charged` | number | Fare plus tip actually charged — only when the payment is settled (`captured`, `collected`, `settled`) |
| `payment.amount_due` | string | Fare still owed — only when the payment is not settled |
| `carbon` | object\|null | Carbon emissions and credits — null if not yet calculated |

**Response 403:** `{"message": "You do not have access to this receipt.", "error_code": "HTTP_ERROR"}` — not the ride's passenger or driver, and not an admin.
**Response 404:** `{"message": "Receipt is only available for completed rides.", "error_code": "NOT_FOUND"}`

---

## Payment Webhooks

### Paystack Webhook

```
POST /webhooks/paystack
```

**No authentication** — verified by HMAC-SHA512 signature (`x-paystack-signature` header) using `PAYSTACK_SECRET_KEY`. This is the single webhook URL to configure in your Paystack dashboard. It handles all Paystack events: ride payments, wallet top-ups, and driver payouts.

**Handled Events:**

| Event | Condition | Action |
|---|---|---|
| `charge.success` | Reference starts with `TOPUP-` and metadata contains `account_id` | Dispatches `ProcessTopupWebhookJob` (wallet top-up, credited once per reference) |
| `charge.success` | All other charges | Re-verifies the transaction with Paystack **by its reference**, checks amount and currency, and captures the matching `Payment` (ride payment), then settles driver earnings |
| `transfer.success` | — | Dispatches `ProcessPayoutWebhookJob` with status `paid` |
| `transfer.failed` | — | Dispatches `ProcessPayoutWebhookJob` with status `failed` (money returned to the driver's balance) |
| `transfer.reversed` | — | Dispatches `ProcessPayoutWebhookJob` with status `failed` (money returned to the driver's balance) |

**Deduplication:** every event is stored in `webhook_events`, keyed by provider, event ID **and** event type (a transfer's `transfer.success` and later `transfer.reversed` share an ID but are both processed). A redelivered event that was fully processed returns `"Already processed."`. If processing failed part-way (for example Paystack's verify API timed out and the webhook returned 500), the provider's retry is processed again — every handler is idempotent, so nothing is applied twice.

**Response 200:**
```json
{
  "message": "Webhook received."
}
```

**Response 200 (duplicate):**
```json
{
  "message": "Already processed."
}
```

**Response 403:**
```json
{
  "message": "Invalid signature."
}
```

**Response 503 (secret key not configured):**
```json
{
  "message": "Webhook verification unavailable."
}
```

### Paystack Transfer Approval

```
POST /webhooks/paystack/transfer-approval
```

**No authentication. Called by Paystack, not by the apps.** This is the **Approval URL** in the Paystack dashboard (Settings → Preferences → Transfer Approval). Once it is set, Paystack asks this endpoint about every transfer from the balance before sending it. A `200` lets the transfer go (status `pending`), a `400` rejects it (status `rejected`), and no answer within a few seconds blocks it (status `blocked`).

A transfer is approved only when it is a driver payout that the payout job is sending right now:

| Check | Rejected when |
|---|---|
| `reference` | No payout has this `gateway_reference` (`PAYOUT-{id}`, `PAYOUT-{id}-2`, …) |
| Payout status | The payout is not `approved` or `processing` |
| `amount` (kobo) | It differs from the payout amount |
| `currency` (if sent) | It is not `NGN` |
| `recipient` | It is not the recipient code saved on the payout (`gateway_recipient_code`), which the payout job creates for the driver's bank account just before it starts the transfer |

Anything else is rejected and logged as **critical**. That includes a transfer started with a leaked secret key, or one made by hand in the Paystack dashboard. The endpoint changes nothing and approves only what is already in our database, so it does not need to trust the caller. The transfer may also arrive wrapped in `data`, as webhooks are, and the recipient may be a string code or an object carrying `recipient_code`.

If a payout's transfer is rejected or blocked, the payout fails and the money goes back to the driver: either at once, if Paystack reports it when the transfer is created, or through payout reconciliation. Finance can then retry it.

**Request body** (the payload the transfer was started with):
```json
{
  "source": "balance",
  "reason": "E-tiGo driver payout #9f3c1a52-6c1e-4f8e-9d0b-3b7d2a4c8e11",
  "amount": 250000,
  "recipient": "RCP_1a2b3c4d5e6f7g8",
  "reference": "PAYOUT-9f3c1a52-6c1e-4f8e-9d0b-3b7d2a4c8e11",
  "currency": "NGN"
}
```

**Response 200 (approved):**
```json
{
  "message": "Transfer approved."
}
```

**Response 400 (rejected):**
```json
{
  "message": "Transfer rejected."
}
```

The response never says why a transfer was rejected. The reason is in the critical log entry, with the reference, amount and recipient. The endpoint is public, so after 5 critical alerts in a minute from one IP address, further rejections from that address are logged as warnings instead, so junk requests cannot bury a real alert. The endpoint is not rate-limited, so a burst of weekly auto-payouts is never refused.

### Flutterwave Webhook

```
POST /webhooks/flutterwave
```

**No authentication** — verified by `verif-hash` header matching `FLUTTERWAVE_WEBHOOK_SECRET`. This is the webhook URL to configure in your Flutterwave dashboard. Flutterwave serves as a failover gateway — if Paystack is unavailable, payments are routed through Flutterwave instead.

**Handled Events:**

| Event | Condition | Action |
|---|---|---|
| `charge.completed` | `status` = `successful`, `tx_ref` starts with `TOPUP-` and meta contains `account_id` | Dispatches `ProcessTopupWebhookJob` (wallet top-up; Flutterwave amounts are in naira and converted to kobo) |
| `charge.completed` | All other charges | Verifies transaction with Flutterwave API and updates the `Payment` record (ride payment) |
| `transfer.completed` | `data.status` = `SUCCESSFUL` | Dispatches `ProcessPayoutWebhookJob` with status `paid` |
| `transfer.completed` | any other `data.status` (e.g. `FAILED`) | Dispatches `ProcessPayoutWebhookJob` with status `failed` |
| `transfer.failed` | — | Dispatches `ProcessPayoutWebhookJob` with status `failed` |

Deduplication works as for Paystack (provider = `flutterwave`): processed events are skipped, events whose processing failed are processed again on retry. Driver payouts are currently sent through Paystack only, so Flutterwave transfer events are not expected yet.

**Response 200:**
```json
{
  "message": "Webhook received."
}
```

**Response 200 (duplicate):**
```json
{
  "message": "Already processed."
}
```

**Response 403:**
```json
{
  "message": "Invalid signature."
}
```

### Payment Gateway Failover

The system supports a primary-with-failover gateway configuration. When both `PAYSTACK_SECRET_KEY` and `FLUTTERWAVE_SECRET_KEY` are set:

- **Paystack** is the primary gateway — all payment operations are attempted through Paystack first
- **Flutterwave** is the failover — if Paystack is unavailable (timeout, error), the operation is automatically retried through Flutterwave
- **Token charges** (saved cards) always use the primary gateway since tokens are gateway-specific
- Configure webhook URLs for **both** gateways in their respective dashboards

| Environment Variable | Purpose |
|---|---|
| `PAYSTACK_SECRET_KEY` | Paystack API secret key (primary) |
| `FLUTTERWAVE_SECRET_KEY` | Flutterwave API secret key (failover) |
| `FLUTTERWAVE_WEBHOOK_SECRET` | Secret hash for Flutterwave webhook verification |

---

## Ratings

### Submit Rating

`POST /api/v1/rides/{ride}/rating`

Submit a 1–5 star rating for a completed ride. Passengers rate drivers and drivers rate passengers. Each participant can submit one rating per ride.

**Auth:** Bearer token (passenger or driver)

**Request Body:**

| Field     | Type    | Required | Rules              |
|-----------|---------|----------|--------------------|
| `score`   | integer | Yes      | 1–5                |
| `comment` | string  | No       | Max 1000 characters |

**Example Request:**
```json
{
  "score": 5,
  "comment": "Great ride, very smooth!"
}
```

**Response: `201 Created`**
```json
{
  "message": "Rating submitted successfully.",
  "rating": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e37",
    "score": 5,
    "comment": "Great ride, very smooth!",
    "rated_by": { "id": "...", "first_name": "John", "last_name": "Doe", "type": "passenger", "profile_photo_url": null },
    "rated_user": { "id": "...", "first_name": "Jane", "last_name": "Smith", "type": "driver", "profile_photo_url": null },
    "created_at": "2026-10-09T12:00:00.000000Z"
  }
}
```

**Error Responses:**
- `422` — Ride not completed, user not a participant, or already rated

---

## Disputes

### File a Dispute (Report an Issue)

`POST /api/v1/rides/{ride}/dispute`

File a dispute / report an issue for a completed ride. Both passengers and drivers can file disputes. Each participant can submit one dispute per ride. Supports optional evidence photo uploads (up to 5 images).

**Auth:** Bearer token (passenger or driver)

**Content-Type:** `multipart/form-data` (when uploading photos) or `application/json`

**Request Body:**

| Field         | Type     | Required | Rules                                              |
|---------------|----------|----------|----------------------------------------------------|
| `category`    | string   | Yes      | One of `DisputeCategory` enum values (see below)   |
| `description` | string   | Yes      | 10–2000 characters                                 |
| `photos[]`    | file[]   | No       | Up to 5 images (jpg, jpeg, png, webp), max 5MB each |
| `audio`       | file     | No       | Audio recording (mp3, m4a, wav, ogg, webm, aac), max 10MB |

**DisputeCategory values:**

| Value              | Display Label       |
|--------------------|---------------------|
| `safety_concern`   | Safety concern      |
| `driver_behaviour` | Driver behaviour    |
| `route_deviation`  | Wrong route taken   |
| `fare_dispute`     | Fare is incorrect   |
| `vehicle_condition`| Vehicle condition   |
| `item_left_behind` | Lost item           |
| `payment_issue`    | Payment issue       |
| `other`            | Something else      |

**Example Request (JSON):**
```json
{
  "category": "route_deviation",
  "description": "The driver took a longer route through Maryland instead of Third Mainland Bridge."
}
```

**Example Request (multipart/form-data):**
```
category: route_deviation
description: The driver took a longer route through Maryland instead of Third Mainland Bridge.
photos[0]: <image file>
photos[1]: <image file>
audio: <audio file (optional)>
```

**Response: `201 Created`**
```json
{
  "message": "Dispute filed successfully. Our operations team will review it and get back to you within 24 hours.",
  "dispute": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e38",
    "reference_number": "DPT-000048",
    "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e37",
    "category": "route_deviation",
    "category_label": "Wrong route taken",
    "description": "The driver took a longer route through Maryland instead of Third Mainland Bridge.",
    "evidence_photos": [
      "https://res.cloudinary.com/etigo/image/upload/disputes/ride-uuid/photo1.jpg",
      "https://res.cloudinary.com/etigo/image/upload/disputes/ride-uuid/photo2.jpg"
    ],
    "status": "open",
    "status_label": "Open",
    "reported_by": { "id": "...", "first_name": "John", "last_name": "Doe" },
    "resolved_at": null,
    "created_at": "2026-10-12T12:00:00.000000Z",
    "updated_at": "2026-10-12T12:00:00.000000Z"
  }
}
```

**Error Responses:**
- `422` — Ride not completed, user not a participant, already disputed, invalid category, or photo validation failed

---

### List My Disputes (Passenger)

`GET /api/v1/passenger/disputes`

List all disputes filed by the authenticated passenger. Returns paginated results ordered by most recent first.

**Auth:** Bearer token (passenger)

**Query Parameters:**

| Parameter  | Type | Description                        |
|------------|------|------------------------------------|
| `per_page` | int  | Items per page (default: 15, max: 50) |
| `page`     | int  | Page number                        |

**Response: `200 OK`**
```json
{
  "disputes": [
    {
      "id": "...",
      "reference_number": "DPT-000048",
      "ride_id": "...",
      "category": "route_deviation",
      "category_label": "Wrong route taken",
      "description": "The driver took a longer route...",
      "evidence_photos": ["https://..."],
    "evidence_audio": "https://res.cloudinary.com/etigo/video/upload/disputes/ride-uuid/audio/recording.m4a",
      "status": "open",
      "status_label": "Open",
      "reported_by": { "id": "...", "first_name": "John" },
      "ride": { "id": "...", "reference_number": "TRP-NG-000142", "destination": { "address": "Eko Atlantic City" } },
      "resolved_at": null,
      "created_at": "2026-10-12T12:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

---

### Show My Dispute (Passenger)

`GET /api/v1/passenger/disputes/{dispute}`

View details of a specific dispute filed by the authenticated passenger.

**Auth:** Bearer token (passenger)

**Response: `200 OK`** — Same structure as individual dispute object above.

**Response 403:** Dispute was not filed by this user.

---

## Admin — Dispute Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:support`

**Allowed Roles:** Support (Super Admin always has access)

### List Disputes

`GET /api/v1/admin/disputes`

List all disputes with optional filters and pagination.

**Query Parameters:**

| Parameter  | Type   | Description                                     |
|------------|--------|-------------------------------------------------|
| `status`   | string | Filter by status: `open`, `under_review`, `resolved`, `dismissed` |
| `category` | string | Filter by category (see `DisputeCategory` enum) |
| `ride_id`  | uuid   | Filter by specific ride ID                      |
| `city_id`  | uuid   | Filter by city (via ride's city)                |
| `search`   | string | Search by description or reporter name/email    |
| `from`     | date   | Filter disputes created on or after (YYYY-MM-DD) |
| `to`       | date   | Filter disputes created on or before (YYYY-MM-DD) |
| `per_page` | int    | Items per page (default: 15, max: 100)          |
| `page`     | int    | Page number                                     |

**Response: `200 OK`**
```json
{
  "disputes": [
    {
      "id": "...",
      "reference_number": "DPT-000048",
      "ride_id": "...",
      "category": "fare_dispute",
      "category_label": "Fare is incorrect",
      "description": "...",
      "status": "open",
      "status_label": "Open",
      "resolution_notes": null,
      "refund_amount": null,
      "refund_currency": null,
      "reported_by": { "id": "...", "first_name": "John", "last_name": "Doe" },
      "ride": {
        "id": "...",
        "reference_number": "TRP-NG-000142",
        "status": "completed",
        "city": { "id": "...", "name": "Lagos" },
        "passenger": { "id": "...", "first_name": "John", "last_name": "Doe", "phone": "+234..." },
        "driver": { "id": "...", "first_name": "Jane", "last_name": "Smith", "phone": "+234..." }
      },
      "resolved_at": null,
      "created_at": "2026-10-09T12:00:00.000000Z",
      "updated_at": "2026-10-09T12:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Show Dispute Detail

`GET /api/v1/admin/disputes/{dispute}`

Return full dispute details including ride, passenger, driver, vehicle class, city, and payment information.

**Auth:** Bearer token (admin)

**Response: `200 OK`**
```json
{
  "dispute": {
    "id": "c3d4e5f6-a7b8-9012-cdef-345678901234",
    "reference_number": "DPT-000048",
    "ride_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
    "category": "fare_dispute",
    "category_label": "Fare is incorrect",
    "description": "The fare charged was significantly higher than the estimated fare.",
    "status": "open",
    "status_label": "Open",
    "resolution_notes": null,
    "refund_amount": null,
    "refund_currency": null,
    "reported_by": {
      "id": "f1a2b3c4-d5e6-7890-abcd-111111111111",
      "first_name": "John",
      "last_name": "Doe",
      "phone": "+2348100000001",
      "email": "john@example.com",
      "type": "passenger"
    },
    "ride": {
      "id": "e5f6a7b8-c9d0-1234-efab-345678901234",
      "reference_number": "TRP-NG-000142",
      "status": "completed",
      "pickup": { "lat": 9.0579, "lng": 7.4951, "address": "123 Main Street, Abuja" },
      "destination": { "lat": 9.0065, "lng": 7.2632, "address": "456 Airport Road, Abuja" },
      "fare_estimate_amount": "3500.00",
      "final_fare_amount": "4200.00",
      "fare_currency": "NGN",
      "payment_method": "card",
      "passenger": { "id": "...", "first_name": "John", "last_name": "Doe", "phone": "+234..." },
      "driver": { "id": "...", "first_name": "Jane", "last_name": "Smith", "phone": "+234..." },
      "vehicle_class": { "id": "...", "name": "comfort", "display_name": "Comfort" },
      "city": { "id": "...", "name": "Abuja" },
      "payment_details": {
        "amount": 4200.00,
        "currency": "NGN",
        "method": "card",
        "status": "captured",
        "card_brand": "visa",
        "card_last_four": "4081",
        "tip_amount": null
      }
    },
    "notes": [
      {
        "id": "...",
        "content": "Passenger confirmed the overcharge via screenshots.",
        "admin": { "id": "...", "first_name": "Support", "last_name": "Agent" },
        "created_at": "2026-10-09T13:00:00.000000Z",
        "updated_at": "2026-10-09T13:00:00.000000Z"
      }
    ],
    "resolved_at": null,
    "created_at": "2026-10-09T12:00:00.000000Z",
    "updated_at": "2026-10-09T12:00:00.000000Z"
  }
}
```

### Resolve Dispute

`POST /api/v1/admin/disputes/{dispute}/resolve`

Resolve or dismiss a dispute. Optionally issue a card refund (full or partial) when resolving. Creates an audit log entry.

**Auth:** Bearer token (admin)

**Request Body:**

| Field              | Type    | Required | Rules                                                     |
|--------------------|---------|----------|-----------------------------------------------------------|
| `status`           | string  | Yes      | `resolved` or `dismissed`                                 |
| `resolution_notes` | string  | Yes      | 10–2000 characters                                        |
| `refund_amount`    | numeric | No       | Min 1. Must not exceed ride payment. Card payments only.  |

**Notes on `refund_amount`:**
- Only valid when `status` is `resolved` (cannot refund on dismissal)
- Only card payments can be refunded — cash payments return a 422 error
- Supports partial refunds (e.g., refund ₦500 of a ₦3,000 fare)
- The refund is processed via the payment gateway immediately
- When the Wallet Infrastructure is built, refunds will support wallet credits and driver clawbacks

**Example Request (resolve with refund):**
```json
{
  "status": "resolved",
  "resolution_notes": "Fare overcharge confirmed. Partial refund of ₦500 issued to passenger.",
  "refund_amount": 500.00
}
```

**Example Request (resolve without refund):**
```json
{
  "status": "resolved",
  "resolution_notes": "Driver behaviour addressed. Warning issued to driver."
}
```

**Response: `200 OK` (with refund)**
```json
{
  "message": "Dispute resolved successfully. Refund of NGN 500.00 issued.",
  "dispute": {
    "id": "c3d4e5f6-a7b8-9012-cdef-345678901234",
    "ride_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
    "category": "fare_dispute",
    "category_label": "Fare is incorrect",
    "description": "The fare charged was significantly higher than the estimated fare.",
    "status": "resolved",
    "status_label": "Resolved",
    "resolution_notes": "Fare overcharge confirmed. Partial refund of ₦500 issued to passenger.",
    "refund_amount": "500.00",
    "refund_currency": "NGN",
    "reported_by": { "id": "...", "first_name": "John", "last_name": "Doe" },
    "resolved_by": { "id": "...", "first_name": "Admin", "last_name": "User", "type": "admin" },
    "ride": { "id": "e5f6a7b8-c9d0-1234-efab-345678901234", "status": "completed" },
    "resolved_at": "2026-10-10T14:00:00.000000Z",
    "created_at": "2026-10-09T12:00:00.000000Z",
    "updated_at": "2026-10-10T14:00:00.000000Z"
  }
}
```

**Response: `200 OK` (without refund)**
```json
{
  "message": "Dispute resolved successfully.",
  "dispute": {
    "id": "c3d4e5f6-a7b8-9012-cdef-345678901234",
    "ride_id": "e5f6a7b8-c9d0-1234-efab-345678901234",
    "category": "driver_behaviour",
    "category_label": "Driver Behaviour",
    "description": "Driver was rude during the ride.",
    "status": "resolved",
    "status_label": "Resolved",
    "resolution_notes": "Driver behaviour addressed. Warning issued to driver.",
    "reported_by": { "id": "...", "first_name": "John", "last_name": "Doe" },
    "resolved_by": { "id": "...", "first_name": "Admin", "last_name": "User", "type": "admin" },
    "ride": { "id": "e5f6a7b8-c9d0-1234-efab-345678901234", "status": "completed" },
    "resolved_at": "2026-10-10T14:00:00.000000Z",
    "created_at": "2026-10-09T12:00:00.000000Z",
    "updated_at": "2026-10-10T14:00:00.000000Z"
  }
}
```

**Error Responses:**
- `422` — Dispute already resolved/dismissed, missing resolution notes, refund on dismissal, refund exceeds payment, or cash payment refund attempted

---

### Update Dispute Status

`PATCH /api/v1/admin/disputes/{dispute}/status`

Transition a dispute from `open` to `under_review`.

| Field    | Type   | Required | Rules            |
|----------|--------|----------|------------------|
| `status` | string | Yes      | `under_review`   |

**Response: `200 OK`**
```json
{
  "message": "Dispute moved to under review.",
  "dispute": {
    "id": "c3d4e5f6-a7b8-9012-cdef-345678901234",
    "reference_number": "DPT-000048",
    "status": "under_review",
    "status_label": "Under Review",
    "...": "..."
  }
}
```

**Response 422:**
```json
{
  "message": "Only open disputes can be moved to under review."
}
```

---

### List Dispute Notes

`GET /api/v1/admin/disputes/{dispute}/notes`

Returns internal notes for a dispute, paginated.

**Response: `200 OK`**
```json
{
  "notes": [
    {
      "id": "...",
      "content": "Passenger confirmed the overcharge via screenshots.",
      "admin": { "id": "...", "first_name": "Support", "last_name": "Agent" },
      "created_at": "2026-10-09T13:00:00.000000Z",
      "updated_at": "2026-10-09T13:00:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

---

### Add Dispute Note

`POST /api/v1/admin/disputes/{dispute}/notes`

| Field     | Type   | Required | Rules                  |
|-----------|--------|----------|------------------------|
| `content` | string | Yes      | Max 2000 characters    |

**Response: `201 Created`**
```json
{
  "message": "Note added successfully.",
  "note": {
    "id": "...",
    "content": "Called passenger to verify details.",
    "admin": { "id": "...", "first_name": "Support", "last_name": "Agent" },
    "created_at": "2026-10-09T14:00:00.000000Z",
    "updated_at": "2026-10-09T14:00:00.000000Z"
  }
}
```

---

### Update Dispute Note

`PUT /api/v1/admin/disputes/{dispute}/notes/{note}`

| Field     | Type   | Required | Rules                  |
|-----------|--------|----------|------------------------|
| `content` | string | Yes      | Max 2000 characters    |

**Response: `200 OK`**
```json
{
  "message": "Note updated successfully.",
  "note": { "..." }
}
```

---

### Delete Dispute Note

`DELETE /api/v1/admin/disputes/{dispute}/notes/{note}`

**Response: `200 OK`**
```json
{
  "message": "Note deleted successfully."
}
```

---

## Gamification & Carbon Scoring

### View Gamification Profile

```
GET /gamification
Authorization: Bearer {token}
```

Returns the authenticated user's gamification profile with current tier, points, carbon score, progress to next tier, and unlocked benefits. Auto-creates a Bronze profile on first access.

**Response `200`:**

```json
{
  "gamification": {
    "id": "uuid",
    "user_id": "uuid",
    "current_tier": 1,
    "tier_name": "Bronze",
    "total_ranking_points": 150,
    "total_carbon_score": 12.50,
    "tier_upgraded_at": null,
    "progress_to_next_tier": 30.0,
    "points_to_next_tier": 350,
    "next_tier": "Silver",
    "benefits": {
      "booking_fee_discount_pct": 0,
      "priority_matching": false,
      "ev_reservation_fee_waived": false
    },
    "created_at": "2026-10-09T00:00:00Z",
    "updated_at": "2026-10-09T00:00:00Z"
  }
}
```

### Carbon History

```
GET /gamification/carbon-history?per_page=15
Authorization: Bearer {token}
```

Returns paginated list of the user's per-trip carbon scores.

**Response `200`:**

```json
{
  "carbon_scores": [
    {
      "id": "uuid",
      "ride_id": "uuid",
      "user_id": "uuid",
      "distance_km": 10.50,
      "baseline_emission": 1.2600,
      "vehicle_emission": 0.6825,
      "co2_saved": 0.5775,
      "base_points": 6,
      "multiplier_applied": 1.25,
      "multiplier_reason": "Off-Peak",
      "final_points": 8,
      "created_at": "2026-10-09T00:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

### List Tiers

```
GET /gamification/tiers
Authorization: Bearer {token}
```

Returns all 5 tier configurations (Bronze → Silver → Gold → Platinum → Diamond).

**Response `200`:**

```json
{
  "tiers": [
    {
      "id": "uuid",
      "tier_level": 1,
      "tier_name": "Bronze",
      "tier_label": "Bronze",
      "min_points_required": 0,
      "booking_fee_discount_pct": 0,
      "ev_reservation_fee_waived": false,
      "priority_matching_enabled": false,
      "unlocked": true
    }
  ],
  "current_tier": 3
}
```

### Leaderboard

```
GET /gamification/leaderboard?limit=20
Authorization: Bearer {token}
```

Returns top users sorted by total ranking points.

**Response `200`:**

```json
{
  "leaderboard": [
    {
      "user_id": "uuid",
      "first_name": "Ade",
      "tier": 3,
      "tier_name": "Gold",
      "total_ranking_points": 2500,
      "total_carbon_score": 150.25
    }
  ]
}
```


### Download Green Impact PDF

`GET /api/v1/gamification/green-impact-pdf`

Downloads a PDF report of the authenticated user's green impact: total rides, CO2 saved, electric km driven, tier status, and recent trip breakdown.

**Auth:** Bearer token

**Response:** PDF file download (`application/pdf`)

**Filename:** `etigo-green-impact-{first_name}.pdf`

---

## Admin — Gamification Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access)

### List Gamification Users

```
GET /admin/gamification/users?tier=2&search=ade&sort_by=total_ranking_points&sort_dir=desc&per_page=15&city_id={uuid}
Authorization: Bearer {admin_token}
```

Lists **passengers only** — only passengers earn points and tiers.

**Filters:** `tier` (1-5), `search` (name/email), `min_points`, `city_id` (uuid — passengers who have taken a ride in that city), `sort_by` (total_ranking_points/total_carbon_score/current_tier/created_at), `sort_dir` (asc/desc), `per_page` (default 15, max 100), `page`

**Response `200`:**

```json
{
  "profiles": [
    {
      "id": "uuid",
      "user_id": "uuid",
      "first_name": "Tolu",
      "last_name": "Bakare",
      "email": "passenger.tolu@ops-demo.etigo.com",
      "city_id": "uuid",
      "city_name": "Lagos",
      "current_tier": 5,
      "tier_name": "Diamond",
      "total_ranking_points": 16200,
      "total_carbon_score": 182.4,
      "total_co2_saved_kg": 1.72,
      "co2_saved_this_month_kg": 0.42,
      "points_this_month": 21,
      "tier_upgraded_at": "2026-09-25T10:00:00.000000Z",
      "progress_to_next_tier": null,
      "points_to_next_tier": null,
      "next_tier": null,
      "benefits": { "booking_fee_discount_pct": 15, "priority_matching": true, "ev_reservation_fee_waived": true },
      "created_at": "...",
      "updated_at": "..."
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 6 }
}
```

- `city_id` / `city_name` — the passenger's city, taken from their most recent ride. `null` for a passenger with no rides yet.

### Aggregate Stats

```
GET /admin/gamification/aggregate
Authorization: Bearer {admin_token}
```

**Query Parameters:**

| Param     | Type | Description                                           |
|-----------|------|-------------------------------------------------------|
| `from`    | date (`Y-m-d`) | Start of the date range (inclusive)             |
| `to`      | date (`Y-m-d`) | End of the date range (inclusive)               |
| `city_id` | uuid | Passengers who have taken a ride in this city             |

Covers passengers only. **The date range applies to the whole response:**

- `total_co2_saved_kg`, `total_points_awarded`, `average_co2_per_trip` — only trips scored in the range.
- `total_profiles`, `tier_distribution`, `top_users`, `average_carbon_score`, `average_ranking_points` — only passengers who earned points in the range (who completed a scored trip). Their tier and point totals are their current, lifetime values.

Without `from`/`to` everything covers all time. An invalid date returns `422` `INVALID_PARAMETER`.

**Response `200`:**

```json
{
  "aggregate": {
    "total_profiles": 150,
    "average_carbon_score": 45.20,
    "average_ranking_points": 1200,
    "total_co2_saved_kg": 6780.50,
    "total_points_awarded": 180000,
    "average_co2_per_trip": 0.85,
    "tier_distribution": [
      { "tier_level": 1, "tier_name": "Bronze", "count": 80 },
      { "tier_level": 2, "tier_name": "Silver", "count": 40 },
      { "tier_level": 3, "tier_name": "Gold", "count": 20 },
      { "tier_level": 4, "tier_name": "Platinum", "count": 8 },
      { "tier_level": 5, "tier_name": "Diamond", "count": 2 }
    ],
    "top_users": [
      {
        "user_id": "uuid",
        "first_name": "Ade",
        "last_name": "Ogunleye",
        "tier": 5,
        "tier_name": "Diamond",
        "total_ranking_points": 18500,
        "total_carbon_score": 890.25
      }
    ]
  }
}
```

### View Tier Configuration

```
GET /admin/gamification/tiers
Authorization: Bearer {admin_token}
```

Returns all 5 tier configurations.

### Update Tier Configuration

```
PUT /admin/gamification/tiers
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Body:**

```json
{
  "tiers": [
    {
      "tier_level": 1,
      "tier_name": "Bronze",
      "min_points_required": 0,
      "booking_fee_discount_pct": 0,
      "ev_reservation_fee_waived": false,
      "priority_matching_enabled": false
    },
    {
      "tier_level": 2,
      "tier_name": "Silver",
      "min_points_required": 500,
      "booking_fee_discount_pct": 2,
      "ev_reservation_fee_waived": false,
      "priority_matching_enabled": false
    },
    {
      "tier_level": 3,
      "tier_name": "Gold",
      "min_points_required": 2000,
      "booking_fee_discount_pct": 5,
      "ev_reservation_fee_waived": false,
      "priority_matching_enabled": true
    },
    {
      "tier_level": 4,
      "tier_name": "Platinum",
      "min_points_required": 5000,
      "booking_fee_discount_pct": 10,
      "ev_reservation_fee_waived": true,
      "priority_matching_enabled": true
    },
    {
      "tier_level": 5,
      "tier_name": "Diamond",
      "min_points_required": 15000,
      "booking_fee_discount_pct": 15,
      "ev_reservation_fee_waived": true,
      "priority_matching_enabled": true
    }
  ]
}
```

**Response `200`:**

```json
{
  "message": "Tier configuration updated successfully.",
  "tiers": [ "...TierConfigResource..." ]
}
```

### View Multiplier Configuration

```
GET /admin/gamification/multipliers
Authorization: Bearer {admin_token}
```

**Response `200`:**

```json
{
  "multipliers": [
    {
      "id": "uuid",
      "condition_type": "shared_journey",
      "condition_label": "Shared Journey",
      "multiplier_value": 1.5,
      "is_stackable": true,
      "is_awarded": false,
      "created_at": "2026-09-29T10:00:00.000000Z",
      "updated_at": "2026-09-29T10:00:00.000000Z"
    }
  ]
}
```

`is_awarded` is `false` for a condition rides aren't checked for yet. `shared_journey` needs pooled rides (Phase 2), so it can be configured but is never awarded today; show it as "coming soon" rather than as an active bonus.

### Update Multiplier Configuration

```
PUT /admin/gamification/multipliers
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Body:**

```json
{
  "multipliers": [
    { "condition_type": "ev_ride", "multiplier_value": 2.00, "is_stackable": true },
    { "condition_type": "off_peak", "multiplier_value": 1.25, "is_stackable": true },
    { "condition_type": "shared_journey", "multiplier_value": 1.50, "is_stackable": true }
  ]
}
```

**Response `200`:**

```json
{
  "message": "Multiplier configuration updated successfully.",
  "multipliers": [ "...PointMultiplierConfigResource..." ]
}
```

---

## Promo / Discount Engine

**Middleware:** `auth:sanctum`, `user.type:passenger`

### Validate Promo Code

`POST /api/v1/promos/validate`

Pre-validates a promo code for the authenticated passenger. Checks code existence, active status, time window, and user-level eligibility. Returns the promo's configured discount details so the frontend can display what the user will receive (e.g. "50% off, up to ₦2,000").

**Design rationale:** Only the `code` field is used for validation. Extra ride-context fields (city, vehicle class, coordinates, fare) are ignored if sent. Ride-context checks (city, vehicle class, geo-fence, fare amount, peak/off-peak) are deferred to apply time — the backend derives all context from the Ride model, preventing frontend spoofing. City-restricted or geo-fenced promos will still pass validation here; their restrictions are returned in the response so the frontend can display them, but enforcement happens server-side when the promo is applied to a ride.

**Request:**
```json
{
  "code": "WELCOME50"
}
```

| Field  | Type   | Required | Description              |
|--------|--------|----------|--------------------------|
| `code` | string | Yes      | The promo code to check  |

**Response (200 — valid):**
```json
{
  "valid": true,
  "promo": {
    "id": "uuid",
    "code": "WELCOME50",
    "description": "50% off your first ride",
    "discount_type": "percentage",
    "discount_type_label": "Percentage",
    "discount_value": "50.00",
    "max_discount_cap": "2000.00",
    "per_user_limit": 1,
    "starts_at": "2026-10-01T00:00:00.000000Z",
    "expires_at": "2026-12-31T23:59:59.000000Z",
    "min_tier_level": null,
    "peak_only": false,
    "off_peak_only": false,
    "city_id": null,
    "vehicle_class_id": null,
    "minimum_fare_amount": null,
    "is_active": true,
    "cancelled_at": null,
    "is_cancelled": false,
    "city": null,
    "vehicle_class": null
  }
}
```

**Response (200 — valid, city-restricted promo):**

City/vehicle restrictions are returned as informational data. The frontend should display these to the user (e.g. "Valid in Lagos only"), but enforcement happens at apply time.

```json
{
  "valid": true,
  "promo": {
    "id": "uuid",
    "code": "LAGOS30",
    "description": "30% off rides in Lagos",
    "discount_type": "percentage",
    "discount_type_label": "Percentage",
    "discount_value": "30.00",
    "max_discount_cap": "1500.00",
    "per_user_limit": 3,
    "starts_at": "2026-10-01T00:00:00.000000Z",
    "expires_at": "2026-12-31T23:59:59.000000Z",
    "city_id": "uuid",
    "vehicle_class_id": null,
    "minimum_fare_amount": "1000.00",
    "is_active": true,
    "cancelled_at": null,
    "is_cancelled": false,
    "city": {
      "id": "uuid",
      "name": "Lagos"
    },
    "vehicle_class": null
  }
}
```

**Response (422 — invalid):**
```json
{
  "valid": false,
  "message": "This promo code has expired."
}
```

**Possible rejection messages:**
- `"Invalid promo code."` — code does not exist
- `"This promo code is no longer active."` — admin deactivated
- `"This promo code is not yet active."` — starts_at is in the future
- `"This promo code has expired."` — past expires_at
- `"Your tier level does not qualify for this promo code."` — user tier too low
- `"You need at least {n} completed rides to use this code."` — min ride count not met
- `"This promo code is for new users only."` — max ride count exceeded
- `"You have already used this promo code the maximum number of times."` — per-user limit
- `"This promo code has reached its redemption limit."` — global limit

**Validation rules checked at this stage (user eligibility):**
1. Code exists
2. Code is active
3. Within time window (starts_at ≤ now < expires_at)
4. Tier level requirement (if set)
5. Completed ride count (min_order_count / max_order_count)
6. Per-user redemption limit
7. Global redemption limit

**Rules deferred to apply time (ride context — derived from Ride model):**
1. City restriction (matched against ride's pickup city)
2. Vehicle class restriction (matched against ride's vehicle class)
3. Minimum fare requirement (checked against ride's fare)
4. Geo-fence (radius or polygon, checked against ride's pickup coordinates)
5. Peak/off-peak constraint (checked at time of ride)

### Available Promos

`GET /api/v1/promos/available`

Returns active promo codes the authenticated user is currently eligible for. Pass `city_id` to also include promos restricted to that city alongside universal ones.

**Query Parameters:**

| Parameter | Type | Required | Description                                                                                |
|-----------|------|----------|--------------------------------------------------------------------------------------------|
| `city_id` | uuid | No       | Include promos restricted to this city. Without it, only universal (no city restriction) promos are returned. |

**Response (200):**
```json
{
  "promos": [
    {
      "id": "uuid",
      "code": "WELCOME50",
      "description": "50% off your first ride",
      "discount_type": "percentage",
      "discount_type_label": "Percentage",
      "discount_value": "50.00",
      "max_discount_cap": "2000.00",
      "per_user_limit": 1,
      "starts_at": "2026-10-01T00:00:00.000000Z",
      "expires_at": "2026-12-31T23:59:59.000000Z",
      "city_id": null,
      "is_active": true,
      "cancelled_at": null,
      "is_cancelled": false,
      "city": null,
      "vehicle_class": null
    },
    {
      "id": "uuid",
      "code": "LAGOS30",
      "description": "30% off rides in Lagos",
      "discount_type": "percentage",
      "discount_value": "30.00",
      "max_discount_cap": "1500.00",
      "city_id": "uuid",
      "cancelled_at": null,
      "is_cancelled": false,
      "city": { "id": "uuid", "name": "Lagos" },
      "vehicle_class": null
    }
  ]
}
```


### Promo Redemption History

`GET /api/v1/promos/redemptions`

Returns the authenticated passenger's promo code redemption history, paginated.

**Auth:** Bearer token (passenger)

**Query Parameters:**

| Parameter  | Type | Description                           |
|------------|------|---------------------------------------|
| `per_page` | int  | Items per page (default: 15, max: 50) |
| `page`     | int  | Page number                           |

**Response: `200 OK`**
```json
{
  "redemptions": [
    {
      "id": "uuid",
      "promo_code": {
        "id": "uuid",
        "code": "WELCOME20",
        "description": "Welcome discount",
        "discount_type": "percentage",
        "discount_value": "20.00",
        "max_discount_cap": "2000.00"
      },
      "ride_id": "uuid",
      "discount_amount": "1000.00",
      "redeemed_at": "2026-10-10T14:30:00.000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

---

## Admin — Promo Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,finance`

### List Promos

`GET /api/v1/admin/promos`

**Query parameters:**
- `status` — `active`, `expired`, `inactive`, `scheduled`, `cancelled`. The `active`, `expired`, `inactive`, and `scheduled` filters automatically exclude cancelled promos (i.e. they only return promos where `cancelled_at` is null). Use `cancelled` to list only permanently cancelled promos.
- `city_id` — filter by city
- `search` — search by code or description
- `per_page` — pagination size (default 20, max 100)

**Response (200):**
```json
{
  "promos": [...],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 5
  }
}
```

### Create Promo

`POST /api/v1/admin/promos`

**Request:**
```json
{
  "code": "WELCOME20",
  "description": "20% off for new riders",
  "discount_type": "percentage",
  "discount_value": 20,
  "max_discount_cap": 2000,
  "total_redemption_limit": 500,
  "per_user_limit": 1,
  "starts_at": "2026-10-01T00:00:00Z",
  "expires_at": "2026-11-01T00:00:00Z",
  "city_id": "uuid (optional)",
  "vehicle_class_id": "uuid (optional)",
  "minimum_fare_amount": 1500 (optional),
  "min_tier_level": 2 (optional, 1-5),
  "min_order_count": 0 (optional),
  "max_order_count": 5 (optional, for new-user promos),
  "peak_only": false,
  "off_peak_only": false,
  "geo_fence": {
    "center_lat": 9.0579,
    "center_lng": 7.4951,
    "radius_km": 10
  }
}
```

**Response (201):**
```json
{
  "message": "Promo code created successfully.",
  "promo": { ... }
}
```

### Show Promo

`GET /api/v1/admin/promos/{promo}`

### Update Promo

`PUT /api/v1/admin/promos/{promo}`

All fields from create are accepted; only provided fields are updated.

**Response 422 (cancelled promo):**
```json
{
  "message": "Cannot update a cancelled promo code."
}
```

### Toggle Promo Status

`PATCH /api/v1/admin/promos/{promo}/status`

Activates or deactivates the promo. Audit logged.

**Response 422 (cancelled promo):**
```json
{
  "message": "Cannot change status of a cancelled promo code."
}
```

---

### Cancel Promo

`POST /api/v1/admin/promos/{promo}/cancel`

Permanently cancels a promo code. Sets `is_active = false` and records `cancelled_at` timestamp. This action **cannot be undone** — a cancelled promo can never be reactivated or updated.

**Cancel vs. Pause:** Toggling status (via `PATCH .../status`) is reversible — it simply flips `is_active` on/off. Cancelling is permanent: once cancelled, the promo is frozen and cannot be toggled, updated, or reused.

**Response (200):**
```json
{
  "message": "Promo code cancelled permanently.",
  "promo": {
    "id": "uuid",
    "code": "WELCOME20",
    "description": "20% off for new riders",
    "discount_type": "percentage",
    "discount_type_label": "Percentage",
    "discount_value": "20.00",
    "max_discount_cap": "2000.00",
    "is_active": false,
    "cancelled_at": "2026-10-15T12:00:00.000000Z",
    "is_cancelled": true,
    "...": "..."
  }
}
```

**Response 422 (already cancelled):**
```json
{
  "message": "This promo code has already been cancelled."
}
```

---

### Promo Performance Analytics

`GET /api/v1/admin/promos/{promo}/performance`

**Query parameters:**

| Parameter    | Type | Required | Description                                                      |
|--------------|------|----------|------------------------------------------------------------------|
| `start_date` | date | No       | Filter redemption stats from this date (inclusive)                |
| `end_date`   | date | No       | Filter redemption stats until this date (inclusive, must be >= `start_date`) |

Date filtering applies to `total_redemptions`, `unique_users`, `total_discount_given`, `average_discount`, `total_fare_value`, and `redemptions_by_day`. The `remaining_redemptions` and `usage_percentage` fields always use unfiltered (all-time) counts.

**Response (200):**
```json
{
  "promo": { ... },
  "performance": {
    "total_redemptions": 42,
    "unique_users": 38,
    "total_discount_given": 84000.00,
    "average_discount": 2000.00,
    "total_fare_value": 252000.00,
    "remaining_redemptions": 458,
    "usage_percentage": 8.4,
    "redemptions_by_day": {
      "2026-10-05": { "count": 12, "total_discount": 24000.00 },
      "2026-10-06": { "count": 30, "total_discount": 60000.00 }
    }
  },
  "recent_redemptions": [...]
}
```

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `total_fare_value` | number | Pre-discount fare total (SUM of `final_fare_amount + discount_amount` for all redemptions in the date range) |
| `usage_percentage` | number\|null | Percentage of `total_redemption_limit` used (all-time, ignoring date filter). `null` if the promo has no redemption limit. |

---

## SOS Telemetry & Escalation

**Middleware:** `auth:sanctum` (ride participants for trigger/acknowledge/cancel)

### Trigger SOS

`POST /api/v1/rides/{ride}/sos`

Triggers an emergency SOS incident on an active ride (any status from `requested` to `in_progress`). Either the passenger or the driver of the ride can trigger it. Only one open incident per ride is allowed; simultaneous taps are serialised so a double tap cannot open two.

**What happens next (server-side, no client action needed):**
1. A WhatsApp alert (SMS fallback) with GPS and trip details is queued to E-tiGo's safety desk — it does not depend on the WebSocket server.
2. After `SOS_CHECK_IN_DELAY` (default 5 s) the triggering user gets an "Are you safe?" check-in (`sos.check_in.requested` on `user.{userId}` + push).
3. If there is no response within `SOS_ESCALATION_TIMEOUT` (default 30 s) the incident is escalated to the safety console.

A safety operator can take over or resolve the incident at any open stage — they do not wait for the escalation.

**Request:**
```json
{
  "gps_lat": 9.0579,
  "gps_lng": 7.4951,
  "speed": 45.2,
  "heading": 180
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `gps_lat` | decimal | Yes | -90 to 90 |
| `gps_lng` | decimal | Yes | -180 to 180 |
| `speed` | decimal | No | Speed in km/h |
| `heading` | decimal | No | 0 to 360 degrees |

**Response (201):**
```json
{
  "message": "SOS triggered. A check-in will be sent shortly.",
  "incident": {
    "id": "uuid",
    "ride_id": "uuid",
    "triggered_by_user_id": "uuid",
    "trigger_type": "passenger",
    "trigger_type_label": "Passenger",
    "status": "triggered",
    "status_label": "Triggered",
    "gps_lat": "9.0579000",
    "gps_lng": "7.4951000",
    "check_in_sent_at": null,
    "check_in_acknowledged_at": null,
    "escalated_at": null,
    "resolved_at": null,
    "created_at": "2026-10-11T12:00:00.000000Z"
  },
  "auto_dial_emergency": "112"
}
```

**`auto_dial_emergency`:** The mobile client should immediately initiate a phone call to this number (Nigeria's emergency line) when receiving this response.

**Real-time:** A `sos.incident.triggered` event is broadcast on the `admin.sos` WebSocket channel immediately. If the broadcast fails, the SOS is still created and the WhatsApp alert still goes out.

**Errors:**
- `422` — validation: invalid coordinates, or `errors.ride`: `"SOS can only be triggered on an active ride."` / `"You are not a participant of this ride."`
- `409` — `"An active SOS incident already exists for this ride."`

### Acknowledge SOS Check-In

`POST /api/v1/sos/{incident}/acknowledge`

User confirms they are safe ("I'm safe"). Only the triggering user can acknowledge. Accepted as soon as the SOS is raised (`triggered`) or after the check-in prompt (`check_in_sent`); this stops the escalation.

**Errors:** `422` — `"Only the person who triggered the SOS can acknowledge it."` / `"This incident is not awaiting a check-in response."`

**Response (200):**
```json
{
  "message": "Check-in acknowledged. Glad you are safe.",
  "incident": { "status": "acknowledged", ... }
}
```

### Cancel SOS

`POST /api/v1/sos/{incident}/cancel`

Cancels an open SOS incident (false alarm). Available to the triggering user or an admin, until a safety operator takes over — after that only the operator can close it (resolve).

**Response (200):**
```json
{
  "message": "SOS incident cancelled.",
  "incident": { "status": "cancelled", ... }
}
```

**Errors:** `422` — `"You are not authorized to cancel this incident."`, `"This incident has already been closed."`, or `"A safety operator is handling this incident and will close it."` (status `operator_assigned` or `dispatched`).

---

## Admin — SOS Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:safety_operator`

### List Active Incidents

`GET /api/v1/admin/sos/active`

Returns active SOS incidents prioritized by severity (escalated first).

**Query Params:** `status`, `per_page`

**Response (200):**
```json
{
  "message": "Active SOS incidents retrieved.",
  "incidents": [
    {
      "id": "uuid",
      "ride_id": "uuid",
      "status": "escalated",
      "trigger_type": "passenger",
      "gps_lat": "9.0579000",
      "gps_lng": "7.4951000",
      "vehicle_details": { "plate_number": "AB-123-CD", "make": "Toyota", "model": "Corolla", "color": "White" },
      "telemetry_data": { "ride_status": "in_progress", "passenger_id": "uuid", "driver_id": "uuid" },
      "escalated_at": "2026-10-11T12:01:00.000000Z",
      "triggered_by": { "id": "uuid", "first_name": "Ade", ... },
      "ride": { ... }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

### Show Incident Detail

`GET /api/v1/admin/sos/{incident}`

Returns full incident details with event logs and ride information.

### Incident History

`GET /api/v1/admin/sos/history`

Returns all incidents (active and resolved) with filters.

**Query Params:** `status`, `trigger_type`, `ride_id`, `date_from`, `date_to`, `per_page`

### Assign Operator

`POST /api/v1/admin/sos/{incident}/assign`

Safety operator takes over an incident at any open stage (`triggered`, `check_in_sent`, `acknowledged`, `escalated`). Calling it on an incident another operator already holds reassigns it to the caller (logged as `operator_reassigned`). Uses row locking to prevent concurrent assignment conflicts.

**Errors:** `422` — `"This incident cannot be assigned in its current state."` (already resolved or cancelled).

**Response (200):**
```json
{
  "message": "You have been assigned to this incident.",
  "incident": { "status": "operator_assigned", "operator_id": "uuid", ... }
}
```

### Dispatch Emergency Services

`POST /api/v1/admin/sos/{incident}/dispatch`

Initiates emergency services handoff. Requires operator assignment first (`422` `"An operator must be assigned before dispatching emergency services."`).

**Request:**
```json
{
  "emergency_service_type": "police",
  "contact_number": "112",
  "notes": "Dispatching police to location."
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `emergency_service_type` | string | Yes | `police`, `ambulance`, `fire`, or `all` |
| `contact_number` | string | No | Emergency contact number |
| `notes` | string | No | Dispatch notes |

### Resolve Incident

`POST /api/v1/admin/sos/{incident}/resolve`

Resolves an SOS incident with operator notes. Allowed at any open stage — an operator can close a false alarm without waiting for escalation. If no operator was assigned, the resolving operator is recorded. `422` `"This incident has already been closed."` if it is already resolved or cancelled.

**Request:**
```json
{
  "notes": "Situation resolved. Both parties confirmed safe. False alarm triggered accidentally."
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `notes` | string | Yes | Min 10 characters, max 2000 |

**SOS Incident Status Flow:**
```
triggered ──→ check_in_sent ──→ escalated ──→ operator_assigned ──→ dispatched ──→ resolved
    │               │               │                 │
    └─→ acknowledged ←┘             │                 └──────────────→ resolved
                                    │
operator_assigned / resolved: reachable from triggered, check_in_sent, acknowledged, escalated
cancelled: reachable from triggered, check_in_sent, acknowledged, escalated (not once an operator holds it)
```

**WebSocket Channels:**
- `admin.sos` — Receives `sos.incident.escalated` events (SafetyOperator + SuperAdmin)
- `user.{userId}` — Receives `sos.check_in.requested` events (triggering user)

---

## Anti-Offline Trip Detection — Driver Compliance

**Middleware:** `auth:sanctum`, `user.type:driver`

### Get Compliance Status

`GET /api/v1/driver/compliance`

Returns the authenticated driver's compliance status including active flag count, total flags, active sanction details, and recent flag history.

**Response 200:**
```json
{
  "message": "Compliance status retrieved.",
  "compliance": {
    "active_flags_count": 1,
    "total_flags_count": 2,
    "active_sanction": {
      "tier": { "value": 1, "label": "Warning" },
      "action": "warning_issued",
      "flagged_at": "2026-10-10T14:30:00.000000Z",
      "is_disputed": false
    },
    "lookback_days": 30
  },
  "recent_flags": [...]
}
```

### Dispute Offline Flag

`POST /api/v1/driver/flags/{flag}/dispute`

Driver disputes an offline trip flag. Only the flagged driver can dispute. Cannot dispute an already-disputed flag.

**Request Body:**
```json
{
  "notes": "I did not complete this ride offline. The passenger cancelled and I drove home."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `notes` | string | Required, min 10, max 2000 chars |

**Response 200:**
```json
{
  "message": "Flag disputed successfully. An admin will review your dispute.",
  "flag": { ... }
}
```

**Response 422:** Already disputed, wrong driver, or validation error.

---

## Admin — Offline Trip Flags

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,safety_operator`

### List Offline Trip Flags

`GET /api/v1/admin/offline-flags`

List flagged trips with optional filters and pagination.

| Query Param | Type | Description |
|-------------|------|-------------|
| `sanction_tier` | int | Filter by tier: 1 (Warning), 2 (Suspension), 3 (Deactivation) |
| `is_disputed` | bool | Filter by disputed status |
| `dispute_outcome` | string | Filter: pending, upheld, overturned |
| `driver_id` | uuid | Filter by driver user ID |
| `date_from` | datetime | Filter from date |
| `date_to` | datetime | Filter to date |
| `status` | string | `active` (unresolved), `disputed` (pending dispute) |
| `per_page` | int | Items per page (default 20) |

### Show Offline Trip Flag

`GET /api/v1/admin/offline-flags/{flag}`

Get detailed information about a specific flag including detection data, ride details, and resolution info.

### Review Disputed Flag

`POST /api/v1/admin/offline-flags/{flag}/review`

Resolve a driver's dispute — uphold or overturn the flag. If overturned, the sanction is reversed and the driver may be reinstated.

**Request Body:**
```json
{
  "outcome": "upheld",
  "notes": "Evidence confirms offline trip activity after investigation."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `outcome` | string | Required: `upheld` or `overturned` |
| `notes` | string | Required, min 10, max 2000 chars |

**Response 200:**
```json
{
  "message": "Dispute Upheld.",
  "flag": { ... }
}
```

**Response 422:** Not disputed, already resolved, or validation error.

### Escalate Flag Sanction

`POST /api/v1/admin/offline-flags/{flag}/escalate`

Manually escalate a flag's sanction tier (L1→L2 or L2→L3). Cannot escalate beyond tier 3 or resolved flags.

**Request Body:**
```json
{
  "notes": "Pattern of abuse confirmed after investigation."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `notes` | string | Optional, max 2000 chars |

**Response 200:**
```json
{
  "message": "Sanction escalated to 48-Hour Suspension.",
  "flag": { ... }
}
```

**Response 422:** Already at max tier or resolved.

### Confirm Permanent Deactivation

`POST /api/v1/admin/offline-flags/{flag}/confirm-deactivation`

Admin confirms permanent deactivation for L3 (deactivation-tier) flags. Only works on flags where `sanction_tier` is 3, and never while the driver's dispute of the flag is pending or after it was overturned. Sets the driver's status to `deactivated`, forces them offline, removes them from the live driver index and sends a push notification.

**Auth required (admin).** Roles: `operations`, `safety_operator`

No request body required.

**Response 200:**
```json
{
  "message": "Driver permanently deactivated after admin review.",
  "flag": {
    "id": "uuid",
    "ride_id": "uuid",
    "driver_id": "uuid",
    "passenger_id": "uuid",
    "sanction_tier": { "value": 3, "label": "Permanent Deactivation" },
    "sanction_action": "pending_admin_review",
    "is_disputed": false,
    "flagged_at": "2026-10-10T14:30:00.000000Z",
    "driver": { "..." },
    "passenger": { "..." },
    "ride": { "..." }
  }
}
```

**Response 422:** one of:
- `"Only L3 (deactivation-tier) flags can be confirmed for permanent deactivation."`
- `"Review the driver's dispute before confirming deactivation."` (dispute pending)
- `"This flag was overturned and cannot lead to deactivation."`
- `"Driver is already deactivated."`

**Response 404:**
```json
{
  "message": "Driver not found."
}
```

**Sanction Tiers:**
| Tier | Label | Action |
|------|-------|--------|
| 1 | Warning | Push notification warning |
| 2 | 48-Hour Suspension | Force offline + suspend account. Lifted automatically after `OFFLINE_SUSPENSION_HOURS` (default 48) by the hourly `LiftExpiredComplianceSuspensionsJob`; the driver is notified. Suspensions set by an admin for other reasons are never lifted automatically. |
| 3 | Permanent Deactivation | Force offline + suspend pending mandatory admin review (confirm-deactivation above) |

---

## EV Charging Stations — Public

No authentication required.

### List EV Stations

```
GET /ev-stations?city_id={city_id}
```

| Param | Type | Rules |
|-------|------|-------|
| `city_id` | string (UUID) | Required, must exist in `cities` |

**Response 200:**
```json
{
  "stations": [
    {
      "id": "uuid",
      "name": "Lekki Phase 1 Charging Hub",
      "city_id": "uuid",
      "lat": "6.4378000",
      "lng": "3.4709000",
      "address": "12 Admiralty Way, Lekki Phase 1, Lagos",
      "total_stalls": 6,
      "status": { "value": "active", "label": "Active" },
      "availability": {
        "available": 3,
        "occupied": 2,
        "reserved": 1,
        "out_of_service": 0
      },
      "stalls": [ ... ]
    }
  ]
}
```

### Show EV Station

```
GET /ev-stations/{station}
```

**Response 200:**
```json
{
  "station": {
    "id": "uuid",
    "name": "Lekki Phase 1 Charging Hub",
    "city_id": "uuid",
    "lat": "6.4378000",
    "lng": "3.4709000",
    "address": "12 Admiralty Way, Lekki Phase 1, Lagos",
    "total_stalls": 6,
    "status": { "value": "active", "label": "Active" },
    "availability": { ... },
    "city": { ... },
    "stalls": [ ... ]
  }
}
```

---

## EV Charging — Driver Reservations

**Middleware:** `auth:sanctum`, `user.type:driver`

### Submit EV Charge Request

```
POST /driver/ev-reservations
Authorization: Bearer {driver_token}
Content-Type: application/json
```

Submit a general EV charging request. If no `station_id` is provided, the system auto-selects an active station in the driver's city. Creates a reservation in `pending_review` status. Only drivers whose vehicle can charge (`fuel_type` `electric` or `plug_in_hybrid`) may submit one (422 otherwise).

**Request Body:**
```json
{
  "battery_level": 25,
  "requested_date": "2026-10-15",
  "requested_time": "morning",
  "notes": "Battery draining fast, need urgent charge",
  "station_id": "uuid (optional)"
}
```

| Field          | Type    | Required | Notes                                     |
|----------------|---------|----------|-------------------------------------------|
| battery_level  | integer | Yes      | Current battery percentage (0–100)        |
| requested_date | date    | No       | Preferred date (defaults to today)        |
| requested_time | string  | No       | Preferred time slot (max 50 chars)        |
| notes          | string  | No       | Additional notes (max 1000 chars)         |
| station_id     | uuid    | No       | Specific station; auto-selected if omitted |

**Response 201:**
```json
{
  "message": "Charge request submitted.",
  "reservation": {
    "id": "uuid",
    "station_id": "uuid",
    "driver_id": "uuid",
    "status": "pending_review",
    "type": "charging",
    "battery_level": 25,
    "requested_date": "2026-10-15",
    "requested_time": "morning",
    "notes": "Battery draining fast, need urgent charge",
    "created_at": "2026-10-13T10:00:00.000000Z"
  }
}
```

**Response 422:** Validation error (battery_level out of range, invalid station_id, etc.).

---

### Reserve EV Stall

```
POST /driver/ev-reservations/stations/{station}/reserve
Authorization: Bearer {driver_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "estimated_charge_minutes": 45
}
```

| Field | Type | Rules |
|-------|------|-------|
| `estimated_charge_minutes` | integer | Optional, 5–480 |

Three possible outcomes:

| Outcome | HTTP | Condition |
|---------|------|-----------|
| `reserved` | 201 | Stall available — immediate lock |
| `queued` | 200 | All occupied but departure imminent — queue hold |
| `retry` | 200 | No availability, no imminent departures — retry later |

**Reservation expiry:** a `reserved` stall is held for `EV_RESERVATION_EXPIRY` minutes (default 30). If the driver has not activated it by then, `ExpireEvReservationsJob` (every 5 minutes) marks it `expired`, frees the stall and hands it to the first queued driver at that station, who receives a push notification that a stall is ready. The same hand-over happens when a session is completed or cancelled. Queues hold at most `EV_MAX_QUEUE_SIZE` drivers (default 10).

**Who can reserve:** only drivers whose vehicle has a charging port — `fuel_type` `electric` or `plug_in_hybrid` (set on the vehicle; see **Register/Update Vehicle**). Other drivers get 422 `"EV charging is only available for electric and plug-in hybrid vehicles. Set your vehicle's fuel type if it is one."`.

**Reservation fee** (`EV_RESERVATION_FEE`, default ₦500):
- Charged to the driver's **available earnings** when a stall is actually held for them — immediately for `reserved`, or when a `queued` driver is promoted. Nothing is charged while waiting in the queue. `fee_charged_at` records it.
- Like cash-ride commission, it may take the balance below zero, down to the wallet's `max_negative_balance` (default −₦5,000). Beyond that the reservation is refused (422 `"Your earnings balance cannot cover the ₦500.00 reservation fee."`). A driver whose earnings account is frozen gets 422 `"Your earnings account is on hold, so the reservation fee cannot be paid. Contact support."`. A queued driver who can no longer pay when a stall frees up is skipped: their reservation is cancelled (the reason is in `notes`) and they get an `ev_reservation_cancelled` push.
- Waived for Platinum and Diamond drivers (`fee_waived: true`).
- Refunded in full if the driver cancels within `EV_RESERVATION_FREE_CANCEL_MINUTES` (default 2) of the stall being held (`fee_refunded_at`). Kept after that, and on a no-show (expiry) or a completed session.
- `free_cancellation_until` is the time until which cancelling refunds the fee; use it for a countdown instead of hard-coding the window. It is `null` when there is nothing to refund (fee waived, not charged yet, already refunded, or the reservation is closed).

**Response 201 — Immediate Reservation:**
```json
{
  "message": "Stall reserved successfully.",
  "outcome": "reserved",
  "reservation": {
    "id": "uuid",
    "station_id": "uuid",
    "stall_id": "uuid",
    "status": { "value": "reserved", "label": "Reserved" },
    "fee_amount": "500.00",
    "fee_waived": false,
    "fee_charged_at": "2026-10-11T10:00:00.000000Z",
    "fee_refunded_at": null,
    "free_cancellation_until": "2026-10-11T10:02:00.000000Z",
    "reserved_at": "2026-10-11T10:00:00.000000Z"
  }
}
```

**Response 200 — Queued:**
```json
{
  "message": "Added to queue.",
  "outcome": "queued",
  "reservation": { ... },
  "queue_position": 2,
  "estimated_available_at": "2026-10-11T10:15:00.000000Z"
}
```

**Response 200 — Retry:**
```json
{
  "message": "All stalls are occupied with no imminent departures. Please try again later.",
  "outcome": "retry",
  "retry_after_minutes": 15,
  "estimated_wait_minutes": 35
}
```

**Response 422:** `"You already have an active reservation."` (one open reservation per driver, `EV_MAX_ACTIVE_RESERVATIONS`), station not operational, or `"Only drivers can reserve EV charging stalls."`

### List My Reservations

```
GET /driver/ev-reservations?status={status}&per_page=20
Authorization: Bearer {driver_token}
```

| Param | Type | Rules |
|-------|------|-------|
| `status` | string | Optional filter: `pending_review`, `reserved`, `queued`, `active`, `completed`, `expired`, `cancelled` |
| `per_page` | integer | Optional, default 20 |

**Response 200:**
```json
{
  "reservations": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
}
```

### Show Reservation

```
GET /driver/ev-reservations/{reservation}
Authorization: Bearer {driver_token}
```

**Response 200:** Returns reservation with station and stall details.

**Response 403:** Not your reservation.

### Activate Reservation

```
POST /driver/ev-reservations/{reservation}/activate
Authorization: Bearer {driver_token}
```

Transitions `reserved` → `active`. Marks the stall as occupied with estimated departure time.

**Response 200:**
```json
{
  "message": "Reservation activated. Charging session started.",
  "reservation": {
    "status": { "value": "active", "label": "Active" },
    "activated_at": "2026-10-11T10:05:00.000000Z"
  }
}
```

**Response 422:** `"Only reserved reservations can be activated."` (including a reservation that has already expired).

### Complete Reservation

```
POST /driver/ev-reservations/{reservation}/complete
Authorization: Bearer {driver_token}
```

Transitions `active`/`reserved` → `completed`. Releases the stall and triggers queue transfer.

**Response 200:**
```json
{
  "message": "Charging session completed.",
  "reservation": {
    "status": { "value": "completed", "label": "Completed" },
    "completed_at": "2026-10-11T11:00:00.000000Z"
  }
}
```

### Cancel Reservation

```
POST /driver/ev-reservations/{reservation}/cancel
Authorization: Bearer {driver_token}
```

Cancels any non-terminal reservation. Releases the stall and triggers queue transfer. If the reservation fee was charged less than `EV_RESERVATION_FREE_CANCEL_MINUTES` (default 2) ago it is refunded to the driver's earnings and `fee_refunded_at` is set; otherwise the fee is kept.

**Response 200:**
```json
{
  "message": "Reservation cancelled.",
  "reservation": {
    "status": { "value": "cancelled", "label": "Cancelled" },
    "fee_charged_at": "2026-10-11T10:00:00.000000Z",
    "fee_refunded_at": "2026-10-11T10:01:10.000000Z",
    "free_cancellation_until": null
  }
}
```

**Response 422:** `"Cannot cancel a terminal reservation."` (already completed, expired or cancelled).

---

## Admin — EV Station Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

### List Stations

```
GET /admin/ev-stations?city_id=&status=&search=&per_page=20
Authorization: Bearer {admin_token}
```

| Param | Type | Rules |
|-------|------|-------|
| `city_id` | string (UUID) | Optional filter |
| `status` | string | Optional: `active`, `inactive`, `maintenance` |
| `search` | string | Optional, search by name (case-insensitive) |
| `per_page` | integer | Optional, default 20, max 100 |

**Response 200:**
```json
{
  "stations": [ ... ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 5 }
}
```

### Create Station

```
POST /admin/ev-stations
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "name": "Lekki Phase 1 Charging Hub",
  "city_id": "uuid",
  "lat": 6.4378,
  "lng": 3.4709,
  "address": "12 Admiralty Way, Lekki Phase 1, Lagos",
  "total_stalls": 6,
  "connector_types": ["CCS2", "Type2"],
  "power_output_kw": 150,
  "pricing_per_kwh": 15000,
  "operating_hours": { "open": "06:00", "close": "22:00" }
}
```

| Field | Type | Rules |
|-------|------|-------|
| `name` | string | Required, max 255 |
| `city_id` | string (UUID) | Required, must exist |
| `lat` | numeric | Required, -90 to 90 |
| `lng` | numeric | Required, -180 to 180 |
| `address` | string | Required, max 500 |
| `total_stalls` | integer | Required, 1–100 |
| `connector_types` | array | Optional, values: `CCS2`, `CHAdeMO`, `Type2`, `GBT`, `Tesla` |
| `power_output_kw` | integer | Optional, 1–500 kW |
| `pricing_per_kwh` | integer | Optional, in kobo per kWh |
| `operating_hours` | object | Optional, `{ "open": "HH:mm", "close": "HH:mm" }` |
| `status` | string | Optional: `active`, `inactive`, `maintenance` |

Stalls are auto-generated (numbered 1 to `total_stalls`).

**Response 201:**
```json
{
  "message": "EV charging station created successfully.",
  "station": { ... }
}
```

### Show Station

```
GET /admin/ev-stations/{station}
Authorization: Bearer {admin_token}
```

Returns station with stall-level availability and current occupant details.

**Response 200:**
```json
{
  "station": { ... },
  "availability": {
    "total": 6,
    "available": 3,
    "occupied": 2,
    "reserved": 1,
    "out_of_service": 0,
    "queue_length": 1
  }
}
```

### Update Station

```
PUT /admin/ev-stations/{station}
Authorization: Bearer {admin_token}
Content-Type: application/json
```

All fields optional. `total_stalls` is managed via the Manage Stalls endpoint.

**Request Body:**
```json
{
  "name": "Updated Hub Name",
  "address": "New address"
}
```

**Response 200:**
```json
{
  "message": "EV charging station updated successfully.",
  "station": { ... }
}
```

### Toggle Station Status

```
PATCH /admin/ev-stations/{station}/status
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "status": "maintenance"
}
```

| Field | Type | Rules |
|-------|------|-------|
| `status` | string | Required: `active`, `inactive`, `maintenance` |

**Response 200:**
```json
{
  "message": "Station status updated successfully.",
  "station": { ... }
}
```

### Manage Stalls

```
PUT /admin/ev-stations/{station}/stalls
Authorization: Bearer {admin_token}
Content-Type: application/json
```

Upserts stalls by stall number. Auto-updates `total_stalls` count.

**Request Body:**
```json
{
  "stalls": [
    { "stall_number": 1, "status": "available" },
    { "stall_number": 7, "status": "out_of_service" }
  ]
}
```

| Field | Type | Rules |
|-------|------|-------|
| `stalls` | array | Required, min 1 |
| `stalls.*.stall_number` | integer | Required, min 1 |
| `stalls.*.status` | string | Optional: `available`, `occupied`, `reserved`, `out_of_service` |

**Response 200:**
```json
{
  "message": "Stalls updated successfully.",
  "station": { ... }
}
```

### Station Utilisation Report

```
GET /admin/ev-stations/utilisation?city_id=
Authorization: Bearer {admin_token}
```

Real-time occupancy across all active stations. Computed from eager-loaded stalls (no N+1 queries).

**Response 200:**
```json
{
  "utilisation": [
    {
      "station_id": "uuid",
      "station_name": "Lekki Phase 1 Charging Hub",
      "city_id": "uuid",
      "total_stalls": 6,
      "availability": {
        "total": 6,
        "available": 3,
        "occupied": 2,
        "reserved": 1,
        "out_of_service": 0,
        "queue_length": 1
      },
      "occupancy_rate": 50.0,
      "estimated_wait_minutes": 12
    }
  ],
  "summary": {
    "total_stations": 5,
    "total_stalls": 30,
    "total_available": 15,
    "total_occupied": 10,
    "average_occupancy_rate": 50.0
  }
}
```

### Delete Station

```
DELETE /admin/ev-stations/{station}
Authorization: Bearer {admin_token}
```

Permanently deletes an EV charging station and all its stalls. Fails if the station has active reservations.

**Response 200:**
```json
{
  "message": "EV charging station deleted."
}
```

**Response 422:** Station has active reservations.

---

## Passenger — Nearby Drivers

**Middleware:** `auth:sanctum`, `user.type:passenger`

### List Nearby Drivers (Passenger)

```
GET /passenger/nearby-drivers
Authorization: Bearer {passenger_token}
```

Returns online, approved drivers near the given coordinates. Only drivers with a recent location update (within 120 seconds) are included. Results are capped at 50 drivers.

| Query Param | Type   | Required | Description                                   |
|-------------|--------|----------|-----------------------------------------------|
| lat         | number | Yes      | Latitude of the passenger (-90 to 90)         |
| lng         | number | Yes      | Longitude of the passenger (-180 to 180)      |
| radius_km   | number | No       | Search radius in km (default: 10, max: 50)    |

**Response 200:**
```json
{
  "drivers": [
    {
      "driver_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
      "lat": 6.5180,
      "lng": 3.3750,
      "heading": 145.2,
      "timestamp": 1728223200,
      "distance_km": 0.85,
      "vehicle_class": "Economy"
    },
    {
      "driver_id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
      "lat": 6.5290,
      "lng": 3.3820,
      "heading": 270.0,
      "timestamp": 1728223195,
      "distance_km": 1.23,
      "vehicle_class": "Comfort"
    }
  ]
}
```

**Response 422:** Validation error (missing `lat`/`lng`, or values out of range).

---

## Passenger — Wallet

**Middleware:** `auth:sanctum`, `user.type:passenger`

### View Wallet

```
GET /passenger/wallet
Authorization: Bearer {passenger_token}
```

**Response 200:**
```json
{
  "wallet": {
    "id": "uuid",
    "balance": 500000,
    "balance_formatted": "₦5,000.00",
    "status": "active",
    "currency": "NGN"
  }
}
```

### Wallet Transaction History

```
GET /passenger/wallet/transactions?per_page=20
Authorization: Bearer {passenger_token}
```

Returns paginated ledger entries for the passenger's wallet.

### Initialize Top-up

```
POST /passenger/wallet/topup
Authorization: Bearer {passenger_token}
Content-Type: application/json
```

Initializes a Paystack payment for wallet top-up. Amounts are in kobo. The passenger does not need an email on their account — Paystack requires one, so a stable placeholder (`{user_id}@rider.etigo.ng`) is sent instead. Supported payment channels: **card**, **bank transfer**, and **USSD**. OPay users can fund their wallet via the bank transfer option (transfer to the generated temporary account number from their OPay app).

**Request Body:**

| Field          | Type    | Required | Rules                                |
|----------------|---------|----------|--------------------------------------|
| `amount`       | integer | Yes      | Min: 50000 (₦500)                   |
| `callback_url` | string  | Yes      | Valid URL for redirect after payment |

**Example Request:**
```json
{
  "amount": 500000,
  "callback_url": "https://app.etigo.ng/wallet/callback"
}
```

**Response 200:**
```json
{
  "message": "Top-up initialized.",
  "payment_link": "https://checkout.paystack.com/...",
  "tx_ref": "TOPUP-ABCD1234EFGH"
}
```

**Response 422:** `"Wallet is frozen or closed."`, `"Top-up would exceed maximum wallet balance of ₦…"`, or `"Daily top-up limit exceeded."` (limits come from the admin wallet settings).
**Response 429:** Too many top-up attempts (5 per hour, 10 per day).
**Response 502:** `"Unable to initialize payment. Please try again."`

### Verify Top-up

```
GET /passenger/wallet/topup/{reference}/verify
Authorization: Bearer {passenger_token}
```

Fallback for a missed or delayed Paystack webhook. `{reference}` is the `tx_ref` returned by **Initialize Top-up** (Paystack also appends it to the callback URL as `reference`). Only the caller's own top-ups can be verified. The wallet is credited with the amount Paystack actually collected, once — calling this repeatedly or racing the webhook never credits twice.

**Response 200 (verified — credit queued):**
```json
{
  "message": "Top-up is being processed."
}
```

**Response 200 (already credited):**
```json
{
  "message": "Top-up has already been credited."
}
```

**Response 404:** `"Top-up not found."` — unknown reference, or a reference belonging to another user.

**Response 422 (payment not completed):**
```json
{
  "message": "Transaction was not successful.",
  "status": "abandoned"
}
```

**Response 502:** `"Unable to verify transaction."` — Paystack could not be reached; retry later.

---

## Driver — Bank Account

**Middleware:** `auth:sanctum`, `user.type:driver`

Typical flow: **List Supported Banks** → **Resolve Bank Account** (show the account name and any `name_check` / `payout_hold` message for confirmation) → **Request Bank Account Code** → **Add Bank Account** with the code. The code is required for the first account and for every later change.

**Payout safeguards** (configured by finance in **Wallet Settings**):
- **Name check.** The account name from Paystack is compared with the driver's verified name (their profile name, checked against their NIN at KYC, plus the NIN middle name). Word order, case, punctuation and titles are ignored and a one-letter spelling difference in a longer name is accepted; the surname and at least one given name must appear. On a mismatch, depending on `payout_account_name_check`: `review` (default) saves the account but pauses payouts to it until finance approves it; `block` refuses to save it; `off` only records the result.
- **Hold after a change.** Payouts to an account that **replaces** another wait `payout_account_hold_hours` (default 24) after it is saved; a first account is not held unless `payout_account_hold_first_account` is on. Finance can release a hold early.
- `payout_eligibility` (on **Add** and **View**) tells the app whether the driver can request a payout now and, if not, why:

| `code` | Meaning | Suggested app behaviour |
|--------|---------|-------------------------|
| `null` (`can_request_payouts: true`) | Payouts allowed | — |
| `payout_account_on_hold` | Recently changed; `payouts_held_until` says when payouts start | Show `message` with a countdown; disable **Withdraw** until then |
| `payout_account_under_review` | Name did not match; finance is checking | Show `message`; disable **Withdraw**; the driver gets `payout_account_reviewed` when it is done |
| `payout_account_rejected` | Finance rejected the account | Show `message` and an **Add another account** button |
| `no_payout_account` | No account on file (View only returns 404 instead) | Show **Add bank account** |

### List Supported Banks

```
GET /driver/bank-account/banks
Authorization: Bearer {driver_token}
```

Lists Nigerian banks supported for payouts (from Paystack, cached for 24 hours). Use a bank's `code` as `bank_code`.

**Response 200:**
```json
{
  "banks": [
    { "id": 9, "code": "058", "name": "Guaranty Trust Bank" },
    { "id": 1, "code": "044", "name": "Access Bank" }
  ]
}
```

### Resolve Bank Account

```
POST /driver/bank-account/resolve
Authorization: Bearer {driver_token}
Content-Type: application/json
```

Looks up the account holder's name so the driver can confirm it before saving. Nothing is stored. Rate limited to 30 requests per minute.

| Field            | Type   | Required | Rules          |
|------------------|--------|----------|----------------|
| `bank_code`      | string | Yes      | Valid bank code |
| `account_number` | string | Yes      | 10-digit NUBAN |

**Response 200:**
```json
{
  "bank_code": "058",
  "account_number": "0123456789",
  "account_name": "JOHN DOE",
  "name_check": {
    "status": "mismatch",
    "verified_name": "Ada Chioma Obi",
    "outcome": "review",
    "message": "This account is in the name JOHN DOE, which does not match your verified name Ada Chioma Obi. You can still save it, but payouts will be paused until E-tiGo checks it. Use an account in your own name to be paid without delay."
  },
  "payout_hold": {
    "applies": true,
    "hours": 24,
    "message": "For your security, payouts to this account will start 24 hours after you save it."
  }
}
```

Show these **before** asking for the code:

| Field | Values |
|-------|--------|
| `name_check.status` | `matched` or `mismatch` |
| `name_check.outcome` | `ok` (nothing to show), `review` (can save; payouts wait for E-tiGo — show `message` and let the driver continue or pick another account), `blocked` (cannot save — show `message` and ask for an account in their own name) |
| `payout_hold.applies` | `true` when saving this account will hold payouts for `hours`; show `message` |

**Response 422:**
```json
{
  "message": "Unable to resolve bank account details. Please check the account number and bank."
}
```

### Request Bank Account Code

```
POST /driver/bank-account/otp
Authorization: Bearer {driver_token}
```

Sends a 6-digit code to the driver's phone (WhatsApp with SMS fallback; always `123456` in local/staging). The code is required to **add or replace** the payout account, including the first one, so a stolen session cannot direct payouts to another account. Codes expire after 5 minutes; a new code can be requested every 60 seconds. Rate limited to 3 requests per minute.

**Response 200:**
```json
{
  "message": "Verification code sent to your phone.",
  "expires_at": "2026-10-15T10:05:00+00:00"
}
```

**Response 422:** `"Add a phone number to your account before adding bank details."`
**Response 429:** `"Please wait before requesting another code."`

### Add Bank Account

```
POST /driver/bank-account
Authorization: Bearer {driver_token}
Content-Type: application/json
```

Verifies the bank account via Paystack account resolution, checks the code, and saves it as the driver's primary bank account (any previous account stops being primary). Supports both traditional banks (NUBAN) and OPay accounts.

The account is resolved **before** the code is checked, so a Paystack failure does not use up the code: the driver can correct the details and retry with the same code while it is valid. After a save the driver is notified (`payout_account_changed`, in-app and push) and sent a WhatsApp/SMS alert that ignores notification preferences: *"Your payout bank account was changed to ADA OBI, account ending 4321. If you did not do this, contact E-tiGo support immediately."* (*"set to"* for the first account).

**Request Body:**

| Field            | Type   | Required | Rules              |
|------------------|--------|----------|--------------------|
| `account_number` | string | Yes      | 10-digit NUBAN     |
| `bank_code`      | string | Yes      | Valid bank code     |
| `otp_code`       | string | Yes      | 6-digit code from **Request Bank Account Code** |

The name check runs before the code is checked: in `block` mode a mismatch is refused without using up the code. When payouts are held or under review, the alert says so (*"Payouts to it start 17 Oct 2026, 2:05 PM (Lagos time)."* or *"Payouts to it are paused until E-tiGo checks the account name."*).

**Response 201:**
```json
{
  "message": "Bank account added and verified.",
  "bank_account": {
    "id": "uuid",
    "bank_code": "058",
    "bank_name": "GTBank",
    "account_number": "******6789",
    "account_name": "ADA OBI",
    "is_verified": true,
    "is_primary": true,
    "payouts_held_until": "2026-10-17T13:05:00+00:00",
    "name_match_status": "matched",
    "review_status": null
  },
  "payout_eligibility": {
    "can_request_payouts": false,
    "code": "payout_account_on_hold",
    "message": "For your security, payouts to a newly added bank account start 17 Oct 2026, 2:05 PM (Lagos time).",
    "payouts_held_until": "2026-10-17T13:05:00+00:00"
  }
}
```

`review_status` is `null` (no review needed), `pending`, `approved` or `rejected`.

**Response 422 (name mismatch, `block` mode):**
```json
{
  "message": "This account is in the name JOHN DOE, which does not match your verified name Ada Obi. Payouts can only go to a bank account in your own name.",
  "code": "payout_account_name_mismatch",
  "errors": { "account_number": ["This account is in the name JOHN DOE, which does not match your verified name Ada Obi. Payouts can only go to a bank account in your own name."] }
}
```

**Response 422 (no code):**
```json
{
  "message": "Adding or changing your payout account requires a verification code. Request one with POST /driver/bank-account/otp.",
  "errors": { "otp_code": ["Adding or changing your payout account requires a verification code. Request one with POST /driver/bank-account/otp."] }
}
```

**Response 422 (wrong or expired code):** `errors.otp_code` is `"Invalid verification code."`, `"No active verification code found. Please request a new one."` or `"Too many attempts. Please request a new code."` (5 attempts per code).

### View Bank Account

```
GET /driver/bank-account
Authorization: Bearer {driver_token}
```

**Response 200:** `bank_account` (the primary account, number masked, same shape as **Add Bank Account**) and `payout_eligibility`.
**Response 404:** No bank account on file.

---

## Driver — Earnings

**Middleware:** `auth:sanctum`, `user.type:driver`

### Earnings Overview

```
GET /driver/earnings
Authorization: Bearer {driver_token}
```

Returns the driver's earnings balances and period summaries.

**Response 200:**
```json
{
  "earnings": {
    "balances": {
      "pending_kobo": 0,
      "available_kobo": 300000,
      "total_paid_kobo": 150000
    },
    "summaries": {
      "today": 2500,
      "this_week": 12000,
      "this_month": 45000
    }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `pending_kobo` | integer | Earnings awaiting settlement (held during settlement delay) |
| `available_kobo` | integer | Earnings available for payout |
| `total_paid_kobo` | integer | Total paid out to driver's bank account |
| `today` | integer | Sum of `final_fare_amount` for rides completed today (in ₦) |
| `this_week` | integer | Sum for current week |
| `this_month` | integer | Sum for current month |

### Ride Earnings Breakdown

```
GET /driver/earnings/rides/{ride}
Authorization: Bearer {driver_token}
```

Returns the earnings breakdown for a specific completed ride. Driver can only view their own rides.

**Response 200:**
```json
{
  "breakdown": {
    "ride_id": "uuid",
    "fare_amount": 2000,
    "fare_kobo": 200000,
    "commission_rate": 0.2,
    "commission_kobo": 40000,
    "net_earnings_kobo": 160000,
    "payment_method": "cash",
    "tip_amount": 0,
    "completed_at": "2026-10-10T14:30:00Z"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `fare_amount` | integer | Final fare in ₦ |
| `fare_kobo` | integer | Final fare in kobo |
| `commission_rate` | float | Commission rate applied (e.g. 0.2 = 20%) |
| `commission_kobo` | integer | Commission amount deducted (kobo) |
| `net_earnings_kobo` | integer | Driver's net earnings after commission (kobo) |
| `payment_method` | string | `cash`, `card`, `wallet`, or `bank_transfer` |
| `tip_amount` | integer | Tip amount in ₦ (0 if no tip) |
| `completed_at` | string | ISO 8601 timestamp of ride completion |

**Response 403:** Ride does not belong to the authenticated driver.
**Response 404:** Ride not found.

---

## Driver — Repairs

**Middleware:** `auth:sanctum`, `user.type:driver`

Drivers can submit vehicle repair and maintenance requests and track their progress.

### List Repair Requests

```
GET /driver/repairs
Authorization: Bearer {driver_token}
```

Returns all repair/maintenance requests submitted by the authenticated driver, ordered by most recent first.

**Response 200:**
```json
{
  "repairs": [
    {
      "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
      "reference_number": "REP-000012",
      "type": {
        "value": "repair",
        "label": "Repair"
      },
      "status": {
        "value": "in_progress",
        "label": "In progress"
      },
      "title": "Brake pad replacement",
      "details": "Front brake pads are worn out and making a squealing noise when braking.",
      "preferred_date": "2026-10-15",
      "preferred_time": "Morning",
      "requested_date": "2026-10-15",
      "requested_time": "Morning",
      "summary_note": "Request received · Workshop visit to be scheduled for Morning",
      "vehicle_name": "Toyota Corolla",
      "vehicle_plate": "LAG-234-XY",
      "vehicle_color": "White",
      "workshop_visit": {
        "scheduled_time": "Morning",
        "instructions": "A technician is being assigned for your vehicle. Contact fleet support for workshop details.",
        "support_phone": "0812 5690 6790"
      },
      "appointment": null,
      "cancellation_reason": null,
      "cancelled_by": null,
      "progress_steps": [
        {
          "title": "Request submitted",
          "subtitle": "13 Oct 2026 · Repair received",
          "state": "completed"
        },
        {
          "title": "Technician assigned",
          "subtitle": "Your request is being coordinated",
          "state": "in_progress"
        },
        {
          "title": "Workshop visit",
          "subtitle": "Scheduled for Morning",
          "state": "upcoming"
        },
        {
          "title": "Inspection complete",
          "subtitle": "Report will be available once completed",
          "state": "upcoming"
        }
      ],
      "attachments": [],
      "submitted_at": "2026-10-13T09:30:00.000000Z",
      "created_at": "2026-10-13T09:30:00.000000Z",
      "updated_at": "2026-10-13T09:30:00.000000Z"
    }
  ]
}
```

---

### Show Repair Request

```
GET /driver/repairs/{repair}
Authorization: Bearer {driver_token}
```

Returns a single repair request. The repair must belong to the authenticated driver.

**Response 200:**
```json
{
  "repair": {
    "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "reference_number": "REP-000012",
    "type": {
      "value": "maintenance",
      "label": "Maintenance"
    },
    "status": {
      "value": "scheduled",
      "label": "Scheduled"
    },
    "title": "Routine oil change",
    "details": "Vehicle is due for its 20,000 km service. Engine oil and filter replacement needed.",
    "preferred_date": "2026-10-18",
    "preferred_time": "Afternoon",
    "requested_date": "2026-10-18",
    "requested_time": "Afternoon",
    "summary_note": "Request received · Workshop visit to be scheduled for Afternoon",
    "vehicle_name": "Toyota Corolla",
    "vehicle_plate": "LAG-234-XY",
    "vehicle_color": "White",
    "workshop_visit": {
      "scheduled_time": "Afternoon",
      "instructions": "A technician is being assigned for your vehicle. Contact fleet support for workshop details.",
      "support_phone": "0812 5690 6790"
    },
    "appointment": null,
    "cancellation_reason": null,
    "cancelled_by": null,
    "progress_steps": [
      {
        "title": "Request submitted",
        "subtitle": "13 Oct 2026 · Maintenance received",
        "state": "completed"
      },
      {
        "title": "Technician assigned",
        "subtitle": "Your request is being coordinated",
        "state": "in_progress"
      },
      {
        "title": "Workshop visit",
        "subtitle": "Scheduled for Afternoon",
        "state": "upcoming"
      },
      {
        "title": "Inspection complete",
        "subtitle": "Report will be available once completed",
        "state": "upcoming"
      }
    ],
    "attachments": [],
    "submitted_at": "2026-10-13T11:00:00.000000Z",
    "created_at": "2026-10-13T11:00:00.000000Z",
    "updated_at": "2026-10-13T11:15:00.000000Z"
  }
}
```

**Response 403:**
```json
{
  "message": "Unauthorized."
}
```

**Response 404:** Repair request not found.

---

### Create Repair Request

```
POST /driver/repairs
Authorization: Bearer {driver_token}
Content-Type: application/json
```

Submits a new vehicle repair or maintenance request. The vehicle is automatically linked from the driver's profile.

| Field          | Type   | Required | Description                                                   |
|----------------|--------|----------|---------------------------------------------------------------|
| type           | string | Yes      | `repair` or `maintenance`                                     |
| title          | string | Yes      | Short summary of the issue (max 150 characters)               |
| details        | string | Yes      | Detailed description of the problem (max 2000 characters)     |
| preferred_date | date   | Yes      | Preferred date for workshop visit (e.g. `2026-10-15`)         |
| preferred_time | string | Yes      | Preferred time slot (e.g. `Morning`, `Afternoon`, max 50 chars) |

**Request:**
```json
{
  "type": "repair",
  "title": "Brake pad replacement",
  "details": "Front brake pads are worn out and making a squealing noise when braking.",
  "preferred_date": "2026-10-15",
  "preferred_time": "Morning"
}
```

**Response 201:**
```json
{
  "message": "Vehicle service request submitted successfully.",
  "repair": {
    "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "reference_number": "REP-000012",
    "type": {
      "value": "repair",
      "label": "Repair"
    },
    "status": {
      "value": "in_progress",
      "label": "In progress"
    },
    "title": "Brake pad replacement",
    "details": "Front brake pads are worn out and making a squealing noise when braking.",
    "preferred_date": "2026-10-15",
    "preferred_time": "Morning",
    "requested_date": "2026-10-15",
    "requested_time": "Morning",
    "summary_note": "Request received · Workshop visit to be scheduled for Morning",
    "vehicle_name": "Toyota Corolla",
    "vehicle_plate": "LAG-234-XY",
    "vehicle_color": "White",
    "workshop_visit": {
      "scheduled_time": "Morning",
      "instructions": "A technician is being assigned for your vehicle. Contact fleet support for workshop details.",
      "support_phone": "0812 5690 6790"
    },
    "appointment": null,
    "cancellation_reason": null,
    "cancelled_by": null,
    "progress_steps": [
      {
        "title": "Request submitted",
        "subtitle": "13 Oct 2026 · Repair received",
        "state": "completed"
      },
      {
        "title": "Technician assigned",
        "subtitle": "Your request is being coordinated",
        "state": "in_progress"
      },
      {
        "title": "Workshop visit",
        "subtitle": "Scheduled for Morning",
        "state": "upcoming"
      },
      {
        "title": "Inspection complete",
        "subtitle": "Report will be available once completed",
        "state": "upcoming"
      }
    ],
    "attachments": [],
    "submitted_at": "2026-10-13T09:30:00.000000Z",
    "created_at": "2026-10-13T09:30:00.000000Z",
    "updated_at": "2026-10-13T09:30:00.000000Z"
  }
}
```

**Response 422:**
```json
{
  "message": "The type field is required.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "type": ["The type field is required."]
  }
}
```

---

## Driver — Ledger & Payouts

**Middleware:** `auth:sanctum`, `user.type:driver`

### Ledger Summary

```
GET /driver/ledger/summary
Authorization: Bearer {driver_token}
```

Returns the driver's earnings balances in kobo: `pending` (waiting for the settlement delay), `available` (withdrawable) and `total_paid`.

**Response 200:**
```json
{
  "earnings": {
    "currency": "NGN",
    "pending": 0,
    "available": 378000,
    "total_paid": 0
  }
}
```

### Ledger Transactions

```
GET /driver/ledger/transactions?type=credit&from=2026-10-01&to=2026-10-31&per_page=20&page=1
Authorization: Bearer {driver_token}
```

Paginated entries on the driver's **available** earnings account, newest first.

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `debit` or `credit` |
| `from`, `to` | date | `Y-m-d`; `to` is inclusive |
| `per_page` | integer | 1–100, default 20 |
| `page` | integer | ≥ 1 |

Each entry links to its source (`source_type`: `ride` or `payout`, `source_id`) and its journal. `journal.metadata.category` says what the entry was: a card or wallet ride posts the **full fare as a credit** (`ride_fare`) and the **platform commission as a separate debit** (`commission`); card tips are credits (`tip`); cash rides post only the commission debit.

**Response 200:**
```json
{
  "transactions": [
    {
      "id": "01a14047-1bc4-707e-9972-3a378e78c331",
      "type": "debit",
      "type_label": "Debit",
      "amount": 82000,
      "running_balance": 328000,
      "created_at": "2026-10-15T15:56:04+00:00",
      "source_type": "ride",
      "source_id": "01a14047-1b9d-73ef-bc2b-e61c243918a5",
      "journal": {
        "id": "01a14047-1bc2-7354-b0bd-32969645f4aa",
        "reference": "JRN-U9QFE9ICXENY-261015",
        "description": "Platform commission for ride 01a14047-1b9d-73ef-bc2b-e61c243918a5",
        "metadata": {
          "ride_id": "01a14047-1b9d-73ef-bc2b-e61c243918a5",
          "category": "commission",
          "fare_kobo": 410000,
          "commission_kobo": 82000,
          "commission_rate": 0.2
        },
        "posted_at": "2026-10-15T15:56:04+00:00"
      }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 3 }
}
```

**Response 422:** invalid `type`, dates or `per_page` (e.g. `"The per page field must not be greater than 100."`).

### Get Ledger Transaction

```
GET /driver/ledger/transactions/{entry}
Authorization: Bearer {driver_token}
```

One entry on the driver's available earnings account, with its journal and source — same shape as an item of **Ledger Transactions**, wrapped in `transaction`.

**Response 404:** `"Transaction not found."` when the entry belongs to another account; `NOT_FOUND` (`"Resource not found."`) for an unknown id.

### List Payouts

```
GET /driver/payouts?status=paid
Authorization: Bearer {driver_token}
```

Returns the driver's payout history, newest first, 20 per page. Optional `status` filter: `requested`, `approved`, `processing`, `paid`, `failed`, `rejected`, `reversed`.

### Request Payout

```
POST /driver/payouts
Authorization: Bearer {driver_token}
```

Requests a withdrawal from available driver earnings to the driver's primary bank account via Paystack transfer. Supports both traditional bank accounts (NUBAN) and OPay accounts. Requires a verified bank account. Finance approves the request before the transfer is sent. Rate limit: 3 requests per minute.

**Request Body:**

| Field    | Type    | Required | Rules                         |
|----------|---------|----------|-------------------------------|
| `amount` | integer | Yes      | In kobo. At least the admin `min_payout` wallet setting (default 100000 = ₦1,000) |

**Payout lifecycle:** `requested` → `approved` (finance) → `processing` (transfer sent) → `paid`.

- The money leaves the driver's available balance when the transfer is sent. If the balance no longer covers it at that point, the payout becomes `failed` with a reason and nothing is debited.
- A transfer Paystack refuses or reports as failed, or one the bank sends back, returns the money to the driver's available balance (`failed` or `reversed`). Finance can `retry` either.
- If Paystack cannot be reached or answers with a server error, or its webhook never arrives, the money **stays reserved** and the payout stays `approved`/`processing` until E-tiGo confirms the outcome with Paystack (automatically every 15 minutes, or when finance uses **check status**). A transfer is never sent twice.
- Finance can `reject` a request before it is approved.

**Response 201:**
```json
{
  "message": "Payout request submitted for approval.",
  "payout": {
    "id": "5e4d3c2b-1a09-4f8e-9d7c-6b5a4f3e2d1c",
    "amount": 500000,
    "status": "requested",
    "status_label": "Requested",
    "requested_at": "2026-10-15T09:00:00+00:00",
    "bank_account": { "...": "..." },
    "created_at": "2026-10-15T09:00:00+00:00"
  }
}
```

**Response 422:** one of:
- `"No verified bank account on file. Please add a bank account first."`
- the account's payout safeguard, with `code` and `payouts_held_until` (see **Driver — Bank Account**):
  ```json
  {
    "code": "payout_account_on_hold",
    "message": "For your security, payouts to a newly added bank account start 17 Oct 2026, 2:05 PM (Lagos time).",
    "payouts_held_until": "2026-10-17T13:05:00+00:00"
  }
  ```
  `code` can also be `payout_account_under_review` or `payout_account_rejected`.
- `"Insufficient available balance."`
- `"You already have a pending payout request."` (one `requested`, `approved` or `processing` payout at a time)
- a validation error on `amount` (below the minimum)

### Update Payout Preference

```
PUT /driver/payouts/preference
Authorization: Bearer {driver_token}
```

Sets the driver's payout preference between on-demand requests and weekly automatic payouts.

**Request Body:**

| Field | Type | Required | Values |
|-------|------|----------|--------|
| `payout_preference` | string | Yes | `on_demand`, `weekly_auto` |

- `on_demand` — Driver manually requests payouts (default)
- `weekly_auto` — Automatic payout every Monday at 06:00 WAT. Requires a verified bank account. Skips drivers below the minimum payout threshold.

**Response 200:**
```json
{
  "message": "Payout preference updated.",
  "payout_preference": "weekly_auto"
}
```

**Response 422:** Bank account not verified (required for weekly auto).

---

## Admin — Wallet & Ledger Management

**Middleware:** `auth:sanctum`, `user.type:admin`, role-based (see individual endpoints)

Wallet and ledger endpoints use granular role-based access. Super Admin always has access to all endpoints.

### List Wallets

```
GET /admin/wallets?search=john&status=active&per_page=20
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support

### View Wallet

```
GET /admin/wallets/{account}
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support

### Freeze Wallet

```
POST /admin/wallets/{account}/freeze
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

**Request Body:**

| Field    | Type   | Required | Rules          |
|----------|--------|----------|----------------|
| `reason` | string | Yes      | Max 500 chars  |

### Unfreeze Wallet

```
POST /admin/wallets/{account}/unfreeze
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

**Request Body:**

| Field    | Type   | Required | Rules          |
|----------|--------|----------|----------------|
| `reason` | string | Yes      | Max 500 chars  |

### Driver Ledgers

```
GET /admin/driver-ledgers
GET /admin/driver-ledgers/{account}
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support

### Ledger Explorer

```
GET /admin/ledger?per_page=50
GET /admin/ledger/export?format=csv
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

### Adjustments

```
GET /admin/adjustments
POST /admin/adjustments
POST /admin/adjustments/{adjustment}/approve
POST /admin/adjustments/{adjustment}/reject
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Manual credits or debits to any ledger account, with maker–checker approval: the admin who creates an adjustment cannot approve it. Nothing is posted to the ledger until approval.

**Create — request body:**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `account_id` | string (UUID) | Yes | Existing ledger account |
| `type` | string | Yes | `credit` or `debit` |
| `amount` | integer | Yes | In kobo, min 1 |
| `reason` | string | Yes | 10–2000 chars |

A credit is funded from the `refunds` system account; a debit goes to `platform_commission`.

**Reject — request body:** `reason` (string, 10–2000 chars, required).

| Response | When |
|----------|------|
| 201 | `"Adjustment created and pending approval."` |
| 200 | `"Adjustment approved and posted."` / `"Adjustment rejected."` |
| 403 | `"Approver must differ from creator."` |
| 422 | `"Adjustment is not pending."`, or the ledger refused the posting (e.g. the account is frozen: `"Account … is frozen, cannot post."`) — the adjustment stays `pending` |

### Payouts

```
GET /admin/payouts?status=requested&search=bayo&from=2026-10-01&to=2026-10-15
POST /admin/payouts/{payout}/approve
POST /admin/payouts/{payout}/reject
POST /admin/payouts/{payout}/retry
POST /admin/payouts/{payout}/verify
GET /admin/payouts/export?from=2026-10-01&to=2026-10-15
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Each action locks the payout, so two admins acting at once cannot approve, reject or retry the same payout twice. The driver is notified of approval, rejection (with the reason) and the final transfer result.

| Action | Allowed from | Result |
|--------|--------------|--------|
| `approve` | `requested` | `approved`; transfer queued — `"Payout approved and transfer initiated."` |
| `reject` | `requested` | `rejected` — body: `reason` (string, min 10 chars, required) |
| `retry` | `failed`, `reversed` | `approved`; transfer queued again — `"Payout retry initiated."` The job first checks the previous transfer with Paystack: if it actually went through, the payout is marked paid instead of sending again; otherwise a new transfer reference is used (`PAYOUT-{id}-2`, `-3`, …). |
| `verify` | `approved`, `processing` | **Check status with Paystack** — see below. |

**Response 422:** `"Payout is not in requested status."` (approve/reject), `"Only failed or reversed payouts can be retried."` (retry), or `"Only approved or processing payouts can be checked with the payment provider."` with `current_status` (verify).

**Response 409 (approve, retry):** the payout's bank account is on hold, under review or rejected (see **Payout Accounts**). Resolve it there first:
```json
{
  "message": "Payouts to this bank account are blocked: it was added recently and is on hold until 2026-10-17T13:05:00+00:00. Release the hold under payout accounts if the driver confirmed the change.",
  "code": "payout_account_on_hold",
  "bank_account_id": "uuid",
  "payouts_held_until": "2026-10-17T13:05:00+00:00"
}
```
Each payout in the list has `bank_account_block` (`null`, or `code`, `message`, `payouts_held_until`): use it to disable **Approve**/**Retry** and link to the account. The weekly auto-payout skips drivers whose account is blocked and pays them in a later week.

**Response 200 (list):** paginated, newest first, with `meta` (`current_page`, `last_page`, `per_page`, `total`). Approve, reject and retry return the same payout object as `payout`.
```json
{
  "payouts": [
    {
      "id": "5e4d3c2b-1a09-4f8e-9d7c-6b5a4f3e2d1c",
      "amount": 500000,
      "status": "paid",
      "status_label": "Paid",
      "requested_at": "2026-10-15T09:00:00+00:00",
      "approved_at": "2026-10-15T09:20:00+00:00",
      "paid_at": "2026-10-15T09:21:30+00:00",
      "bank_account": {
        "id": "01a14039-5be7-7169-8042-492e20edeb4f",
        "bank_code": "058",
        "bank_name": "Guaranty Trust Bank",
        "account_number": "******6789",
        "account_name": "BAYO AKINOLA",
        "is_verified": true,
        "is_primary": true,
        "payouts_held_until": null,
        "name_match_status": "matched",
        "review_status": null,
        "created_at": "2026-10-14T18:00:00+00:00"
      },
      "driver": { "id": "uuid", "user": { "first_name": "Bayo", "last_name": "Akinola", "...": "..." }, "...": "..." },
      "approved_by": { "id": "uuid", "first_name": "Finance", "last_name": "Admin", "...": "..." },
      "gateway_transfer_id": "476948",
      "needs_attention": false,
      "attention_reason": null,
      "provider_status": "success",
      "bank_account_block": null,
      "created_at": "2026-10-15T09:00:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

| Field | Notes |
|-------|-------|
| `failure_reason` | Only present when set (failed, rejected or reversed payouts) |
| `approved_at`, `paid_at` | Only present once the payout was approved / paid |
| `driver`, `approved_by` | Admin only; `approved_by` only when an admin approved it (absent for weekly auto-payouts) |
| `gateway_transfer_id` | Admin only: Paystack's transfer id (numeric, as a string), `null` until the transfer is created; use it to find the transfer in the Paystack dashboard |
| `needs_attention`, `attention_reason`, `provider_status` | Admin only, see below |
| `bank_account_block` | Admin only, see the 409 above |

Drivers (`GET /driver/payouts`) see the same object without the admin-only fields.

Admin payout objects include **`needs_attention: true`** when a payout has been `approved` for more than 10 minutes without being sent, or `processing` for more than 30 minutes without a result — show the **check status** action for those. They also carry:

- `attention_reason`: `awaiting_otp` (Paystack is holding the transfer for an OTP: turn off transfer OTP in the Paystack dashboard, then check status), `not_sent`, `unconfirmed`, or `null`. `awaiting_otp` is flagged immediately, not after the delay.
- `provider_status`: Paystack's last reported status for the transfer (`pending`, `otp`, `success`, …), or `null` before it was sent.

#### Check payout status with Paystack

```
POST /admin/payouts/{payout}/verify
```

Asks Paystack about the payout's transfer reference and settles the payout accordingly. The same check runs automatically every 15 minutes (`ReconcilePayoutsJob`) for payouts that need attention.

| `outcome` | Meaning | Payout afterwards |
|-----------|---------|-------------------|
| `paid` | Paystack completed the transfer | `paid` |
| `failed` | Paystack failed or reversed it; the money is returned to the driver | `reversed` — finance can retry |
| `processing` | Still in flight at Paystack | `processing`, wait for the webhook |
| `resent` | Paystack never created it; it was sent again under the same reference (cannot pay twice) | `processing` once Paystack accepts it |
| `unknown` | A processing payout Paystack has no record of — investigate | unchanged |

**Response 200:**
```json
{
  "message": "The transfer went through; the payout is now paid.",
  "outcome": "paid",
  "provider_status": "success",
  "payout": { "id": "5e4d3c2b-1a09-4f8e-9d7c-6b5a4f3e2d1c", "status": "paid", "needs_attention": false, "...": "..." }
}
```

**Response 502:** `"Could not reach the payment provider. Try again shortly."` — nothing is changed.

`export` streams a CSV (`from` and `to` dates are required) with payout ID, driver, bank code, masked account number, amount (kobo), status, requested and paid times.

### Payout Accounts

```
GET  /admin/payout-accounts?review_status=pending&name_match_status=mismatch&on_hold=1&search=obi&per_page=20
GET  /admin/payout-accounts/{bank_account_id}
POST /admin/payout-accounts/{bank_account_id}/approve
POST /admin/payout-accounts/{bank_account_id}/reject
POST /admin/payout-accounts/{bank_account_id}/release-hold
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance; Support can list and view only.

Drivers' payout bank accounts with their payout safeguards: accounts whose name did not match the driver's verified name wait here for review (listed first), and recently changed accounts are on hold. Every action is audited (`bank_account.review_approved`, `bank_account.review_rejected`, `bank_account.hold_released`) and approve/reject notify the driver (`payout_account_reviewed`).

**Filters:** `review_status` (`pending`, `approved`, `rejected`), `name_match_status` (`matched`, `mismatch`), `on_hold` (`1` held now, `0` not held), `search` (account name, driver name or phone).

**Response 200 (list):**
```json
{
  "payout_accounts": [
    {
      "id": "uuid",
      "bank_code": "058",
      "bank_name": "Guaranty Trust Bank",
      "account_number": "******4321",
      "account_name": "JOHN DOE",
      "is_verified": true,
      "is_primary": true,
      "payouts_held_until": "2026-10-17T13:05:00+00:00",
      "name_match_status": "mismatch",
      "review_status": "pending",
      "created_at": "2026-10-16T13:05:00+00:00",
      "verified_name": "Ada Chioma Obi",
      "review_note": null,
      "reviewed_at": null,
      "reviewed_by": null,
      "payout_block": { "code": "payout_account_under_review", "message": "…", "payouts_held_until": null },
      "driver": { "id": "uuid", "name": "Ada Obi", "phone": "+2348012345678", "kyc_status": "verified" }
    }
  ],
  "summary": { "pending_review": 1, "on_hold": 3 },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

Use `summary.pending_review` for a badge on the finance menu.

| Action | Body | Allowed when | Result |
|--------|------|--------------|--------|
| `approve` | `note` (optional, max 500) | `review_status` is `pending` or `rejected` | `approved`: payouts allowed (any hold still applies). Driver told *"Your bank account ending 4321 has been approved for payouts."* |
| `reject` | `reason` (required, 10–500) | any account not already rejected, including one whose name matched | `rejected`: payouts refused until the driver adds another account. Driver told the reason. |
| `release-hold` | `reason` (required, 10–500), e.g. how the driver confirmed the change | the account is on hold | Hold ends now. Use it after confirming the change with the driver. |

**Response 200:** `message` and `payout_account` (same shape as a list item).
**Response 422:** `"Only a bank account waiting for review or rejected can be approved."`, `"This bank account is already rejected."` (with `review_status`) or `"This bank account is not on hold."`

**Existing accounts:** accounts saved before the name check have `name_match_status: null`. `php artisan payout-accounts:check-names` reports how they compare; with `--apply` it records the result and, in `review` mode, sends mismatches to this queue (their payouts pause until reviewed).

### Refund to Wallet

```
POST /admin/rides/{ride}/refund
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Refunds part or all of a completed ride's fare to the passenger's E-tiGo wallet, whatever the original payment method. Several partial refunds are allowed up to what the passenger actually paid (tips are excluded). By default the driver's share of the refunded amount is recovered from their earnings, in the same proportion as the ride was settled (net of commission and fleet remittance).

**Request Body:**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `amount` | integer | Yes | In kobo, min 1 |
| `reason` | string | Yes | 10–2000 chars |
| `clawback_driver_earnings` | boolean | No | Default `true`. `false` = E-tiGo absorbs the whole refund |

**Response 200:**
```json
{
  "message": "Refund credited to passenger wallet.",
  "refund_kobo": 100000,
  "driver_clawback_kobo": 80000,
  "remaining_refundable_kobo": 400000
}
```

**Response 422:**
- `"Only completed rides can be refunded."`
- `"Refund exceeds remaining refundable amount of ₦4,000.00."` with `max_refundable_kobo`. The check is repeated under a lock, so concurrent refunds can never exceed what was paid.
- a ledger error, e.g. the driver's earnings account is frozen (retry with `clawback_driver_earnings: false`)

The passenger receives a `wallet_refund` notification once the refund is committed.

### Commission Settings

```
GET /admin/settings/commission
PUT /admin/settings/commission
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

### Wallet Settings

```
GET /admin/settings/wallet
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Returns current wallet configuration (merged from database overrides and config file defaults).

**Response 200:**
```json
{
  "settings": {
    "currency": "NGN",
    "min_topup": 50000,
    "max_balance": 50000000,
    "daily_topup_cap": 10000000,
    "min_payout": 100000,
    "settlement_delay": "instant",
    "max_negative_balance": -500000,
    "hold_expiry_hours": 4,
    "abandoned_topup_minutes": 30,
    "payout_account_hold_hours": 24,
    "payout_account_hold_first_account": false,
    "payout_account_name_check": "review"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `min_topup` | integer | Minimum top-up amount (kobo) |
| `max_balance` | integer | Maximum wallet balance (kobo) |
| `daily_topup_cap` | integer | Daily top-up limit per user (kobo) |
| `min_payout` | integer | Minimum payout amount (kobo) |
| `settlement_delay` | string | `instant`, `24h`, or `weekly` |
| `max_negative_balance` | integer | Max negative balance for driver cash-ride commission (kobo, ≤ 0) |
| `hold_expiry_hours` | integer | Hours before an unreleased hold expires (1–48) |
| `abandoned_topup_minutes` | integer | Minutes before a pending top-up is marked abandoned (5–1440) |
| `payout_account_hold_hours` | integer | Hours payouts wait after a driver replaces their bank account (0 = no hold) |
| `payout_account_hold_first_account` | boolean | Also hold a driver's first bank account |
| `payout_account_name_check` | string | What a bank account name mismatch does: `off` (record only), `review` (save, pause payouts until finance approves), `block` (refuse the account) |

```
PUT /admin/settings/wallet
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Updates wallet configuration. Only provided fields are updated; omitted fields retain their current values. Changes take effect immediately for top-up limits, payout minimums, settlement timing, cash-commission debt limits and hold/top-up expiry. Every change is recorded in the audit log with the old and new values.

**Request Body:** (all fields optional)

| Field | Type | Rules |
|-------|------|-------|
| `min_topup` | integer | Min: 1000. Cannot exceed `max_balance` or `daily_topup_cap` |
| `max_balance` | integer | Min: 100000 |
| `daily_topup_cap` | integer | Min: 100000 |
| `min_payout` | integer | Min: 10000 |
| `settlement_delay` | string | `instant`, `24h`, or `weekly` |
| `max_negative_balance` | integer | Max: 0 (must be ≤ 0) |
| `hold_expiry_hours` | integer | 1–48 |
| `abandoned_topup_minutes` | integer | 5–1440 |
| `payout_account_hold_hours` | integer | 0–168 (applies to accounts saved after the change) |
| `payout_account_hold_first_account` | boolean | |
| `payout_account_name_check` | string | `off`, `review` or `block` (applies to accounts saved after the change; use `payout-accounts:check-names` for existing ones) |

**Response 200:**
```json
{
  "message": "Wallet settings updated.",
  "settings": { /* same shape as GET response */ }
}
```

**Response 422:** Validation error, including `min_topup` errors when the combined settings would make top-ups impossible: `"The minimum top-up cannot exceed the maximum wallet balance."` / `"The minimum top-up cannot exceed the daily top-up cap."`

### Tip Settings

```
GET /admin/settings/tips
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Returns current tip configuration (preset amounts shown as quick-select buttons on the tipping screen, and min/max limits).

**Response 200:**
```json
{
  "settings": {
    "presets": [100, 200, 500, 1000],
    "min_amount": 50,
    "max_amount": 50000,
    "currency": "NGN"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `presets` | array | Suggested tip amounts (₦) displayed as buttons |
| `min_amount` | integer | Minimum allowed tip amount (₦) |
| `max_amount` | integer | Maximum allowed tip amount (₦) |
| `currency` | string | Currency code |

```
PUT /admin/settings/tips
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Updates tip configuration. Only provided fields are updated; omitted fields retain their current values. Presets are automatically sorted ascending, and every preset must lie within the (new or current) `min_amount`–`max_amount` range. Changes are audit-logged.

**Request Body:** (all fields optional)

| Field | Type | Rules |
|-------|------|-------|
| `presets` | array of integers | 1–10 values, each 50–50,000 |
| `min_amount` | integer | Min: 1 |
| `max_amount` | integer | Min: 100, must be greater than `min_amount` |

**Response 200:**
```json
{
  "message": "Tip settings updated.",
  "settings": { /* same shape as GET response */ }
}
```

**Response 422:** Validation error (empty presets, more than 10 presets), `"min_amount must be less than max_amount."`, or `"Every tip preset must be between 50 and 50000."` (using the effective limits).

### Daily Reconciliation Report

```
GET /admin/reports/reconciliation?date=2026-10-09
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Returns the daily reconciliation report generated by `DailyReconciliationJob` (02:00 daily, for the previous day; one report per date). It compares, in kobo:

| Pair | Gateway side | Ledger side |
|------|--------------|-------------|
| Charges | Successful wallet top-up charges confirmed by webhook (`TOPUP-` references; Paystack `charge.success` and Flutterwave `charge.completed`) | Top-up credits to passenger wallets |
| Transfers | Successful payout transfers confirmed by webhook (Paystack `transfer.success`, Flutterwave `transfer.completed`) | Payouts marked `paid` that day |

Any difference is listed in `mismatches` with `type`, `gateway_total`, `ledger_total` and `difference`, and the report `status` is `mismatched`. Ride card payments are not part of this report. A webhook received just before midnight and processed just after it can show as a one-day timing difference.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | string | No | Date in `YYYY-MM-DD` format (default: yesterday) |

**Response 200:**
```json
{
  "report": {
    "id": "uuid",
    "report_date": "2026-10-09",
    "gateway_charges_total": 500000,
    "ledger_credits_total": 500000,
    "gateway_transfers_total": 200000,
    "ledger_payouts_total": 200000,
    "mismatches_count": 0,
    "mismatches": null,
    "status": "clean",
    "generated_at": "2026-10-10T02:00:00Z"
  }
}
```

**Response 404:** No report generated for that date.

### Reconciliation Report History

```
GET /admin/reports/reconciliation/history?status=mismatched&per_page=15
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

Returns paginated list of reconciliation reports, newest first.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `status` | string | No | Filter by `clean` or `mismatched` |
| `per_page` | integer | No | Items per page (default 15) |

### Reconciliation — Wallet Liability

```
GET /admin/reports/reconciliation/wallet-liability
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

---

## Admin Reporting API

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,finance`

**Allowed Roles:** Operations, Finance (Super Admin always has access)

### Ride Volume Report

```
GET /admin/reports/ride-volume
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Operations, Finance

**Query Parameters:**

| Param      | Type   | Description                                            |
|------------|--------|--------------------------------------------------------|
| `from`     | date   | Start date (inclusive), e.g. `2026-10-01`              |
| `to`       | date   | End date (inclusive), e.g. `2026-10-31`                |
| `city_id`  | uuid   | Filter by city                                         |
| `group_by` | string | Trend grouping: `day`, `week`, or `month`              |

**Response 200:**
```json
{
  "report": {
    "type": "ride_volume",
    "period": { "from": "2026-10-01", "to": "2026-10-31" },
    "total_rides": 1250,
    "by_status": {
      "completed": 980,
      "cancelled": 150,
      "no_driver_found": 45,
      "requested": 30,
      "searching": 15,
      "in_progress": 30
    },
    "by_city": [
      { "city_id": "uuid", "city_name": "Lagos", "count": 800 },
      { "city_id": "uuid", "city_name": "Abuja", "count": 450 }
    ],
    "by_vehicle_class": [
      { "vehicle_class_id": "uuid", "vehicle_class": "Comfort", "count": 600 },
      { "vehicle_class_id": "uuid", "vehicle_class": "Lite", "count": 400 }
    ],
    "trend": [
      { "period": "2026-10-01", "count": 42 },
      { "period": "2026-10-02", "count": 55 },
      { "period": "2026-10-03", "count": 0 }
    ],
    "previous_period": {
      "from": "2026-08-31",
      "to": "2026-09-30",
      "total_rides": 1100,
      "change_pct": 13.6
    },
    "generated_at": "2026-10-10T14:30:00+01:00"
  }
}
```

`trend` is only included when `group_by` is specified. When `from` and `to` are provided, empty periods (days/weeks/months with no rides) are filled with `count: 0` entries.

`previous_period` is included when both `from` and `to` are provided. It covers the same-length period immediately preceding the requested range.

---

### Completion Rate Report

```
GET /admin/reports/completion-rate
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Operations, Finance

**Query Parameters:**

| Param     | Type | Description                               |
|-----------|------|-------------------------------------------|
| `from`    | date | Start date (inclusive)                     |
| `to`      | date | End date (inclusive)                       |
| `city_id` | uuid | Filter by city                            |

**Response 200:**
```json
{
  "report": {
    "type": "completion_rate",
    "period": { "from": "2026-10-01", "to": "2026-10-31" },
    "total_rides": 1250,
    "completed": 980,
    "cancelled": 150,
    "no_driver_found": 45,
    "completion_rate": 78.4,
    "cancellation_rate": 12.0,
    "cancellation_reasons": {
      "changed_mind": 50,
      "driver_too_far": 35,
      "wait_too_long": 25,
      "passenger_no_show": 20,
      "other": 20
    },
    "cancelled_by_role": {
      "passenger": 100,
      "driver": 50
    },
    "by_city": [
      { "city_id": "uuid", "city_name": "Lagos", "total": 800, "completed": 640, "cancelled": 96, "completion_rate": 80.0 },
      { "city_id": "uuid", "city_name": "Abuja", "total": 450, "completed": 340, "cancelled": 54, "completion_rate": 75.6 }
    ],
    "previous_period": {
      "from": "2026-08-31",
      "to": "2026-09-30",
      "total_rides": 1100,
      "completed": 880,
      "completion_rate": 80.0,
      "change_pct": -2.0
    },
    "generated_at": "2026-10-10T14:30:00+01:00"
  }
}
```

`previous_period` is included when both `from` and `to` are provided. It covers the same-length period immediately preceding the requested range.

---

### Revenue Report

```
GET /admin/reports/revenue
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Operations, Finance

**Query Parameters:**

| Param      | Type   | Description                               |
|------------|--------|-------------------------------------------|
| `from`     | date   | Start date (inclusive)                     |
| `to`       | date   | End date (inclusive)                       |
| `city_id`  | uuid   | Filter by city                            |
| `group_by` | string | Trend grouping: `day`, `week`, or `month` |

**Response 200:**
```json
{
  "report": {
    "type": "revenue",
    "period": { "from": "2026-10-01", "to": "2026-10-31" },
    "currency": "NGN",
    "total_revenue": 4900000.00,
    "total_tips": 125000.00,
    "ride_count": 980,
    "average_fare": 5000.00,
    "by_payment_method": [
      { "method": "cash", "count": 400, "total": 2000000.00 },
      { "method": "card", "count": 350, "total": 1750000.00 },
      { "method": "wallet", "count": 230, "total": 1150000.00 }
    ],
    "by_city": [
      { "city_id": "uuid", "city_name": "Lagos", "count": 600, "total": 3000000.00 },
      { "city_id": "uuid", "city_name": "Abuja", "count": 380, "total": 1900000.00 }
    ],
    "trend": [
      { "period": "2026-10-01", "count": 35, "total": 175000.00 },
      { "period": "2026-10-02", "count": 0, "total": 0 },
      { "period": "2026-10-03", "count": 42, "total": 210000.00 }
    ],
    "previous_period": {
      "from": "2026-08-31",
      "to": "2026-09-30",
      "total_revenue": 4500000.00,
      "ride_count": 920,
      "average_fare": 4891.30,
      "revenue_change_pct": 8.9,
      "count_change_pct": 6.5
    },
    "generated_at": "2026-10-10T14:30:00+01:00"
  }
}
```

`trend` is only included when `group_by` is specified. When `from` and `to` are provided, empty periods (days/weeks/months with no rides) are filled with `count: 0` and `total: 0` entries.

`previous_period` is included when both `from` and `to` are provided. It covers the same-length period immediately preceding the requested range.

---

### Driver Utilisation Report

```
GET /admin/reports/driver-utilisation
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Operations, Finance

**Query Parameters:**

| Param     | Type    | Description                              |
|-----------|---------|------------------------------------------|
| `from`    | date    | Start date (inclusive)                    |
| `to`      | date    | End date (inclusive)                      |
| `city_id` | uuid    | Filter by city                           |
| `limit`   | integer | Top drivers limit (1–100, default 20)    |

**Response 200:**
```json
{
  "report": {
    "type": "driver_utilisation",
    "period": { "from": "2026-10-01", "to": "2026-10-31" },
    "currency": "NGN",
    "total_active_drivers": 85,
    "total_approved_drivers": 120,
    "utilisation_rate": 70.8,
    "total_trips": 980,
    "average_trips_per_driver": 11.53,
    "total_earnings": 4900000.00,
    "average_earnings_per_driver": 57647.06,
    "average_trip_duration_minutes": 22.5,
    "top_drivers": [
      {
        "driver_id": "uuid",
        "driver_name": "John Doe",
        "trip_count": 45,
        "total_earnings": 225000.00,
        "average_fare": 5000.00
      }
    ],
    "by_city": [
      { "city_id": "uuid", "city_name": "Lagos", "trip_count": 600, "active_drivers": 50, "total_earnings": 3000000.00 },
      { "city_id": "uuid", "city_name": "Abuja", "trip_count": 380, "active_drivers": 35, "total_earnings": 1900000.00 }
    ],
    "previous_period": {
      "from": "2026-08-31",
      "to": "2026-09-30",
      "total_active_drivers": 78,
      "total_trips": 900,
      "drivers_change_pct": 9.0,
      "trips_change_pct": 8.9
    },
    "generated_at": "2026-10-10T14:30:00+01:00"
  }
}
```

**Field Notes:**
- `total_approved_drivers` (int) — Total approved drivers in the system (or filtered city).
- `utilisation_rate` (float) — Percentage of approved drivers who completed at least one trip in the period.

`previous_period` is included when both `from` and `to` are provided. It covers the same-length period immediately preceding the requested range.

---

### Report Export (CSV)

```
GET /admin/reports/export
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Operations, Finance

**Query Parameters (all required):**

| Param     | Type   | Description                                                            |
|-----------|--------|------------------------------------------------------------------------|
| `type`    | string | Report type: `ride_volume`, `completion_rate`, `revenue`, `driver_utilisation`, `all` |
| `from`    | date   | Start date (inclusive)                                                 |
| `to`      | date   | End date (inclusive)                                                   |
| `city_id` | uuid   | Optional city filter                                                   |

**Response 200:** Streamed CSV file download. Content-Type: `text/csv`.

CSV columns per report type:
- **ride_volume:** Ride ID, Status, City, Vehicle Class, Fare Estimate, Final Fare, Payment Method, Created At, Completed At
- **completion_rate:** Ride ID, Status, City, Cancellation Reason, Cancelled By, Created At
- **revenue:** Ride ID, City, Vehicle Class, Final Fare, Tip, Payment Method, Payment Status, Completed At
- **driver_utilisation:** Driver ID, Driver Name, Trip Count, Total Earnings, Average Fare, First Trip, Last Trip
- **all:** Ride ID, Status, City, Vehicle Class, Fare Estimate, Final Fare, Payment Method, Payment Status, Tip, Cancellation Reason, Cancelled By, Driver ID, Driver Name, Created At, Completed At

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

State conflicts (409) — a ride or SOS incident changed state between reading and acting on it (e.g. the passenger cancels as the trip starts):
```json
{
  "message": "Invalid ride state transition from 'in_progress' to 'cancelled'.",
  "error_code": "INVALID_STATE_TRANSITION",
  "current_status": "in_progress"
}
```

| Code | Error Code                 | Description |
|------|----------------------------|-------------|
| 401  | `UNAUTHENTICATED`          | Missing, invalid or expired token |
| 403  | `HTTP_ERROR`               | Forbidden / wrong user type or admin role |
| 404  | `NOT_FOUND`                | `"Resource not found."` for an unknown id; `"The requested endpoint does not exist."` for an unknown URL |
| 409  | `INVALID_STATE_TRANSITION` | Ride/SOS state changed concurrently; refresh and retry (`current_status` included) |
| 409  | `HTTP_ERROR` / none        | Other conflicts (e.g. duplicate active ride, driver already on a ride) |
| 422  | `VALIDATION_ERROR`         | Validation error (`errors` object) |
| 422  | `INVALID_PARAMETER`        | A malformed identifier or date reached the database |
| 429  | `HTTP_ERROR`               | Too many requests — wait for `Retry-After` seconds |
| 500  | `SERVER_ERROR`             | Server error (no stack traces in production) |

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
| `operations`      | Drivers, vehicles, trips, fleet management |
| `support`         | Passenger/driver complaints, refunds, fare adjustments |
| `finance`         | Payments, wallets, payouts, financial reports |
| `safety_operator` | SOS incidents, safety investigations, suspensions |

### Admin Role Permissions Matrix

Super Admins always bypass role checks and have full access.

| Endpoint Group | Operations | Support | Finance | Safety |
|----------------|:----------:|:-------:|:-------:|:------:|
| `/admin/drivers` | Yes | — | — | Yes |
| `/admin/cities` | Yes | — | — | — |
| `/admin/vehicle-classes` | Yes | — | — | — |
| `/admin/pricing` | Yes | — | Yes | — |
| `/admin/surge-rules` | Yes | — | — | — |
| `/admin/rides` (assign) | Yes | — | — | — |
| `/admin/rides` (refund) | — | — | Yes | — |
| `/admin/fleet-vehicles` | Yes | — | Yes | — |
| `/admin/fleet-agreements` | Yes | — | Yes | — |
| `/admin/gamification` | Yes | — | — | — |
| `/admin/sos` | — | — | — | Yes |
| `/admin/offline-flags` | Yes | — | — | Yes |
| `/admin/ev-stations` | Yes | — | — | — |
| `/admin/passengers` | — | Yes | — | Yes |
| `/admin/disputes` | — | Yes | — | — |
| `/admin/wallets` | — | Yes | Yes | — |
| `/admin/wallets` (freeze) | — | — | Yes | — |
| `/admin/driver-ledgers` | — | Yes | Yes | — |
| `/admin/ledger` | — | — | Yes | — |
| `/admin/adjustments` | — | — | Yes | — |
| `/admin/payouts` | — | — | Yes | — |
| `/admin/settings` | — | — | Yes | — |
| `/admin/reports` (financial) | — | — | Yes | — |
| `/admin/reports` (operational) | Yes | — | Yes | — |
| `/admin/admins` | — | — | — | — |

### Driver Status
| Value            | Description                              |
|------------------|------------------------------------------|
| `onboarding`     | New driver, completing onboarding steps  |
| `pending_review` | Awaiting admin KYC review                |
| `approved`       | KYC approved, can go online              |
| `rejected`       | KYC rejected (reason provided)           |
| `suspended`      | Account suspended by admin               |
| `deactivated`    | Permanently deactivated (L3 offline flag) |

### Vehicle Ownership Type
| Value           | Description                                    |
|-----------------|------------------------------------------------|
| `own_vehicle`   | Driver owns and registers their own vehicle    |
| `fleet_vehicle` | Driver will be assigned a fleet vehicle by admin |

### Fuel Type (Vehicle)
| Value | Description | Can use EV charging stalls |
|-------|-------------|----------------------------|
| `petrol` | Petrol | No |
| `diesel` | Diesel | No |
| `cng` | Compressed natural gas | No |
| `hybrid` | Hybrid (self-charging) | No |
| `plug_in_hybrid` | Plug-in hybrid | Yes |
| `electric` | Battery electric | Yes |

`null` means the fuel type has not been declared yet. Vehicle responses also include `can_charge`.

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

### SOS Incident Status
| Value               | Description                                |
|---------------------|--------------------------------------------|
| `triggered`         | SOS triggered, awaiting check-in dispatch  |
| `check_in_sent`     | Check-in prompt sent to user               |
| `acknowledged`      | User confirmed they are safe (an operator can still take over or close it) |
| `escalated`         | No response — escalated to safety console  |
| `operator_assigned` | Safety operator took over the incident     |
| `dispatched`        | Emergency services dispatch initiated      |
| `resolved`          | Incident closed by operator (terminal)     |
| `cancelled`         | Incident cancelled by the user or an admin before an operator took over (terminal) |

### SOS Trigger Type
| Value       | Description                        |
|-------------|------------------------------------|
| `passenger` | Triggered by the passenger         |
| `driver`    | Triggered by the driver            |

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
- `no_driver_found` -> `searching` (passenger retry or admin assignment), `cancelled` (passenger dismisses it)
- `completed`, `cancelled` -> (terminal states)

A transition that is no longer allowed — usually because another request changed the ride first (e.g. the passenger cancelled while the driver was accepting) — returns `409` with `error_code: "INVALID_STATE_TRANSITION"` and the ride's `current_status`.

### Payment Method
| Value  | Description |
|--------|-------------|
| `cash` | Cash paid to the driver at the end of the trip |
| `card` | Saved card charged via the payment gateway |
| `wallet` | E-tiGo wallet: fare held at booking, settled at completion |
| `bank_transfer` | Payment link / transfer via the payment gateway |

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
| `paid`               | Reserved (not set by current flows; wallet rides settle as `captured`, or `failed` with a shortfall) |

### Payout Status
| Value | Description |
|-------|-------------|
| `requested` | Driver requested it; waiting for finance |
| `approved` | Approved; the transfer is being sent (money reserved) |
| `processing` | Sent to Paystack; waiting for the result |
| `paid` | Paystack completed the transfer (terminal) |
| `failed` | Not sent or refused; money returned to the driver — finance can retry |
| `reversed` | Paystack failed or the bank sent it back; money returned — finance can retry |
| `rejected` | Finance rejected the request (terminal) |

### Discount Type
| Value        | Description                                |
|--------------|---------------------------------------------|
| `percentage` | Percentage of fare (capped by max_discount_cap) |
| `flat`       | Fixed amount subtracted from fare            |

### Dispute Category
| Value                | Description                              |
|----------------------|------------------------------------------|
| `fare_dispute`       | Dispute about fare amount                |
| `driver_behaviour`   | Issue with driver conduct                |
| `route_deviation`    | Driver took an unexpected route          |
| `vehicle_condition`  | Vehicle was in poor condition            |
| `safety_concern`     | Safety-related concern                   |
| `payment_issue`      | Problem with payment processing          |
| `item_left_behind`   | Item left in the vehicle                 |
| `other`              | Other issue                              |

### Dispute Status
| Value          | Description                              |
|----------------|------------------------------------------|
| `open`         | Newly filed, awaiting review             |
| `under_review` | Being reviewed by admin                  |
| `resolved`     | Resolved by admin                        |
| `dismissed`    | Dismissed by admin                       |

### Fleet Agreement Status
| Value        | Description                                            |
|--------------|--------------------------------------------------------|
| `active`     | Agreement is in force; daily remittance is tracked     |
| `paused`     | Temporarily suspended (e.g., vehicle maintenance)      |
| `completed`  | Total vehicle cost fully remitted; ownership transfers |
| `terminated` | Agreement ended early by admin                         |

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

### Tier Level
| Value | Name       | Points Required | Booking Discount | Priority Matching | EV Fee Waived |
|-------|------------|-----------------|------------------|-------------------|---------------|
| `1`   | Bronze     | 0               | 0%               | No                | No            |
| `2`   | Silver     | 500             | 2%               | No                | No            |
| `3`   | Gold       | 2,000           | 5%               | Yes               | No            |
| `4`   | Platinum   | 5,000           | 10%              | Yes               | Yes           |
| `5`   | Diamond    | 15,000          | 15%              | Yes               | Yes           |

Priority matching keeps distance first: priority passengers are always offered their nearest driver, and get precedence when drivers are scarce plus an extra retry wave (see **Matching Engine — How It Works**).

### Multiplier Condition Type
| Value            | Description                   | Default Multiplier | Stackable |
|------------------|-------------------------------|--------------------|-----------|
| `ev_ride`        | Ride in an electric vehicle   | 2.00x              | Yes       |
| `shared_journey` | Shared/pooled ride (not awarded until pooled rides launch, Phase 2) | 1.50x | Yes |
| `off_peak`       | Ride during off-peak hours    | 1.25x              | Yes       |

### Sanction Tier (Offline Trip Detection)
| Value | Label                  | Action                                      |
|-------|------------------------|---------------------------------------------|
| `1`   | Warning                | Push notification warning                   |
| `2`   | 48-Hour Suspension     | Force offline + suspend driver account      |
| `3`   | Permanent Deactivation | Force offline + permanently suspend account |

### Dispute Outcome (Offline Trip Flag)
| Value        | Description                                           |
|--------------|-------------------------------------------------------|
| `pending`    | Dispute filed, awaiting admin review                  |
| `upheld`     | Admin confirmed the flag — sanction remains           |
| `overturned` | Admin overturned the flag — sanction reversed         |

### EV Station Status
| Value         | Description                          |
|---------------|--------------------------------------|
| `active`      | Station is operational               |
| `inactive`    | Station is temporarily closed        |
| `maintenance` | Station is under maintenance         |

### EV Stall Status
| Value            | Description                       |
|------------------|-----------------------------------|
| `available`      | Stall is free                     |
| `occupied`       | Vehicle currently charging        |
| `reserved`       | Reserved for incoming driver      |
| `out_of_service` | Stall is disabled                 |

### EV Reservation Status
| Value       | Description                             |
|-------------|-----------------------------------------|
| `pending_review` | General charge request awaiting review by operations |
| `reserved`  | Stall locked, awaiting driver arrival (expires after 30 min by default) |
| `queued`    | Waiting for stall to become available   |
| `active`    | Charging session in progress            |
| `completed` | Charging session finished (terminal)    |
| `expired`   | Reservation timed out (terminal)        |
| `cancelled` | Cancelled by driver (terminal)          |

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
| EV stall ready     | Driver     | `ev_reservation_ready` | Queued reservation promoted — stall assigned |
| EV reservation cancelled | Driver | `ev_reservation_cancelled` | A stall freed up but the reservation fee could not be taken, so the queued reservation was cancelled |
| Fuel type needed | Driver | `vehicle_fuel_type_required` | The driver's vehicle has no fuel type; open the vehicle screen so they can set it (sent by `php artisan vehicles:request-fuel-types`) |

**Wallet and payout notifications** (stored in-app — see `GET /notifications` — and pushed):

| Event | Recipient | Notification Type |
|-------|-----------|-------------------|
| Top-up credited / failed | Passenger | `topup_success` / `topup_failed` |
| Ride paid from wallet | Passenger | `ride_wallet_payment` |
| Refund credited to wallet | Passenger | `wallet_refund` |
| Cash change credited to wallet | Passenger | `cash_change_credit` |
| Payout approved / paid / failed | Driver | `payout_approved` / `payout_paid` / `payout_failed` |
| Payout request rejected (with reason) | Driver | `payout_rejected` |
| Payout bank account added or changed (data: `bank_account_id`, `replaced_another`); also sent by WhatsApp/SMS regardless of preferences. Says when payouts start or that they are paused for review | Driver | `payout_account_changed` |
| Finance approved or rejected a payout account after the name check (data: `bank_account_id`, `review_status`) | Driver | `payout_account_reviewed` |

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
| `private-ride.{rideId}` | Passenger or assigned driver | Ride participants | `ride.status.updated`, `driver.location.updated`, `payment.updated`, `ride.no-driver-found` |
| `private-driver.{userId}` | That driver | Driver app | `ride.request.dispatched` (ride offers) |
| `private-user.{userId}` | Authenticated user (self) | User apps | `sos.check_in.requested`, `tier.upgraded` |
| `private-admin.rides` | Admin users | Admin dashboard | `ride.status.updated`, `driver.location.updated` |
| `private-admin.sos` | Safety operators, super admins | SOS console | `sos.incident.triggered`, `sos.incident.escalated` |
| `private-passengers.nearby` / `private-drivers.nearby` | Passengers / drivers | Home-screen maps | `nearby.map.changed` |

Every event also has a non-WebSocket path (push notification, polling endpoint or the WhatsApp SOS alert), so a Reverb outage degrades the experience without breaking rides or safety.

### Events

#### `driver.location.updated`

Dispatched on every driver location update (max 1/second). Includes live GPS coordinates and ETA when an active ride exists.

```json
{
  "driver_id": "3b2a1f0e-9d8c-4b7a-8f6e-5d4c3b2a1f0e",
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

#### `tier.upgraded`

Dispatched when a user's gamification tier is promoted. Broadcast to `private-user.{userId}` channel.

```json
{
  "user_id": "uuid",
  "previous_tier": 1,
  "new_tier": 2,
  "tier_name": "Silver"
}
```

### Driver Location Stream (Telemetry Gateway)

High-frequency GPS from the driver app can go over a dedicated WebSocket instead of `POST /driver/location` (both write to the same location index and trigger the same `driver.location.updated` broadcasts).

```
wss://{host}:{TELEMETRY_SERVER_PORT}/?token={driver_token}     (default port 8081)
```

The token can also be sent as an `Authorization: Bearer {driver_token}` header during the handshake, or in an `auth` frame after connecting. Only **online, approved drivers** may stream, and the same session lifetime as the HTTP API applies (an expired app token is refused).

| Client frame | Server reply |
|--------------|--------------|
| `{"type": "auth", "token": "..."}` | `{"type": "authenticated", "driver_id": "...", "message": "Authenticated successfully."}` |
| `{"type": "location", "lat": 6.5244, "lng": 3.3792, "heading": 90, "speed": 32.5, "ride_id": "optional"}` | `{"type": "location_ack", "timestamp": 1760540400, "eta": { "distance_km": 3.2, "duration_minutes": 8.0 }}` (`eta` is null without an active ride) |
| `{"type": "ping"}` | `{"type": "pong", "timestamp": 1760540400}` |

Errors arrive as `{"type": "error", "code": <status>, "message": "..."}`: `401` invalid/expired token or a `location` frame before authenticating (the connection is closed for a bad token), `403` driver not online/approved, `422` invalid coordinates, `400` malformed JSON or unknown frame type. Location frames sent less than `TELEMETRY_RATE_LIMIT_MS` (500 ms) apart are dropped silently. `ride_id` is optional — the gateway finds the driver's active ride itself.
