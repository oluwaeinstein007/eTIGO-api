# E-tiGo API Reference

**Base URL:** `{APP_URL}/api/v1`  
**Auth:** Bearer token via Laravel Sanctum (`Authorization: Bearer {token}`)  
**Content-Type:** `application/json` (except file uploads: `multipart/form-data`)  
**Rate Limiting:** Auth endpoints (`/auth/*`, `/admin/auth/*`) are limited to 5 requests/minute per IP — exceeding returns `429 Too Many Requests` with a `Retry-After` header (seconds)  
**Resource IDs:** All resource IDs (users, drivers, cities, vehicle classes, rides, documents, etc.) are UUIDs (e.g. `"9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b"`). Parameters referencing an ID field accept `string (UUID)` format; a malformed id in a filter returns `422`.  
**Pagination:** List endpoints accept `per_page`; it is clamped to the endpoint's range (minimum 1), so zero, negative or oversized values never disable pagination.  
**Sessions:** Admin dashboard tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes (default 480 — 8 hours). Passenger and driver app tokens expire after `SANCTUM_APP_TOKEN_EXPIRATION` minutes (default 43,200 — 30 days). Expired tokens return `401 UNAUTHENTICATED`; sign in again.
**What changed for the apps:** `FRONTEND_UPDATE_PAYMENTS_EV_SURGE.md` summarises the October 2026 changes (payouts, tips, EV, fuel type, surge) per app, with the breaking ones first. `FRONTEND_UPDATE_SCHEDULED_RIDES.md` covers scheduled rides (new `scheduled` ride status, booking, driver reservations). `FRONTEND_UPDATE_LOST_AND_FOUND.md` covers Lost & Found (report, driver answer, return, support queue). `FRONTEND_UPDATE_ADVANCED_DISPUTES.md` covers advanced disputes (evidence, appeals, tiers, refund approvals). `FRONTEND_UPDATE_DRIVER_SCHEDULING_MAINTENANCE.md` covers driver shifts, availability plans and vehicle maintenance.  

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

**Cities:** Lagos (live, 40km), Abuja (live, 30km), Port Harcourt (live, 20km), Ibadan (onboarding, 20km)

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

This static OTP is **disabled in production** — production always generates random 6-digit codes and sends them through Sendchamp: WhatsApp (approved OTP template) first, SMS fallback. The SMS reads *"Your E-tiGo verification code is 482910. It expires in 5 minutes. Do not share it with anyone."* If no channel accepts the code, `POST /auth/otp/send` returns `429` with *"Failed to send verification code. Please try again."* and the code is cancelled.

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

**Response 429:** `"Please wait before requesting another code."` (60-second cooldown), or `"Failed to send verification code. Please try again."` when neither WhatsApp nor SMS accepted the code (production only; the code is cancelled, so request a new one).

The code goes by WhatsApp (approved OTP template) first, then by SMS, through Sendchamp. The SMS reads *"Your E-tiGo verification code is 482910. It expires in 5 minutes. Do not share it with anyone."*

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
| city_id    | string (UUID) | Conditional | Required for drivers. For drivers, an onboarding or live city (`GET /cities?include_onboarding=1`); a paused city returns 422 `The selected city is not open for driver sign-ups.` For passengers, any existing city |

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
    "database": { "status": "ok" },
    "redis": { "status": "ok" }
  },
  "timestamp": "2026-10-16T00:38:48+00:00"
}
```

**Response 503 (degraded):**
```json
{
  "status": "degraded",
  "checks": {
    "database": { "status": "ok" },
    "redis": { "status": "error", "message": "Redis connection failed" }
  },
  "timestamp": "2026-10-16T00:38:48+00:00"
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
  "support_email": null,
  "scheduled_rides": {
    "enabled": true,
    "min_lead_minutes": 30,
    "max_advance_days": 3,
    "dispatch_lead_minutes": 15,
    "reservation_fee": 0,
    "late_cancellation_fee_basis": "economy_base_fare",
    "late_cancellation_fee": 0,
    "late_cancellation_window_minutes": 30,
    "driver_reservations_enabled": true
  },
  "third_party_bookings": {
    "enabled": true,
    "payment_methods": ["wallet", "card"],
    "pin_recipient": "both",
    "tracking_recipient": "both",
    "share_with_options": [
      {
        "value": "rider",
        "label": "Rider only",
        "description": "The rider gets the PIN and a tracking link by WhatsApp or SMS. You can still follow the trip in the app."
      },
      {
        "value": "both",
        "label": "Both of us",
        "description": "You and the rider both get the PIN and live tracking."
      }
    ],
    "default_share_with": "both",
    "max_active_per_booker": 2
  },
  "lost_and_found": {
    "enabled": true,
    "report_window_days": 30,
    "return_fee": 0,
    "categories": [
      { "value": "phone", "label": "Phone" },
      { "value": "wallet_or_purse", "label": "Wallet or purse" },
      { "value": "bag", "label": "Bag or luggage" },
      { "value": "keys", "label": "Keys" },
      { "value": "id_or_documents", "label": "ID card or documents" },
      { "value": "electronics", "label": "Laptop, tablet or other electronics" },
      { "value": "clothing", "label": "Clothing" },
      { "value": "glasses", "label": "Glasses" },
      { "value": "jewellery", "label": "Jewellery or watch" },
      { "value": "other", "label": "Something else" }
    ]
  }
}
```

**Usage:** Mobile clients should check `min_app_version` on every launch. If the installed version is below the minimum, show a force-update screen with a link to the App Store / Play Store. When `force_update` is `true`, block usage entirely until updated.

`scheduled_rides` drives the "Schedule" date/time picker: offer pickup times from `min_lead_minutes` after now up to `max_advance_days` ahead, and hide the option when `enabled` is `false`. Matching starts `dispatch_lead_minutes` before pickup, which is also when a booking stops being editable. `reservation_fee` is added to every scheduled quote (₦0: the client decided not to charge for reservations). A passenger who cancels a booking a driver has reserved within `late_cancellation_window_minutes` (30) of pickup pays a late-cancellation fee: with `late_cancellation_fee_basis` `economy_base_fare` (the default) it is the economy base fare of the ride's city; with `flat` it is `late_cancellation_fee`; `off` means no fee. The ride shows the exact amount as `scheduled.cancellation_fee_if_cancelled_now`. See [Scheduled Rides](#scheduled-rides).

`third_party_bookings` drives "Book for someone else": hide it when `enabled` is `false`, and offer only `payment_methods` for such a booking. For "Who gets the PIN?", show `share_with_options` with each option's `label` and `description` as given, pre-select `default_share_with`, and send the chosen `value` as `share_with` on `POST /rides`. The descriptions are worded from the current tracking setting, so they never promise the booker a live map they won't get. When `share_with_options` is empty (operations send PINs to bookers only), hide the question. `pin_recipient` and `tracking_recipient` are the admin settings: the PIN default when no choice is sent, and who gets live tracking. Sending `default_share_with` always gives the same result as sending nothing. The ride saves what applied at booking. See [Third-Party Bookings](#third-party-bookings).

`lost_and_found` drives "Report a lost item" on a completed trip: show it while `enabled` is `true` and the trip ended less than `report_window_days` ago, and offer `categories`. When `return_fee` is above 0, tell the passenger it is charged when the item comes back. See [Lost & Found](#lost--found).

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

`ride` is the passenger's **own** ride. Rides they booked for someone else that are in progress are listed in `third_party_rides` (ride detail objects, newest first; `[]` when none). See [Third-Party Bookings](#third-party-bookings).

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
  },
  "third_party_rides": []
}
```

**Response 200 (no active ride):**
```json
{
  "ride": null,
  "third_party_rides": []
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
| city_id        | string (UUID) | No       | An onboarding or live city (the driver's current city is accepted whatever its status) |

The city can only be changed while the driver is in `onboarding` or `rejected`. After the application is submitted it returns 422 `Your city can only be changed before you submit your application. Contact support to move city.`; Operations moves drivers with [`PUT /admin/drivers/{driver_id}/city`](#move-driver-to-another-city).

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
| type     | string | Yes      | `driving_licence`, `vehicle_registration`, `insurance_certificate`, `roadworthiness_certificate`, `vehicle_licence`, `government_id` |
| document | file   | Yes      | JPG, PNG, or PDF. Max 10MB.                                                        |
| expires_at | date | For `roadworthiness_certificate` and `vehicle_licence` | Expiry on the paper (after today). Optional for the other types |

Re-uploading a document of the same type replaces the existing one, with one exception: **vehicle papers** (`vehicle_registration`, `insurance_certificate`, `roadworthiness_certificate`, `vehicle_licence`) belong to the driver's car (Phase 2), and an **approved** vehicle paper stays valid until its renewal is approved, so renewing never leaves the car without papers; only a pending upload of the same type is replaced. Papers uploaded before the car is registered are linked to it when it is. Drivers of a fleet car with no car assigned get 422 (operations manage fleet papers). Insurance, roadworthiness and the vehicle licence decide whether the car may be driven: see [Vehicle Maintenance](#vehicle-maintenance-phase-2).

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
Toggle driver online/offline status. No body is required; plug-in cars (`electric`, `plug_in_hybrid`) may send `battery_level_percent` (0–100) when going online, shown on [Vehicle Status](#vehicle-status-driver).

**Going online requires:** status `approved` (not `suspended`), a registered vehicle with an assigned vehicle class, completed KYC (`kyc_status` = `verified`), an available earnings balance no lower than the negative-balance threshold (`max_negative_balance`, default −₦5,000 — see Wallet Settings), a car whose papers are not expired past the grace period ([Vehicle Maintenance](#vehicle-maintenance-phase-2)), and, for a shared fleet car, being inside the driver's shift window ([Driver Scheduling](#driver-scheduling-phase-2)). A shared car passes to the shift driver here when the previous driver has handed it over.

Vehicle objects now also carry `shift_mode`, `odometer_km`, `estimated_odometer_km`, `battery_level_percent` (plug-in cars), `is_blocked` and `block_reason`, for drivers and admins only (a passenger seeing their driver's car does not get them).

**Phase 2 response fields (going online):** `shift` (the shift being worked on a shared car, else `null`) and `vehicle_notice` (papers expiring soon or in their grace period, e.g. `"Insurance expired on 13 Oct 2026; upload the renewed paper by 20 Oct 2026 to keep driving."`, else `null`).

**Going offline** is refused with `409` while the driver has an active ride (`matched` → `in_progress`), so passenger tracking and location updates continue until the trip ends.

Drivers whose app stops sending locations for 2 minutes are no longer offered rides or shown on maps, even if they forgot to go offline.

**When the app stops sending locations:** an online driver without a location gets no ride requests, so the server steps in twice:

| After (no location) | What happens | Setting |
|---|---|---|
| 5 minutes | One `driver_location_missing` push per silent spell ("we can't see your location"); `on_trip: true` when the driver is on a trip. The driver stays online | `MATCHING_SILENT_DRIVER_WARNING_MINUTES` |
| 30 minutes | The driver is set offline (audited as `driver_went_offline`, reason `no_location`) and gets a `driver_set_offline` push. **Never done to a driver on a trip**, who is only warned | `MATCHING_SILENT_DRIVER_OFFLINE_MINUTES` |

The silence counts from the last location, or from going online if none has come since. **App requirements:** keep sending locations while online even when the app is in the background (an Android foreground service with its persistent notification; iOS background location updates with "Always" permission). Whenever the app opens or returns to the foreground with `is_online: true`, restart location updates. On a `driver_location_missing` push, restart them and show a banner until a location is accepted; on `driver_set_offline`, show the driver as offline.

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
    "Outstanding negative balance exceeds the allowed threshold.",
    "E-tiGo has not launched in Ibadan yet. You can go online from launch day."
  ]
}
```

Phase 2 reasons: `"Insurance expired on 13 Oct 2026. Upload the renewed paper; you can go online once E-tiGo approves it."` (car off the road), and for shared fleet cars the shift reasons listed under [My Shifts](#my-shifts-driver).

**Multi-city:** a driver whose city is still `onboarding` cannot go online (last reason above). In a `paused` city the driver can go online for bookings made before the pause; the response then has `city_notice`: `"Rides in Lagos are paused. You will only get rides passengers booked before the pause."`, or, when operations cancelled the bookings, `"Rides in Lagos are paused and upcoming bookings were cancelled. You will not get new trips until rides resume."` (`null` outside a paused city).

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
  "accuracy": 12.5,
  "timestamp": "2026-10-06T14:15:00Z"
}
```

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `lat` | float | Yes | Between -90 and 90 |
| `lng` | float | Yes | Between -180 and 180 |
| `heading` | float | No | Between 0 and 360 (degrees from north) |
| `speed` | float | No | >= 0 (km/h) |
| `accuracy` | float | No | 0–10000. Horizontal GPS accuracy in metres as the phone reports it (iOS `horizontalAccuracy`, Android `Location.getAccuracy()`). Shown to compliance reviewers on anti-offline flags. |
| `timestamp` | string | No | Valid date/time. Accepted but not used: the server's time is recorded |

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
| expires_at       | date   | No                    | When approving: the expiry date read from the paper (confirms or corrects what the driver entered) |

Approving a renewed vehicle paper replaces the car's earlier approved paper of that type and re-checks the car's maintenance items, which lifts an expired-papers block.

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

**Phase 2:** the driver's upcoming scheduled-ride reservations are released at once (reason `driver_suspended`; the passenger gets `scheduled_ride_reservation_released` and the booking goes back to the pool) and their fleet shifts are freed: a shift in progress ends now and upcoming shifts are cancelled (audit `driver_shift_withdrawn`), with one operations email listing the slots to reassign. The same happens whenever an approved driver stops being approved — an automatic sanction suspension or a compliance deactivation included.

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

### Move Driver to Another City
```
PUT /admin/drivers/{driver_id}/city
```
Moves a driver to another city (Phase 2 multi-city). Drivers choose their city during onboarding; after they apply, only Operations can move them. An online driver is taken offline (their location is in the old city's matching pool) and every driver gets a `driver_city_changed` notification. Audited as `driver_city_changed` with the reason.

**Roles:** `operations`, `safety_operator` (and `super_admin`).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| city_id | string (UUID) | Yes | Any city (onboarding, live or paused) |
| reason | string | Yes | Max 500 |

**Response 200:**
```json
{
  "message": "Driver moved to Abuja.",
  "driver": { "id": "uuid", "city_id": "uuid", "is_online": false, "...": "..." },
  "warnings": [
    "Abuja does not offer the driver's vehicle class (premium), so they will not get trips until it is offered or their class changes."
  ]
}
```
`warnings` also flags a city that has not launched yet (the driver cannot go online there), and says how many of the driver's upcoming availability plans for the old city were removed (`"The driver's 2 upcoming availability plan(s) in their old city were removed."`).

**Response 409:** `"The driver cannot change city during an active ride."`; `"The driver has 2 upcoming reserved bookings. Release them before changing city."` with `upcoming_reservations`; or `"The driver has 3 upcoming fleet shifts. Cancel or reassign them before changing city."` with `upcoming_shifts` (shared fleet cars stay in their city).
**Response 422:** the driver is already in that city; missing `reason`.

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
| vehicle_ownership_type | string | No | `own_vehicle` or `fleet_vehicle`. Sets it for a driver who never chose one. When omitted, the driver's own choice is used, or it is inferred from their vehicle (fleet vehicle → `fleet_vehicle`) |

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
- The car is not in shift mode, and the driver has no upcoming shifts on a shared fleet car (Phase 2: one car per driver on E-tiGo).

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

```json
{
  "message": "This driver has upcoming shifts on a shared fleet car. Cancel or reassign them before giving the driver a car of their own."
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
  "message": "Driver already has an active or paused fleet agreement."
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

Pauses an active agreement (e.g., during vehicle maintenance or driver leave). Only active agreements can be paused. While paused no daily target is created and the day never counts towards the shortfall streak. The driver still sees the agreement and gets a `fleet_agreement_paused` notification (`fleet_agreement_resumed` on resume). A new agreement for the same driver is refused while one is paused.

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

Returns the current day's remittance progress. `agreement_status` is `active`, `paused` or `null` (no agreement). While the agreement is paused no remittance is due, so `remittance` is `null`.

**Response 200 (active agreement):**
```json
{
  "agreement_status": "active",
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

**Response 200 (paused agreement):**
```json
{
  "message": "Your fleet agreement is paused. No remittance is due.",
  "agreement_status": "paused",
  "paused_at": "2026-10-22T09:00:00.000000Z",
  "remittance": null
}
```

**Response 200 (no active agreement):**
```json
{
  "message": "No active fleet agreement.",
  "agreement_status": null,
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

Returns the driver's current fleet agreement: the active one, or a paused one (`status: paused`, with `paused_at`; show "Agreement paused"). **Now includes `total_vehicle_cost` and `remaining_amount`** per hire-purchase disclosure requirements (Q7 reversal). The UI should emphasise percentage progress but drivers must have access to the full financial terms.

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
| admin_role | string | Yes      | `operations`, `safety_operator`, `support`, `support_lead`, or `finance` |

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
| admin_role | string | Yes      | `operations`, `safety_operator`, `support`, `support_lead`, or `finance` |

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
Returns the live cities. No authentication required.

| Query Param | Type | Description |
|-------------|------|-------------|
| include_onboarding | boolean | `1` for the driver sign-up picker: also returns cities in `onboarding` (recruiting drivers before launch). Never returns paused cities |

Each city has `launch_status` (`onboarding`, `live`, `paused`) and `launch_status_label`. `is_active` is `true` exactly when the city is live. See [Multi-City Launches](#multi-city-launches).

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
      "launch_status": "live",
      "launch_status_label": "Live",
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
Returns active vehicle classes available in a city. No authentication required. Filters by both pivot `is_active` and global `is_active`. Works for live and onboarding cities (drivers pick a preferred class before launch); a paused city returns 404.

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

No authentication required. Detects which live city a set of coordinates falls within. The city's drawn boundary decides, exactly as for estimates and bookings, so the city returned here is the one a booking from that point gets. Reverse geocoding only supplies `resolved_address` (and a name match for a city with no boundary drawn yet); a district name from the geocoder such as "Ikeja" never overrides the boundary.

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

**Response 404 (in a city that is not open):** inside a city that is `onboarding` or `paused`, the 404 names the city so the app can offer the [waitlist](#city-waitlist):
```json
{
  "message": "E-tiGo is not available in Ibadan yet. Join the waitlist and we will tell you when we launch.",
  "resolved_address": "Ring Road, Ibadan, Nigeria",
  "unavailable_city": {
    "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "name": "Ibadan",
    "launch_status": "onboarding",
    "waitlist_open": true
  }
}
```

---

## City Waitlist

**Auth:** passenger. Phase 2 multi-city (OQ-22): a passenger asks to be told when a city that is `onboarding` or `paused` goes live. They get one `city_launched` notification when it does; joining again later starts a new wait.

```
GET    /cities/{city_id}/waitlist
POST   /cities/{city_id}/waitlist     (10 per minute)
DELETE /cities/{city_id}/waitlist
```

`POST` returns 201 when the passenger joins, or rejoins after being told about an earlier launch (200 if they are already waiting) and 422 `E-tiGo is already live in Lagos. You can book now.` for a live city.

**Response (all three):**
```json
{
  "message": "You are on the waitlist. We will tell you when E-tiGo opens in Ibadan.",
  "city": { "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f", "name": "Ibadan", "launch_status": "onboarding" },
  "on_waitlist": true,
  "joined_at": "2026-10-18T10:00:00.000000Z",
  "notified_at": null
}
```
`message` is only on `POST` and `DELETE`. After the launch notification, `on_waitlist` is `false` and `notified_at` is set.

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

No authentication required. Returns all 36 Nigerian states plus the Federal Capital Territory (37 entries, alphabetical), each with a two-letter code and its geopolitical zone (`region`). The FCT is named `FCT`. Use `name` as the `state` when creating a city.

**Response 200:**
```json
{
  "states": [
    { "code": "AB", "name": "Abia", "region": "South-East" },
    { "code": "FC", "name": "FCT", "region": "North-Central" },
    { "code": "LA", "name": "Lagos", "region": "South-West" },
    { "code": "RI", "name": "Rivers", "region": "South-South" }
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

Single-city responses (show, create, update, toggle status, update vehicle classes) include a `warnings` array that flags vehicle classes with no pricing in effect. See [Pricing coverage warnings](#pricing-coverage-warnings).

#### Multi-City Launches

*Phase 2 (OQ-22, engineering recommendation of 16 Oct 2026, not yet client-confirmed).* A city has a `launch_status`:

| Status | Drivers | Passengers |
|--------|---------|------------|
| `onboarding` | Can sign up, do KYC and be approved; cannot go online | Cannot get estimates or book; can join the [waitlist](#city-waitlist) |
| `live` | Normal | Normal |
| `paused` | Can go online for bookings made before the pause; no new sign-ups | Cannot book; trips in progress finish; upcoming bookings go ahead unless the admin cancelled them |

New cities start in `onboarding`. Moves: onboarding → live (first launch, needs every required [readiness gate](#get-city-readiness)), live → paused, paused → live (resume, no checklist), paused → onboarding (cancels upcoming bookings). `is_active` stays and is always `launch_status == live`, for older clients.

Admin city responses add `launch_date` (the client's target date, a readiness gate), `launched_at` (first launch), `paused_at`, `pause_reason`, `pause_scheduled_rides` (`honour` or `cancel`) and `readiness_confirmations` (the manual gates); public responses only carry `launch_status`/`launch_status_label`.

**Boundaries cannot overlap.** Create and update return 422 on `boundary` (`This boundary overlaps Lagos. …`) when the boundary overlaps another city's (any status), so a pickup always maps to one city. An update only checks a boundary that changed, so a form that sends the whole city back still saves other edits. Cities may share a border: points within 10 m of the other city's edge count as on the edge. **Time zone and currency:** `timezone` must be `Africa/Lagos` and `currency_code` `NGN` (`config/cities.php`), because payout days, promo peaks and fleet business days still run on Lagos time. An update also accepts the city's current value unchanged.

### List Cities
```
GET /admin/cities
```
| Query Param | Type    | Description                       |
|-------------|---------|-----------------------------------|
| is_active   | boolean | Filter by active/inactive status (active = live) |
| launch_status | string | Comma list of `onboarding`, `live`, `paused` |
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
      "launch_status": "live",
      "launch_status_label": "Live",
      "launch_date": null,
      "launched_at": "2026-10-01T10:00:00.000000Z",
      "paused_at": null,
      "pause_reason": null,
      "pause_scheduled_rides": null,
      "readiness_confirmations": null,
      "waitlist_count": 0,
      "vehicle_classes_count": 3,
      "drivers_count": 12,
      "unpriced_vehicle_classes_count": 0,
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
| timezone          | string | No       | `Africa/Lagos` only for now (default)                 |
| currency_code     | string | No       | `NGN` only for now (default)                          |
| launch_date       | string | No       | `YYYY-MM-DD`: the client's target launch date (readiness gate) |

The city starts in `onboarding`. A `boundary` that overlaps another city returns 422. `region` is auto-derived from `state`. `area_sq_km` is auto-computed from the boundary (π×r² for Point, spherical excess for Polygon).

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
    "is_active": false,
    "launch_status": "onboarding",
    "launch_status_label": "Onboarding",
    "launch_date": "2026-12-01",
    "launched_at": null,
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
Returns city with loaded vehicle classes and aggregate counts, plus `readiness`: `{ "ready", "missing", "counts" }` from the [readiness checklist](#get-city-readiness).

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
**Legacy switch, kept for existing dashboards; use [Change City Launch Status](#change-city-launch-status).** A live city is paused (upcoming bookings follow the `pause_scheduled_rides` setting, default honour); an onboarding city launches (422 with `readiness` when a required gate fails); a paused city resumes. The response adds `scheduled_rides`, `scheduled_rides_to_cancel` and `active_rides`. Audit events: `city_launched`, `city_resumed`, `city_paused` (before Phase 2: `city_activated` / `city_deactivated`).

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

### Get City Readiness
```
GET /admin/cities/{city_id}/readiness
```
The city readiness checklist (OQ-22). A first launch needs every **required** gate to pass; resuming a paused city does not.

| Gate | Kind | Passes when |
|------|------|-------------|
| `boundary` | computed | A boundary is drawn and overlaps no other city |
| `pricing` | computed | At least one class is offered and every offered class has pricing in effect |
| `drivers` | computed | Approved, KYC-verified drivers in the city ≥ `launch_min_approved_drivers` (default 30) |
| `ev_charging` | computed | At least one active charging station, when approved drivers in the city have plug-in vehicles (`electric`, `plug_in_hybrid`); otherwise `not_applicable` |
| `support_cover` | manual | An admin confirmed support and Safety cover for the city's hours |
| `permits` | manual | An admin confirmed state permits are `obtained` or `not_required` |
| `launch_date` | computed | The client's target `launch_date` is set (no city goes live without one) |
| `launch_promos` | computed, optional | Active promo codes limited to the city (never blocks) |

**Response 200:**
```json
{
  "city": { "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f", "name": "Ibadan", "launch_status": "onboarding", "...": "..." },
  "readiness": {
    "ready": false,
    "missing": ["drivers", "support_cover"],
    "gates": [
      { "gate": "boundary", "label": "Map boundary drawn, with no overlap", "kind": "computed", "required": true, "status": "passed", "detail": "Boundary drawn with no overlap." },
      { "gate": "pricing", "label": "Prices set for every vehicle class offered", "kind": "computed", "required": true, "status": "passed", "detail": "All 3 vehicle classes offered have prices in effect.", "offered_classes": 3, "unpriced_classes": [] },
      { "gate": "drivers", "label": "Enough approved, KYC-verified drivers", "kind": "computed", "required": true, "status": "failed", "detail": "12 of 30 approved, KYC-verified drivers.", "approved": 12, "minimum": 30 },
      { "gate": "ev_charging", "label": "Charging stations live for plug-in drivers", "kind": "computed", "required": true, "status": "not_applicable", "detail": "No approved drivers with plug-in vehicles.", "plug_in_drivers": 0, "active_stations": 0 },
      { "gate": "support_cover", "label": "Support and Safety cover for the city", "kind": "manual", "required": true, "status": "failed", "detail": "Not confirmed yet.", "confirmation": null },
      { "gate": "permits", "label": "State transport permits or registrations", "kind": "manual", "required": true, "status": "passed", "detail": "Confirmed: permits obtained.", "confirmation": { "confirmed_at": "2026-10-18T10:00:00+00:00", "confirmed_by_admin_id": "uuid", "note": "Oyo permit ref 123", "value": "obtained" } },
      { "gate": "launch_date", "label": "Launch date agreed with the client", "kind": "computed", "required": true, "status": "passed", "detail": "Target launch date: 2026-12-01.", "launch_date": "2026-12-01" },
      { "gate": "launch_promos", "label": "Launch promo codes limited to the city", "kind": "computed", "required": false, "status": "failed", "detail": "No promo codes limited to the city (optional).", "promo_codes": 0 }
    ],
    "counts": { "approved_drivers": 12, "min_approved_drivers": 30, "waitlist": 418, "active_rides": 0, "upcoming_scheduled_rides": 0 }
  }
}
```
`status` is `passed`, `failed` or `not_applicable`. `counts` shows what a pause would affect (`active_rides`, `upcoming_scheduled_rides`) and the passengers waiting.

---

### Confirm Readiness Gate
```
POST /admin/cities/{city_id}/readiness/{gate}
```
Confirms or clears a **manual** gate (`support_cover`, `permits`); any other gate returns 422. Stored on the city with who and when; audit-logged (`city_readiness_confirmed` / `city_readiness_cleared`).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| confirmed | boolean | No | Default `true`; `false` clears the gate |
| value | string | For `permits` | `obtained` or `not_required` |
| note | string | No | Max 500, e.g. the permit reference |

**Response 200:** `{ "message": "Readiness gate confirmed.", "readiness": { … } }`

---

### Change City Launch Status
```
POST /admin/cities/{city_id}/launch-status
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| status | string | Yes | `onboarding`, `live` or `paused` |
| reason | string | When pausing | Max 500, shown to admins |
| scheduled_rides | string | No (pause only) | `honour`: upcoming bookings still go ahead (default, from the `pause_scheduled_rides` setting). `cancel`: each is cancelled with reason `city_paused`; the passenger (and any reserved driver) is told |

What happens:
- **onboarding → live:** every required readiness gate must pass, else 422 with `readiness`. The waitlist gets `city_launched`; approved drivers in the city get `city_status_changed`.
- **live → paused:** trips in progress are never touched. New estimates and bookings are refused. Drivers get `city_status_changed`. A booking that reaches dispatch in a city paused with `cancel` fails with `dispatch_failure_reason` `city_paused`.
- **paused → live:** a resume; no checklist (the city already launched). Clears the pause fields. The waitlist is told.
- **paused → onboarding:** closes the city again and cancels upcoming bookings.

Cancelling bookings and the notifications run on the queue after the response (driver notices in their own job that is never retried, so nobody is told twice). Audit events: `city_launched`, `city_resumed`, `city_paused`, `city_returned_to_onboarding`.

**Response 200:**
```json
{
  "message": "Lagos is paused.",
  "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos", "launch_status": "paused", "is_active": false, "paused_at": "2026-10-18T10:00:00.000000Z", "pause_reason": "Flooding on the mainland", "pause_scheduled_rides": "honour", "...": "..." },
  "previous_status": "live",
  "scheduled_rides": "honour",
  "scheduled_rides_to_cancel": 0,
  "active_rides": 14,
  "readiness": { "...": "..." },
  "warnings": []
}
```
Other messages: `"Ibadan is live."`, `"Lagos has resumed."`, `"Ibadan is back in onboarding."`.

**Response 422:** `"Ibadan is not ready to launch. Complete the readiness checklist first."` (with `readiness`); `"Ibadan cannot move from Onboarding to Paused."`; `"Lagos is already Live."`; missing `reason` when pausing; `scheduled_rides` when not pausing.

---

### City Settings
```
GET /admin/settings/cities
PUT /admin/settings/cities
```
**Roles:** `operations` (and `super_admin`). Defaults in `config/cities.php`; every change is audit-logged (`city_settings.updated`).

| Setting | Default | Description |
|---------|---------|-------------|
| launch_min_approved_drivers | 30 | Approved, KYC-verified drivers a city needs before its first launch (0–10000) |
| require_ev_charging | true | Require an active charging station when the city has plug-in drivers |
| pause_scheduled_rides | `honour` | What a pause does to upcoming bookings when the admin does not choose: `honour` or `cancel` |

**Response 200:** `{ "settings": { "launch_min_approved_drivers": 30, "require_ev_charging": true, "pause_scheduled_rides": "honour" } }` (`PUT` adds `"message": "City settings updated."`).

---

## Admin — Vehicle Class Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

**Allowed Roles:** Operations (Super Admin always has access)

Single-class responses (show, create, update) include a `warnings` array that flags cities where the class has no pricing in effect, or cities left with nothing bookable when the class is turned off. See [Pricing coverage warnings](#pricing-coverage-warnings).

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

**Auth required.** Returns fare estimates for all active vehicle classes in the pickup's city. Detects cross-city rides and long-distance trips, returning warnings when applicable. Pickup city pricing always applies.

**The pickup decides the city (24 Oct 2026).** The server finds the city whose drawn boundary holds the pickup and quotes that city; the response's `city` says which. `city_id` is optional: it is used only when the pickup lies outside every drawn city (or inside the city sent, e.g. on a shared border). A stale or wrong `city_id` no longer causes an error. `POST /rides` resolves the city the same way, so the city quoted is the city booked.

| Field           | Type   | Required | Description                |
|-----------------|--------|----------|----------------------------|
| city_id         | string (UUID) | No       | Fallback city for a pickup outside every drawn city. Ignored when the pickup lies inside another city |
| pickup_lat      | number | Yes      | Pickup latitude (-90 to 90)|
| pickup_lng      | number | Yes      | Pickup longitude (-180 to 180)|
| destination_lat | number | Yes      | Destination latitude       |
| destination_lng | number | Yes      | Destination longitude      |
| scheduled_pickup_at | string (ISO 8601) | No | Quote a [scheduled ride](#scheduled-rides) for this pickup time. Must include a UTC offset (e.g. `2026-10-20T07:30:00+01:00`) and be in the future. Surge is then the surge in effect **at that time**: time-based and manual rules covering it apply, demand-based surge never does. Each estimate then also has `reservation_fee` (NGN, already included in `fare_estimate`; 0 while the fee is off). |

**Validation rules:**
- The pickup's city must be **live**. A pickup inside an onboarding or paused city returns 422 on `pickup_lat` with that city's message (`E-tiGo is not available in Ibadan yet. Join the waitlist and we will tell you when we launch.` / `Rides in Lagos are paused for now. Please try again later.`), whatever `city_id` says, so a city that has not opened cannot be booked under a live city's name and prices. When the city used is the `city_id` sent (pickup outside every drawn city), the same messages come on `city_id`. (`POST /rides` returns the same)
- A pickup outside every drawn city with no `city_id` returns 422 on `pickup_lat`: `E-tiGo is not available at this pickup location yet.`
- Pickup and destination coordinates cannot be identical — returns 422

**How `fare_estimate` is calculated:** metered fare (base + distance + time, never below the minimum fare) × current surge multiplier − the passenger's loyalty-tier booking-fee discount. The discount is a percentage of the base fare and is returned separately as `booking_fee_discount` (NGN). `POST /rides` and the final fare use the same calculation, so the price shown here is the price charged (an upfront fare). Only two things can change it: a waiting charge after the free minutes, and a trip that runs significantly longer than the quoted time (more than 25% and at least 10 minutes over), which bills the actual trip time, capped at 3× the quoted time. A quicker trip keeps the quote.

**Response 200:**
```json
{
  "city": { "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b", "name": "Lagos" },
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

**Balance due:** while the passenger owes money (an unpaid late-cancellation fee or wallet fare), booking is refused with `422` and `error_code: "BALANCE_DUE"` (see [Passenger — Balance Due](#passenger--balance-due)):
```json
{
  "message": "You have an outstanding balance of ₦600.00. Pay it to book a ride.",
  "error_code": "BALANCE_DUE",
  "balance_due": 600,
  "errors": {
    "balance_due": [
      "You have an outstanding balance of ₦600.00. Pay it to book a ride."
    ]
  }
}
```

**Booking for later:** send `scheduled_pickup_at` to book a [scheduled ride](#scheduled-rides) instead. The ride is created in `scheduled` status with no PIN (`pin_code` is `null`), no wallet hold and no matching; the message is `"Ride scheduled successfully."`. A passenger on an active ride can still book for later, and a pending booking does not stop them from riding now.

**Booking for someone else:** send `rider_name`, `rider_phone` and `rider_age_confirmed: true` to book and pay for a ride someone else takes. The booker stays the ride's passenger. The rider gets the driver details, PIN and a tracking link by WhatsApp/SMS. Wallet or card only by default. See [Third-Party Bookings](#third-party-bookings).

**What the quote locks in:** the `pricing_snapshot` records the rates, the surge multiplier in effect at booking (`surge_multiplier`, `surge_rule_name`) and the passenger's tier booking-fee discount (`booking_fee_discount_pct`, `tier_name`). It also records the quoted route (`distance_km`, `duration_minutes`), which the final fare reuses (upfront fare: the time charge changes only when the trip runs significantly longer), with these same values, so a surge that ends mid-trip does not change the agreed multiplier. `fare_estimate_amount` already includes surge and the tier discount (before any promo).

**PIN:** valid for 6 hours (the whole pickup phase). If the driver uses up the 3 attempts, the passenger can issue a new PIN with `POST /rides/{ride}/pin/regenerate`.

On creation, the **matching engine** is automatically triggered: `DispatchRideRequestJob` searches for nearby eligible drivers via Redis GEOSEARCH and dispatches the ride to the nearest candidate. A `MatchingTimeoutJob` is also scheduled as a safety net (default 300s). See [Matching Engine — How It Works](#matching-engine--how-it-works) for details.

| Field              | Type    | Required | Description                         |
|--------------------|---------|----------|-------------------------------------|
| city_id            | string (UUID) | Yes      | Must exist and be live; a pickup inside another city's boundary returns 422 on `pickup_lat` (see [Get Fare Estimates](#get-fare-estimates)) |
| vehicle_class_id   | string (UUID) | Yes      | Must be active in the specified city |
| pickup_lat         | number  | Yes      | Pickup latitude (-90 to 90)         |
| pickup_lng         | number  | Yes      | Pickup longitude (-180 to 180)      |
| pickup_address     | string  | Yes      | Human-readable pickup address       |
| destination_lat    | number  | Yes      | Destination latitude                |
| destination_lng    | number  | Yes      | Destination longitude               |
| destination_address| string  | Yes      | Human-readable destination address  |
| payment_method     | string  | Yes      | `cash`, `card`, `wallet`, or `bank_transfer` |
| scheduled_pickup_at | string (ISO 8601) | No | Book for later. Must include a UTC offset (e.g. `2026-10-20T07:30:00+01:00`). See [Scheduled Rides](#scheduled-rides) for the limits. |
| rider_name | string | With `rider_phone` | Book for someone else: the rider's name (max 100). See [Third-Party Bookings](#third-party-bookings) |
| rider_phone | string | No | The rider's phone: E.164 (`+2348020000002`) or Nigerian local (`0802 000 0002`). The booker's own number makes it a normal ride |
| rider_age_confirmed | boolean | With `rider_phone` | Must be `true`: the rider is 18 or over, or an adult travels with them |
| share_with | string | No | Who gets the PIN: `rider` ("Rider only") or `both` ("Both of us"). Live tracking follows the admin `tracking_recipient` (default `both`: the rider gets a tracking link and the booker keeps following the trip). Omit to use the admin default (`default_share_with` in `GET /config`). Ignored on the booker's own ride, or when `share_with_options` is empty |
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
    "is_scheduled": false,
    "scheduled_pickup_at": null,
    "scheduled": null,
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

Other `422` validation messages: `vehicle_class_id` — `"This vehicle class is not available in the selected city."` (inactive in the city or deactivated platform-wide) or `"Pricing is not yet configured for this vehicle class in the selected city."`; `promo_code` — the promo's rejection reason (e.g. `"This promo code is not valid in your city."`); `payment_method` — wallet problems such as `"Insufficient wallet balance."`; `scheduled_pickup_at` — see [Scheduled Rides](#scheduled-rides).

**Ride responses** (`POST /rides`, `GET /rides`, `GET /rides/{ride}`, active ride) carry `is_scheduled`, `scheduled_pickup_at` and a `scheduled` object for rides booked ahead (all `false`/`null` otherwise). They stay on the ride after it is dispatched, so the apps can still show "Scheduled for 7:30 AM" on the trip.

They also carry `is_third_party`, a `rider` object (who the driver picks up: `first_name`, `phone`, `is_third_party`) and, for a ride booked for someone else, a `third_party` object for the booker and admins. See [Third-Party Bookings](#third-party-bookings). `pin_code` and `share_token` are `null` for the booker when the ride's policy keeps them from the booker.

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
| is_third_party | boolean | `1`: only rides booked for someone else; `0`: only the passenger's own rides |
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
    "co2_saved_kg": 1.2,
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

**Auth required.** Cancels a ride that is in a cancellable state (`scheduled`, `requested`, `searching`, `matched`, `driver_en_route`, `driver_arrived`, `no_driver_found`). Passengers can dismiss a ride that found no driver. In-progress and completed rides cannot be cancelled. Any wallet hold is released.

The other party is notified with who cancelled — the passenger, the driver, or E-tiGo support (admin) — and the human-readable reason.

| Field          | Type   | Required | Description                                    |
|----------------|--------|----------|------------------------------------------------|
| reason         | string | No       | Cancellation reason (see `CancellationReason` enum). The system-only `system_timeout`, `scheduled_dispatch_failed` and `city_paused` are rejected with `422` |
| reason_details | string | No       | Additional details (max 500 chars). Send the free text for a reason with `allows_custom_description` here |

`custom_description`, `custom_reason` and `description` (strings, max 500) are also accepted, but they are **not stored**. Only `reason_details` is saved and shown to the other party.

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

**Third-party ride:** when the ride's PIN policy includes the rider, the new PIN is sent to them again by WhatsApp/SMS. `rider_notification_status` goes back to `pending`, unless the last message failed: then it stays `failed` so the booker keeps seeing the PIN. `pin_code` is `null` when the policy gives the PIN to the rider only.
```json
{
  "message": "A new PIN has been issued and sent to Ada.",
  "pin_code": "4821",
  "rider_notification_status": "pending"
}
```

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

**Scheduled rides** start matching `dispatch_lead_minutes` (default 15) before pickup and keep searching until `matching_grace_minutes` (default 10) after the pickup time: waves repeat every `retry_wave_delay_seconds` (default 30) instead of stopping after `max_auto_retries`, and a driver who declined is not offered the ride again for `matching_timeout`. Offers for a scheduled ride carry `scheduled_pickup_at`. A driver with a reserved scheduled pickup in the next `driver_reservation_buffer_minutes` (60) is not offered other rides.

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
  "response_timeout_seconds": 20,
  "payment_method": "card",
  "passenger_name": "Ade Ogunleye",
  "passenger_rating": 4.9,
  "distance_km": 8.5,
  "duration_minutes": 15,
  "scheduled_pickup_at": null,
  "is_third_party": false
}
```

When `is_third_party` is `true` the ride was booked for someone else and `passenger_name` is the **rider's** first name (the person to pick up); `passenger_rating` is still the booker's.

`scheduled_pickup_at` is the booked pickup time (ISO 8601) when the offer is for a [scheduled ride](#scheduled-rides), otherwise `null`. (A scheduled ride a driver reserved in advance is assigned to them directly, with no offer.) The push notification's `data` carries it too, only for scheduled rides.

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

**Auth required.** Fallback polling endpoint for when WebSocket connection drops. Returns the driver's latest cached location from Redis and a computed ETA. Accessible by the ride's passenger, the assigned driver, or any admin. On a [third-party ride](#third-party-bookings) with rider-only tracking the booker gets `403`, unless the rider's message failed.

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

**No auth required** (`throttle:60,1`). The data behind trip sharing and the no-app tracking page that third-party riders receive. The page itself is served by the API host at `GET /track/{ride}/{token}` (HTML, `noindex`, polls this endpoint every 10 seconds). If the web team hosts its own page, set its address in `THIRD_PARTY_TRACKING_URL` (placeholders `{ride}` and `{token}`).

Returns the status, pickup and destination, the driver's first name, photo and car (make, model, colour, plate), the driver's live position and ETA while the ride is active and the position is under 2 minutes old (`null` otherwise), and the emergency number and E-tiGo safety line. For a third-party ride it also gives the rider's and the booker's first names. Nothing about the passenger's account. The link expires 1 hour after the ride ends (`410`).

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
      "first_name": "Bayo",
      "profile_photo_url": null,
      "vehicle": {
        "make": "Toyota",
        "model": "Corolla",
        "colour": "Silver",
        "plate_number": "LND-123-XY"
      }
    },
    "is_third_party": true,
    "rider_first_name": "Ada",
    "booked_by_first_name": "Ade",
    "started_at": null,
    "completed_at": null
  },
  "driver_location": {
    "lat": 6.5301,
    "lng": 3.3812,
    "heading": 90,
    "timestamp": 1792405262
  },
  "eta": {
    "target": "pickup",
    "distance_km": 1.4,
    "duration_minutes": 5
  },
  "safety": {
    "emergency_number": "112",
    "safety_line": "+2348000000000"
  }
}
```

`rider_first_name` and `booked_by_first_name` appear only on third-party rides. `safety.safety_line` is `ETIGO_SAFETY_LINE` (falling back to `ETIGO_SAFETY_NUMBER`), or `null` when neither is set. `safety.emergency_number` is `SOS_EMERGENCY_NUMBER` (default `112`), the same number as `emergency_number` in `GET /config` and `auto_dial_emergency` in the SOS response.

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

## Scheduled Rides

A passenger can book a ride for a later time (Phase 2, PRD P-20–P-22, B-27). Book with `POST /rides` plus `scheduled_pickup_at`; manage upcoming bookings with the endpoints below.

**How a booking works**

1. **Booking** — the pickup must be at least `min_lead_minutes` (default 30) and at most `max_advance_days` (default 3, set by the client) ahead. A passenger can hold `max_upcoming_per_passenger` (default 3, set by the client) upcoming bookings, at least `min_minutes_between_bookings` (default 30) apart. The ride is created in `scheduled` status.
2. **Price** — quoted and locked at booking for the pickup time: time-based and manual surge rules in effect at the pickup time apply; demand-based surge does not (live demand says nothing about a later time). A promo code must be valid at the pickup time, and peak/off-peak rules follow the pickup time.
3. **Payment** — a wallet ride's balance must cover the quote when booking, but nothing is held until dispatch. Card, cash and bank transfer work as for on-demand rides.
4. **Reminder** — `reminder_minutes` (default 60) before pickup the passenger gets a `scheduled_ride_reminder` push. If the wallet would not cover the fare it says so, so there is time to top up. A booking made inside that window gets no separate reminder.
5. **Driver reservation (optional)** — until dispatch, a driver in the same city and vehicle class can reserve the booking (`POST /driver/scheduled-rides/{ride}/reserve`), or operations can assign one (`POST /admin/scheduled-rides/{ride}/assign`). The passenger gets `scheduled_ride_reserved` and sees the driver in `scheduled.reserved_driver`. Changing the pickup time or place releases the driver. In the `driver_reservation_buffer_minutes` (60) before a reserved pickup the driver gets no on-demand offers. See [Driver — Scheduled Ride Reservations](#driver--scheduled-ride-reservations).
6. **Dispatch** — `dispatch_lead_minutes` (default 15) before pickup the dispatcher places the wallet hold, issues the PIN and moves the ride to `searching`. If a driver reserved it and is online (location under 2 minutes old), free and still in the right vehicle class, the ride goes **straight to them** (`searching → matched → driver_en_route`, the usual `ride_matched` / `ride_assigned` pushes). Otherwise the reservation is released (`reservation_released_reason: driver_unavailable`, both told) and normal matching starts (`scheduled_ride_dispatched` push). From there it is a normal ride; the PIN appears on the ride for the passenger (`pin_code` in `GET /passenger/active-ride` or `GET /rides/{ride}`). Matching keeps trying until `matching_grace_minutes` (default 10) after the pickup time, and never for less than the standard `matching_timeout` (5 minutes), before `no_driver_found`.
7. **When dispatch cannot happen** — the booking is cancelled with `cancellation_reason` `scheduled_dispatch_failed`, a passenger-facing `cancellation_details`, and a `ride_cancelled` push carrying the same text. Reasons (`scheduled.dispatch_failure_reason`): `insufficient_wallet_balance`, `wallet_unavailable` (frozen wallet), `account_suspended`, `city_paused` (the city was paused with bookings cancelled, or moved back to onboarding; bookings in a city paused with `honour` still dispatch), `service_unavailable` (vehicle class withdrawn), `missed_dispatch_window` (the pickup plus grace period passed, e.g. the passenger was still on another trip). While the passenger is on another ride the dispatcher waits and tries again each minute until then.

8. **Fees** (set by the client on 16 October 2026; see `doc/client/SCHEDULED_RIDES_DECISIONS.md`) — **No reservation fee** (`reservation_fee` ₦0). **Late cancellation:** when the passenger cancels a booking **a driver has reserved** within `late_cancellation_window_minutes` (**30**) of the ride time, they pay the **economy base fare** of the ride's city (`late_cancellation_fee_basis: economy_base_fare`; if the city has no economy pricing, the base fare the ride was quoted with). It is free before the window and free when no driver has reserved the booking, and never charged for `emergency`, `safety_concern`, `vehicle_mismatch` or `driver_no_show`, or when operations or the system cancel. The reserved driver receives it in full. Wallet and saved-card bookings are charged at once (queued job). **What cannot be collected — every cash or bank-transfer booking, a wallet that cannot cover it, a declined card — becomes a [balance due](#passenger--balance-due)**; the passenger cannot book again until it is paid. Show `scheduled.cancellation_fee_if_cancelled_now` in the cancel confirmation.

The dispatcher is a database-driven sweep that the scheduler runs every minute (`ProcessScheduledRidesJob`), so bookings survive restarts and deploys. The scheduler cron must be running (see DEPLOYMENT.md).

**The `scheduled` object** (on every ride response; `null` for on-demand rides):

| Field | Description |
|-------|-------------|
| `scheduled_pickup_at` | Booked pickup time (UTC) |
| `dispatch_at` | When matching starts; the booking can be edited until then |
| `can_edit` | `true` while the booking is upcoming and before `dispatch_at` |
| `reminder_sent_at` | When the reminder went out (set at booking when booked inside the reminder window) |
| `dispatched_at` | When matching started |
| `cancelled_at` | When the booking was cancelled before dispatch |
| `dispatch_failure_reason` | Why the dispatcher cancelled it (see above), else `null` |
| `reservation_fee` | Reservation fee included in the quote (NGN) |
| `reserved_driver` | `null`, or the driver who reserved it: `id`, `first_name`, `profile_photo_url`, `rating`, `vehicle` (`make`, `model`, `colour`, `plate_number`) |
| `reserved_at` | When the driver reserved it |
| `reserved_by_operations` | `true` when operations assigned the driver |
| `reservation_released_reason` | Why the last reservation was dropped: `driver_released`, `admin_released`, `pickup_changed`, `driver_unavailable`, `vehicle_blocked` (the driver's papers expired past the grace period), `shift_changed` (a shared-car driver's shift no longer covers the pickup), `driver_suspended` (the driver was suspended or deactivated) |
| `cancellation_fee_if_cancelled_now` | What cancelling now would cost the passenger (0 when free) |
| `late_cancellation_fee` | Fee recorded when the passenger cancelled late, else `null` |
| `late_cancellation_fee_status` | `pending` (being charged), `charged` (paid, including a balance due paid later), `balance_due` (owed by the passenger), `waived` (finance waived the balance), `failed` (card outcome unknown; finance checks), or `null` |

Example (a reserved booking inside the late window; Economy base fare ₦600):
```json
{
  "scheduled_pickup_at": "2026-10-20T04:30:00.000000Z",
  "dispatch_at": "2026-10-20T04:15:00.000000Z",
  "can_edit": true,
  "reminder_sent_at": null,
  "dispatched_at": null,
  "cancelled_at": null,
  "dispatch_failure_reason": null,
  "reservation_fee": 0,
  "reserved_driver": {
    "id": "01a142ed-dca3-73c4-b7e9-c302f8a44181",
    "first_name": "Bayo",
    "profile_photo_url": null,
    "rating": null,
    "vehicle": {
      "make": "Toyota",
      "model": "Corolla",
      "colour": "Silver",
      "plate_number": "LND-482KJ"
    }
  },
  "reserved_at": "2026-10-19T08:00:00.000000Z",
  "reserved_by_operations": false,
  "reservation_released_reason": null,
  "cancellation_fee_if_cancelled_now": 600,
  "late_cancellation_fee": null,
  "late_cancellation_fee_status": null
}
```

### Book a Scheduled Ride
```
POST /rides
```

Same as [Create Ride](#create-ride) plus `scheduled_pickup_at`.

**Request:**
```json
{
  "city_id": "7c6b5a49-3827-4165-9f0e-d1c2b3a4f5e6",
  "vehicle_class_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
  "pickup_lat": 6.5244,
  "pickup_lng": 3.3792,
  "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
  "destination_lat": 6.5774,
  "destination_lng": 3.3210,
  "destination_address": "Murtala Muhammed International Airport, Ikeja",
  "payment_method": "card",
  "scheduled_pickup_at": "2026-10-20T05:30:00+01:00"
}
```

**Response 201** (abridged — the full ride object as in Create Ride):
```json
{
  "message": "Ride scheduled successfully.",
  "ride": {
    "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "status": "scheduled",
    "status_label": "Scheduled",
    "is_scheduled": true,
    "scheduled_pickup_at": "2026-10-20T04:30:00.000000Z",
    "scheduled": {
      "scheduled_pickup_at": "2026-10-20T04:30:00.000000Z",
      "dispatch_at": "2026-10-20T04:15:00.000000Z",
      "can_edit": true,
      "reminder_sent_at": null,
      "dispatched_at": null,
      "cancelled_at": null,
      "dispatch_failure_reason": null
    },
    "fare_estimate_amount": "5600.00",
    "payment_method": "card"
  },
  "pin_code": null
}
```

**Response 422** (`errors.scheduled_pickup_at`):

| Message | When |
|---------|------|
| `The scheduled pickup time must include a timezone offset, e.g. 2026-10-20T07:30:00+01:00.` | No offset in the time |
| `The pickup time must be at least 30 minutes from now.` | Too soon (`min_lead_minutes`) |
| `The pickup time can be at most 3 days from now.` | Too far ahead (`max_advance_days`) |
| `You can have at most 3 upcoming scheduled rides.` | Too many upcoming bookings |
| `You already have a scheduled ride within 30 minutes of this time.` | Clashes with another booking |
| `Scheduled rides are not available right now.` | Operations switched scheduling off |

`payment_method`: `Insufficient wallet balance.` / `Wallet is frozen or closed.` for a wallet booking.

---

### List Upcoming Scheduled Rides
```
GET /rides/scheduled
```

**Auth required. Middleware:** `user.type:passenger`

The passenger's bookings still in `scheduled` status, soonest pickup first. Dispatched, completed and cancelled ones appear in `GET /rides` (filter `status=scheduled` there to get the same rides).

| Query | Type | Description |
|-------|------|-------------|
| per_page | integer | 1–50, default 15 |

**Response 200:**
```json
{
  "rides": [
    {
      "id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
      "status": "scheduled",
      "is_scheduled": true,
      "scheduled_pickup_at": "2026-10-20T04:30:00.000000Z",
      "scheduled": { "dispatch_at": "2026-10-20T04:15:00.000000Z", "can_edit": true },
      "pickup": { "lat": "6.5244000", "lng": "3.3792000", "address": "123 Herbert Macaulay Way, Yaba, Lagos" },
      "destination": { "lat": "6.5774000", "lng": "3.3210000", "address": "Murtala Muhammed International Airport, Ikeja" },
      "fare_estimate_amount": "5600.00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

---

### Update a Scheduled Ride
```
PUT /rides/{ride}/scheduled
```

**Auth required. Middleware:** `user.type:passenger` (own rides only)

Change the pickup time, pickup, destination or payment method of a booking before `scheduled.dispatch_at`. Send only what changes; a new place needs its `_lat`, `_lng` and `_address` together. The fare is quoted again for the new trip and time, and a promo code on the booking is checked again. A new pickup time or pickup place releases a reserved driver (`reservation_released_reason: pickup_changed`; the driver is told); a new destination or payment method keeps them.

| Field | Type | Description |
|-------|------|-------------|
| scheduled_pickup_at | string (ISO 8601) | New pickup time, with a UTC offset; same limits as booking |
| pickup_lat / pickup_lng / pickup_address | number / number / string | New pickup |
| destination_lat / destination_lng / destination_address | number / number / string | New destination |
| payment_method | string | `cash`, `card`, `wallet` or `bank_transfer` |

**Response 200:** `{ "message": "Scheduled ride updated.", "ride": { ... } }`

**Errors:** `403` not your ride. `422` `errors.ride` — `This ride is about to start looking for a driver and can no longer be changed. Cancel it and book again instead.` (at or after `dispatch_at`) or `Only a scheduled ride that has not started can be changed.`; `errors.scheduled_pickup_at` — as for booking; `errors.promo_code` — the code no longer applies to the changed trip; `errors.payment_method` — wallet cannot cover the new quote; `errors.pickup_lat` — the new pickup is inside another city's boundary: `This pickup is in Abuja, not the booking's city. Cancel and book again to be picked up there.` (a booking keeps the city, prices and drivers it was booked with), or that city's not-open message.

---

### Cancel a Scheduled Ride
```
DELETE /rides/{ride}/scheduled
```

**Auth required. Middleware:** `user.type:passenger` (own rides only)

Cancels a booking before it is dispatched. Free unless a driver has reserved the booking and the ride time is less than 30 minutes away (see step 8 above). The response's `scheduled.late_cancellation_fee` and `late_cancellation_fee_status` (`charged`, `pending` or `balance_due`) show what happened. The reserved driver is told. Optional body: `reason` and `reason_details`, as for [Cancel Ride](#cancel-ride). `POST /rides/{ride}/cancel` also cancels a `scheduled` ride (and is the endpoint for admins and for rides already dispatched).

**Response 200:** `{ "message": "Scheduled ride cancelled.", "ride": { "status": "cancelled", ... } }`

**Response 422:** `{ "message": "Only a scheduled ride that has not started can be cancelled here.", "current_status": "searching" }`

---

### List Upcoming Scheduled Rides (Admin)
```
GET /admin/scheduled-rides
```

**Auth required. Admin role:** `operations` (and `super_admin`)

Every booking still in `scheduled` status, soonest pickup first, with the passenger.

| Query | Type | Description |
|-------|------|-------------|
| city_id | string (UUID) | Filter by city |
| vehicle_class_id | string (UUID) | Filter by vehicle class |
| from / to | string (date/time) | Pickup time range (an offset in the value is honoured) |
| reserved | boolean | `true`: only bookings with a reserved driver; `false`: only unreserved |
| per_page | integer | 1–100, default 25 |

**Response 200:** `{ "rides": [ ...ride objects with "passenger" and scheduled.reserved_driver... ], "meta": { ... } }`

---

### Assign a Driver to a Scheduled Ride (Admin)
```
POST /admin/scheduled-rides/{ride}/assign
DELETE /admin/scheduled-rides/{ride}/assign
```

**Auth required. Admin role:** `operations` (and `super_admin`)

`POST` with `{ "driver_id": "<driver's user id>" }` reserves the booking for that driver before dispatch, as if they had reserved it (`scheduled.reserved_by_operations: true`). Posting again with another driver moves it (the first driver is told). The same rules apply as for a driver reserving (approved, same city and vehicle class, no negative balance, at most 5 reservations, 60 minutes apart, before `dispatch_at`), except the `driver_reservations_enabled` switch. The driver gets `scheduled_ride_reserved`, the passenger too.

`DELETE` removes the driver (`reservation_released_reason: admin_released`; both told).

**Response 200:** `{ "message": "Driver assigned to the scheduled ride.", "ride": { ...ride with scheduled.reserved_driver... } }` / `{ "message": "Driver removed from the scheduled ride.", "ride": { ... } }`

**Response 422:** `errors.driver_id` — not a driver, or the reservation rules above; `errors.ride` — `This ride has no reservation to release.` (DELETE).

---

### Scheduled Ride Settings (Admin)
```
GET /admin/settings/scheduled-rides
PUT /admin/settings/scheduled-rides
```

**Auth required. Admin role:** `operations` (and `super_admin`). Changes are audit-logged. Defaults come from `config/scheduled_rides.php` (`SCHEDULED_RIDES_*` environment variables).

| Field | Type | Default | Range | Description |
|-------|------|---------|-------|-------------|
| enabled | boolean | `true` | — | Accept new bookings. Existing bookings still dispatch when off |
| min_lead_minutes | integer | 30 | 5–1440 | Earliest pickup after booking |
| max_advance_days | integer | 3 | 1–90 | Latest pickup after booking (set by the client) |
| dispatch_lead_minutes | integer | 15 | 5–120 | Matching starts this long before pickup. Cannot exceed `min_lead_minutes` |
| reminder_minutes | integer | 60 | 0–1440 | Reminder push before pickup; `0` turns it off. Otherwise must exceed `dispatch_lead_minutes` |
| matching_grace_minutes | integer | 10 | 0–60 | Keep searching this long after the pickup time |
| retry_wave_delay_seconds | integer | 30 | 10–300 | Pause between matching waves for a scheduled ride |
| max_upcoming_per_passenger | integer | 3 | 1–50 | Upcoming bookings per passenger (set by the client) |
| min_minutes_between_bookings | integer | 30 | 0–1440 | Minimum gap between one passenger's bookings |
| driver_reservations_enabled | boolean | `true` | — | Drivers can reserve bookings in advance (operations can always assign) |
| max_reservations_per_driver | integer | 5 | 1–50 | Upcoming reservations one driver may hold |
| driver_reservation_gap_minutes | integer | 60 | 0–1440 | Minimum gap between one driver's reserved pickups |
| driver_reservation_buffer_minutes | integer | 60 | 0–240 | No on-demand offers to a driver this long before their reserved pickup (0 = off) |
| reservation_fee | number (NGN) | 0 | 0–100000 | Added to every scheduled ride's quote. Client: no charge for reservations |
| late_cancellation_fee_basis | string | `economy_base_fare` | `economy_base_fare`, `flat`, `off` | How the late-cancellation fee is set. Client: the economy base fare of the ride's city |
| late_cancellation_fee_vehicle_class | string | `economy` | an existing vehicle class `name` | The class whose base fare is the fee (`economy_base_fare` basis) |
| late_cancellation_fee | number (NGN) | 0 | 0–100000 | The fee when the basis is `flat` |
| late_cancellation_window_minutes | integer | 30 | 0–1440 | How long before the ride time the fee applies. Client: 30 minutes |

The defaults and why they were chosen are in `doc/client/SCHEDULED_RIDES_DECISIONS.md`.

**PUT** accepts any subset. **Response 200:**
```json
{
  "message": "Scheduled ride settings updated.",
  "settings": {
    "enabled": true,
    "min_lead_minutes": 30,
    "max_advance_days": 3,
    "dispatch_lead_minutes": 15,
    "reminder_minutes": 60,
    "matching_grace_minutes": 10,
    "retry_wave_delay_seconds": 30,
    "max_upcoming_per_passenger": 3,
    "min_minutes_between_bookings": 30,
    "driver_reservations_enabled": true,
    "max_reservations_per_driver": 5,
    "driver_reservation_gap_minutes": 60,
    "driver_reservation_buffer_minutes": 60,
    "reservation_fee": 0,
    "late_cancellation_fee_basis": "economy_base_fare",
    "late_cancellation_fee_vehicle_class": "economy",
    "late_cancellation_fee": 0,
    "late_cancellation_window_minutes": 30
  }
}
```

**Response 422:** `dispatch_lead_minutes cannot be more than min_lead_minutes.` or `reminder_minutes must be more than dispatch_lead_minutes (or 0 to turn reminders off).`, plus the range checks above.

---

## Driver — Scheduled Ride Reservations

Drivers can commit to a passenger's [scheduled ride](#scheduled-rides) in advance (Uber Reserve model). **Middleware:** `auth:sanctum`, `user.type:driver`.

At the booking's `dispatch_at` (15 minutes before pickup) the ride is assigned straight to the reserving driver if they are **online with a location under 2 minutes old, not on another trip, still in the booking's vehicle class and not blocked by a negative balance**. It then arrives like any assigned ride (`ride_assigned` push, `driver_en_route`). Otherwise the reservation is dropped (`scheduled_ride_reservation_released` push) and the ride goes to normal matching. In the hour before a reserved pickup (`driver_reservation_buffer_minutes`) the driver gets no on-demand offers. A reminder (`scheduled_ride_reminder`, "be online by …") goes out 60 minutes before pickup.

The ride object here is driver-facing: the trip, times, fare, passenger first name and rating; no phone number.

| Field | Description |
|-------|-------------|
| `scheduled_pickup_at` | Booked pickup time (UTC) |
| `dispatch_at` | Be online by then; the hand-over happens at this time |
| `fare_estimate_amount`, `distance_km`, `duration_minutes`, `payment_method` | The quoted trip |
| `passenger` | `first_name`, `rating` |
| `reserved_by_me`, `reserved_at` | Whether this driver holds it |

### List Scheduled Rides Available to Reserve
```
GET /driver/scheduled-rides/available
```

Bookings in the driver's city and vehicle class, not reserved by anyone, whose matching has not started. Soonest first. `per_page` 1–50 (default 15). Empty for a driver without a vehicle.

**Response 200:**
```json
{
  "rides": [
    {
      "id": "01a142a2-0d1b-7393-aa4e-743b38e7cc53",
      "reference_number": "TRP-NG-000001",
      "scheduled_pickup_at": "2026-10-20T04:30:00.000000Z",
      "dispatch_at": "2026-10-20T04:15:00.000000Z",
      "pickup": {
        "lat": "6.5244000",
        "lng": "3.3792000",
        "address": "123 Herbert Macaulay Way, Yaba, Lagos"
      },
      "destination": {
        "lat": "6.5774000",
        "lng": "3.3210000",
        "address": "Murtala Muhammed International Airport, Ikeja"
      },
      "fare_estimate_amount": "6500.00",
      "fare_currency": "NGN",
      "distance_km": 18,
      "duration_minutes": 35,
      "payment_method": "card",
      "vehicle_class": {
        "id": "01a142a2-0cee-71ca-9007-d7763d7881b7",
        "name": "economy",
        "display_name": "Economy",
        "capacity": 4,
        "icon": "lite"
      },
      "passenger": {
        "first_name": "Ade",
        "rating": null
      },
      "reserved_by_me": false,
      "reserved_at": null
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

### List My Reservations
```
GET /driver/scheduled-rides
```

The driver's upcoming reservations, soonest first (same item shape, `reserved_by_me: true`).

### Reserve a Scheduled Ride
```
POST /driver/scheduled-rides/{ride}/reserve
```

**Response 200:** `{ "message": "Ride reserved. Be online by the dispatch time to take it.", "ride": { ... } }`. The passenger gets `scheduled_ride_reserved`.

**Response 422** (`errors.ride`), for example:

| Message | When |
|---------|------|
| `Another driver has already reserved this ride.` | Taken |
| `You already have a reserved pickup within 60 minutes of this one.` | `driver_reservation_gap_minutes` |
| `A driver can hold at most 5 upcoming reservations.` | `max_reservations_per_driver` |
| `This ride needs a different vehicle class.` / `This ride is in another city.` | Not eligible |
| `Clear your outstanding balance before reserving rides.` | Negative balance beyond the limit |
| `This ride is about to start looking for a driver and can no longer be reserved.` | At or after `dispatch_at` |
| `This ride is no longer open for reservation.` | Cancelled or already dispatched |
| `Only approved drivers can reserve scheduled rides.` / `Reserving scheduled rides is not available right now.` | Driver status / switched off |

### Release a Reservation
```
POST /driver/scheduled-rides/{ride}/release
```

Gives the booking back before dispatch; the passenger is told and the booking returns to the available list. No penalty (tracked in the audit log).

**Response 200:** `{ "message": "Reservation released.", "ride": { ... } }`. **Response 422:** `This ride has no reservation to release.` / `You have not reserved this ride.`

---

## Third-Party Bookings

A passenger (the **booker**) can book and pay for a ride that someone else takes (the **rider**), such as a parent, a guest or a colleague (Phase 2, PRD B-28, P-23, P-24, D-13, A-34). The defaults follow the OQ-18 recommendation of 16 October 2026, which the client has not confirmed yet (`doc/client/PHASE2_DECISIONS.md`). Operations can change every default without a release.

**How it works**

1. **Booking.** `POST /rides` (now, or with `scheduled_pickup_at`) plus `rider_name`, `rider_phone` and `rider_age_confirmed: true`. The booker stays the ride's `passenger`: they pay, cancel, rate, dispute and see the trip in their history. The booker is never told whether the number has an E-tiGo account.
2. **Who gets the PIN and tracking.** The booker chooses who gets the **PIN** with `share_with`: `rider` ("Rider only") or `both` ("Both of us"). Live tracking is not part of the choice: it follows the admin `tracking_recipient`, which defaults to **both**, so the rider gets a tracking link and the booker keeps following the trip, as on Uber and Bolt (the booker pays and answers for the ride). Without a choice, the admin PIN default applies (`pin_recipient` and `tracking_recipient`: `booker`, `rider` or `both`; default **both**). When operations set `pin_recipient` to `booker` (no messages to riders), the choice is not offered and `share_with` is ignored. The ride saves what applied (`third_party.pin_recipient`, `third_party.tracking_recipient`), so a later settings change affects only new bookings. With "Rider only" the booking response has `pin_code: null` and the booker does not see the PIN, unless the rider can't be reached (step 4).
3. **Driver assigned.** The rider gets a WhatsApp message (SMS if WhatsApp fails). It contains the booker's first name, the driver's first name, the car and plate, and the safety contacts (112 and the E-tiGo safety line). It also has the **PIN** when the policy includes the rider, and a **tracking link that works without the app** (`/track/{ride}/{share_token}`) when tracking includes the rider. It tells the rider to contact the booker for changes. The booker sees the PIN and live map in the app when the policy includes them.
4. **Delivery fallback.** If WhatsApp and SMS both refuse the message (3 attempts over about a minute), `rider_notification_status` becomes `failed`. The booker gets a `third_party_rider_unreachable` push, and **the PIN and tracking are shown to them whatever the policy**, so the ride can still start.
5. **Trip updates.** With `rider_trip_updates` on (the default), the rider also gets "your driver has arrived" with the car and plate. If the ride is cancelled after the rider was told about it, they get a cancellation message.
6. **PIN change.** When the booker regenerates the PIN, the new one is sent to the rider again, if the policy includes them.
7. **Scheduled rides.** The rider gets a reminder with the time and places (no PIN) at the scheduled-ride reminder time, 60 minutes before pickup by default. A booking made inside that window (or with reminders off) is sent to the rider at once, and so is a change of time or places when no reminder is still to come. The PIN, driver details and tracking link follow at dispatch, as for every scheduled ride.

Text the booker typed (their name, the addresses) is sent with links removed, so the feature cannot be used for phishing.

**Rules**

| Rule | Default | Error (`422`) |
|------|---------|---------------|
| The feature is on | `enabled: true` | `rider_phone`: "Booking a ride for someone else is not available right now." |
| Rider name required | — | `rider_name`: "Enter the rider's name." |
| Age confirmation: the rider is 18+, or an adult travels with them | — | `rider_age_confirmed`: "Confirm that the rider is 18 or over, or that an adult is travelling with them." |
| Payment by wallet or card, because the payer is not in the car | `allow_cash: false`, `allow_bank_transfer: false` | `payment_method`: "Cash can't be used when you book for someone else. Pay by wallet or card." Also applies when a scheduled booking is edited |
| Third-party rides under way at once | `max_active_per_booker: 2` | `rider_phone`: "You can have at most 2 rides for other people at the same time." |
| Different rider numbers per booker in 24 hours (cancelled bookings count) | `max_rider_numbers_per_day: 10` | `rider_phone`: "You can book for at most 10 different people in 24 hours." |
| Rider phone format: E.164 or Nigerian local | — | `rider_phone`: "Enter the rider's phone number in international format, e.g. +2348031234567." |

A third-party ride does **not** count as the booker's own active ride. The booker can ride themselves at the same time, and the 409 "already have an active ride" applies only to their own rides. Scheduled bookings for different people do not clash on `min_minutes_between_bookings`, and the booker's own trip does not hold up dispatch of a third-party booking. The scheduled-ride limit `max_upcoming_per_passenger` counts all of the booker's bookings.

**The `third_party` object** (booker and admins only; absent on the passenger's own rides):

| Field | Description |
|-------|-------------|
| `rider_name`, `rider_phone` | As booked (`rider_phone` in E.164) |
| `pin_recipient`, `tracking_recipient` | `booker`, `rider` or `both`, saved at booking |
| `rider_notification_status` | `pending` (no channel has accepted it yet), `sent_whatsapp`, `sent_sms`, `delivered` (reserved for provider delivery receipts; not set yet), `failed`; `null` when the policy sends the rider nothing (booker-only PIN and tracking) |
| `rider_notification_channel` | `whatsapp` or `sms` once sent |
| `rider_notified_at` | When the driver-details (or new-PIN) message was accepted |
| `booker_sees_pin`, `booker_sees_tracking` | Whether the booker gets the PIN and live tracking now (from the policy, or the `failed` fallback) |

**The `rider` object** is on every ride (`null` in lists that do not load the passenger): `first_name`, `phone` and `is_third_party`. It describes the person the driver picks up: the rider on a third-party ride, otherwise the passenger. The driver sees the phone until the trip ends.

**What each viewer sees on a third-party ride**

| | Booker | Driver | Admin |
|---|---|---|---|
| `third_party` | ✅ | — | ✅ |
| `rider` (name, phone) | ✅ | ✅ (phone until the trip ends) | ✅ |
| `passenger` | full | `id` and `first_name` only | full |
| `pin_code` | when `booker_sees_pin` | — | — |
| `share_token`, `GET /rides/{ride}/location`, `driver.location.updated` on `private-ride.{id}` | when `booker_sees_tracking` (otherwise `null` / `403` / not sent) | ✅ | ✅ |

The driver's ride offer (`ride.request.dispatched`) and the driver's scheduled-ride lists show the rider's first name, with `is_third_party: true`.

Retrying a third-party ride that found no driver (`POST /rides/{ride}/rebroadcast`) is refused with `422` on `ride` when the booker already has `max_active_per_booker` third-party rides on the road.

**Environments without a messaging provider** (no `SENDCHAMP_ACCESS_KEY`) count rider messages as not delivered, so after the retries the booker is shown the PIN (`failed`). A message that cannot even be queued goes straight to `failed`. A PIN past its 6-hour life is issued again before the driver-details message is sent.

**Safety.** The booker's in-app SOS works for the rider's trip. The incident's telemetry carries `third_party_rider` (`name`, `phone`), `triggered_by_booker`, the booker's own position (`reporter_gps`) and `gps_source`. When the booker raises the SOS, the incident's `gps_lat` and `gps_lng` are the **car's** live position (if it is under 2 minutes old), not the booker's. The WhatsApp alert to the safety desk names the rider and the booker. The tracking page shows a "Call 112" button and the safety line.

**Real time.** `ride.rider_notification.updated` is sent on `private-user.{booker_id}` whenever `rider_notification_status` changes (see [Real-Time Broadcasting](#real-time-broadcasting-websocket)). When `booker_sees_pin` turns `true`, fetch the ride to show the PIN.

### Third-Party Booking Settings (Admin)
```
GET /admin/settings/third-party
PUT /admin/settings/third-party
```

**Auth required. Admin role:** `operations` (and `super_admin`). Changes are audit-logged (`third_party_settings.updated`) and apply to new bookings only. Defaults come from `config/third_party.php` (`THIRD_PARTY_*` environment variables).

| Field | Type | Default | Range | Description |
|-------|------|---------|-------|-------------|
| enabled | boolean | `true` | — | Accept new third-party bookings. Rides already booked carry on |
| pin_recipient | string | `both` | `booker`, `rider`, `both` | Who gets the ride PIN when the booker sends no `share_with`; also the pre-selected choice. `booker` turns the booker's choice off |
| tracking_recipient | string | `both` | `booker`, `rider`, `both` | Who gets live tracking, whatever the booker chooses. Keep `both` so the booker can follow the trip |
| allow_cash | boolean | `false` | — | Allow cash payment |
| allow_bank_transfer | boolean | `false` | — | Allow bank-transfer payment |
| max_active_per_booker | integer | 2 | 1–10 | Third-party rides under way at once per booker |
| max_rider_numbers_per_day | integer | 10 | 1–100 | Different rider numbers per booker in 24 hours |
| rider_trip_updates | boolean | `true` | — | Also message the rider on driver arrival and cancellation |

`THIRD_PARTY_TRACKING_URL` (environment only) sets the tracking-page address sent to riders. The default is `{APP_URL}/track/{ride}/{token}`.

**PUT** accepts any subset of the fields. **Response 200:**
```json
{
  "message": "Third-party booking settings updated. They apply to new bookings.",
  "settings": {
    "enabled": true,
    "pin_recipient": "rider",
    "tracking_recipient": "both",
    "allow_cash": true,
    "allow_bank_transfer": false,
    "max_active_per_booker": 2,
    "max_rider_numbers_per_day": 10,
    "rider_trip_updates": true
  }
}
```

**Response 422:** range and enum checks, e.g. `"The selected pin recipient is invalid."`.

---

## Lost & Found

A passenger who left something in the car reports it against the trip; a driver who finds something hands it in the same way (Phase 2, PRD B-29, P-25, P-26, D-14, A-35). The flow follows Uber, Bolt and Lyft. The defaults are engineering choices of 18 October 2026 that the client has not confirmed yet. Operations can change all of them without a release (see [Lost & Found Settings](#lost--found-settings-admin)).

**How it works**

1. **Report.** The passenger reports the item from the trip (`POST /rides/{ride}/lost-items`) within `report_window_days` (30) of the trip ending. They can give a `contact_phone`, which matters when the lost item is their phone. The driver gets a `lost_item_report` push and the report becomes `driver_notified`. If the driver's account is inactive, the report stays `reported` and goes straight to support.
2. **Driver answers.** The driver says whether they have it (`POST /lost-items/{id}/respond`). `found: true` → `driver_confirmed`. `found: false` → `driver_denied`. A driver who said "not found" can still answer `found: true` later. The passenger gets a `lost_item_update` push, and an SMS on `contact_phone` when the driver has the item.
3. **Arranging the return.** Once the driver has the item, each side sees the other's first name and phone number in `contact` (unless `share_contact_details` is off), and both can message on the report (`POST /lost-items/{id}/messages`). Every message is stored and support can read it. Tell users to meet somewhere public.
4. **Returned.** The driver marks it returned (`POST /lost-items/{id}/returned` → `returned`) and the passenger confirms (`POST /lost-items/{id}/received` → `resolved`). The passenger can also confirm straight from `driver_confirmed`. A return the passenger neither confirms nor disputes resolves on its own after `auto_resolve_hours` (72).
5. **Return fee.** When `return_fee` is set (₦0, so off, by default), the passenger pays it once the item is back, and the driver receives all of it. The fee is fixed when the driver confirms, so a later settings change does not affect the report. It comes from the wallet when the wallet covers it. Otherwise it becomes a [balance due](#passenger--balance-due) (`lost_item_return_fee`). Support can waive it. A trip carries at most one return fee: a second item returned from the same trip is `waived`.
6. **Support.** The passenger can ask support to step in once (`POST /lost-items/{id}/escalate`), for example after "not found" or when a return has not happened. Reports also go to support on their own:
   - when the driver has not answered within `driver_response_hours` (24); the driver is reminded, and the hand-over ends when they answer;
   - when an item the driver has is not back within `return_deadline_days` (7).

   A "not found" report nobody follows up closes as `unresolved` (`not_found`) after `denied_auto_close_days` (7). Each of these sends a `lost_item_update`.
7. **Withdrawing.** The passenger can cancel the report until the driver has the item (`POST /lost-items/{id}/cancel`). After that, only support can close it.

A third-party ride's report is made by the booker (the ride's passenger). The driver sees the rider's first name.

**Statuses**

| Status | Meaning | Open |
|--------|---------|------|
| `reported` | Filed, but no driver could be asked; support follows up | ✅ |
| `driver_notified` | The driver has been asked to check the car | ✅ |
| `driver_confirmed` | The driver has the item; passenger and driver arrange the return | ✅ |
| `driver_denied` | The driver did not find it | ✅ |
| `returned` | The driver says it was handed back; waiting for the passenger | ✅ |
| `resolved` | The passenger has it back | — |
| `unresolved` | Closed without the item being returned (`closure_reason`) | — |
| `cancelled` | The passenger withdrew the report | — |

Each trip can have one open report at a time, whoever filed it, and one report can describe several items. Each passenger can have `max_open_reports_per_passenger` (3) open reports at once.

**Categories:** `phone`, `wallet_or_purse`, `bag`, `keys`, `id_or_documents`, `electronics`, `clothing`, `glasses`, `jewellery`, `other`. They are also listed with labels in `GET /config` → `lost_and_found.categories`.

**Closure reasons:** `not_found`, `unclaimed`, `passenger_unreachable`, `driver_unreachable`, `other`.

**The lost item object**

| Field | Description |
|-------|-------------|
| `reference_number` | `LF-000001`, for support conversations |
| `viewer_role` | `passenger`, `driver` or `support`: how the object was cut for the viewer |
| `reported_by` | `passenger` or `driver` (the driver handed in something they found) |
| `category`, `category_label`, `description`, `photos` | As reported. `photos` are links valid for 30 minutes |
| `status`, `status_label`, `is_open` | See the table above |
| `driver_response` | The driver's note when they answered |
| `contact_phone` | The extra number the passenger gave (passenger and support only) |
| `escalated`, `escalated_at`, `escalation_reason` | Whether support has it and why (`escalation_reason`: passenger and support only; `escalation_source`, `passenger` or `system`: support only) |
| `closure_reason`, `closure_reason_label`, `resolution_notes` | How it was closed |
| `return_fee` | `{ amount, currency, status }`. `amount` is fixed when the driver confirms (0 = no fee). `status`: `pending`, `charged`, `balance_due`, `waived`, or `null` when no fee is due |
| `contact` | The other side's `first_name` and `phone`, while the status is `driver_confirmed` or `returned` and `share_contact_details` is on; otherwise `null`. Not on the support view |
| `ride` | Trip summary: `id`, `reference_number`, `pickup_address`, `destination_address`, `started_at`, `completed_at`, `city`, `is_third_party`, and `vehicle` (`make`, `model`, `colour`, `plate_number`; passenger and support only) |
| `passenger` / `driver` | The other side's `first_name` (the rider's on a third-party trip); full user objects for support |
| `messages` | On the detail endpoints only. Each message has `sender_role` (`passenger`, `driver`, `support`), `sender_name` (`E-tiGo Support` for support), `is_mine`, `body` and `created_at`. Support also sees internal notes (`is_internal: true`) |
| `available_actions` | What the viewer can do now: passenger `cancel`, `confirm_received`, `escalate`, `message`; driver `respond`, `mark_returned`, `message`. Use it to show the buttons |
| `driver_notified_at`, `driver_responded_at`, `returned_at`, `resolved_at`, `closed_at`, `cancelled_at`, `created_at` | Timeline |

**Notifications** (in-app and push, Account category):

| Event | Recipient | Type |
|-------|-----------|------|
| New report; reminder when the driver has not answered | Driver | `lost_item_report` |
| Driver found it / did not find it, item returned, report resolved or closed, support following up, driver handed in an item | Passenger | `lost_item_update` |
| Passenger confirmed receipt, passenger cancelled, report resolved or closed | Driver | `lost_item_update` |
| New message (data: `message_id`) | The other side; both for a support message | `lost_item_message` |

Every push carries `lost_item_id`, `reference_number`, `ride_id` and `status` in its data. Open the report from it.

### Report a Lost Item

```
POST /rides/{ride}/lost-items
```

**Auth:** passenger or driver of the ride (others get `403`). **Rate limit:** 5 per minute. **Content-Type:** `multipart/form-data` with photos, otherwise `application/json`.

When the passenger sends it, it is a report of something they lost. When the driver sends it, it is an item they found: it starts as `driver_confirmed` and the passenger is told at once.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| category | string | Yes | See the categories above |
| description | string | Yes | 5–1000 characters: what it is, colour, where in the car |
| contact_phone | string | No | Another number to reach the passenger on, in E.164 or Nigerian local format (`080…` → `+23480…`). Ignored on a driver's report |
| photos[] | file | No | Up to 3 images (jpg, jpeg, png, webp; 5 MB each): a photo of a similar item, or of the item found |

**Response 201:**
```json
{
  "message": "We have asked your driver to check the car. We will let you know as soon as they answer.",
  "lost_item": {
    "id": "01a14c5e-0877-730f-a80d-39c2a50ac02b",
    "reference_number": "LF-000001",
    "ride_id": "01a14c5e-084c-73e4-bf1b-1742de2412dc",
    "viewer_role": "passenger",
    "reported_by": "passenger",
    "category": "phone",
    "category_label": "Phone",
    "description": "Black iPhone in a blue case, left on the back seat.",
    "photos": [],
    "status": "driver_notified",
    "status_label": "Driver notified",
    "is_open": true,
    "driver_response": null,
    "contact_phone": "+2348031234567",
    "escalated": false,
    "escalated_at": null,
    "escalation_reason": null,
    "closure_reason": null,
    "closure_reason_label": null,
    "resolution_notes": null,
    "return_fee": { "amount": 0, "currency": "NGN", "status": null },
    "contact": null,
    "ride": {
      "id": "01a14c5e-084c-73e4-bf1b-1742de2412dc",
      "reference_number": "TRP-NG-000142",
      "pickup_address": "12 Allen Avenue, Ikeja",
      "destination_address": "5 Bode Thomas Street, Surulere",
      "started_at": "2026-10-19T07:40:00.000000Z",
      "completed_at": "2026-10-19T08:00:00.000000Z",
      "city": "Lagos",
      "is_third_party": false,
      "vehicle": { "make": "Toyota", "model": "Corolla", "colour": "Silver", "plate_number": "LAG-123-XY" }
    },
    "driver": { "first_name": "Musa" },
    "available_actions": ["cancel", "escalate", "message"],
    "driver_notified_at": "2026-10-19T10:00:00.000000Z",
    "driver_responded_at": null,
    "returned_at": null,
    "resolved_at": null,
    "closed_at": null,
    "cancelled_at": null,
    "created_at": "2026-10-19T10:00:00.000000Z",
    "updated_at": "2026-10-19T10:00:00.000000Z"
  }
}
```

The `message` is `"Thank you. We have told the passenger you found their item."` on a driver's report and `"Your report has been passed to our support team, who will contact you."` when no driver could be asked.

**Response 422:**

| Case | `message` |
|------|-----------|
| Ride not completed | `You can only report a lost item for a completed trip.` |
| Past the report window | `Lost items can be reported up to 30 days after the trip. Please contact support.` |
| Already an open report on the trip | `You already have an open lost item report for this trip.` (driver: `… Answer it from your lost items list.`) |
| Too many open reports | `You can have up to 3 open lost item reports. Close one, or contact support.` |
| Feature off | `Lost & Found is not available at the moment. Please contact support.` |
| Field errors | `errors.category`, `errors.description`, `errors.contact_phone`, `errors.photos.*` |

### List My Lost Item Reports

```
GET /lost-items
```

**Auth:** passenger (their reports) or driver (reports on their trips). Newest first.

| Query | Description |
|-------|-------------|
| status | One status |
| open | `1` open only, `0` closed only |
| ride_id | Reports for one trip: use it on the trip screen to show "Report a lost item" or "View report" |
| per_page | 1–50 (default 15) |

**Response 200:** `{ "lost_items": [ …lost item objects without messages… ], "meta": { "current_page", "last_page", "per_page", "total" } }`.

### Get a Lost Item Report

```
GET /lost-items/{lostItem}
```

**Auth:** the report's passenger or driver; anyone else gets `404`. Returns the lost item object with `messages`.

```json
{
  "lost_item": {
    "reference_number": "LF-000001",
    "status": "driver_confirmed",
    "status_label": "Driver has the item",
    "driver_response": "It is with me. I am in Ikeja until 6pm.",
    "return_fee": { "amount": 2000, "currency": "NGN", "status": null },
    "contact": { "first_name": "Musa", "phone": "+2348020000002" },
    "messages": [
      {
        "id": "01a14c5e-08ae-715b-9814-00fa5de77b63",
        "sender_role": "passenger",
        "sender_name": "Ada",
        "is_mine": true,
        "body": "Thank you! Can we meet at Ikeja City Mall at 5pm?",
        "created_at": "2026-10-19T10:05:00.000000Z"
      }
    ],
    "available_actions": ["confirm_received", "escalate", "message"]
  }
}
```

(Abridged: the other fields are as in the report response.)

### Respond to a Lost Item Report (Driver)

```
POST /lost-items/{lostItem}/respond
```

**Auth:** the trip's driver (`403` otherwise).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| found | boolean | Yes | `true`: I have it. `false`: I checked and it is not in the car |
| note | string | No | Up to 500 characters, shown to the passenger (e.g. where and when they can return it) |

**Response 200:** `{ "message": "Thank you. We have told the passenger you have their item.", "lost_item": { … } }`. With `found: true` the driver's view now has `contact` (the passenger's first name and `contact_phone`, else their account phone).

**Response 422:** the report has moved on, e.g. `This report is now "Driver has the item" and cannot be changed that way. Refresh and try again.` "Not found" can only be given before an answer; "found" also after "not found".

### Mark a Lost Item Returned (Driver)

```
POST /lost-items/{lostItem}/returned
```

**Auth:** the trip's driver. Only from `driver_confirmed`. → `returned`. The passenger is asked to confirm. Without an answer the report resolves after `auto_resolve_hours`, unless the passenger asks support to step in first.

### Confirm a Lost Item Received (Passenger)

```
POST /lost-items/{lostItem}/received
```

**Auth:** the report's passenger. From `driver_confirmed` or `returned`. → `resolved`, and the return fee (if any) is collected. **Response 200** has `return_fee.status` `charged` (from the wallet) or `balance_due`.

### Cancel a Lost Item Report (Passenger)

```
POST /lost-items/{lostItem}/cancel
```

**Auth:** the report's passenger. Optional `reason` (max 500, saved as `resolution_notes`). Only while the status is `reported`, `driver_notified` or `driver_denied`. After the driver has the item: `422` `"Your driver has the item, so this report cannot be cancelled. Confirm you got it back, or ask support to close it."`. The driver is told.

### Ask Support to Help (Passenger)

```
POST /lost-items/{lostItem}/escalate
```

**Auth:** the report's passenger. **Rate limit:** 5 per minute. Body: `reason` (required, 5–500 characters). Once per report, on an open report not already with support. **Response 422:** `"Our support team is already looking into this report."` or `"This report is closed."`.

### Send a Message on a Lost Item Report

```
POST /lost-items/{lostItem}/messages
```

**Auth:** the report's passenger or driver (`404` otherwise). **Rate limit:** 30 per minute. Body: `body` (required, max 1000). The other side gets a `lost_item_message` push.

**Response 201:**
```json
{
  "message": "Message sent.",
  "lost_item_message": {
    "id": "01a14c5e-08ae-715b-9814-00fa5de77b63",
    "sender_role": "passenger",
    "sender_name": "Ada",
    "is_mine": true,
    "body": "Thank you! Can we meet at Ikeja City Mall at 5pm?",
    "created_at": "2026-10-19T10:05:00.000000Z"
  }
}
```

**Response 422:** `"This report is closed. Contact support if you still need help."`, or for the passenger when the trip has no driver to reach, `"The driver cannot be reached through the app. Our support team will contact you."`.

### Lost Item Queue (Admin)

```
GET /admin/lost-items
GET /admin/lost-items/{lostItem}
```

**Auth:** admin role `support` or `operations` (and `super_admin`). Reports waiting on support (open and escalated) come first, then the newest. The detail includes every message and internal note.

| Query | Description |
|-------|-------------|
| status | One status or several, comma-separated (`driver_notified,driver_denied`) |
| needs_attention | `1`: open reports support has to act on (escalated, or `reported` with no driver) |
| open | `1` open only, `0` closed only |
| category, city_id, ride_id, passenger_id, driver_id | Filters |
| search | Report or trip reference, or text in the description |
| from, to | Created between these dates |
| per_page | 1–100 (default 15) |

**Response 200:**
```json
{
  "lost_items": [ { "reference_number": "LF-000002", "viewer_role": "support", "status": "driver_denied", "escalated": true, "escalation_source": "passenger", "passenger": { "...": "full user" }, "driver": { "...": "full user with vehicle" }, "resolved_by": null } ],
  "meta": { "current_page": 1, "last_page": 2, "per_page": 15, "total": 18 },
  "summary": {
    "open": 7,
    "needs_attention": 2,
    "by_status": { "driver_notified": 3, "driver_denied": 2, "driver_confirmed": 2, "resolved": 9, "unresolved": 2 }
  }
}
```

### Intervene on a Lost Item Report (Admin)

```
POST /admin/lost-items/{lostItem}/intervene
```

**Auth:** admin role `support` or `operations`. Audit-logged.

| `action` | Other fields | Effect |
|----------|--------------|--------|
| `note` | `body` (required) | Internal note, seen only by support. **201** |
| `message` | `body` (required) | Message to passenger and driver from "E-tiGo Support"; both get a push. Also allowed on closed reports. **201** |
| `mark_found` | `notes` (optional, saved as an internal note) | Support reached the driver, or the item is at the office → `driver_confirmed`; the passenger is told |
| `resolve` | `notes`, `waive_return_fee` (boolean) | The item is back with the passenger → `resolved`. The return fee is collected unless waived (only when the driver had the item) |
| `close` | `closure_reason` (required), `notes` | Closed without the item being returned → `unresolved`; the passenger (and the driver, if involved) is told |

`note` and `message` return `{ "message", "lost_item_message", "lost_item" }`; the others return `{ "message", "lost_item" }`. **Response 422:** a closed report cannot be resolved, closed or marked found again; missing `body` or `closure_reason`.

### Lost & Found Settings (Admin)

```
GET /admin/settings/lost-and-found
PUT /admin/settings/lost-and-found
```

**Auth:** admin role `operations` (and `super_admin`). Changes are audit-logged (`lost_and_found_settings.updated`). Defaults come from `config/lost_and_found.php` (`LOST_AND_FOUND_*` environment variables).

| Field | Type | Default | Range | Description |
|-------|------|---------|-------|-------------|
| enabled | boolean | `true` | — | Accept new reports. Open ones carry on |
| report_window_days | integer | 30 | 1–90 | Days after the trip an item can be reported |
| max_open_reports_per_passenger | integer | 3 | 1–20 | Open reports per passenger |
| driver_response_hours | integer | 24 | 1–168 | Hours before a report the driver has not answered goes to support (and the driver is reminded) |
| return_deadline_days | integer | 7 | 1–60 | Days after the driver confirms before an unreturned item goes to support |
| auto_resolve_hours | integer | 72 | 1–336 | Hours after "returned" before the report resolves without the passenger's confirmation |
| denied_auto_close_days | integer | 7 | 1–60 | Days a "not found" report stays open for the passenger to ask support |
| share_contact_details | boolean | `true` | — | Show passenger and driver each other's phone number once the driver has the item |
| return_fee | number | 0 | 0–100000 | NGN the passenger pays when the item comes back, all of it to the driver. 0 = off. Applies to items confirmed after the change |

**PUT** accepts any subset of the fields. **Response 200:**
```json
{
  "message": "Lost & Found settings updated.",
  "settings": {
    "enabled": true,
    "report_window_days": 30,
    "max_open_reports_per_passenger": 3,
    "driver_response_hours": 24,
    "return_deadline_days": 7,
    "auto_resolve_hours": 72,
    "denied_auto_close_days": 7,
    "share_contact_details": true,
    "return_fee": 1500
  }
}
```

---

## Driver Scheduling (Phase 2)

Phase 2 OQ-20 (engineering recommendation of 16 Oct 2026, not yet client-confirmed). Two separate things:

- **Availability plans — every driver, voluntary.** A driver says when and where they expect to drive. There is no penalty for changing or skipping a plan, it **never** stops a driver going online, and passengers never see it. Operations use the plans to spot gaps.
- **Shifts — only fleet cars in shift mode** (E-tiGo cars shared by two or more drivers). Drivers of their own car, or of a fleet car not in shift mode, have no shifts and drive when they like.

**How a shared car works.** The car's `driver_id` is the driver holding it now. When a shift driver goes online inside their window, the car passes to them, provided the previous driver is offline and not on a trip (else `toggle-online` returns 422 naming who has it). A car on hire purchase (active fleet agreement) cannot be in shift mode, and a driver with their own car cannot be given shifts.

**Shift rules** (settings, `/admin/settings/driver-scheduling`):

| Rule | Default | Setting |
|---|---|---|
| Driver can go online | From 15 min before the start until the end | `shift_online_lead_minutes` |
| New ride offers stop | 15 min before the end (a trip running past the end finishes) | `shift_offer_cutoff_minutes` |
| Not online after the start | Shift marked `missed` after 30 min; driver and operations told; no fine; can still start late | `shift_missed_after_minutes` |
| Shift length | 60 min – 12 h, up to 30 days ahead | `shift_min_minutes`, `shift_max_hours`, `shift_max_advance_days` |
| Scheduled-ride reservations | Only for pickups inside the driver's own shifts, in that shift car's class | — |
| Overlaps | Refused on the same car and for the same driver (cancelled shifts free their slot) | — |

Times are full ISO 8601 date-times (send a UTC offset); overnight shifts such as 22:00–06:00 work. Responses are in UTC.

**Shift status:** `scheduled` → `active` (driver went online in the window) → `completed`; `scheduled` → `missed` (may still become `active`); `scheduled` / `missed` → `cancelled`.

**Every 5 minutes** (`ProcessDriverShiftsJob`): marks missed shifts (push `driver_shift_missed`, one email to operations per run), tells the driver when offers stop (`driver_shift_ending`), tells the next driver when the car is late because the previous driver is on a trip (`shift_handover_delayed`), and ends shifts past their end (`driver_shift_ended`; the driver is set offline unless on a trip — the shift then ends on a later run — or going straight into their next shift on the same car).

### Shift object

```json
{
  "id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
  "status": "scheduled",
  "status_label": "Scheduled",
  "starts_at": "2026-10-20T05:00:00+00:00",
  "ends_at": "2026-10-20T13:00:00+00:00",
  "online_from": "2026-10-20T04:45:00+00:00",
  "offers_stop_at": "2026-10-20T12:45:00+00:00",
  "started_at": null,
  "ended_at": null,
  "missed_at": null,
  "cancelled_at": null,
  "cancellation_reason": null,
  "notes": "Day shift",
  "vehicle": { "id": "…", "plate_number": "LAG-100AA", "make": "Toyota", "model": "Corolla", "colour": "White" },
  "created_at": "2026-10-19T08:00:00+00:00"
}
```

Admin responses add `driver` (`id`, `reference_number`, `name`, `phone`, `is_online`), `vehicle.held_by_driver_id` and `created_by_admin_id`.

### My Shifts (Driver)
```
GET /driver/shifts
```
**Auth:** driver. Query: `include_past` (boolean; default lists shifts not yet ended and not cancelled; `1` lists the last 30 days, every status). Paginated (30).

**Response 200:**
```json
{
  "shift_driver": true,
  "current_shift": { "...": "shift object, or null outside a shift window" },
  "rules": { "online_lead_minutes": 15, "offer_cutoff_minutes": 15, "missed_after_minutes": 30 },
  "shifts": [ { "...": "shift object" } ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 30, "total": 5 }
}
```

`shift_driver` is `false` for drivers whose car is not shared; they can ignore shifts.

**Going online** (`POST /driver/toggle-online`) for a shift driver returns 422 reasons such as:
- `"Your car is shared on shifts. Your next shift starts Tue 20 Oct, 6:00 AM; you can go online from Tue 20 Oct, 5:45 AM."`
- `"Your car is shared on shifts and you have no shift now. Contact fleet operations for your next shift."`
- `"LAG-100AA is still with Kemi, who is finishing a trip. You can go online once the car is handed over."`

and on success adds `shift` (the shift object).

### Availability Plans (Driver)
```
GET    /driver/availability-plans
POST   /driver/availability-plans
DELETE /driver/availability-plans/{plan_id}
```
**Auth:** driver (approved, with a city). `GET` lists plans not yet ended, soonest first.

**POST body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| starts_at | ISO 8601 | Yes | Now or later; up to 14 days ahead (`availability_max_advance_days`) |
| ends_at | ISO 8601 | Yes | 30 min – 12 h after `starts_at` |
| area_label | string | No | 2–60 characters, e.g. "Lekki". Spaces and case are normalised ("lekki " → "Lekki") so the same area groups together |
| repeat_weekly | integer | No | 0–3: also create the same plan on each of the next N weeks (all or nothing) |

Overlapping one of the driver's own plans → 422 on `starts_at`. At most 42 upcoming plans (`availability_max_upcoming`). Throttled to 20 per minute.

**Response 201:**
```json
{
  "message": "2 plans saved.",
  "plans": [
    { "id": "…", "city_id": "…", "area_label": "Lekki Phase 1", "starts_at": "2026-10-23T17:00:00+00:00", "ends_at": "2026-10-23T20:00:00+00:00", "created_at": "…" },
    { "id": "…", "city_id": "…", "area_label": "Lekki Phase 1", "starts_at": "2026-10-30T17:00:00+00:00", "ends_at": "2026-10-30T20:00:00+00:00", "created_at": "…" }
  ]
}
```

`DELETE` returns 200 `{"message": "Plan removed."}`; 404 for another driver's plan; 422 once the plan has ended.

### Admin — Shift Mode for a Fleet Car
```
PUT /admin/fleet-vehicles/{vehicle_id}/shift-mode
```
**Auth:** operations. Body: `shift_mode` (boolean). On: fleet cars only, not on an active hire-purchase agreement. Off: refused while the car has upcoming shifts (cancel them first). Returns the vehicle (with `shift_mode`). Audit: `vehicle_shift_mode_on` / `vehicle_shift_mode_off`. A car in shift mode cannot be put on a hire-purchase agreement.

### Admin — Driver Shifts
```
GET  /admin/driver-shifts
POST /admin/driver-shifts
GET  /admin/driver-shifts/{shift_id}
PUT  /admin/driver-shifts/{shift_id}
POST /admin/driver-shifts/{shift_id}/cancel
```
**Auth:** operations.

**List** query: `vehicle_id`, `driver_id`, `status` (comma-separated, e.g. `scheduled,missed`), `from`, `to` (default: yesterday to 14 days ahead; shifts overlapping the range). Paginated (50), soonest first; `summary` counts per status in the range.

**Create** body:

| Field | Type | Required | Notes |
|---|---|---|---|
| driver_id | UUID | Yes | Approved driver with no car of their own |
| vehicle_id | UUID | Yes | Fleet car in shift mode, not blocked by expired papers |
| starts_at, ends_at | ISO 8601 | Yes | 60 min – 12 h; ends in the future; starts within 30 days |
| notes | string | No | Max 500 |
| repeat_daily_for | integer | No | 0–13: the same shift on each of the next N days (a rota; all or nothing) |

Response 201 `{"message": "5 shifts created.", "shifts": [...]}`. The driver gets one `driver_shift_assigned` push.

**Update:** before the shift starts (`scheduled` or `missed`): `driver_id`, `vehicle_id`, `starts_at`, `ends_at`, `notes`; a missed shift moved to a later start is `scheduled` again. In progress (`active`): only `ends_at` (to extend it or end it early; must be in the future) and `notes`. Other states: 422. Overlaps are re-checked. A new driver: the old one gets `driver_shift_cancelled`, the new one `driver_shift_assigned`; otherwise `driver_shift_updated`.

**Cancel** body: `reason` (required, 3–500). Only `scheduled` or `missed` shifts (end an active one by changing `ends_at`). The driver gets `driver_shift_cancelled`.

After a move, reassignment or cancellation, the driver's scheduled-ride reservations no longer inside one of their shifts are released (reason `shift_changed`; the passenger is told the booking is still on).

Audit events: `driver_shift_created`, `driver_shift_updated`, `driver_shift_cancelled`, `driver_shift_missed`, `driver_shift_completed`, `shift_vehicle_handover`, `driver_set_offline_shift_ended`.

### Admin — Availability Coverage
```
GET /admin/availability-coverage?city_id={city_id}&from={iso}&to={iso}
```
**Auth:** operations. `from` defaults to now, `to` to 7 days after `from`; up to 14 days (else 422). Hours are Lagos hours.

**Response 200:**
```json
{
  "coverage": {
    "city": { "id": "…", "name": "Lagos" },
    "timezone": "Africa/Lagos",
    "from": "2026-10-23T18:00:00+01:00",
    "to": "2026-10-23T22:00:00+01:00",
    "approved_drivers": 214,
    "drivers_with_plans": 3,
    "areas": ["Ikeja", "Lekki"],
    "peak_hour": { "starts_at": "2026-10-23T19:00:00+01:00", "planned_drivers": 3 },
    "lowest_hour": { "starts_at": "2026-10-23T18:00:00+01:00", "planned_drivers": 1 },
    "hours": [
      {
        "starts_at": "2026-10-23T19:00:00+01:00",
        "ends_at": "2026-10-23T20:00:00+01:00",
        "planned_drivers": 3,
        "areas": [
          { "area_label": "Lekki", "planned_drivers": 2 },
          { "area_label": "Ikeja", "planned_drivers": 1 }
        ]
      }
    ]
  }
}
```

A plan with no area counts in `planned_drivers` and appears under `area_label: null`. Only plans of drivers who are approved and in this city now count: a suspended driver's plans, or plans left behind by a driver moved to another city, are not cover.

### Admin — Driver Scheduling Settings
```
GET /admin/settings/driver-scheduling
PUT /admin/settings/driver-scheduling
```
**Auth:** operations. Any subset of: `shifts_enabled` (bool, default true; off: shift-mode cars stop enforcing their timetable), `shift_online_lead_minutes` (0–120, 15), `shift_offer_cutoff_minutes` (0–120, 15), `shift_missed_after_minutes` (5–240, 30), `shift_min_minutes` (15–720, 60), `shift_max_hours` (1–24, 12), `shift_max_advance_days` (1–90, 30), `availability_max_advance_days` (1–60, 14), `availability_min_minutes` (15–720, 30), `availability_max_hours` (1–24, 12), `availability_max_upcoming` (1–200, 42). The shortest shift or plan cannot be longer than the longest (422). Audit: `driver_scheduling_settings.updated`.

---

## Vehicle Maintenance (Phase 2)

Phase 2 OQ-19 (engineering recommendation of 16 Oct 2026, not yet client-confirmed): general items for every vehicle, plus battery health for electric and plug-in hybrid cars, chosen by fuel type. Records belong to the **vehicle**, so they stay with a car that changes hands.

| Item (`type`) | Applies to | Due | Source |
|---|---|---|---|
| `service` | All | 5,000 km or 3 months after the last one, whichever first | Approved maintenance records (odometer required) plus trip distance since the last odometer reading |
| `tyres_brakes` | All | 6 months | Approved records |
| `insurance` | All | Expiry date | The vehicle's approved `insurance_certificate` |
| `roadworthiness` | All | Expiry date | The vehicle's approved `roadworthiness_certificate` |
| `vehicle_licence` | All | Expiry date | The vehicle's approved `vehicle_licence` |
| `battery_health` | `electric`, `plug_in_hybrid` | 12 months | Approved records |

Intervals are settings per item and per fuel type. A non-plug-in `hybrid` gets the general items only; correcting the fuel type adds or archives battery health.

**Item status:** `unknown` (no record yet), `ok`, `due_soon` (within 14 days or 500 km), `overdue` (service, tyres, battery — **warning only, never blocks**), `expired` (legal papers).

**Expired papers.** Insurance, roadworthiness or the vehicle licence past its expiry date: a **7-day grace period** (`legal_grace_days`) with a warning (`vehicle_notice` on going online), then the car is **off the road** (`is_blocked`): the driver cannot go online, gets no offers, cannot be assigned by operations, an idle online driver is set offline, and their reserved scheduled pickups are released (reason `vehicle_blocked`, passenger told). For a shared (shift-mode) car, every shift driver loses the reserved pickups that fall in their shifts on that car, not only the driver holding it; pickups covered by a shift on another car stay. **A trip in progress is never ended**; the driver finishes it and gets no new offers. The block lifts as soon as a renewed paper is **approved** (a pending upload does not lift it). Papers with no record yet (e.g. the new roadworthiness and vehicle licence types for older cars) only remind, never block.

**Daily at 06:00 Lagos** (`ProcessVehicleMaintenanceJob`): recomputes every car, starts or lifts blocks, sends the driver one `vehicle_maintenance_due` push per car when items change state (once per change), and emails operations one digest of fleet cars that are overdue or expired (plus an alert when a fleet car is blocked).

**Who records work:** drivers of their **own car** submit records with proof, approved by operations; **fleet cars** are recorded by operations (drivers get 403). Booking a workshop still uses [Driver — Repairs](#driver--repairs); a record can link the `REP-` request (`driver_repair_id`).

### Maintenance item object

```json
{
  "id": "…",
  "type": "insurance",
  "label": "Insurance",
  "is_legal_paper": true,
  "status": "expired",
  "status_label": "Expired",
  "due_at": "2026-10-13",
  "days_until_due": -6,
  "due_odometer_km": null,
  "km_until_due": null,
  "last_done_at": "2025-10-14",
  "last_odometer_km": null,
  "blocks_driving": false,
  "blocks_driving_from": "2026-10-21",
  "message": "Insurance expired on 13 Oct 2026; upload the renewed paper by 20 Oct 2026 to keep driving."
}
```

`message` is ready to show the driver. `blocks_driving_from` is set for an expired paper still in its grace period. For `service`, `due_odometer_km` / `km_until_due` use the estimated odometer (last reading + trip km since).

### Vehicle Status (Driver)
```
GET /driver/vehicle/status
```
**Auth:** driver with a vehicle (404 otherwise).

**Response 200:**
```json
{
  "vehicle": {
    "id": "…", "plate_number": "ABC-123XY", "make": "Toyota", "model": "Corolla",
    "fuel_type": "electric", "is_fleet": false, "shift_mode": false,
    "odometer_km": 42000, "odometer_read_at": "2026-09-01T22:59:59+00:00", "estimated_odometer_km": 43180
  },
  "can_drive": true,
  "block_reason": null,
  "records_managed_by": "driver",
  "items": [ { "...": "maintenance item object" } ],
  "charging": {
    "battery_level_percent": 64,
    "battery_level_at": "2026-10-19T08:00:00+00:00",
    "active_reservation": { "id": "…", "status": "reserved", "station": { "id": "…", "name": "Lekki Hub" }, "confirmed_slot": "09:30" }
  },
  "pending_records": [ { "...": "maintenance record object" } ]
}
```

`charging` is `null` for cars without a charging port. `records_managed_by` is `operations` for fleet cars.

### Maintenance Records (Driver)
```
GET  /driver/vehicle/maintenance-records
POST /driver/vehicle/maintenance-records
Content-Type: multipart/form-data
```
**Auth:** driver. `GET` lists the car's records, newest first (paginated, 20). `POST` (own cars only; 403 for fleet cars; throttled 10/min):

| Field | Type | Required | Notes |
|---|---|---|---|
| item_type | string | Yes | `service`, `tyres_brakes`, `battery_health` (plug-in cars only) |
| performed_at | date | Yes | Today or earlier, within 2 years |
| odometer_km | integer | For `service` | Must not be lower than an earlier reading nor higher than a later one |
| workshop | string | No | Max 120 |
| notes | string | No | Max 1,000 |
| driver_repair_id | UUID | No | One of the driver's `REP-` repair requests |
| proof | file | Yes | Receipt or workshop report: JPG, PNG or PDF, max 10 MB |

Response 201 with `record` (`status: pending`). The driver gets `maintenance_record_reviewed` when operations approve or reject it.

**Maintenance record object:**
```json
{
  "id": "…", "vehicle_id": "…", "item_type": "service", "item_label": "Routine service",
  "performed_at": "2026-09-01", "odometer_km": 42000, "workshop": "Mainland Motors", "notes": null,
  "proof_url": "https://…", "driver_repair_id": null,
  "status": "pending", "status_label": "Awaiting review", "rejection_reason": null,
  "reviewed_at": null, "created_at": "…"
}
```

Admin responses add `vehicle` (`id`, `plate_number`, `is_fleet`) and `submitted_by` (`id`, `name`, `type`).

### Odometer Reading (Driver)
```
POST /driver/vehicle/odometer
```
Body: `odometer_km` (integer, 0–2,000,000). A reading lower than the last one is refused (422 on `odometer_km`). Resets the trip-distance count. Response 200: `message`, `odometer_km`, `odometer_read_at`. Throttled 10/min.

### Admin — Vehicle Maintenance Overview
```
GET /admin/vehicle-maintenance
```
**Auth:** operations. Items needing attention across all cars, worst first (expired, overdue, due soon). Query: `status` (comma-separated; default `due_soon,overdue,expired`; add `unknown` for cars with no record), `type`, `fleet_only`, `blocked_only`, `search` (plate, make, model). Paginated (30).

**Response 200:**
```json
{
  "items": [
    {
      "...": "maintenance item object",
      "vehicle": { "id": "…", "plate_number": "FLT-001AA", "make": "Toyota", "model": "Corolla", "is_fleet": true, "is_blocked": true, "driver": { "id": "…", "name": "Bayo Akinola" } }
    }
  ],
  "summary": { "ok": 120, "unknown": 40, "due_soon": 6, "overdue": 3, "expired": 1, "blocked_vehicles": 1, "pending_records": 2 },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 30, "total": 10 }
}
```

### Admin — Vehicle Maintenance (One Car)
```
GET  /admin/vehicles/{vehicle_id}/maintenance
POST /admin/vehicles/{vehicle_id}/maintenance-records
POST /admin/vehicles/{vehicle_id}/documents
```
**Auth:** operations. Works for any car (own or fleet).

- `GET` → `vehicle` (with `shift_mode`, `odometer_km`, `estimated_odometer_km`, `is_blocked`, `block_reason`), `items`, `records` (last 50), `documents` (the car's papers).
- `POST …/maintenance-records` → same fields as the driver's, `proof` optional; the record is approved at once. Response 201 with `record` and the updated `items`. Audit `maintenance_record_added`.
- `POST …/documents` (multipart) → `type` (`vehicle_registration`, `insurance_certificate`, `roadworthiness_certificate`, `vehicle_licence`), `document` (file), `expires_at` (required except for `vehicle_registration`). Approved at once; replaces the car's earlier paper of that type. Response 201 with `document` and `items`. Audit `vehicle_document_uploaded_by_admin`.

### Admin — Maintenance Records Queue
```
GET  /admin/vehicle-maintenance/records?status=pending&vehicle_id=
POST /admin/vehicle-maintenance/records/{record_id}/review
```
**Auth:** operations. The queue lists `pending` records oldest first by default (`status` also `approved` / `rejected`). Review body: `action` (`approve` / `reject`), `rejection_reason` (required to reject). Only pending records; approving re-checks the odometer. Audit `maintenance_record_approved` / `maintenance_record_rejected`.

### Admin — Unlinked Vehicle Papers
```
GET  /admin/vehicle-documents/unlinked
POST /admin/vehicle-documents/{document_id}/link
```
**Auth:** operations. Papers are now linked to the vehicle. The Phase 2 migration linked each existing paper to its driver's car; papers it could not match (the driver had no car, or more than one) are listed here with the driver. Link body: `vehicle_id`. Audit `vehicle_document_linked`.

### Admin — Vehicle Maintenance Settings
```
GET /admin/settings/vehicle-maintenance
PUT /admin/settings/vehicle-maintenance
```
**Auth:** operations. Any subset of:

| Field | Default | Notes |
|---|---|---|
| enabled | true | Off: items are still tracked and shown; no reminders, no block |
| intervals | `{"service": {"km": 5000, "months": 3}, "tyres_brakes": {"km": null, "months": 6}, "battery_health": {"km": null, "months": 12}}` | Merged item by item; each item needs `km` (500–100,000) or `months` (1–60) |
| fuel_type_intervals | `{}` | Per fuel type overrides, e.g. `{"electric": {"service": {"km": 10000, "months": 6}}}`; merged per fuel type and item (other stored overrides are kept); each needs `km` or `months` |
| due_soon_days | 14 | 0–90 |
| due_soon_km | 500 | 0–5,000 |
| legal_grace_days | 7 | 0–60 |
| block_on_expired_papers | true | Off: expired papers only warn |

Changes apply on each car's next check (daily run, opening the car, going online). Audit: `vehicle_maintenance_settings.updated`.

---

## Admin — Overview

The console's landing page ("Good morning, Tessy"): headline cards, a "needs your attention" row, recent activity, a live operations panel and the top-bar search. The greeting uses `user.first_name` from `GET /admin/auth/me`; the bell uses the existing `GET /notifications/unread-count`.

**Middleware:** `auth:sanctum`, `user.type:admin` (live operations also `admin.role:operations`)

**Who sees what:** every admin can open the overview and search. Each card or result group is `null` when the admin's role cannot open the screen behind it, so hide those rather than showing zero.

| Card / group | Shown to (Super Admin always) | Opens |
|--------------|-------------------------------|-------|
| `stats.active_rides`, `stats.drivers_online`, `attention.unmatched_requests`, live operations, search `rides` | Operations | Live Monitoring, Manual Assignment |
| `stats.open_disputes`, search `disputes` | Support, Support Lead, Safety | Ride Disputes (Support roles count the support queue only) |
| `stats.revenue` | Operations, Finance | Operational Reporting |
| `attention.safety_alerts`, search `sos_incidents` | Safety | Safety & SOS |
| `attention.compliance_reviews`, search `offline_flags` | Operations, Safety | Anti-offline Compliance |
| `attention.pending_kyc`, search `drivers` | Operations, Safety | Driver & Passenger → pending review |
| search `passengers` | Support, Support Lead, Safety | Driver & Passenger |

### Get Overview
```
GET /admin/overview
```

| Param | Type | Description |
|-------|------|-------------|
| activity_limit | integer | Recent activity items, 1–20. Default 5 |

**What each figure means:**

| Field | Meaning |
|-------|---------|
| `stats.active_rides.count` | Rides from `requested` to `in_progress`, all cities |
| `stats.drivers_online.count` / `on_trip` | Approved drivers online / of those, holding a matched-to-in-progress ride |
| `stats.drivers_online.utilisation_pct` | `on_trip ÷ count × 100`, one decimal |
| `*.change_pct` (rides, drivers) | Against a snapshot taken within 10 minutes of this time yesterday (`compared_at`). `null` when there is none, e.g. for the first day after release. Snapshots are taken every 5 minutes |
| `stats.open_disputes.count` / `overdue` | Open or under-review cases / those that missed their next target (the queue's "Overdue") |
| `stats.revenue` | Gross fares (`final_fare_amount`) of rides **completed today** since midnight Lagos time, and `change_pct` against the same stretch of yesterday. Same basis as the revenue report, so not E-tiGo's commission |
| `attention.safety_alerts.count` / `critical` | Active SOS incidents / those not yet picked up (triggered, check-in sent, escalated) |
| `attention.compliance_reviews.count` / `high_severity` | Offline trip flags not yet resolved / of those, level 3 (pending deactivation) |
| `attention.pending_kyc.count` | Drivers in `pending_review` |
| `attention.unmatched_requests.count` | Rides with no driver that can still be assigned by hand (the Manual Assignment "awaiting driver" count) |
| `change_pct` rule | From zero, any rise is `100`; no change is `0` |

**Response 200:**
```json
{
  "stats": {
    "active_rides": { "count": 248, "change_pct": 12.4, "compared_at": "2026-10-20T09:05:00+00:00" },
    "drivers_online": { "count": 1482, "on_trip": 1008, "utilisation_pct": 68.0, "change_pct": 12.4, "compared_at": "2026-10-20T09:05:00+00:00" },
    "open_disputes": { "count": 18, "overdue": 3 },
    "revenue": {
      "period": "today",
      "timezone": "Africa/Lagos",
      "currency": "NGN",
      "amount": 84600000,
      "completed_rides": 1946,
      "change_pct": 6.7,
      "previous_amount": 79287722.5
    }
  },
  "attention": {
    "safety_alerts": { "count": 4, "critical": 3 },
    "compliance_reviews": { "count": 11, "high_severity": 6 },
    "pending_kyc": { "count": 3 },
    "unmatched_requests": { "count": 3 }
  },
  "recent_activity": [
    {
      "id": "01a16011-6475-722d-9f78-2c12c7b12326",
      "event": "driver_approved",
      "category": "driver",
      "title": "Driver verification approved",
      "subtitle": "Amina Yusuf · Toyota Corolla · Lagos",
      "subject": { "type": "driver", "id": "01a16011-643a-7304-b66c-5ca393151f5c", "reference_number": "D-000214" },
      "actor": { "id": "01a16011-6430-7247-bacc-4094a3cf30a3", "name": "Tessy Okoro", "type": "admin" },
      "created_at": "2026-10-21T09:01:14+00:00"
    },
    {
      "id": "01a16011-6473-7254-9e62-871c7423989d",
      "event": "pricing_config_created",
      "category": "pricing",
      "title": "Pricing version published",
      "subtitle": "Abuja · Standard · v4",
      "subject": { "type": "pricing_config", "id": "01a16011-6470-739b-84db-2b37eac7e7c4", "reference_number": null },
      "actor": { "id": "01a16011-6430-7247-bacc-4094a3cf30a3", "name": "Tessy Okoro", "type": "admin" },
      "created_at": "2026-10-21T08:57:02+00:00"
    }
  ],
  "can_view_live_operations": true,
  "generated_at": "2026-10-21T09:05:15+00:00"
}
```

A Finance admin gets the same shape with `stats.revenue` filled and the other cards `null`, `can_view_live_operations: false`, and only finance events in `recent_activity`.

**Recent activity** is a curated slice of the audit log, newest first, limited to areas the admin can open (dispute events also follow the queue rules). `category` drives the icon. `subject.type` + `subject.id` tell you which detail page to open. `actor` is `null` for system events. `title` reads "Dispute assigned to you" when the viewer is the assignee.

| `event` | `category` | `title` | `subject.type` |
|---------|-----------|---------|----------------|
| `driver_approved`, `driver_rejected`, `driver_suspended`, `driver_reactivated` | `driver` | Driver verification approved / application rejected / suspended / reactivated | `driver` |
| `dispute_filed`, `dispute_assigned`, `dispute_resolved` | `dispute` | New dispute filed / Dispute assigned (to you) / Dispute resolved | `dispute` |
| `sos_triggered`, `sos_resolved` | `safety` | SOS raised / SOS resolved | `sos_incident` |
| `offline_trip_flagged` | `compliance` | Offline trip flagged | `offline_flag` |
| `ride_manually_assigned` | `ride` | Ride assigned manually | `ride` |
| `pricing_config_created`, `surge_rule_created` | `pricing` | Pricing version published / Surge rule created | `pricing_config`, `surge_rule` |
| `promo_code_created` | `promo` | Promo created | `promo_code` |
| `city_created` | `city` | City added | `city` |
| `ev_station_created` | `ev_station` | EV station added | `ev_station` |
| `payout.approved`, `adjustment.approved`, `commission.updated` | `finance` | Driver payout approved / Wallet adjustment approved / Commission rate updated | `payout`, `adjustment`, `commission_config` |
| `admin_invited` | `admin` | Admin invited | `admin_invitation` |

Per-trip events such as "ride completed" are not listed: at thousands a day they would push everything else off the list, and the Revenue card already counts them.

**Refreshing:** poll every 30–60 seconds. For live ride movement, use the Live Monitoring WebSocket (`admin.rides`).

### Live Operations Panel
```
GET /admin/overview/live-operations
```

Map markers and the latest trips for one city. Operations and Super Admin only (`403` otherwise).

| Param | Type | Description |
|-------|------|-------------|
| city_id | string (UUID) | City to show. Default: the city with the most active rides |
| rides_limit | integer | Trips in the list, 1–20. Default 3 |

- `cities` lists every city with active rides, busiest first, for the city switcher.
- `markers` holds up to 300 active rides, newest first. `markers_truncated` is `true` when there are more (`summary.active_rides` is still the full count). The position is the driver's live location when they have one (`position_source: "driver"`), otherwise the pickup point (`"pickup"`).
- `needs_attention` (red pin) is set when the ride has an active SOS (`"sos"`) or the driver is assigned but their app has sent no location for 5+ minutes (`"driver_location_missing"`). `summary.needs_attention` counts these among the markers.
- `city` is `null` and every list is empty when nothing is moving and no `city_id` was given.

**Response 200:**
```json
{
  "city": { "id": "01a16011-6419-73fe-a8a8-df4f726d6c02", "name": "Lagos" },
  "cities": [
    { "id": "01a16011-6419-73fe-a8a8-df4f726d6c02", "name": "Lagos", "active_rides": 168 },
    { "id": "01a16011-7713-71b0-9d2a-0f0c7b1f1a90", "name": "Abuja", "active_rides": 80 }
  ],
  "summary": { "active_rides": 168, "needs_attention": 1 },
  "markers": [
    {
      "ride_id": "01a16011-6453-714a-9f55-408eb5563d86",
      "reference_number": "TRP-NG-000001",
      "status": "in_progress",
      "lat": 6.5012,
      "lng": 3.379,
      "position_source": "driver",
      "needs_attention": false
    },
    {
      "ride_id": "01a16011-6459-737e-ba4f-8b7a00b0ba9e",
      "reference_number": "TRP-NG-000002",
      "status": "searching",
      "lat": 6.4474,
      "lng": 3.4723,
      "position_source": "pickup",
      "needs_attention": true
    }
  ],
  "markers_truncated": false,
  "rides": [
    {
      "id": "01a16011-6453-714a-9f55-408eb5563d86",
      "reference_number": "TRP-NG-000001",
      "status": "in_progress",
      "status_label": "In Progress",
      "pickup_address": "Bodija Market, Ibadan Road, Yaba",
      "destination_address": "Ring Road, Surulere",
      "passenger": { "id": "01a16011-6433-70cb-a7fb-8eb698001ee8", "name": "Kemi Balogun" },
      "driver": { "id": "01a16011-6438-71f7-aa44-0818c53512dc", "name": "Seyi Adeyemi" },
      "needs_attention": false,
      "attention_reasons": [],
      "requested_at": "2026-10-21T09:00:14+00:00"
    },
    {
      "id": "01a16011-6459-737e-ba4f-8b7a00b0ba9e",
      "reference_number": "TRP-NG-000002",
      "status": "searching",
      "status_label": "Searching for Driver",
      "pickup_address": "Admiralty Way, Lekki Phase 1",
      "destination_address": "Victoria Island",
      "passenger": { "id": "01a16011-6433-70cb-a7fb-8eb698001ee8", "name": "Kemi Balogun" },
      "driver": null,
      "needs_attention": true,
      "attention_reasons": ["sos"],
      "requested_at": "2026-10-21T08:58:40+00:00"
    }
  ],
  "generated_at": "2026-10-21T09:05:15+00:00"
}
```

"Open live view" goes to Live Monitoring (`GET /admin/monitoring/active-rides?city_id=…`).

### Global Search
```
GET /admin/search?q={term}
```

Top-bar search across trips, people and cases. Matching is case-insensitive "contains". `%` and `_` are matched literally. In each group an exact reference match comes first (so `TRP-NG-00001` is not pushed out by `TRP-NG-000010`–`19`), then the newest. Rate limit: 60 per minute.

| Param | Type | Description |
|-------|------|-------------|
| q | string | Required, 2–100 characters |
| limit | integer | Results per group, 1–10. Default 5 |

| Group | Matches on | Then newest by |
|-------|-----------|-----------------|
| `rides` | Trip reference, passenger or driver name / phone / email / reference | requested |
| `drivers` | Driver reference (`D-…`), name, phone, email, user reference | joined |
| `passengers` | Name, phone, email, reference (`P-…`) | joined |
| `disputes` | Dispute reference (`DPT-…`), trip reference, reporter name / phone / email | filed |
| `sos_incidents` | Incident reference (`SOS-…`), trip reference, passenger / driver / raiser name or phone | raised |
| `offline_flags` | Flag reference, trip reference, driver name / phone / driver ID | flagged |

**Response 200:**
```json
{
  "query": "kemi",
  "results": {
    "rides": [
      {
        "id": "01a16011-6453-714a-9f55-408eb5563d86",
        "reference_number": "TRP-NG-000001",
        "status": "in_progress",
        "pickup_address": "Bodija Market, Ibadan Road, Yaba",
        "destination_address": "Ring Road, Surulere",
        "passenger_name": "Kemi Balogun",
        "driver_name": "Seyi Adeyemi",
        "created_at": "2026-10-21T09:00:14+00:00"
      }
    ],
    "drivers": [],
    "passengers": [
      {
        "id": "01a16011-6433-70cb-a7fb-8eb698001ee8",
        "reference_number": "P-000001",
        "name": "Kemi Balogun",
        "phone": "+2348012345678",
        "email": "kemi@example.com",
        "is_active": true
      }
    ],
    "disputes": [
      {
        "id": "01a16012-0b1e-7c3d-8a51-3f0e2d9b7c11",
        "reference_number": "DPT-000041",
        "status": "open",
        "category": "fare_dispute",
        "category_label": "Fare is incorrect",
        "queue": "support",
        "created_at": "2026-10-21T07:45:00+00:00"
      }
    ],
    "sos_incidents": [
      {
        "id": "01a16011-6463-7309-8dfc-ff9c19f07156",
        "reference_number": "SOS-000001",
        "status": "triggered",
        "ride_reference_number": "TRP-NG-000002",
        "created_at": "2026-10-21T08:59:15+00:00"
      }
    ],
    "offline_flags": []
  }
}
```

An empty array means "searched, nothing found"; `null` means "not searched for your role".

**Errors:** `422 VALIDATION_ERROR` when `q` is missing, shorter than 2 or longer than 100 characters; `429` over the rate limit.

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
| search     | string  | Up to 100 characters. Case-insensitive "contains" match on the trip reference, pickup address, destination address, passenger name, rider name (on a ride booked for someone else) and driver name. `%` and `_` are matched literally |
| sort_by    | string  | `requested_at` (default) or `status`. `status` sorts in ride order (`requested`, `searching`, `matched`, `driver_en_route`, `driver_arrived`, `in_progress`), not alphabetically, with the newest request first within a status |
| sort_dir   | string  | `desc` (default) or `asc` |
| per_page   | integer | Default 50, max 100 |

An unknown `sort_by` or `sort_dir` returns 422. `meta.total` counts the rides matching every param; `summary` follows `city_id` and `status` but not `search`, so the status counts stay put while someone types.

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

`no_driver_found` rides are listed (and counted) only for **24 hours** after they were requested (`MATCHING_MANUAL_ASSIGNMENT_WINDOW_HOURS`); after that the passenger has long gone and the ride drops off. For a [scheduled ride](#scheduled-rides) the 24 hours count from its pickup time, not from when it was booked. Rides still in `scheduled` status are not listed here; see [List Upcoming Scheduled Rides (Admin)](#list-upcoming-scheduled-rides-admin).

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

#### Pricing coverage warnings

Fare estimates only include a vehicle class in a city when the class is active, turned on for that city, and has a pricing config in effect (`effective_from` in the past). If none of a city's classes qualifies, `POST /rides/estimate` returns an empty `estimates` list with "No pricing available for this city."

To catch this when it is set up rather than when a passenger hits it, these admin responses include a `warnings` array of plain-English messages (empty when there is nothing to flag). They never block the change; show them to the admin after saving.

| Endpoint | Warns when |
|---|---|
| `POST /admin/pricing` | The vehicle class is turned off; the city doesn't offer the class; the config starts later and nothing is in effect until then |
| `GET /admin/cities/{id}`, `POST /admin/cities`, `PUT /admin/cities/{id}`, `PATCH /admin/cities/{id}/status`, `PUT /admin/cities/{id}/vehicle-classes` | Offered classes have no pricing in effect; an active city has no priced class at all, or offers no classes |
| `GET /admin/vehicle-classes/{id}`, `POST /admin/vehicle-classes`, `PUT /admin/vehicle-classes/{id}` | The class has no pricing in effect in some of its cities; turning it off leaves a city with nothing bookable |

`GET /admin/cities` also returns `unpriced_vehicle_classes_count` on each city, for a badge in the city list. The `pricing:check-coverage` command runs daily at 07:00 (Africa/Lagos) and logs a warning for every gap in an active city.

**Example (`POST /admin/pricing` for a class that is turned off):**
```json
{
  "message": "Pricing configuration created successfully.",
  "pricing_config": { "...": "..." },
  "warnings": [
    "Comfort is turned off, so this pricing won't be used until the vehicle class is turned on."
  ]
}
```

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
  },
  "warnings": []
}
```

`warnings` lists reasons this config won't be used for fares yet (see [Pricing coverage warnings](#pricing-coverage-warnings)).

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

### List Pricing Coverage Gaps
```
GET /admin/pricing/gaps
```
| Query Param      | Type          | Required | Description                   |
|------------------|---------------|----------|-------------------------------|
| city_id          | string (UUID) | No       | Only gaps in this city        |
| vehicle_class_id | string (UUID) | No       | Only gaps for this class      |

Lists every vehicle class that is offered in a city (active overall and turned on for the city) but has no pricing config in effect, so fare estimates leave it out. `next_effective_from` is the start of the earliest scheduled config, or `null` when none is scheduled. Sorted by city name, then class name.

**Response 200:**
```json
{
  "gaps": [
    {
      "city_id": "01a14034-2ff9-71fc-952d-b178fdfd604a",
      "city_name": "Lekki Test",
      "city_is_active": true,
      "vehicle_class_id": "7d6e10db-aedf-4442-9453-c9fb9b29778e",
      "vehicle_class_name": "Comfort",
      "next_effective_from": null
    }
  ],
  "total": 1
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

### Initialize Payment (add a card)
```
POST /payments/initialize
```

Starts adding a card. The passenger pays a small amount by card through Paystack; **that payment is a wallet top-up**: it is credited to their wallet and the card is saved once it succeeds. The amount is set by the server (admin wallet setting `card_verification_amount`, default ₦100) and returned in the response so the app can tell the passenger (e.g. "₦100 will be added to your wallet"). Only cards are offered at checkout. Users without an email on their account are supported: a stable placeholder (`{user_id}@rider.etigo.ng`) is sent to Paystack and remembered with the saved card. Pass the returned `tx_ref` to **Verify Payment**.

Because it is a top-up, the usual top-up rules apply: it is refused while the wallet is frozen or closed, if it would take the wallet over its maximum balance or the daily top-up cap, and it counts as one of the passenger's top-up attempts (5 per hour, 10 per day).

| Field        | Type   | Required | Description                       |
|--------------|--------|----------|-----------------------------------|
| redirect_url | string | Yes      | URL to redirect after payment     |
| channels     | array  | No       | **Ignored** (cards only); still accepted from older app builds |
| amount       | number | No       | **Ignored** (the server sets the amount); still accepted from older app builds |
| currency     | string | No       | **Ignored** (always `NGN`)        |

Rate limit: 10 requests per minute.

**Response 200:**
```json
{
  "payment_link": "https://checkout.paystack.com/abc123def456",
  "tx_ref": "TOPUP-ABCDEFGHIJKL",
  "amount": 100,
  "currency": "NGN"
}
```

**Response 422:** `"Wallet is frozen or closed."`, `"Top-up would exceed maximum wallet balance of ₦…"`, or `"Daily top-up limit exceeded."`
**Response 429:** Too many top-up attempts (5 per hour, 10 per day, shared with **Initialize Top-up**).
**Response 502:** `"Unable to initialize payment. Please try again."`

---

### Verify Payment
```
GET /payments/{transactionId}/verify
```
Confirms a checkout started with **Initialize Payment**; `{transactionId}` is its `tx_ref` (Paystack also appends it to the callback URL as `reference`). When the payment succeeded:

- the amount Paystack collected is credited to the passenger's wallet, once: calling this again, or the Paystack webhook arriving too, never credits twice. The passenger gets the usual "Top-up Successful" notification;
- the card is saved as a payment method if it was paid by a card Paystack lets us charge again (the first saved card becomes the default). Paystack's card signature identifies the card: adding a card that is already saved refreshes it instead of duplicating it, and a different card with the same last four digits is saved separately. Each payment saves its card at most once, so verifying again after the passenger removed the card does not bring it back.

If the app never calls this (closed mid-checkout), the webhook (or the stuck top-up sweeper) still credits the wallet and saves the card.

Only the user who started the checkout can verify it: anyone else, or an unknown `tx_ref`, gets **403** `"Transaction does not belong to this user."`.

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | `successful`, `failed`, `abandoned`, … (Paystack's status) |
| `amount` | number | Amount paid (naira) |
| `payment_type` | string\|null | `card`, `bank_transfer`, `ussd`, … |
| `card_saved` | boolean | Whether a card was saved (or refreshed) |
| `payment_method_id` | string\|null | ID of the saved card in **List Payment Methods** |
| `wallet_credit` | number | Naira credited to the wallet (0 unless successful). The credit is applied within a few seconds |

**Response 200:**
```json
{
  "status": "successful",
  "tx_ref": "TOPUP-ABCDEFGHIJKL",
  "amount": 100,
  "currency": "NGN",
  "payment_type": "card",
  "card_last_four": "4081",
  "card_brand": "visa",
  "card_saved": true,
  "payment_method_id": "9f3a7c2e-1b4d-4e5f-8a6b-0c9d2e3f4a5b",
  "wallet_credit": 100
}
```

**Response 200 (failed):**
```json
{
  "status": "failed",
  "tx_ref": "TOPUP-ABCDEFGHIJKL",
  "amount": 100,
  "currency": "NGN",
  "payment_type": "card",
  "card_last_four": null,
  "card_brand": null,
  "card_saved": false,
  "payment_method_id": null,
  "wallet_credit": 0
}
```

**Response 502:** `"Unable to verify transaction."` (Paystack could not be reached; retry later.)

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

When a ride completes with `payment_method=card` and the passenger has no saved card, or with `payment_method=bank_transfer`, the payment response includes a `payment_link` field. The mobile app should present this link to the passenger to complete payment. The payment status updates from `pending` to `captured` automatically when the Paystack webhook confirms the transaction. A saved card is **not** required to select `card` as the payment method — new users can pay with a one-time card entry. Payment objects shown to admins also carry `gateway_transaction_id` (the Paystack or Flutterwave transaction ID); passengers and drivers never see it.

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
      "surge_multiplier": null,
      "reservation_fee": 0
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
| `transfer.rejected` | — | Dispatches `ProcessPayoutWebhookJob` with status `failed` (money returned to the driver's balance). Sent when the transfer approval URL rejects a transfer |

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

Anything else is rejected and logged as **critical**. That includes a transfer started with a leaked secret key, or one made by hand in the Paystack dashboard. The endpoint changes nothing and approves only what is already in our database, so it does not need to trust the caller. Paystack doesn't document the request's exact shape, so the endpoint looks for the transfer, meaning every object with a `reference`. It can be at the top level, under `data`, or in a list (a bulk transfer), up to three levels deep. Every transfer found must match, or the request is rejected. A request with more than 100 transfers, or too large to search fully (over 1,000 nested objects and lists), is rejected rather than approved in part. The recipient may be a string code or an object carrying `recipient_code`. The body is read as JSON whatever the content type. A rejection also logs the request's shape (key names and value types, never the values, because the request carries the recipient's bank details), so a format we don't expect can be diagnosed from the log.

If a payout's transfer is rejected or blocked, the payout fails and the money goes back to the driver: at once, if Paystack reports it when the transfer is created or sends `transfer.rejected`, and otherwise through payout reconciliation. Finance can then retry it.

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

A passenger or driver reports a problem with a completed trip — or a passenger contests the late-cancellation fee of a cancelled scheduled booking (see [Late-cancellation fee disputes](#late-cancellation-fee-disputes)) (Phase 2 advanced disputes, PRD B-30, P-27, A-36, A-37, NF-12). The flow follows the OQ-21 recommendation of 16 October 2026, which the client has not confirmed yet; super admins can change every limit and target without a release (see [Dispute Settings](#dispute-settings-admin)). App changes: `FRONTEND_UPDATE_ADVANCED_DISPUTES.md`.

1. **File.** The reporter files from the trip (`POST /rides/{ride}/dispute`), with optional photos and audio. Each person can file one dispute per trip.
2. **Routing.** A new dispute goes to **tier 1** (support agents). A `safety_concern` goes straight to the **Safety team**, which is alerted by email and has a 1-hour first-response target. If the other person on the trip has already filed, the two disputes are linked and handled by the same owner.
3. **Review.** A reviewer takes the case (the reporter is told "We are looking into your report"). The reporter can add evidence while the case is open (`POST /disputes/{id}/evidence`).
4. **Escalation.** A case moves up to **tier 2** (support leads) or **tier 3** (management) when a reviewer escalates it or tier 1 misses its target. The reporter only sees "Your case is with our senior team", never an admin's name.
5. **Decision.** The case is resolved (optionally with a refund, to the wallet by default) or dismissed. The reporter gets an email and a push.
6. **Appeal.** The reporter can appeal once, within 7 days (`POST /disputes/{id}/appeal`). The case reopens one tier above whoever decided it, for a different reviewer. A tier 3 decision is final.

For an item left in the car, send the passenger to [Lost & Found](#lost--found) instead: it notifies the driver and tracks the return. `item_left_behind` disputes are still accepted.

### File a Dispute (Report an Issue)

`POST /api/v1/rides/{ride}/dispute`

**Auth:** Bearer token (the trip's passenger or driver)

**Content-Type:** `multipart/form-data` (with files) or `application/json`

| Field         | Type     | Required | Rules                                              |
|---------------|----------|----------|----------------------------------------------------|
| `category`    | string   | Yes      | One of `DisputeCategory` (below)                   |
| `description` | string   | Yes      | 10–2000 characters                                 |
| `photos[]`    | file[]   | No       | Up to 5 images (jpg, jpeg, png, webp), max 5 MB each |
| `audio`       | file     | No       | One recording (mp3, m4a, wav, ogg, webm, aac), max 10 MB |

**DisputeCategory values:**

| Value              | Display label       |
|--------------------|---------------------|
| `safety_concern`   | Safety concern (goes to the Safety team) |
| `driver_behaviour` | Driver behaviour    |
| `route_deviation`  | Wrong route taken   |
| `fare_dispute`     | Fare is incorrect   |
| `vehicle_condition`| Vehicle condition   |
| `item_left_behind` | Lost item (use Lost & Found instead) |
| `payment_issue`    | Payment issue       |
| `other`            | Something else      |

**Example request (multipart/form-data):**
```
category: route_deviation
description: The driver took a longer route through Maryland instead of Third Mainland Bridge.
photos[0]: <image file>
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
    "evidence_photos": ["https://res.cloudinary.com/etigo/image/upload/disputes/ride-uuid/photo1.jpg"],
    "evidence": [
      {
        "id": "01a10e6f-...",
        "type": "photo",
        "url": "https://res.cloudinary.com/etigo/image/upload/disputes/ride-uuid/photo1.jpg",
        "file_type": "image/jpeg",
        "file_size_bytes": 482113,
        "original_name": "IMG_2041.jpg",
        "caption": null,
        "uploaded_at": "2026-10-18T12:00:00.000000Z"
      }
    ],
    "status": "open",
    "status_label": "Open",
    "resolution_notes": null,
    "refund_amount": null,
    "refund_currency": null,
    "refund_method": null,
    "reported_by": { "id": "...", "first_name": "John", "last_name": "Doe" },
    "stage": "review",
    "stage_label": "With our support team",
    "can_add_evidence": true,
    "evidence_slots_left": 9,
    "appeal": { "can_appeal": false, "reason_unavailable": "You can appeal once a decision has been made.", "deadline": null, "appealed_at": null },
    "resolved_at": null,
    "created_at": "2026-10-18T12:00:00.000000Z",
    "updated_at": "2026-10-18T12:00:00.000000Z"
  }
}
```

For a `safety_concern` the message is `Report received. Our safety team has been alerted and will contact you shortly.` and `stage_label` is `With our safety team`.

**Errors:** `422` — ride not completed (and not a fee dispute, below), not a participant, already disputed, invalid category, or file validation failed (`You can only dispute a completed ride, or a cancellation fee you were charged.`). `500` — the files could not be stored (nothing is saved; try again).

#### Late-cancellation fee disputes

Added in the Phase 2 review (18 Oct 2026). A passenger charged the scheduled-ride late-cancellation fee (SR-4) can contest it from the cancelled booking with the same endpoint and fields. Only the booking's **passenger** can, and only while `ride.scheduled.late_cancellation_fee_status` is `pending`, `charging`, `charged`, `balance_due` or `failed`; a waived fee, or a cancelled ride with no fee, returns the `422` above. The driver cannot dispute a cancelled booking. Routing, targets, escalation and appeals are as for any dispute. If the case is upheld, see [Resolve Dispute](#resolve-dispute): a collected fee is refunded to the wallet, a fee still owed is waived.

### Reporter fields

Every dispute the reporter sees (filing, list, detail, evidence, appeal) carries these, and never an admin's name or the internal routing:

| Field | Meaning |
|-------|---------|
| `stage` | `review` (support), `senior_review` (tier 2+, or the Safety team) or `decided` |
| `stage_label` | Show as-is: `With our support team`, `With our senior team`, `With our safety team`, `Decision made` |
| `evidence` | The files on the case (also the older `evidence_photos` / `evidence_audio` for files sent when filing) |
| `can_add_evidence`, `evidence_slots_left` | Show "Add evidence" while `can_add_evidence` is `true` |
| `appeal.can_appeal` | Show "Appeal this decision" when `true`; `appeal.deadline` is the last moment to appeal |
| `appeal.reason_unavailable` | Why the reporter cannot appeal (already appealed, window passed, decision final, no decision yet) |
| `appeal.appealed_at` | Set once the reporter has appealed |
| `refund_amount`, `refund_currency`, `refund_method` | What was refunded on the case in total, and where (`wallet` or `card`) |
| `waived_amount` | An unpaid late-cancellation fee written off when a fee dispute was upheld (nothing was paid back); `null` otherwise |

### List My Disputes

`GET /api/v1/disputes` (passenger or driver) — also `GET /api/v1/passenger/disputes` for older passenger apps.

Disputes the authenticated user reported, newest first.

| Parameter  | Type   | Description |
|------------|--------|-------------|
| `status`   | string | `open`, `under_review`, `resolved` or `dismissed` |
| `ride_id`  | uuid   | One trip |
| `per_page` | int    | Default 15, max 50 |

**Response: `200 OK`** — `{ "disputes": [ <dispute as above, with "ride"> ], "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 } }`

### Show My Dispute

`GET /api/v1/disputes/{dispute}` (passenger or driver) — also `GET /api/v1/passenger/disputes/{dispute}`.

**Response: `200 OK`** — `{ "dispute": { ... } }`. **`403`** — the user did not report this dispute.

### Add Evidence

`POST /api/v1/disputes/{dispute}/evidence`

**Auth:** Bearer token (the reporter). Throttled to 10 a minute.

**Content-Type:** `multipart/form-data`

| Field        | Type     | Required | Rules |
|--------------|----------|----------|-------|
| `files[]`    | file[]   | Yes      | 1–5 files: images (jpg, jpeg, png, webp, heic) and PDFs up to 5 MB; audio (mp3, m4a, wav, ogg, webm, aac) up to 10 MB |
| `captions[]` | string[] | No       | One per file, same order, max 255 characters |

A case holds at most `max_evidence_files` (10) files in all. Only while the case is open (including a reopened appeal).

**Response: `201 Created`** — `{ "message": "Evidence added to your report.", "dispute": { ... } }`

**Errors:** `403` — not the reporter. `422` — case closed (`Evidence can only be added while the case is open.`), too many files (`A case can hold at most 10 evidence files.`), or file validation.

### Appeal a Decision

`POST /api/v1/disputes/{dispute}/appeal`

**Auth:** Bearer token (the reporter). Throttled to 5 a minute.

| Field    | Type   | Required | Rules |
|----------|--------|----------|-------|
| `reason` | string | Yes      | 10–2000 characters |

Once per dispute, within `appeal_window_days` (7) of the decision. The case reopens as `open`, one tier above the tier it was decided at, and whoever decided it cannot review the appeal. A decision made at tier 3 (management) is final.

**Response: `200 OK`**
```json
{
  "message": "Appeal received. A senior member of our team will review your case.",
  "dispute": { "status": "open", "stage": "senior_review", "stage_label": "With our senior team", "appeal": { "can_appeal": false, "reason_unavailable": "You can appeal once a decision has been made.", "appealed_at": "2026-10-19T09:00:00.000000Z" }, "...": "..." }
}
```

**Errors:** `403` — not the reporter. `422` — one of:

| Message | When |
|---------|------|
| `You can appeal once a decision has been made.` | The case is still open |
| `This decision has already been appealed once.` | Second appeal |
| `This decision was made by our management team and is final.` | Decided at tier 3 |
| `The time to appeal this decision has passed.` | Outside the window |

---

## Admin — Dispute Management

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:support,support_lead,safety_operator` (super admins always pass).

### Tiers and who can do what

| Tier / queue | Role | Works | Refund limit per dispute |
|--------------|------|-------|--------------------------|
| Tier 1 | `support` | New disputes except safety | ₦5,000 |
| Tier 2 | `support_lead` | Escalations, appeals, missed targets; also tier 1 | ₦50,000 |
| Safety | `safety_operator` | Safety concerns (they skip tier 1) | ₦50,000 |
| Tier 3 | `super_admin` | Anything, including final appeals | No limit |

- **Seeing cases.** `support` and `support_lead` see the support queue at every tier. The Safety team and super admins see everything. A safety case is `403` to support roles.
- **Working cases.** An admin works cases at their tier or below (`available_actions` lists what the viewer can do). A tier 1 agent can only take a case; leads, Safety and super admins can also assign it to someone else who can work it.
- **Appeals.** Whoever made the original decision (or approved its refund) cannot work the appeal: `403 You made the original decision on this case, so the appeal goes to a different reviewer.`
- **Evidence (NF-12).** Only the assigned reviewer, higher tiers, the Safety team and super admins see evidence, and only in the detail view. Anyone else gets `evidence_locked: true` and no files: take the case to see them. Each view is audit-logged (`dispute_evidence_viewed`).
- **Response targets.** First response = taking the case, moving it to under review, or deciding it. Safety: 1 h / 24 h; tier 1: 24 h / 72 h; tier 2: 24 h / 5 days; tier 3: none. The clock restarts when a case changes tier. Every 15 minutes, tier 1 misses move to tier 2 and Safety / tier 2 misses are emailed to super admins.
- **Linked cases.** When the passenger and the driver both dispute one trip, the cases are linked (`linked_dispute_id`): assigning, escalating or sending one back does the same to the other while it is open.
- **Admin changes.** Deactivating or deleting an admin returns their open cases to the queue; a role change does so for cases the new role cannot work.

### List Disputes

`GET /api/v1/admin/disputes`

| Parameter  | Type   | Description |
|------------|--------|-------------|
| `status`   | string | Comma list: `open`, `under_review`, `resolved`, `dismissed` |
| `open`     | bool   | `true` = open or under review; `false` = closed |
| `queue`    | string | `support` or `safety` |
| `tier`     | int    | 1, 2 or 3 |
| `assigned` | string | `me`, `unassigned`, or an admin's id (anything else is `422`) |
| `sla`      | string | `breached` (a target has passed) or `at_risk` (under a quarter of the time left) |
| `appealed` | bool   | Only appeals (`true`) or none (`false`) |
| `pending_refund` | bool | Only cases with a refund waiting for approval |
| `category`, `ride_id`, `city_id` | | As before |
| `search`   | string | Description, reference number, or the reporter's name or email |
| `from`, `to` | date | Created on or after / on or before (YYYY-MM-DD) |
| `sort`     | string | `newest` (default) or `due` (open cases first, soonest target first) |
| `per_page` | int    | Default 15, max 100 |

**Response: `200 OK`**
```json
{
  "disputes": [
    {
      "id": "...",
      "reference_number": "DPT-000048",
      "category": "fare_dispute",
      "status": "under_review",
      "queue": "support",
      "queue_label": "Support",
      "escalation_tier": 2,
      "tier_label": "Tier 2 — Support lead",
      "decision_tier": null,
      "assigned_admin": { "id": "...", "first_name": "Ada", "last_name": "Obi", "admin_role": "support_lead" },
      "assigned_admin_id": "...",
      "assigned_at": "2026-10-18T13:00:00.000000Z",
      "is_mine": true,
      "escalation": { "escalated_at": "2026-10-18T12:40:00.000000Z", "reason": "Refund above my limit.", "source": "admin", "escalated_by_admin_id": "..." },
      "sla": {
        "status": "on_track",
        "status_label": "On track",
        "next_due_at": "2026-10-23T12:40:00.000000Z",
        "first_response_due_at": "2026-10-19T12:40:00.000000Z",
        "first_responded_at": "2026-10-18T13:00:00.000000Z",
        "first_response_breached_at": null,
        "resolution_due_at": "2026-10-23T12:40:00.000000Z",
        "resolution_breached_at": null
      },
      "appeal": null,
      "evidence_count": 2,
      "evidence_locked": false,
      "refund_amount": null,
      "refund_method": null,
      "driver_clawback_amount": null,
      "linked_dispute_id": null,
      "sos_incident_id": null,
      "available_actions": ["unassign", "escalate", "send_to_safety", "deescalate", "resolve", "add_note"],
      "refund_limit": 50000,
      "reported_by": { "id": "...", "first_name": "John", "last_name": "Doe" },
      "ride": { "id": "...", "reference_number": "TRP-NG-000142", "city": { "name": "Lagos" } },
      "created_at": "2026-10-18T12:00:00.000000Z"
    }
  ],
  "summary": {
    "open": 14, "unassigned": 5, "mine": 3, "overdue": 2, "safety": 1,
    "tier_1": 9, "tier_2": 3, "tier_3": 1, "appeals": 1, "pending_refunds": 2
  },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 14 }
}
```

The list never includes evidence files (`evidence_count` only). `summary` counts open cases within what the viewer can see. `sla.status`: `on_track`, `at_risk`, `breached`, `met` / `missed` (closed cases), `none` (no target, e.g. tier 3). `available_actions`: `take`, `assign`, `unassign`, `mark_under_review`, `escalate`, `send_to_safety`, `deescalate`, `resolve`, `add_note` (empty when the viewer cannot work the case).

### Show Dispute Detail

`GET /api/v1/admin/disputes/{dispute}`

The list fields plus the ride (passenger, driver and vehicle, class, city, payment), `notes`, `linked_dispute`, `pending_refund_request`, `refund_requests` (history), `resolved_by`, `appeal` (`appealed_at`, `reason`, `original_decision`, `excluded_reviewer_ids`) and, where the viewer may see them, `evidence`, `evidence_photos` and `evidence_audio`.

**Errors:** `403` — a safety case viewed by a support role.

### Take or Assign a Case

`POST /api/v1/admin/disputes/{dispute}/assign`

| Field      | Type | Required | Rules |
|------------|------|----------|-------|
| `admin_id` | uuid | No       | Who gets the case; leave out to take it yourself. Leads, Safety and super admins only |

The case moves to `under_review`; the first time, this is its first response and the reporter is told. The linked case gets the same owner.

**Response: `200 OK`** — `{ "message": "You have taken this case.", "dispute": { ... } }`

**Errors:** `403` — the viewer cannot work the case (`This case is at tier 2, above your tier.`, `Safety cases are handled by the Safety team.`, or the appeal rule). `422` — closed case, `You can only take a case yourself.`, or `That admin cannot work this case.`

### Return a Case to the Queue

`DELETE /api/v1/admin/disputes/{dispute}/assign` — clears the assignee (and the linked case's). **Response: `200 OK`** — `Case returned to the queue.`

### Update Dispute Status

`PATCH /api/v1/admin/disputes/{dispute}/status`

Moves an `open` case to `under_review` without assigning it; counts as the first response and the reporter gets a push.

| Field    | Type   | Required | Rules            |
|----------|--------|----------|------------------|
| `status` | string | Yes      | `under_review`   |

**Response: `200 OK`** — `{ "message": "Dispute moved to under review.", "dispute": { ... } }`. **`422`** — `Only open disputes can be moved to under review.`

### Escalate a Case

`POST /api/v1/admin/disputes/{dispute}/escalate`

| Field       | Type   | Required | Rules |
|-------------|--------|----------|-------|
| `reason`    | string | Yes      | 10–2000 characters |
| `to_safety` | bool   | No       | `true` sends a support case to the Safety team instead of up a tier |

Moves the case one tier up (Safety → tier 3), clears the assignee so it lands in the new tier's queue, and restarts the targets. The reporter is told "Your case is with our senior team" (or "...our safety team"); `to_safety` also emails the Safety team and links any SOS incident on the trip.

**Response: `200 OK`** — `{ "message": "Case escalated.", "dispute": { "escalation_tier": 2, "assigned_admin_id": null, "escalation": { "source": "admin", "...": "..." } } }`

**Errors:** `403` — cannot work the case. `422` — `This case is already at tier 3, the highest tier.`, `This case is already with the Safety team.`, or `A closed dispute cannot be escalated. It can only reopen through an appeal.`

### Send a Case Back Down

`POST /api/v1/admin/disputes/{dispute}/deescalate`

| Field  | Type   | Required | Rules |
|--------|--------|----------|-------|
| `note` | string | Yes      | 10–2000 characters; saved as a dispute note (instructions for the lower tier) |

Tier 3 → 2, tier 2 → 1, Safety → support tier 1 (a case that is not about safety). An appeal cannot go back down to the tier that made the original decision. The reporter is not notified.

**Errors:** `403` — cannot work the case. `422` — `This case is already at tier 1.`, `An appeal must stay above the tier that made the original decision.`, or closed.

### Resolve Dispute

`POST /api/v1/admin/disputes/{dispute}/resolve`

| Field              | Type    | Required | Rules |
|--------------------|---------|----------|-------|
| `status`           | string  | Yes      | `resolved` or `dismissed` |
| `resolution_notes` | string  | Yes      | 10–2000 characters; the reporter sees them |
| `refund_amount`    | numeric | No       | NGN, min 1; only with `resolved` |
| `refund_method`    | string  | No       | `wallet` (default) or `card` |
| `clawback_driver_earnings` | bool | No | Default `true`: recover the driver's share of a wallet refund |

**Refunds.**
- `wallet` (default, OQ-27): credited to the passenger's wallet at once, for card, wallet, bank-transfer and cash rides, up to what is still refundable on the trip (tips excluded). The driver's share is clawed back unless `clawback_driver_earnings` is `false`.
- `card`: back to the card through the gateway. Card payments only, and **always** needs Finance approval.
- Card and wallet refunds share one balance: never more than what is still refundable on the trip, whatever was refunded before and however.
- **Limits.** If this refund plus earlier refunds on the case is above the reviewer's limit, or the method is `card`, nothing is paid yet: the decision becomes a refund request and the case stays open (`202`). See [Dispute Refund Requests](#dispute-refund-requests).
- **Late-cancellation fee** (a cancelled scheduled booking): `wallet` only (`card` → 422). A **charged** fee is refunded to the wallet up to the fee less earlier refunds, and taken back in full from the driver who received it unless `clawback_driver_earnings` is `false` or that driver can no longer be found (`driver_clawback_kobo`). A fee still **owed** (`balance_due`) is waived instead, which lifts the booking block; send the whole fee as `refund_amount` (a balance due cannot be part-waived; another amount → 422). A waiver pays nothing back, so it is recorded as `waived_amount`, not `refund_amount` / `refund_method`, and the message reads `Dispute resolved successfully. The unpaid fee of NGN 400.00 was waived.` (approval: `Approved: the unpaid fee was waived. The dispute is resolved.`). The waived amount counts towards the reviewer's limit. The same tier limits and approvals apply.

**Response: `200 OK`** (decided)
```json
{
  "message": "Dispute resolved successfully. Refund of NGN 3,000.00 issued.",
  "dispute": { "status": "resolved", "refund_amount": "3000.00", "refund_method": "wallet", "decision_tier": 1, "...": "..." },
  "driver_clawback_kobo": 240000
}
```

**Response: `202 Accepted`** (refund waits for approval)
```json
{
  "message": "The refund needs a second approval. The case stays open until it is approved.",
  "dispute": { "status": "under_review", "pending_refund_request": { "id": "...", "status": "pending" }, "...": "..." },
  "refund_request": {
    "id": "...", "dispute_id": "...", "amount": "8000.00", "currency": "NGN",
    "method": "wallet", "method_label": "Wallet credit", "clawback_driver_earnings": true,
    "resolution_notes": "Driver took a much longer route.",
    "status": "pending", "status_label": "Waiting for approval",
    "requested_by": { "id": "...", "first_name": "Tolu", "last_name": "Ade", "admin_role": "support" },
    "reviewed_by": null, "reviewed_at": null, "review_notes": null,
    "created_at": "2026-10-18T14:00:00.000000Z"
  }
}
```

**Errors:** `403` — cannot work the case. `422` — already resolved or dismissed, notes missing, refund on a dismissal, refund above what is refundable, card refund on a non-card payment, or `A refund on this case is waiting for approval. Approve, reject or withdraw it first.`

---

### Dispute Refund Requests

**Middleware:** `admin.role:support,support_lead,safety_operator,finance`.

Who can approve or reject: a **card** refund — Finance or a super admin; a **wallet** refund — Finance, a super admin, or an admin on the case's own team (support roles for support cases, the Safety team for safety cases) whose limit covers the case's total refund. Nobody approves their own request, and the original decider of an appealed case cannot approve its refund. The requester can withdraw their own request.

#### List Refund Requests

`GET /api/v1/admin/dispute-refund-requests?status=pending`

`status`: `pending` (default), `approved`, `rejected`, `cancelled`. Finance sees every request; the dispute roles see requests on cases they can see. Each row adds `can_approve` and `can_withdraw` for the viewer.

```json
{
  "refund_requests": [
    {
      "id": "...", "amount": "8000.00", "method": "wallet", "status": "pending",
      "requested_by": { "first_name": "Tolu", "admin_role": "support" },
      "dispute": { "id": "...", "reference_number": "DPT-000048", "category": "route_deviation", "queue": "support", "escalation_tier": 1, "tier_label": "Tier 1 — Support", "status": "under_review", "refund_amount": null, "ride_id": "..." },
      "can_approve": true,
      "can_withdraw": false,
      "created_at": "..."
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

#### Approve a Refund Request

`POST /api/v1/admin/dispute-refund-requests/{refundRequest}/approve` — body `notes` (optional, max 2000).

Pays the refund and resolves the case with the requester's notes; the requester is recorded as the decider. **Response: `200 OK`** — `{ "message": "Refund approved and paid. The dispute is resolved.", "refund_request": { ... }, "dispute": { ... }, "driver_clawback_kobo": 0 }`

**Errors:** `403` — `You cannot approve your own refund request.`, `A refund to the card must be approved by Finance.`, `This refund is above your limit; it needs a higher tier.`, `Your role cannot approve refunds on this case.` (another team's case) `422` — already decided, case closed, nothing left to refund, `The payment provider did not accept the card refund: …` (nothing was refunded; you can approve again), or `This card refund was already sent to the payment provider. Finance must check the payment before anything else is done with this request.` (the provider refunded but closing the case failed; it is never sent twice).

#### Reject or Withdraw a Refund Request

`POST /api/v1/admin/dispute-refund-requests/{refundRequest}/reject` — body `notes` (required, 10–2000).

By an approver → `rejected`; by the requester → `cancelled`. The case stays open for a new decision. **Response: `200 OK`** — `Refund request rejected. The case is still open.`

---

### Dispute Settings (Admin)

`GET /api/v1/admin/settings/disputes` · `PUT /api/v1/admin/settings/disputes`

**Auth:** super admin only (the limits bind the other dispute roles). Every change is audit-logged (`dispute_settings.updated`). New targets apply to cases that enter a tier afterwards.

| Setting | Default | Rules |
|---------|---------|-------|
| `tier_1_refund_limit` | 5000 | NGN, ≥ 0 |
| `tier_2_refund_limit` | 50000 | NGN, not below tier 1 |
| `safety_refund_limit` | 50000 | NGN |
| `safety_first_response_minutes` | 60 | 0–1440; 0 = no target |
| `safety_resolution_hours` | 24 | 0–720 |
| `tier_1_first_response_hours` | 24 | 0–720 |
| `tier_1_resolution_hours` | 72 | 0–720 |
| `tier_2_first_response_hours` | 24 | 0–720 |
| `tier_2_resolution_hours` | 120 | 0–1440 |
| `auto_escalate_tier_1` | true | Off: tier 1 misses are only flagged |
| `appeal_window_days` | 7 | 1–90 |
| `max_evidence_files` | 10 | 1–30 per case |

**Response: `200 OK`** — `{ "settings": { ... }, "tiers": { "1": "support", "2": "support_lead", "3": "super_admin" } }` (PUT: `{ "message": "Dispute settings updated.", "settings": { ... } }`).

---

### Dispute Notes

Internal notes on a case, visible to admins who can see the case (a safety case's notes are `403` to support roles). Never shown to the reporter.

#### List Dispute Notes

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

#### Add Dispute Note

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

#### Update Dispute Note

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

#### Delete Dispute Note

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
      "ev_reservation_fee_waived": false,
      "exclusive_promotions": false
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
      "exclusive_promotions_enabled": false,
      "unlocked": true
    }
  ],
  "current_tier": 3
}
```

Show each tier's perks from these fields: `booking_fee_discount_pct` as "N% off the base fare" (hide it at 0), and the three switches as ticks. `exclusive_promotions_enabled` means the tier can use tier-only promo codes.

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
      "benefits": { "booking_fee_discount_pct": 15, "priority_matching": true, "ev_reservation_fee_waived": true, "exclusive_promotions": true },
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
      "priority_matching_enabled": false,
      "exclusive_promotions_enabled": false
    },
    {
      "tier_level": 2,
      "tier_name": "Silver",
      "min_points_required": 500,
      "booking_fee_discount_pct": 2,
      "ev_reservation_fee_waived": false,
      "priority_matching_enabled": false,
      "exclusive_promotions_enabled": true
    },
    {
      "tier_level": 3,
      "tier_name": "Gold",
      "min_points_required": 2000,
      "booking_fee_discount_pct": 5,
      "ev_reservation_fee_waived": false,
      "priority_matching_enabled": true,
      "exclusive_promotions_enabled": true
    },
    {
      "tier_level": 4,
      "tier_name": "Platinum",
      "min_points_required": 5000,
      "booking_fee_discount_pct": 10,
      "ev_reservation_fee_waived": true,
      "priority_matching_enabled": true,
      "exclusive_promotions_enabled": true
    },
    {
      "tier_level": 5,
      "tier_name": "Diamond",
      "min_points_required": 15000,
      "booking_fee_discount_pct": 15,
      "ev_reservation_fee_waived": true,
      "priority_matching_enabled": true,
      "exclusive_promotions_enabled": true
    }
  ]
}
```

| Field | Notes |
|-------|-------|
| `booking_fee_discount_pct` | Percent off the **base fare** (not a separate booking fee), 0–100 with up to 2 decimals. 0 means the tier has no discount. Each tier has its own value, so the dashboard needs a number input per tier, not an on/off switch. |
| `ev_reservation_fee_waived`, `priority_matching_enabled` | On/off perks. |
| `exclusive_promotions_enabled` | Optional on/off perk. When on, the tier can use tier-only promo codes (`min_tier_level` above 1) that its level reaches. When off, those codes are refused for the tier even if its level reaches them. Leave it out and the stored value is kept. Default: off for Bronze, on for Silver to Diamond. |

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

`multiplier_value` runs from **1.00 to 3.00 in steps of 0.05** (1.25 is valid; 1.33 and 3.50 are not). The dashboard input should use the same range and step. Stackable bonuses add their extra on top of 1×, so EV (2.00) plus off-peak (1.25) gives 2.25×.

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
- `"Your tier level does not qualify for this promo code."` — user tier below the promo's `min_tier_level`, or the user's tier has exclusive promotions switched off
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

**Promo object (admin view).** Every admin promo endpoint returns promos in this shape:

```json
{
  "id": "uuid",
  "code": "WELCOME20",
  "description": "20% off for new riders",
  "discount_type": "percentage",
  "discount_type_label": "Percentage",
  "discount_value": "20.00",
  "max_discount_cap": "1000.00",
  "total_redemption_limit": 2000,
  "per_user_limit": 1,
  "starts_at": "2026-10-01T00:00:00.000000Z",
  "expires_at": "2026-11-01T00:00:00.000000Z",
  "status": { "value": "active", "label": "Active" },
  "rider_eligibility": { "value": "first_ride", "label": "First-time passengers only" },
  "min_order_count": null,
  "max_order_count": 0,
  "timing": { "value": "peak_only", "label": "Peak hours only" },
  "peak_only": true,
  "off_peak_only": false,
  "min_tier_level": null,
  "city_ids": ["uuid", "uuid"],
  "cities": [{ "id": "uuid", "name": "Lagos", "...": "..." }],
  "vehicle_class_id": null,
  "minimum_fare_amount": null,
  "geo_fence": null,
  "is_active": true,
  "cancelled_at": null,
  "is_cancelled": false,
  "total_redemptions": 640,
  "created_at": "...",
  "updated_at": "..."
}
```

| Field | Notes |
|-------|-------|
| `status` | `active`, `scheduled` (starts in the future), `paused`, `expired` or `cancelled`. Worked out in that precedence: cancelled, then expired, then paused, then scheduled. A paused promo that has passed its end date is `expired`. |
| `rider_eligibility` | `all`, `first_ride` (no completed rides yet), `returning` (at least one completed ride), or `custom` when `min_order_count` / `max_order_count` hold any other pair. |
| `timing` | `any_time`, `peak_only` or `off_peak_only`. Peak hours are 07:00–09:00 and 17:00–19:00 in the ride city's local time (`config/promo.php`). |
| `city_ids` / `cities` | The cities the promo runs in. **Empty means every city.** Replaces the old single `city_id`, which is no longer returned. |

### List Promos

`GET /api/v1/admin/promos`

**Query parameters:**
- `status`: `active`, `scheduled`, `paused`, `expired`, `cancelled`. Matches the `status` field above. `inactive`, the old name for `paused`, is still accepted.
- `city_id`: promos targeted at this city (promos that run in every city are not included)
- `search`: search by code or description
- `per_page`: pagination size (default 20, max 100)

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
  "max_discount_cap": 1000,
  "total_redemption_limit": 2000,
  "per_user_limit": 1,
  "starts_at": "2026-10-01T00:00:00Z",
  "expires_at": "2026-11-01T00:00:00Z",
  "city_ids": ["uuid", "uuid"],
  "rider_eligibility": "first_ride",
  "timing": "peak_only",
  "min_tier_level": 2,
  "vehicle_class_id": "uuid (optional)",
  "minimum_fare_amount": 1500,
  "is_active": true,
  "geo_fence": {
    "center_lat": 9.0579,
    "center_lng": 7.4951,
    "radius_km": 10
  }
}
```

| Field | Notes |
|-------|-------|
| `city_ids` | Optional array of city UUIDs (max 100). Leave out or send `[]` for every city. The old `city_id` (one city or `null`) is still accepted and becomes `city_ids`. |
| `rider_eligibility` | Optional: `all`, `first_ride` or `returning`. Sets `min_order_count` / `max_order_count` and **overrides** them if both are sent. `custom` is output-only; send the counts directly for other rules. |
| `timing` | Optional: `any_time`, `peak_only` or `off_peak_only`. Sets `peak_only` / `off_peak_only` and overrides them if both are sent. |
| `min_tier_level` | Optional, 1–5. Above 1 makes the promo tier-only: passengers whose tier reaches it **and** has exclusive promotions switched on (tier settings) can use it. 1 is open to everyone. |

`geo_fence` is either a circle (`center_lat`, `center_lng`, `radius_km` 0.1–500) or a polygon: `"polygon": [{"lat": 6.45, "lng": 3.38}, ...]` with at least 3 points. `per_user_limit` defaults to 1 (max 100). Create the promo with `is_active: false` to save it paused.

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

All fields from create are accepted; only provided fields are updated. Leaving `city_ids` out keeps the current cities; `[]` makes the promo run in every city.

**Response 422 (cancelled promo):**
```json
{
  "message": "Cannot update a cancelled promo code."
}
```

### Pause Promo

`POST /api/v1/admin/promos/{promo}/pause`

Stops the promo from being redeemed until it is resumed. Pausing a promo that is already paused returns 200 without changing anything. Audit logged (`promo_code_paused`).

**Response (200):**
```json
{
  "message": "Promo code paused.",
  "promo": { "status": { "value": "paused", "label": "Paused" }, "...": "..." }
}
```

**Response 422:** `"Cannot change status of a cancelled promo code."`

### Resume Promo

`POST /api/v1/admin/promos/{promo}/resume`

Makes a paused promo usable again; it shows as `scheduled` if its start date is still ahead. Resuming a promo that is not paused returns 200 without changing anything. Audit logged (`promo_code_resumed`).

**Response 422:** `"Cannot change status of a cancelled promo code."`, or for a promo past its end date: `"This promo code has expired. Move its end date later to run it again."`

### Toggle Promo Status (deprecated)

`PATCH /api/v1/admin/promos/{promo}/status`

**Deprecated: use Pause and Resume.** Pauses an active promo or resumes a paused one, and still answers `"Promo code activated."` / `"Promo code deactivated."`.

---

### Cancel Promo

`POST /api/v1/admin/promos/{promo}/cancel`

Permanently cancels a promo code. Sets `is_active = false` and records `cancelled_at` timestamp. This action **cannot be undone**: a cancelled promo can never be resumed or updated.

**Cancel vs. Pause:** pausing is reversible. Cancelling is permanent: once cancelled, the promo cannot be paused, resumed, updated or reused.

**Response (200):**
```json
{
  "message": "Promo code cancelled permanently.",
  "promo": {
    "id": "uuid",
    "code": "WELCOME20",
    "status": { "value": "cancelled", "label": "Cancelled" },
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

| Parameter    | Type | Required | Description |
|--------------|------|----------|-------------|
| `start_date` | `Y-m-d` | No | First day of the range (inclusive). Default: the promo's start date. |
| `end_date`   | `Y-m-d` | No | Last day of the range (inclusive, on or after `start_date`). Default: today, or the promo's end date if that is earlier. |

Days are calendar days in `Africa/Lagos` (`PROMO_TIMEZONE`). A range can cover at most 366 days; a longer explicit range answers 422 on `end_date`, and a longer default range is cut to the latest 366 days.

The range applies to `total_redemptions`, `unique_users`, `total_discount_given`, `average_discount`, `total_fare_value` and `redemptions_by_day`. Usage against the limit (`lifetime_redemptions`, `remaining_redemptions`, `usage_percentage`) always covers the promo's whole life, because the limit is a lifetime limit.

**Response (200):**
```json
{
  "promo": { ... },
  "performance": {
    "period": { "start_date": "2026-10-01", "end_date": "2026-10-07", "timezone": "Africa/Lagos" },
    "total_redemptions": 42,
    "unique_users": 38,
    "total_discount_given": 84000.00,
    "average_discount": 2000.00,
    "total_fare_value": 252000.00,
    "lifetime_redemptions": 640,
    "total_redemption_limit": 2000,
    "remaining_redemptions": 1360,
    "usage_percentage": 32.0,
    "redemptions_by_day": [
      { "date": "2026-10-01", "count": 0, "total_discount": 0 },
      { "date": "2026-10-02", "count": 12, "total_discount": 24000.00 }
    ]
  },
  "recent_redemptions": [...]
}
```

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `redemptions_by_day` | array | One entry for **every** day in the range, in date order; days with no redemptions have `count: 0`. (Before October 2026 this was an object keyed by date with only the days that had redemptions.) |
| `total_fare_value` | number | Pre-discount fare total (SUM of `final_fare_amount + discount_amount`) for redemptions in the range |
| `remaining_redemptions`, `usage_percentage` | number\|null | Lifetime; `null` when the promo has no `total_redemption_limit` ("No limit"). |
| `unique_users`, `average_discount`, `recent_redemptions` | | Extra figures the design does not show yet; `recent_redemptions` is the latest 20 whatever the range. |

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

**Queue status.** Besides the eight-value `status`, every admin incident carries `queue_status`, the console's three-way view of whether an operator has taken the alert:

| `queue_status` | Incident `status` values |
|---|---|
| `unacknowledged` | `triggered`, `check_in_sent`, `acknowledged`, `escalated` |
| `acknowledged` | `operator_assigned`, `dispatched` |
| `resolved` | `resolved`, `cancelled` |

Incident status `acknowledged` means the person who raised the SOS answered the check-in ("I'm safe"); its label is **Confirmed Safe**. It does not mean an operator has taken the alert, so it counts as `unacknowledged` in the queue until an operator takes or resolves it.

### List Active Incidents

`GET /api/v1/admin/sos/active`

Returns open SOS incidents prioritised by severity (escalated first), each with the ride's passenger, driver and vehicle class.

**Query Params:** `status` (an incident status), `queue_status`, `search`, `per_page`

`search` matches the incident reference (`SOS-000123`), the ride reference, or the name or phone number of the passenger, the driver or whoever raised the SOS.

**Response (200):**
```json
{
  "message": "Active SOS incidents retrieved.",
  "incidents": [
    {
      "id": "uuid",
      "reference_number": "SOS-000123",
      "ride_id": "uuid",
      "status": "escalated",
      "status_label": "Escalated",
      "queue_status": { "value": "unacknowledged", "label": "Unacknowledged" },
      "trigger_type": "passenger",
      "gps_lat": "9.0579000",
      "gps_lng": "7.4951000",
      "vehicle_details": { "plate_number": "AB-123-CD", "make": "Toyota", "model": "Corolla", "color": "White" },
      "escalated_at": "2026-10-11T12:01:00.000000Z",
      "escalates_at": null,
      "operator_assigned_at": null,
      "wait_seconds": 95,
      "triggered_by": { "id": "uuid", "first_name": "Ade", "...": "..." },
      "ride": { "reference_number": "TRP-NG-000045", "passenger": { "...": "..." }, "driver": { "...": "..." }, "...": "..." },
      "created_at": "2026-10-11T12:00:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

| Field | Notes |
|-------|-------|
| `reference_number` | Readable alert ID, `SOS-` + six digits. |
| `wait_seconds` | How long the alert waited for an operator: until one took it (or resolved it), or until now while it is still waiting. `null` for an alert cancelled before any operator saw it. |
| `operator_assigned_at` | When an operator first took the alert. |
| `escalates_at` | When the alert escalates automatically if the check-in goes unanswered: `check_in_sent_at` + `SOS_ESCALATION_TIMEOUT` (default 30 s) once the check-in is out, an estimate (raised + `SOS_CHECK_IN_DELAY` + timeout) before that, and `null` once it can no longer escalate. Use it for the "Automatic escalation in 00:18" countdown. |

### Show Incident Detail

`GET /api/v1/admin/sos/{incident}`

Returns the incident with its audit trail, the ride (passenger, driver with rating, vehicle class, city) and what the map needs. Admin-only fields: `vehicle_details`, `telemetry_data`, `operator_id`, `operator_notes`, `operator`, `queue_status`, `escalates_at`, `operator_assigned_at`, `wait_seconds` and `event_logs`.

**Response (200):**
```json
{
  "message": "SOS incident details retrieved.",
  "incident": {
    "id": "uuid",
    "reference_number": "SOS-000123",
    "ride_id": "uuid",
    "triggered_by_user_id": "uuid",
    "trigger_type": "passenger",
    "trigger_type_label": "Passenger",
    "status": "operator_assigned",
    "status_label": "Operator Assigned",
    "queue_status": { "value": "acknowledged", "label": "Acknowledged" },
    "gps_lat": "9.0579000",
    "gps_lng": "7.4951000",
    "vehicle_details": { "plate_number": "AB-123-CD", "make": "Toyota", "model": "Corolla", "color": "White", "vehicle_class": "Comfort" },
    "telemetry_data": { "ride_status": "in_progress", "passenger_id": "uuid", "driver_id": "uuid" },
    "check_in_sent_at": "2026-10-11T12:00:05.000000Z",
    "check_in_acknowledged_at": null,
    "escalated_at": "2026-10-11T12:00:35.000000Z",
    "escalates_at": null,
    "operator_id": "uuid",
    "operator_assigned_at": "2026-10-11T12:01:40.000000Z",
    "wait_seconds": 100,
    "operator_notes": null,
    "resolved_at": null,
    "triggered_by": { "id": "uuid", "first_name": "Ade", "...": "..." },
    "operator": { "id": "uuid", "first_name": "Kemi", "...": "..." },
    "ride": { "...": "..." },
    "event_logs": [
      {
        "id": "uuid",
        "event_type": "transition:triggered->check_in_sent",
        "description": "Safety check-in sent",
        "actor_type": "system",
        "actor_id": null,
        "metadata": null,
        "created_at": "2026-10-11T12:00:05.000000Z"
      },
      {
        "id": "uuid",
        "event_type": "transition:escalated->operator_assigned",
        "description": "Alert taken by an operator",
        "actor_type": "operator",
        "actor_id": "uuid",
        "actor": { "id": "uuid", "first_name": "Kemi", "admin_role": "safety_operator", "...": "..." },
        "metadata": null,
        "created_at": "2026-10-11T12:01:40.000000Z"
      }
    ],
    "created_at": "2026-10-11T12:00:00.000000Z"
  },
  "route": { "encoded_polyline": "abc...", "distance_km": 12.4, "duration_minutes": 28 },
  "driver_location": { "lat": 9.061, "lng": 7.49, "heading": 90, "speed": 32.5, "updated_at": "2026-10-11T12:01:38.000000Z" }
}
```

| Field | Notes |
|-------|-------|
| `event_logs[].description` | Readable line for the audit trail, e.g. "SOS raised by the passenger", "Safety check-in sent", "Confirmed safe by the person who raised the SOS", "Escalated: no answer to the safety check-in", "Alert taken by an operator", "Emergency services dispatched (police)", "Called the passenger: no answer", "Resolved". |
| `event_logs[].actor_type` | `system` for automatic steps (no actor), `operator` for admins, otherwise `passenger` or `driver`. |
| `route` | The ride's planned route from pickup to destination (Google encoded polyline); `null` if routing fails. |
| `driver_location` | Last position the driver's phone sent, while the incident is open; `null` once it is closed or if unknown. Live positions arrive as `driver.location.updated` on the `admin.rides` channel (filter by `ride_id`). |

`event_type` is `triggered`, `operator_reassigned` (metadata carries `previous_operator_id`), `operator_call` (see Log a Call), or `transition:{from}->{to}` for every status change.

### Incident History

`GET /api/v1/admin/sos/history`

Returns all incidents (open and closed), newest first, each with the ride's passenger, driver and vehicle class.

**Query Params:** `status`, `queue_status`, `search`, `trigger_type`, `ride_id`, `date_from`, `date_to`, `per_page`. An unknown `status` or `queue_status` answers 422.

### Assign Operator (take the alert)

`POST /api/v1/admin/sos/{incident}/assign`

The operator takes the incident at any open stage (`triggered`, `check_in_sent`, `acknowledged`, `escalated`); its `queue_status` becomes `acknowledged`. Calling it on an incident another operator already holds reassigns it to the caller (logged as `operator_reassigned`). Uses row locking to prevent concurrent assignment conflicts. This is the dashboard's "Acknowledge" action.

**Errors:** `422` `"This incident cannot be assigned in its current state."` (already resolved or cancelled).

**Response (200):**
```json
{
  "message": "You have been assigned to this incident.",
  "incident": { "status": "operator_assigned", "operator_id": "uuid", "...": "..." }
}
```

### Dispatch Emergency Services

`POST /api/v1/admin/sos/{incident}/dispatch`

Records that emergency services were called. If no operator has taken the incident yet, the calling operator takes it first (both steps appear in the audit trail).

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
| `contact_number` | string | No | Number called, max 20 characters |
| `notes` | string | No | Dispatch notes, max 2000 |

**Errors (422):** `"Emergency services have already been dispatched for this incident."`, `"This incident has already been closed."`

### Resolve Incident

`POST /api/v1/admin/sos/{incident}/resolve`

Resolves an SOS incident with operator notes. Allowed at any open stage: an operator can close a false alarm without waiting for escalation. If no operator was assigned, the resolving operator is recorded. `422` `"This incident has already been closed."` if it is already resolved or cancelled.

**Request:**
```json
{
  "notes": "Situation resolved. Both parties confirmed safe. False alarm triggered accidentally."
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `notes` | string | Yes | Min 10 characters, max 2000 |

### Log a Call

`POST /api/v1/admin/sos/{incident}/calls`

Adds a call the operator made about the incident to its audit trail. The call itself is placed from the operator's own phone (the passenger's and driver's numbers are on the incident's ride); the API does not place or record calls.

**Request:**
```json
{
  "called_party": "passenger",
  "outcome": "no_answer",
  "phone": "+2348012345678",
  "notes": "Rang twice, went to voicemail."
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `called_party` | string | No | `passenger` (default), `driver`, `emergency_contact` or `other` |
| `outcome` | string | Yes | `reached`, `no_answer`, `busy` or `unreachable` |
| `phone` | string | No | Number called, max 20 |
| `notes` | string | No | Max 2000 |

**Response (201):** `{ "message": "Call logged.", "incident": { ...with event_logs } }`

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
- `admin.sos` (SafetyOperator + SuperAdmin): `sos.incident.triggered` when an SOS is raised, `sos.incident.escalated` when it escalates, and `sos.incident.updated` on **every** later status change (check-in sent, confirmed safe, escalated, taken, dispatched, resolved, cancelled) and on reassignment. Payload: `incident_id`, `reference_number`, `ride_id`, `status`, `previous_status`, `queue_status`, `operator_id`, `escalates_at`, `updated_at`. The `triggered` and `escalated` events also carry `reference_number`.
- `user.{userId}`: `sos.check_in.requested` events (triggering user)

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
      "flag_id": "uuid",
      "tier": { "value": 2, "label": "48-Hour Suspension" },
      "action": "suspended_48h",
      "status": { "value": "upheld", "label": "Upheld" },
      "flagged_at": "2026-10-10T14:30:00.000000Z",
      "is_disputed": true,
      "deactivation_confirmed": false,
      "deactivation_response_due_at": null
    },
    "lookback_days": 30
  },
  "recent_flags": [...]
}
```

`active_sanction` comes from the driver's latest flag that has not been overturned, flagged in the last 30 days (or any age if its deactivation was confirmed), whether or not an admin has upheld it. It is `null` otherwise. Before October 20, 2026 an upheld flag showed `null` here even while the driver was suspended.

`deactivation_response_due_at` is set when the sanction is Level 3: the driver has until then (48 hours from when the flag reached Level 3, `OFFLINE_DEACTIVATION_RESPONSE_HOURS`) to dispute before an admin can uphold the flag or confirm the deactivation. Show it as a deadline on the dispute button.

Each flag in `recent_flags` also has, when they apply:
- `deactivation_response_due_at`: as above, on Level 3 flags.
- `deactivation`: `{ "confirmed_at": "...", "reversed_at": null }`. `reversed_at` is set if the deactivation was overturned on appeal.
- `cash_taking`: `{ "verified_at": "..." }`. An admin verified that the driver took cash for the trip, which moved the flag to Level 3.

A flag the driver sees as `upheld` can later become `overturned` (appeal). Don't treat `upheld` as final.

### Dispute Offline Flag

`POST /api/v1/driver/flags/{flag}/dispute`

Driver disputes an offline trip flag. Only the flagged driver can dispute, once, and not after an admin has reviewed the flag. The time and channel (`driver_app`) are recorded for the admin console.

On a Level 3 flag the driver has until `deactivation_response_due_at` (48 hours after the flag reached Level 3) before an admin can uphold it or confirm the deactivation. Disputing in that time means the admin must review the dispute first. A dispute filed after the deadline is still accepted until the flag is reviewed.

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

**Response 422:** Already disputed, already reviewed (`"This flag has already been reviewed."`), wrong driver, or validation error.

---

## Admin — Offline Trip Flags

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations,safety_operator`

**Flag status.** Each flag carries a `status`:

| `status` | Meaning |
|---|---|
| `flagged` | Raised by detection; no dispute and no review yet |
| `disputed` | The driver disputed it and it awaits a decision |
| `upheld` | An admin upheld it. It can still be overturned, escalated or de-escalated on appeal |
| `overturned` | An admin overturned it (its sanction is lifted and it no longer counts towards later sanctions). Final |

**When sanctions apply.** A flag's sanction starts when detection raises it, not when an admin upholds it: L1 sends a warning, L2 suspends the driver for 48 hours, L3 suspends them pending review. Upholding keeps the sanction; overturning lifts it. Level 3 never deactivates on its own: an admin confirms it with [Confirm Permanent Deactivation](#confirm-permanent-deactivation).

**The driver's time to respond.** When a flag reaches Level 3 (raised at Level 3, escalated, or by verified cash-taking) the driver gets **48 hours** (`OFFLINE_DEACTIVATION_RESPONSE_HOURS`) to dispute it, shown as `deactivation_response_due_at`. Until then, unless the driver disputes sooner, nobody can uphold the flag or confirm the deactivation (422: `"The driver has until 22 Oct 2026, 15:10 WAT to respond to this Level 3 flag. You can uphold it or confirm the deactivation after that, or as soon as they dispute it."`). A dispute ends the wait: review it as usual. A **super admin** can still confirm before the deadline in an urgent case; the history says so. Overturning and de-escalating are always allowed. De-escalating below Level 3 clears the deadline; escalating back starts a new one. Level 3 flags raised before October 20, 2026 got a deadline of `flagged_at` + 48 hours.

**What the admin can do now.** Show and every action's response include `flag.allowed_actions`, the rules below applied to the flag as it stands, for the admin making the request. Use it to show or hide buttons instead of repeating the rules in the dashboard:

| Action | Allowed when |
|---|---|
| `uphold` | Not yet upheld or overturned, and not an undisputed Level 3 flag inside the driver's response window |
| `overturn` | Not yet overturned. An upheld flag can be overturned (an appeal). A flag whose deactivation was confirmed can be overturned only by a super admin (OQ-14) |
| `escalate` | Below Level 3, not overturned, and the driver is not deactivated |
| `de_escalate` | Above Level 1, not overturned, and the deactivation has not been confirmed |
| `confirm_deactivation` | Level 3, not overturned, no dispute awaiting review, the driver's response window over (or they disputed; a super admin need not wait), not confirmed already, driver not already deactivated |
| `verify_cash_taking` | Not overturned, not verified already, deactivation not confirmed |

### List Offline Trip Flags

`GET /api/v1/admin/offline-flags`

List flagged trips with optional filters and pagination, newest first.

| Query Param | Type | Description |
|-------------|------|-------------|
| `status` | string | `flagged`, `disputed`, `upheld`, `overturned`, or `active` (not yet resolved) |
| `search` | string | Flag reference (`FLG-000045`), ride reference, or the driver's name, phone or driver ID |
| `sanction_tier` | int | Filter by tier: 1 (Warning), 2 (Suspension), 3 (Deactivation) |
| `is_disputed` | bool | Filter by disputed status |
| `dispute_outcome` | string | Filter: pending, upheld, overturned |
| `driver_id` | uuid | Filter by driver user ID |
| `date_from` | datetime | Filter from date |
| `date_to` | datetime | Filter to date |
| `per_page` | int | Items per page (default 20) |

List items have the same fields as Show's `flag` except `driver_city`, `driver_status`, `driver_suspension_ends_at` and `allowed_actions`.

### Show Offline Trip Flag

`GET /api/v1/admin/offline-flags/{flag}`

The flag with its evidence, the driver (with their city), the passenger and ride, the driver's earlier flags, the route map and the flag's history.

**Response 200** (a first offence moved to Level 3 after the passenger confirmed paying cash; from the seeded test data):
```json
{
  "message": "Offline trip flag details retrieved.",
  "flag": {
    "id": "uuid",
    "reference_number": "FLG-000021",
    "ride_id": "uuid",
    "driver_id": "uuid",
    "passenger_id": "uuid",
    "status": { "value": "flagged", "label": "Flagged" },
    "sanction_tier": { "value": 3, "label": "Permanent Deactivation" },
    "sanction_action": "pending_admin_review",
    "deactivation_response_due_at": "2026-10-22T14:10:24.000000Z",
    "is_disputed": false,
    "review_notes": null,
    "cash_taking": {
      "verified_at": "2026-10-20T13:44:24.000000Z",
      "verified_by": { "id": "uuid", "name": "Operations Manager" },
      "notes": "Passenger called support: the driver cancelled at the pickup and asked for ₦4,000 cash to do the trip off the app. Passenger paid."
    },
    "detection_summary": "The ride was cancelled with the driver 0.07 km from the pickup. Afterwards, the driver's phone followed the passenger's route: 90.9% of its location samples (20 of 22) were within 200 m of it, over 10 min 30 s.",
    "evidence": {
      "route_match_percentage": 90.9,
      "location_samples": 22,
      "on_route_samples": 20,
      "threshold_meters": 200,
      "cancel_distance_from_pickup_km": 0.07,
      "on_route_duration_seconds": 630,
      "average_gps_accuracy_meters": 6
    },
    "detection_data": { "...": "raw detection output" },
    "flagged_at": "2026-10-20T12:54:24.000000Z",
    "resolved_at": null,
    "driver": { "id": "uuid", "first_name": "Funke", "last_name": "Ajayi", "phone": "+2348300000031", "is_active": true, "...": "..." },
    "driver_city": { "id": "uuid", "name": "Lagos", "...": "..." },
    "driver_status": "suspended",
    "driver_suspension_ends_at": null,
    "allowed_actions": {
      "uphold": false,
      "overturn": true,
      "escalate": false,
      "de_escalate": true,
      "confirm_deactivation": false,
      "verify_cash_taking": false
    },
    "passenger": { "id": "uuid", "first_name": "Femi", "...": "..." },
    "ride": { "id": "uuid", "reference_number": "TRP-NG-000021", "pickup": { "...": "..." }, "destination": { "...": "..." }, "...": "..." },
    "resolved_by": null,
    "created_at": "2026-10-20T12:54:24.000000Z"
  },
  "previous_flags": [],
  "previous_flags_window_days": 30,
  "route_map": {
    "pickup": { "lat": 6.5523659, "lng": 3.386774, "address": "Gbagada General Hospital, Gbagada" },
    "destination": { "lat": 6.6143785, "lng": 3.357768, "address": "Ikeja City Mall, Alausa, Ikeja" },
    "route": { "encoded_polyline": "gw~f@mntSvAbAl@q@T]BOIaAMuAC[GWGUMCUAO?A...", "source": "planned_route" },
    "corridor_meters": 200,
    "cancelled_at": "2026-10-20T12:19:24.000000Z",
    "cancel_point": { "lat": 6.55201, "lng": 3.386296, "accuracy_meters": 5.4 },
    "driver_path": [
      { "lat": 6.553099, "lng": 3.385821, "recorded_at": "2026-10-20T12:19:54.000000Z", "seconds_after_cancellation": 30, "distance_from_route_meters": 134.9, "on_route": true, "accuracy_meters": 6 },
      { "lat": 6.554613, "lng": 3.3892, "recorded_at": "2026-10-20T12:20:24.000000Z", "seconds_after_cancellation": 60, "distance_from_route_meters": 144, "on_route": true, "accuracy_meters": 6 },
      { "...": "one point every 30 seconds" }
    ]
  },
  "history": [
    { "event": "offline_trip_flagged", "description": "Flagged by automatic detection", "notes": null, "actor": null, "created_at": "2026-10-20T12:54:24.000000Z" },
    { "event": "offline_flag_cash_taking_verified", "description": "Cash-taking verified from Level 1 to Level 3", "notes": "Passenger called support: ...", "actor": { "id": "uuid", "name": "Operations Manager", "role": "operations" }, "created_at": "2026-10-20T13:44:24.000000Z" }
  ]
}
```

| Field | Notes |
|-------|-------|
| `detection_summary` | Why the trip was flagged, as one sentence built from the evidence. `null` if the flag has no detection figures. |
| `evidence` | The figures behind the flag. `cancel_distance_from_pickup_km` (driver's distance from the pickup when the ride was cancelled), `on_route_duration_seconds` (from the first to the last location sample on the passenger's route) and `average_gps_accuracy_meters` were added in October 2026: older flags have `null` for them, and GPS accuracy stays `null` until the driver app sends `accuracy` with its location updates. |
| `dispute` | When and through which app the driver disputed; `channel` is `driver_app`. Only present on disputed flags. |
| `review_notes` | The reason for the latest uphold or overturn. After an appeal it is the appeal's reason; the first reason stays in `history`. |
| `deactivation` | Only once a Level 3 deactivation is confirmed: `confirmed_at`, `confirmed_by` (`id`, `name`), `notes` (the reason given) and `reversed_at` (set when a super admin overturns the flag on appeal, which reactivates the driver). |
| `cash_taking` | Only when an admin verified cash-taking: `verified_at`, `verified_by`, `notes`. |
| `sanction_action` | `warning_issued`, `suspended_48h`, `pending_admin_review`, or `deactivated` once the deactivation is confirmed. |
| `deactivation_response_due_at` | Level 3 flags only: the end of the driver's 48-hour window to respond (see above). |
| `driver_status` | The driver profile's status: `approved`, `suspended`, `deactivated`, etc. Use this to show that the driver is deactivated. `driver.is_active` is the login account and does not change when a driver is deactivated. |
| `driver_suspension_ends_at` | When the driver's automatic 48-hour suspension lifts (`suspended_at` + `OFFLINE_SUSPENSION_HOURS`). `null` for any other status, including suspended pending review. |
| `allowed_actions` | See the table above. |
| `previous_flags` | The driver's other flags in the 30 days (`OFFLINE_SANCTION_LOOKBACK_DAYS`) up to this one, newest first. |
| `route_map` | See below. |
| `history` | Each step on the flag, oldest first, with the reason and who did it (`role` is the admin role, or `driver`). `description` is one of: "Flagged by automatic detection", "Disputed by the driver", "Upheld", "Overturned", "Overturned on appeal" (an upheld flag overturned later), "Escalated from Level 1 to Level 2", "De-escalated from Level 3 to Level 2", "Cash-taking verified from Level 1 to Level 3" (no "from…to" when the flag was already Level 3), "Deactivation confirmed", or "Upheld and deactivation confirmed" (confirmed on a flag nobody had reviewed). A super admin's confirmation before the driver's deadline adds " before the driver's response deadline". |

**`route_map`** has what the compliance map draws:

| Field | Notes |
|---|---|
| `pickup`, `destination` | The ride's points, with addresses. |
| `route` | The line the driver's positions were measured against, as an encoded polyline (Google format, precision 5; the same format as `route` in Live Monitoring and SOS). `source` is `planned_route` (the driving route from the maps provider) or `straight_line` (no route was available, so pickup to destination). Draw it with a `corridor_meters` buffer: positions inside count as on the route. |
| `corridor_meters` | `OFFLINE_COLLOCATION_THRESHOLD`, 200 m. |
| `cancelled_at` | When the ride was cancelled; the start of the monitoring. |
| `cancel_point` | Where the driver was when the ride was cancelled, with GPS accuracy. |
| `driver_path` | The driver's positions after the cancellation, oldest first, one every 30 s for up to 15 min. `seconds_after_cancellation` is for markers like "+4 min"; `on_route` and `distance_from_route_meters` show which positions counted. |

Flags raised before October 20, 2026 have `route`, `cancelled_at` and `cancel_point` as `null` and an empty `driver_path`: draw the pickup and destination only. From October 20, 2026 the detection measures against the planned driving route rather than the straight line from pickup to destination.

The detection measures the **driver's** phone against the passenger's planned route after the cancellation; the passenger's phone is not tracked after a cancellation, so there is no "phones stayed together" figure. Audio is not recorded.

### Review a Flag (uphold or overturn)

`POST /api/v1/admin/offline-flags/{flag}/review`

Uphold or overturn a flag, whether or not the driver disputed it. Overturning lifts the flag's sanction and the flag stops counting towards later sanctions.

**Appeals.** An upheld flag can be overturned later (`description` "Overturned on appeal" in `history`). An overturn is final. Overturning a flag whose deactivation was confirmed needs a **super admin** (OQ-14) and reactivates the driver.

**What overturning does to the driver.** When the overturned flag is the driver's latest flag that still counted, their status is set again from their remaining flags: suspended pending review after an unconfirmed Level 3, suspended until 48 hours after a recent Level 2 (counted from that flag), otherwise approved. Overturning an older flag leaves the driver as they are. A suspension an admin made by hand for another reason is never lifted. The driver gets a push (`Account Reinstated`, or `Flag Overturned` if another flag keeps them suspended).

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
| `notes` | string | Required, min 10, max 2000 chars. Returned as `review_notes`. |

**Response 200:**
```json
{
  "message": "Dispute Upheld.",
  "flag": { "...": "as in Show, with allowed_actions" }
}
```

The message reads `Dispute …` for a disputed flag and `Flag …` otherwise.

**Response 422** (`errors.flag`): `"This flag has already been reviewed."` (upholding an upheld or overturned flag, or overturning an overturned one), `"Only a super admin can overturn a flag whose deactivation was confirmed."`, `"The driver has until … WAT to respond to this Level 3 flag. …"` (upholding an undisputed Level 3 flag inside the driver's window), or a validation error.

### Escalate Flag Sanction

`POST /api/v1/admin/offline-flags/{flag}/escalate`

Move the flag up one level (L1→L2 or L2→L3) and apply that level's sanction. Works on flagged, disputed and upheld flags; not beyond Level 3 or on an overturned flag. Escalating to Level 3 starts the driver's 48-hour response window (`deactivation_response_due_at`), so the deactivation cannot be confirmed straight away.

**Request Body:**
```json
{
  "notes": "Pattern of abuse confirmed after investigation."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `notes` | string | **Required**, min 10, max 2000 chars. Shown in the flag's `history`. |

**Response 200:**
```json
{
  "message": "Sanction escalated to 48-Hour Suspension.",
  "flag": { "...": "as in Show, with allowed_actions" }
}
```

**Response 422** (`errors.flag`): `"This flag is already at the maximum sanction tier."`, `"This flag was overturned and no longer counts against the driver."`, `"The driver has been deactivated, so their flags cannot be escalated."` (escalating an older flag would suspend them again, and the suspension would later lift), or reason missing.

No sanction ever changes a deactivated driver's status: only a super admin overturning the flag behind the deactivation undoes it.

### De-escalate Flag Sanction

`POST /api/v1/admin/offline-flags/{flag}/de-escalate`

Move the flag down one level (L3→L2 or L2→L1). Works on flagged, disputed and upheld flags. When the flag is the driver's latest and their current suspension came from the compliance system:
- **L3 → L2:** the driver stays suspended but is no longer under deactivation review; the 48-hour suspension runs from when it began and then lifts automatically.
- **L2 → L1:** the suspension is lifted and the driver is reinstated (approved).

A suspension an admin made by hand for another reason is never changed. The driver gets a push notification. Moving down from Level 3 clears `deactivation_response_due_at`; escalating back to Level 3 starts a new 48-hour window.

**Request Body:**
```json
{
  "notes": "First offence pattern; reducing to a suspension."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `notes` | string | Required, min 10, max 2000 chars. Shown in the flag's `history`. |

**Response 200:** `{ "message": "Sanction reduced to 48-Hour Suspension.", "flag": { ... } }`

**Response 422** (`errors.flag`): `"This flag is already at the lowest sanction tier."`, `"This flag was overturned and no longer counts against the driver."`, or `"The driver's deactivation was confirmed. To undo it, a super admin can overturn the flag on appeal."`

To remove every sanction from a flag, overturn it (Review, `outcome: overturned`).

### Verify Cash-Taking

`POST /api/v1/admin/offline-flags/{flag}/verify-cash-taking`

Record that an admin verified the driver took cash for the trip, for example because the passenger confirmed paying the driver. This moves the flag **straight to Level 3**, whatever the driver's earlier flags, and suspends the driver pending review. It does not deactivate them: [confirm the deactivation](#confirm-permanent-deactivation) as for any Level 3, once the driver's 48-hour response window has passed. Who verified it, when and how are kept on the flag (`cash_taking`) and in `history` ("Cash-taking verified from Level 1 to Level 3").

**Request Body:**
```json
{
  "notes": "Passenger confirmed by phone they paid ₦6,000 cash for the trip."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `notes` | string | Required, min 10, max 2000 chars. How it was verified. |

**Response 200:**
```json
{
  "message": "Cash-taking verified. The flag is now Level 3; confirm the deactivation to make it permanent.",
  "flag": { "...": "as in Show: sanction_tier 3, sanction_action pending_admin_review, cash_taking set" }
}
```

On a flag already at Level 3 the message is `"Cash-taking verified."` and the tier does not change.

**Response 422** (`errors.flag`): `"This flag was overturned and no longer counts against the driver."`, `"Cash-taking has already been verified on this flag."`, or `"The driver's deactivation was confirmed. To undo it, a super admin can overturn the flag on appeal."`

### Confirm Permanent Deactivation

`POST /api/v1/admin/offline-flags/{flag}/confirm-deactivation`

Permanently deactivate the driver on a Level 3 flag. Not while the driver's dispute of the flag awaits review, not after the flag was overturned, and not before the driver's response deadline (`deactivation_response_due_at`) unless they disputed or the admin is a super admin. Sets the driver's status to `deactivated`, forces them offline, removes them from the live driver index and sends a push notification. Level 3 never deactivates a driver on its own: this human confirmation is required.

**A flag nobody has reviewed is upheld in the same step**, with the same reason, so "Uphold and deactivate" is one call. On a flag already upheld, the earlier `review_notes` stay as they were.

**Auth required (admin).** Roles: `operations`, `safety_operator`

**Request Body:**
```json
{
  "notes": "Third off-app trip in 30 days. The passenger confirmed paying cash."
}
```

| Field | Type | Rules |
|-------|------|-------|
| `notes` | string | **Required** (since October 20, 2026), min 10, max 2000 chars. Returned in `deactivation.notes` and the flag's `history`. |

**Response 200:**
```json
{
  "message": "Driver permanently deactivated after admin review.",
  "flag": {
    "id": "uuid",
    "reference_number": "FLG-000015",
    "status": { "value": "upheld", "label": "Upheld" },
    "sanction_tier": { "value": 3, "label": "Permanent Deactivation" },
    "sanction_action": "deactivated",
    "dispute_outcome": { "value": "upheld", "label": "Upheld" },
    "review_notes": "Third off-app trip in 30 days. The passenger confirmed paying cash.",
    "deactivation": {
      "confirmed_at": "2026-10-18T16:30:00.000000Z",
      "reversed_at": null,
      "confirmed_by": { "id": "uuid", "name": "Safety Operator" },
      "notes": "Third off-app trip in 30 days. The passenger confirmed paying cash."
    },
    "resolved_at": "2026-10-18T16:30:00.000000Z",
    "driver_status": "deactivated",
    "allowed_actions": {
      "uphold": false,
      "overturn": false,
      "escalate": false,
      "de_escalate": false,
      "confirm_deactivation": false,
      "verify_cash_taking": false
    },
    "...": "other fields as in Show"
  }
}
```

For a super admin, `allowed_actions.overturn` is `true` on this flag.

**Response 422:** one of:
- `"The notes field is required."` (or too short/long) in `errors.notes`
- `"Only L3 (deactivation-tier) flags can be confirmed for permanent deactivation."`
- `"Review the driver's dispute before confirming deactivation."` (dispute pending)
- `"The driver has until 22 Oct 2026, 15:10 WAT to respond to this Level 3 flag. You can uphold it or confirm the deactivation after that, or as soon as they dispute it."` (response window still open; not for a super admin)
- `"This flag was overturned and cannot lead to deactivation."`
- `"Deactivation has already been confirmed for this flag."`
- `"Driver is already deactivated."`

**Response 404:**
```json
{
  "message": "Driver not found."
}
```

#### Undoing a confirmed deactivation

| Action | After confirmation |
|---|---|
| Overturn (Review, `outcome: overturned`) | **Super admin only** (OQ-14). Reactivates the driver: their status is set from their remaining flags (usually `approved`), `deactivation.reversed_at` is set, the driver gets an `Account Reinstated` push, and the driver record is audited as `compliance_deactivation_reversed`. |
| De-escalate | Refused. A permanent deactivation is not reduced to a suspension; overturn it if it was wrong. |
| Escalate, verify cash-taking | Refused (already Level 3 and confirmed). |
| Driver management `POST /admin/drivers/{driver}/reactivate` | Refused: it reactivates suspended drivers only. |

Deactivations confirmed before October 20, 2026 got `deactivation.confirmed_at` and `confirmed_by` from the audit log when the migration ran; their `notes` are `null`.

**Sanction Tiers:**
| Tier | Label | Action |
|------|-------|--------|
| 1 | Warning | Push notification warning |
| 2 | 48-Hour Suspension | Force offline + suspend account. Lifted automatically after `OFFLINE_SUSPENSION_HOURS` (default 48) by the hourly `LiftExpiredComplianceSuspensionsJob`; the driver is notified. Suspensions set by an admin for other reasons are never lifted automatically. |
| 3 | Permanent Deactivation | Force offline + suspend pending mandatory admin review (confirm-deactivation above). Reached by a third flag in 30 days, by escalation, or by verified cash-taking. |

### Test Data (staging)

`php artisan db:seed --class=OfflineFlagsDemoSeeder` (non-production only; needs Lagos and the economy and comfort classes from `DemoSeeder`) creates 22 flags for 11 Lagos drivers over the last 30 days, covering every status at every level. Each is on a cancelled ride, with detection evidence, a route map (the real detection run on a driver path along the passenger's planned route) and a review history:

| Driver | Flags | Driver now |
|--------|-------|------------|
| Kola Adebayo | L1 this morning, flagged | Approved |
| Ada Obi | L1 this morning, **disputed** (pending) | Approved |
| Bisi Okonkwo | L1 18 days ago; L2 yesterday, **disputed** (pending) | Suspended 48 h |
| Musa Garba | L1, L2, then L3 three days ago, not disputed and his 48 hours are over: ready for confirm-deactivation or de-escalate | Suspended pending review |
| Funke Ajayi | L1 three hours ago, moved to **L3 by verified cash-taking**; still inside her 48 hours to respond, so uphold and confirm are refused (except for a super admin) | Suspended pending review |
| Tayo Bankole | L1, disputed and **overturned** by Operations | Approved |
| Ngozi Eze | L1 disputed and **upheld**; L2 four days ago | Approved (suspension lifted) |
| Yemi Ogunleye | L1 **upheld**; L2 disputed, upheld, then **overturned on appeal** by Safety | Approved |
| Chidi Nwosu | L1; L2 disputed and upheld by Safety; L3 yesterday, **disputed** (pending) | Suspended pending review |
| Emeka Udeh | L1, L2; L3 two days ago, disputed and **overturned** by Safety | Approved |
| Segun Alabi | L1, L2; L3 three days ago, no dispute in 48 hours, then **upheld and deactivation confirmed** by Safety (super admin can overturn on appeal) | **Deactivated** |

That makes 12 flagged, 3 disputed, 4 upheld and 3 overturned:

| | L1 | L2 | L3 |
|---|---|---|---|
| Flagged | Kola, Musa, Bisi, Chidi, Segun, Emeka | Musa, Ngozi, Segun, Emeka | Musa, Funke |
| Disputed | Ada | Bisi | Chidi |
| Upheld | Ngozi, Yemi | Chidi | Segun |
| Overturned | Tayo | Yemi | Emeka |

The drivers use `driver.compliance.*@ops-demo.etigo.com` emails (password `Qwer!234`), stay offline and are separate from the Live Monitoring demo drivers, so acting on their flags affects nothing else. Re-run the seeder to put all 22 flags back as they were, dated relative to now. With a maps provider configured, the route is the real driving route and a few figures can differ slightly from the ones above when an off-route position lands near another part of the road.

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
    "stalls": [
      {
        "id": "uuid",
        "stall_number": 1,
        "status": { "value": "occupied", "label": "Occupied" },
        "occupied_since": "2026-10-11T09:40:00.000000Z",
        "estimated_departure_at": "2026-10-11T10:15:00.000000Z",
        "updated_at": "2026-10-11T09:40:00.000000Z"
      }
    ]
  }
}
```

Admins also get `current_driver` (the driver on the stall) on each stall, when loaded.

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
| requested_date | date    | No       | Preferred date, today or later in Lagos time (defaults to today in Lagos) |
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

**Response 422:** Validation error (battery_level out of range, a past `requested_date`, invalid station_id, etc.), or `"You already have an active reservation."` while another request or reservation is open.

Operations review the request (see **Admin — EV Charge Requests**). Until then it stays `pending_review`; one nobody reviews by the end of `requested_date` (Lagos) becomes `expired` and the driver gets `ev_charge_request_declined`. The driver can cancel it with **Cancel Reservation** at any time before the slot starts.

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
    "type": "charging",
    "battery_level": 18,
    "requested_date": "2026-10-11",
    "requested_time": "10:00",
    "notes": null,
    "approver_name": null,
    "confirmed_slot": null,
    "check_in_time": null,
    "queue_position": null,
    "queue_ahead_count": null,
    "queue_ahead_description": null,
    "estimated_available_at": null,
    "fee_amount": "500.00",
    "fee_waived": false,
    "fee_charged_at": "2026-10-11T10:00:00.000000Z",
    "fee_refunded_at": null,
    "free_cancellation_until": "2026-10-11T10:02:00.000000Z",
    "reserved_at": "2026-10-11T10:00:00.000000Z",
    "activated_at": null,
    "completed_at": null,
    "vehicle_name": "Hyundai Kona Electric",
    "vehicle_plate": "LND-482-KJ",
    "vehicle_color": "White",
    "station_name": "Lekki Phase 1 Charging Hub",
    "station_address": "12 Admiralty Way, Lekki Phase 1, Lagos",
    "assigned_bay": "Bay 3 · DC fast charger",
    "submitted_at": "2026-10-11T09:58:00.000000Z",
    "updated_at": "2026-10-11T10:00:00.000000Z",
    "created_at": "2026-10-11T09:58:00.000000Z"
  }
}
```

Every reservation response has this shape. `approver_name`, `confirmed_slot` and `check_in_time` are set when an operator confirms a charge request. `queue_position`, `queue_ahead_count` and `queue_ahead_description` are set while queued. `vehicle_*` comes from the driver's vehicle, `station_name`/`station_address` from the station, and `assigned_bay` from the stall (`null` until one is assigned). `station`, `stall` and `driver` objects are included when loaded.

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

**Station object.** Admin and public station endpoints return:

```json
{
  "id": "uuid",
  "name": "Lekki Phase 1 Charging Hub",
  "city_id": "uuid",
  "lat": "6.4378000",
  "lng": "3.4709000",
  "address": "12 Admiralty Way, Lekki Phase 1, Lagos",
  "total_stalls": 6,
  "connector_types": ["CCS2", "Type2"],
  "power_output_kw": 150,
  "pricing_per_kwh": 15000,
  "operating_hours": { "open": "06:00", "close": "22:00" },
  "status": { "value": "maintenance", "label": "Under Maintenance" },
  "availability": { "total": 6, "available": 3, "occupied": 2, "reserved": 1, "out_of_service": 0, "queue_length": 1 },
  "city": { "...": "..." },
  "stalls": [
    {
      "id": "uuid",
      "stall_number": 1,
      "status": { "value": "occupied", "label": "Occupied" },
      "occupied_since": "2026-10-21T09:35:00.000000Z",
      "estimated_departure_at": "2026-10-21T10:25:00.000000Z",
      "current_driver": {
        "id": "uuid",
        "name": "Ifeoma Chukwu",
        "phone": "+2348300000041",
        "vehicle": { "make": "BYD", "model": "Dolphin", "colour": "White", "plate_number": "LND-241EV" }
      },
      "updated_at": "2026-10-21T09:35:00.000000Z"
    },
    {
      "id": "uuid",
      "stall_number": 3,
      "status": { "value": "available", "label": "Available" },
      "occupied_since": null,
      "estimated_departure_at": null,
      "current_driver": null,
      "updated_at": "2026-10-21T08:00:00.000000Z"
    }
  ]
}
```

`connector_types`, `power_output_kw`, `pricing_per_kwh` (kobo per kWh) and `operating_hours` are returned as saved, so an edit form can show them. `status` is `active`, `inactive` or `maintenance` ("Under Maintenance": drivers cannot reserve).

On every admin station response (List, Show, Create, Update, Toggle Status, Manage Stalls) the station has the same shape:
- `availability` always includes `queue_length` (drivers waiting for a stall). The public endpoints leave `queue_length` out.
- `stalls` are in stall-number order, each with `current_driver`. The public endpoints leave `current_driver` out.
- `current_driver` is the driver charging at the stall: `{ id, name, phone, vehicle }`, with `vehicle` `{ make, model, colour, plate_number }` (or `null` if they have no vehicle on file). The plate is on `vehicle`. It is `null` when the stall is not occupied, and also when an admin marked it occupied by hand (say, a car from outside E-tiGo). A queue entry's `driver` has the same shape.
- `occupied_since` and `estimated_departure_at` are set while a driver charges. `estimated_departure_at` is `null` when the driver gave no charging time; it can be in the past when a driver stays longer than expected.

**Live updates:** the `admin.ev-stations` private channel (operations and super admins) receives `ev.station.availability_changed` whenever a stall changes status, a driver joins or leaves a queue (or moves in it), or a station is created, switched on or off, resized or deleted. Payload: `station_id`, `deleted`, `status`, `availability` (same shape as above), `updated_at`; a deleted station sends only `station_id` and `deleted: true`. The figures are read when the event is sent, so after a burst of changes the last event has the latest state.

### List Stations

```
GET /admin/ev-stations?city_id=&status=&search=&per_page=20
Authorization: Bearer {admin_token}
```

| Param | Type | Rules |
|-------|------|-------|
| `city_id` | string (UUID) | Optional filter |
| `status` | string | Optional: `active`, `inactive`, `maintenance` |
| `search` | string | Optional, search by name (case-insensitive, max 100) |
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
| `city_id` | string (UUID) | Optional, must exist. Left out, the city is worked out from `lat`/`lng` (city boundaries, then reverse geocoding); 422 on `city_id` if the point is in no active city. |
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

Returns the station with its stalls in number order (each with its current driver and that driver's vehicle), availability, and the queue. On staging, `EvStationsDemoSeeder` keeps a filled example: "Yaba EV Hub" (two drivers charging, one stall out of service, three drivers queued). The Postman request **Admin — EV Station Management → Show Station** has its staging response saved.

**Response 200:**
```json
{
  "station": {
    "...": "...",
    "availability": { "total": 3, "available": 0, "occupied": 2, "reserved": 0, "out_of_service": 1, "queue_length": 3 },
    "stalls": [ "... see the station object above ..." ]
  },
  "availability": { "total": 3, "available": 0, "occupied": 2, "reserved": 0, "out_of_service": 1, "queue_length": 3 },
  "queue": [
    {
      "reservation_id": "uuid",
      "position": 1,
      "driver": {
        "id": "uuid",
        "name": "Zainab Lawal",
        "phone": "+2348300000043",
        "vehicle": { "make": "Nissan", "model": "Leaf", "colour": "Blue", "plate_number": "LND-243EV" }
      },
      "battery_level": 18,
      "joined_at": "2026-10-21T09:46:00.000000Z",
      "wait_seconds": 840,
      "estimated_available_at": "2026-10-21T10:08:00.000000Z"
    }
  ]
}
```

`queue` lists drivers waiting for a stall in the order they joined; `wait_seconds` counts from `joined_at`. `driver` has the same shape as a stall's `current_driver`, with the vehicle (and plate) inside it. `estimated_available_at` is when a stall is expected to free up for them (`null` if unknown).

**Deprecated:** the top-level `availability` repeats `station.availability`. Read `station.availability`; the top-level copy will be removed in a later release.

**Changed 2026-10-21:** a queue entry's vehicle moved from `queue[].vehicle` to `queue[].driver.vehicle`, and `current_driver` changed from the full user to `{ id, name, phone, vehicle }`. Neither had data on staging before, so nothing should be reading the old shapes.

### Update Station

```
PUT /admin/ev-stations/{station}
Authorization: Bearer {admin_token}
Content-Type: application/json
```

All create fields are optional here, including `total_stalls` (1–100):
- **Higher:** new stalls are added after the highest stall number, as `available`.
- **Lower:** the highest-numbered stalls are removed, provided each is `available` or `out_of_service` and nobody holds a reservation on it. Otherwise 422 on `total_stalls`, e.g. `"Stall 6 is in use. Free it before reducing the stall count."`, and nothing changes.

**Request Body:**
```json
{
  "name": "Updated Hub Name",
  "address": "New address",
  "total_stalls": 8
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

Upserts stalls by stall number (to change individual stall statuses or add specific numbers). Auto-updates `total_stalls` count. To remove stalls, lower `total_stalls` with Update Station.

Setting a stall to anything other than `occupied` clears its `current_driver`, `occupied_since` and `estimated_departure_at`. Marking a stall `occupied` by hand starts it afresh: `occupied_since` is now, with no driver and no departure time.

A stall that a driver holds a reservation on (pending review, reserved or active) can't be changed to another status: 422 on `stalls`, e.g. `"Stall 1 has a driver's reservation. Change it once the driver finishes or cancels."`, and nothing in the request is saved. Sending the status it already has is fine.

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

Permanently deletes an EV charging station and all its stalls. Fails if the station has an open reservation (pending review, reserved, queued or active).

**Response 200:**
```json
{
  "message": "EV charging station deleted."
}
```

**Response 422:** Station has active reservations.

---

## Admin — EV Charge Requests

**Middleware:** `auth:sanctum`, `user.type:admin`, `admin.role:operations`

Drivers ask for a charging slot with **Submit EV Charge Request**. Operations confirm it for a slot or decline it. A confirmed request holds a stall when its slot starts, exactly as **Reserve EV Stall** does: the fee is taken then, the stall is held for `EV_RESERVATION_EXPIRY` minutes (default 30), and the driver joins the queue when every stall is busy. `ProcessEvChargeRequestsJob` runs every minute.

Every response carries the reservation object (see **Reserve EV Stall**) with these review fields:

| Field | Description |
|---|---|
| `confirmed_slot_at` | ISO 8601 start of the confirmed slot |
| `confirmed_slot` | The slot time for display, Lagos time (`"10:30 am"`) |
| `check_in_time` | Latest arrival for display: slot + hold minutes (`"11:00 am"`) |
| `approver_name` | Always `"E-tiGo Operations"`; staff names are never sent to drivers |
| `reviewed_at` | When it was confirmed or declined |
| `decline_reason` | The reason given when declined (shown to the driver) |
| `reviewed_by` | Admins only: `{ id, name }` of the reviewer |

### List Charge Requests
```
GET /admin/ev-charge-requests?status=pending_review,confirmed&station_id={id}&city_id={id}&search=ade&per_page=20
Authorization: Bearer {admin_token}
```

| Param | Type | Description |
|---|---|---|
| `status` | string | Comma list of reservation statuses. Default `pending_review,confirmed`. Unknown value → 422 |
| `station_id` | uuid | One station |
| `city_id` | uuid | Stations in one city |
| `search` | string | Driver first name, last name or phone |
| `per_page` | integer | 1–100, default 20 |

Requests awaiting review come first (oldest first), then confirmed slots (soonest first). Direct stall reservations are not listed.

**Response 200:**
```json
{
  "charge_requests": [
    {
      "id": "01a1c3e0-5b2f-7d41-9a6e-3c8f2b1d4e5a",
      "status": { "value": "pending_review", "label": "Pending Review" },
      "battery_level": 18,
      "requested_date": "2026-10-23",
      "requested_time": "10:00 am",
      "notes": "Finishing a trip in Ikeja",
      "station_name": "Lekki Hub",
      "confirmed_slot_at": null,
      "decline_reason": null,
      "vehicle_name": "Mercedes-Benz EQE",
      "vehicle_plate": "ABJ-003EF",
      "...": "..."
    }
  ],
  "summary": { "pending_review": 1, "confirmed": 0 },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

### Confirm Charge Request
```
POST /admin/ev-charge-requests/{reservation}/confirm
Authorization: Bearer {admin_token}
Content-Type: application/json
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `confirmed_slot_at` | datetime | Yes | ISO 8601 with offset. Not more than 5 minutes in the past, at most 7 days ahead |
| `station_id` | uuid | No | Move the request to another station. It must be `active` |

The request becomes `confirmed` and the driver gets `ev_charge_request_confirmed`. A slot that has already started is held at once, so the response can already show `reserved` (or `queued` when the station is full).

**Response 200:**
```json
{
  "message": "Charge request confirmed. The driver has been told.",
  "charge_request": {
    "status": { "value": "confirmed", "label": "Confirmed" },
    "confirmed_slot_at": "2026-10-23T09:30:00.000000Z",
    "confirmed_slot": "10:30 am",
    "check_in_time": "11:00 am",
    "approver_name": "E-tiGo Operations",
    "reviewed_by": { "id": "…", "name": "Ola Ops" },
    "stall_id": null,
    "...": "..."
  }
}
```

**Response 422:** `"Only charge requests awaiting review can be confirmed."` or `"This station is not taking reservations."`, with `current_status`; `errors.confirmed_slot_at` for a slot out of range.

### Decline Charge Request
```
POST /admin/ev-charge-requests/{reservation}/decline
Authorization: Bearer {admin_token}
Content-Type: application/json
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `reason` | string | Yes | 5–500 characters. Shown to the driver |

Works on a request awaiting review or a confirmed slot that has not started. The request becomes `cancelled` and the driver gets `ev_charge_request_declined` ("We couldn't book your charging request: {reason}. You can reserve a stall from the station list.").

**Response 200:** `{ "message": "Charge request declined. The driver has been told.", "charge_request": { "status": { "value": "cancelled" }, "decline_reason": "…", "...": "..." } }`

**Response 422:** `"Only charge requests awaiting review or a confirmed slot that has not started can be declined."` with `current_status`.

### At the slot

| Situation | Outcome | Driver notification |
|---|---|---|
| A stall is free | `reserved`; fee taken; check in by `check_in_time` | `ev_reservation_ready` |
| Every stall busy | `queued`, first come first served; fee taken when a stall is handed over | `ev_reservation_ready` (says they are next in line) |
| Earnings cannot cover the fee | `cancelled`; reason in `notes` | `ev_reservation_cancelled` |
| Station no longer `active` | `cancelled` | `ev_reservation_cancelled` |
| Slot found more than the hold time late (scheduler outage) | `expired` | `ev_reservation_cancelled` ("…We could not hold a stall before your check-in time passed.…") |

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

The wallet is created on first call. Amounts are in kobo. `available` is `balance` minus active holds (`held`), which ride bookings place for the fare estimate. `status` is `active`, `frozen` or `closed`.

**Response 200:**
```json
{
  "wallet": {
    "id": "01a1421a-2a78-73a4-b9f4-bfde68c5d57f",
    "currency": "NGN",
    "status": "active",
    "balance": 500000,
    "available": 500000,
    "held": 0
  }
}
```

### Wallet Transaction History

```
GET /passenger/wallet/transactions?type=credit&from=2026-10-01&to=2026-10-16&page=1
Authorization: Bearer {passenger_token}
```

The passenger's wallet entries, newest first, 20 per page (the page size is fixed).

| Query | Type | Rules | Description |
|---|---|---|---|
| `type` | string | `debit` or `credit` | Only money out (`debit`) or in (`credit`) |
| `from` | date | Date | Entries on or after this date |
| `to` | date | Date | Entries up to the end of this date |
| `page` | integer | — | Page number |

`source_type` is `ride` or `payout` when the entry belongs to one (`source_id` is its ID), otherwise `null`. Top-ups and refunds carry their reference in `journal.metadata`.

**Response 200:**
```json
{
  "transactions": [
    {
      "id": "01a1421a-2a89-7164-a0a1-cecba93c911d",
      "type": "credit",
      "type_label": "Credit",
      "amount": 500000,
      "running_balance": 500000,
      "created_at": "2026-10-16T00:26:13+00:00",
      "source_type": null,
      "source_id": null,
      "journal": {
        "id": "01a1421a-2a7e-73c3-9106-95a5a776ceeb",
        "reference": "JRN-OF9URBRSSFAR-261016",
        "description": "Wallet top-up via Paystack",
        "metadata": { "tx_ref": "TOPUP-8F2K4M9Q1ZXA" },
        "posted_at": "2026-10-16T00:26:13+00:00"
      }
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
| `save_card`    | boolean | No       | Save the card this top-up is paid with as a payment method (default `false`). Only saved if paid by a card Paystack lets us charge again |

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

## Passenger — Balance Due

**Middleware:** `auth:sanctum`, `user.type:passenger`

Money the passenger owes that could not be collected at the time (client decision, 16 October 2026):
- **`late_cancellation_fee`** — a scheduled ride's late-cancellation fee that could not be charged (cash or bank-transfer booking, wallet short or frozen, card declined). Paid to the reserved driver in full once collected.
- **`wallet_shortfall`** — the part of a wallet ride's final fare the wallet could not cover. The ride's payment stays `failed` until the balance is paid; then the payment and the ride's `payment_status` become `captured`, and the driver receives the rest of the fare less the usual commission.
- **`lost_item_return_fee`** — a [Lost & Found](#lost--found) return fee the wallet could not cover when the item came back. Paid to the driver in full once collected; the report's `return_fee.status` then becomes `charged`.

While anything is outstanding, `POST /rides` returns `422` `BALANCE_DUE` (finance can turn this off with the wallet setting `block_booking_with_balance_due`). The passenger gets a `balance_due` notification when a balance is recorded. Bookings made before the balance arose are not cancelled.

### Get Balance Due
```
GET /passenger/balance-due
```

**Response 200:**
```json
{
  "total": 600,
  "currency": "NGN",
  "blocks_booking": true,
  "items": [
    {
      "id": "01a142ed-dd38-724f-a9ba-e9a1e08397c2",
      "reason": "late_cancellation_fee",
      "reason_label": "Late cancellation fee",
      "amount": 600,
      "currency": "NGN",
      "status": "outstanding",
      "ride": {
        "id": "01a142ed-dce0-71fc-97ef-2327192038f7",
        "reference_number": "TRP-NG-000001",
        "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
        "destination_address": "Murtala Muhammed International Airport, Ikeja"
      },
      "paid_at": null,
      "paid_via": null,
      "waived_at": null,
      "created_at": "2026-10-20T04:05:00.000000Z"
    }
  ]
}
```

| Field | Description |
|-------|-------------|
| `total` | Outstanding total, NGN |
| `blocks_booking` | Whether booking is refused until it is paid |
| `items[].reason` | `late_cancellation_fee`, `wallet_shortfall` or `lost_item_return_fee` (`reason_label` for display) |
| `items[].ride` | The ride it came from |

### Pay Balance Due
```
POST /passenger/balance-due/pay
```

Pays **everything outstanding** in one payment. Rate limited to 10 per minute.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| payment_method | string | Yes | `wallet` (the available balance must cover the total) or `card` (charges the default saved card) |

**Response 200:**
```json
{
  "message": "Balance paid. You can book again.",
  "paid": [
    {
      "id": "01a142ed-dd38-724f-a9ba-e9a1e08397c2",
      "reason": "late_cancellation_fee",
      "reason_label": "Late cancellation fee",
      "amount": 600,
      "currency": "NGN",
      "status": "paid",
      "ride": {
        "id": "01a142ed-dce0-71fc-97ef-2327192038f7",
        "reference_number": "TRP-NG-000001",
        "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
        "destination_address": "Murtala Muhammed International Airport, Ikeja"
      },
      "passenger": {
        "id": "01a142ed-dc80-715f-af5b-828a2891ae9f",
        "reference_number": "P-000001",
        "first_name": "Ade",
        "last_name": "Ogunleye",
        "phone": "+2348100000001",
        "email": "ade@demo.etigo.com",
        "type": "passenger",
        "phone_verified_at": "2026-10-19T08:00:00.000000Z",
        "is_active": true,
        "profile_photo_url": null,
        "created_at": "2026-10-19T08:00:00.000000Z"
      },
      "paid_at": "2026-10-20T04:05:00.000000Z",
      "paid_via": "wallet",
      "waived_at": null,
      "created_at": "2026-10-20T04:05:00.000000Z"
    }
  ],
  "total": 0
}
```

**Response 422** (`errors.payment_method`), for example:
```json
{
  "message": "Your wallet balance cannot cover the ₦600.00 you owe. Top up or pay by card.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "payment_method": [
      "Your wallet balance cannot cover the ₦600.00 you owe. Top up or pay by card."
    ]
  }
}
```
Other messages: `You have no balance to pay.`, `Add a card to pay your balance, or pay from your wallet.`, `Your card was declined. Try another card or pay from your wallet.`, `We could not reach the card provider. Please try again shortly or pay from your wallet.`, `A payment for your balance is already in progress.`

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

Sends a 6-digit code to the driver's phone (WhatsApp with SMS fallback through Sendchamp; always `123456` in local/staging). The SMS names its purpose: *"Your E-tiGo code to change your payout bank account is 482910. It expires in 5 minutes. Do not share it with anyone."* The code is required to **add or replace** the payout account, including the first one, so a stolen session cannot direct payouts to another account. Codes expire after 5 minutes; a new code can be requested every 60 seconds. Rate limited to 3 requests per minute.

**Response 200:**
```json
{
  "message": "Verification code sent to your phone.",
  "expires_at": "2026-10-15T10:05:00+00:00"
}
```

**Response 422:** `"Add a phone number to your account before adding bank details."`
**Response 429:** `"Please wait before requesting another code."`, or `"Failed to send verification code. Please try again."` when neither WhatsApp nor SMS accepted the code.

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
GET /admin/wallets?search=ada&status=active&min_balance=100000&page=1&per_page=20
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support, Operations (Support and Operations are read-only)

Passenger wallets, newest first.

| Query | Description |
|---|---|
| `search` | Passenger first name, last name, full name, email, phone or passenger ID (partial match) |
| `status` | `active`, `frozen` or `closed` |
| `min_balance` / `max_balance` | Balance bounds in kobo |
| `page`, `per_page` | Page number; page size (default 20, max 100) |

**Response 200:**
```json
{
  "wallets": [
    {
      "id": "01a1421a-2a78-73a4-b9f4-bfde68c5d57f",
      "type": "passenger_wallet",
      "type_label": "Passenger Wallet",
      "currency": "NGN",
      "status": "active",
      "status_label": "Active",
      "balance": 500000,
      "available_balance": 380000,
      "held": 120000,
      "owner": {
        "id": "uuid",
        "reference_number": "P-000123",
        "name": "Jane Doe",
        "first_name": "Jane",
        "last_name": "Doe",
        "phone": "+2348031234567",
        "email": "jane@example.com"
      },
      "last_activity_at": "2026-10-16T18:02:11+00:00",
      "created_at": "2026-10-16T00:26:13+00:00"
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

`owner.reference_number` is the passenger ID. `last_activity_at` is when money last moved in or out of the wallet (`null` if it never has). A `closed` wallet can no longer be used; show it with its own badge.

### View Wallet

```
GET /admin/wallets/{account}
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support, Operations

The wallet with its passenger, active holds (newest first, each with its ride) and its 20 most recent entries. Only passenger wallets: any other account answers 404 (driver ledgers have their own endpoints).

**Response 200:**
```json
{
  "wallet": {
    "id": "01a1421a-2a78-73a4-b9f4-bfde68c5d57f",
    "type": "passenger_wallet",
    "currency": "NGN",
    "status": "frozen",
    "status_label": "Frozen",
    "balance": 500000,
    "available_balance": 250000,
    "held": 250000,
    "owner": { "id": "uuid", "reference_number": "P-000123", "name": "Jane Doe", "phone": "+234...", "email": "jane@example.com", "...": "..." },
    "last_activity_at": "2026-10-16T18:02:11+00:00",
    "frozen": {
      "frozen_at": "2026-10-17T09:00:00+00:00",
      "reason": "Chargeback investigation opened.",
      "frozen_by": { "id": "uuid", "name": "Kemi Ade", "role": "finance" }
    },
    "created_at": "2026-10-16T00:26:13+00:00"
  },
  "active_holds": [
    {
      "id": "uuid",
      "amount": 250000,
      "status": "active",
      "status_label": "Active",
      "ride_id": "uuid",
      "ride": {
        "id": "uuid",
        "reference_number": "TRP-NG-000412",
        "status": "in_progress",
        "status_label": "In Progress",
        "vehicle_class": "Comfort",
        "pickup_address": "Wuse 2, Abuja",
        "destination_address": "Garki, Abuja"
      },
      "expires_at": "2026-10-17T13:00:00+00:00",
      "created_at": "2026-10-17T09:00:00+00:00"
    }
  ],
  "recent_transactions": [
    {
      "id": "01a1421a-2a89-7164-a0a1-cecba93c911d",
      "type": "credit",
      "type_label": "Credit",
      "transaction_type": { "value": "top_up", "label": "Wallet Top-up" },
      "amount": 500000,
      "running_balance": 500000,
      "created_at": "2026-10-16T00:26:13+00:00",
      "source_type": null,
      "source_id": null,
      "journal": {
        "id": "01a1421a-2a7e-73c3-9106-95a5a776ceeb",
        "reference": "JRN-OF9URBRSSFAR-261016",
        "description": "Wallet top-up via Paystack",
        "metadata": { "tx_ref": "TOPUP-8F2K4M9Q1ZXA" },
        "posted_at": "2026-10-16T00:26:13+00:00"
      }
    }
  ],
  "permissions": { "can_freeze": true }
}
```

| Field | Notes |
|-------|-------|
| `wallet.frozen` | Present only while the wallet is frozen: when, why and by whom. Wallets frozen before October 2026 were filled in from the audit log. |
| `active_holds[].ride` | The ride the hold reserves money for. The hold's `id` is its reference. |
| `recent_transactions[].transaction_type` | What the entry was for (see Wallet Transactions below); `type` stays `credit` or `debit`. |
| `permissions.can_freeze` | Whether the signed-in admin may freeze or unfreeze (Finance, Super Admin). `false` for Support and Operations: show the view-only notice. The server still checks on each action. |

### Wallet Transactions

```
GET /admin/wallets/{account}/transactions?type=ride_payment&status=success&date_from=2026-10-01&date_to=2026-10-17&search=TRP-NG&page=1&per_page=20
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support, Operations

Every money movement on the wallet, plus top-ups that are still pending or failed at the card provider, newest first.

| Query | Description |
|---|---|
| `type` | `top_up`, `ride_payment`, `refund`, `tip`, `cancellation_fee`, `balance_due`, `cash_change`, `adjustment`, `reversal`, … (any transaction type below) |
| `status` | `success`, `pending` or `failed` |
| `direction` | `credit` or `debit` |
| `date_from` / `date_to` | `Y-m-d`, inclusive, as calendar days in Africa/Lagos (`WALLET_TIMEZONE`) |
| `search` | Transaction reference, description or ride reference |
| `page`, `per_page` | Default 20, max 100 |

**Response 200:**
```json
{
  "transactions": [
    {
      "id": "uuid",
      "source": "ledger",
      "type": { "value": "ride_payment", "label": "Ride Payment" },
      "direction": "debit",
      "amount": 150000,
      "status": "success",
      "reference": "JRN-OF9URBRSSFAR-261016",
      "description": "Ride settlement for ride ...",
      "ride_id": "uuid",
      "ride_reference_number": "TRP-NG-000412",
      "created_at": "2026-10-16T18:02:11+00:00"
    },
    {
      "id": "uuid",
      "source": "top_up_attempt",
      "type": { "value": "top_up", "label": "Wallet Top-up" },
      "direction": "credit",
      "amount": 100000,
      "status": "pending",
      "reference": "TOPUP-8F2K4M9Q1ZXA",
      "description": "Wallet top-up",
      "ride_id": null,
      "ride_reference_number": null,
      "created_at": "2026-10-16T17:55:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 47 }
}
```

`source` is `ledger` for money that moved (status always `success`) and `top_up_attempt` for a top-up the provider has not confirmed (`pending`) or that was abandoned or declined (`failed`). A completed top-up appears once, as its ledger entry. Amounts are in kobo.

**Transaction types** (`type.value` → label): `top_up` Wallet Top-up, `ride_payment` Ride Payment, `refund` Ride Refund, `tip` Driver Tip, `cancellation_fee` Cancellation Fee, `balance_due` Balance Due, `cash_change` Cash Change Credit, `adjustment` Manual Adjustment, `reversal` Reversal. (Driver-side types `ride_settlement`, `commission`, `payout`, `ev_reservation_fee`, `fleet_remittance` do not appear on passenger wallets.) `type` is `null` only for a journal the API cannot classify.

### Wallet Audit Trail

```
GET /admin/wallets/{account}/audit-trail?page=1&per_page=20
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support, Operations

Changes staff made to the wallet, newest first: freezes, unfreezes and manual adjustments (requested, approved, rejected).

**Response 200:**
```json
{
  "entries": [
    {
      "id": "uuid",
      "event": "wallet.frozen",
      "description": "Wallet frozen",
      "reason": "Chargeback investigation opened.",
      "amount": null,
      "actor": { "id": "uuid", "name": "Kemi Ade", "role": "finance" },
      "created_at": "2026-10-17T09:00:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

`event` is `wallet.frozen`, `wallet.unfrozen`, `adjustment.created`, `adjustment.approved` or `adjustment.rejected`; `amount` (kobo) is set for adjustments.

### Freeze Wallet

```
POST /admin/wallets/{account}/freeze
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

A frozen wallet cannot be topped up or used to pay. The reason, time and admin are kept on the wallet (`wallet.frozen`) and in the audit trail.

**Request Body:**

| Field    | Type   | Required | Rules                |
|----------|--------|----------|----------------------|
| `reason` | string | Yes      | 10–2000 characters   |

**Response 200:**
```json
{
  "message": "Wallet frozen successfully.",
  "wallet": { "status": "frozen", "frozen": { "frozen_at": "...", "reason": "...", "frozen_by": { "...": "..." } }, "...": "..." }
}
```

**Response 422:** `"Only passenger wallets can be frozen."` or `"Wallet is already frozen."`

### Unfreeze Wallet

```
POST /admin/wallets/{account}/unfreeze
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

**Request Body:**

| Field    | Type   | Required | Rules                |
|----------|--------|----------|----------------------|
| `reason` | string | Yes      | 10–2000 characters   |

**Response 200:**
```json
{
  "message": "Wallet unfrozen successfully.",
  "wallet": { "status": "active", "...": "..." }
}
```

**Response 422:** `"Only passenger wallets can be unfrozen."` or `"Wallet is not frozen."`

### Driver Ledgers

```
GET /admin/driver-ledgers?search=tunde&page=1
GET /admin/driver-ledgers/{account}
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance, Support

The list returns drivers' available-earnings accounts, newest first, 20 per page (fixed). `search` matches the driver's first name, last name or email.

**Response 200 (list):**
```json
{
  "driver_ledgers": [
    {
      "id": "01a1421a-2a8d-736d-9cfb-7ca4c7072353",
      "type": "driver_earnings_available",
      "type_label": "Driver Earnings (Available)",
      "currency": "NGN",
      "status": "active",
      "status_label": "Active",
      "balance": 320000,
      "available_balance": 320000,
      "held": 0,
      "created_at": "2026-10-16T00:26:13+00:00"
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

The detail returns the account, its 20 most recent entries and the driver's 10 most recent payouts, newest first. Each payout is the admin payout object (see **Payouts** below) with `bank_account` included; `driver` and `approved_by` are not loaded here, so those keys are absent. `failure_reason`, `approved_at` and `paid_at` appear only once set. Once #87 is merged, payouts also carry `bank_account_block`.

**Response 200 (detail):**
```json
{
  "driver_ledger": {
    "id": "01a1421a-2a8d-736d-9cfb-7ca4c7072353",
    "type": "driver_earnings_available",
    "type_label": "Driver Earnings (Available)",
    "currency": "NGN",
    "status": "active",
    "status_label": "Active",
    "balance": 320000,
    "available_balance": 320000,
    "held": 0,
    "created_at": "2026-10-16T00:26:13+00:00"
  },
  "recent_transactions": [
    {
      "id": "01a1421a-2a99-701d-828d-810ef81d2773",
      "type": "credit",
      "type_label": "Credit",
      "amount": 320000,
      "running_balance": 320000,
      "created_at": "2026-10-16T00:26:13+00:00",
      "source_type": "ride",
      "source_id": "01a14a20-1c2d-7e3f-9a4b-5c6d7e8f9a0b",
      "journal": {
        "id": "01a1421a-2a91-73fb-acee-15037173c114",
        "reference": "JRN-NBLNKONVEL3X-261016",
        "description": "Card ride settlement for ride 01a14a20-1c2d-7e3f-9a4b-5c6d7e8f9a0b",
        "metadata": { "ride_id": "01a14a20-1c2d-7e3f-9a4b-5c6d7e8f9a0b" },
        "posted_at": "2026-10-16T00:26:13+00:00"
      }
    }
  ],
  "recent_payouts": [
    {
      "id": "01a14229-a599-7360-9d61-373f196f59cf",
      "amount": 200000,
      "status": "paid",
      "status_label": "Paid",
      "requested_at": "2026-10-15T00:43:08+00:00",
      "approved_at": "2026-10-15T00:43:08+00:00",
      "paid_at": "2026-10-15T00:45:08+00:00",
      "bank_account": {
        "id": "01a14229-a575-70c3-8c6f-a455de44365c",
        "bank_code": "058",
        "bank_name": "Guaranty Trust Bank",
        "account_number": "******0473",
        "account_name": "TUNDE BELLO",
        "is_verified": true,
        "is_primary": true,
        "created_at": "2026-10-16T00:43:08+00:00"
      },
      "gateway_transfer_id": "1043901125",
      "needs_attention": false,
      "attention_reason": null,
      "provider_status": "success",
      "created_at": "2026-10-15T00:43:08+00:00"
    }
  ]
}
```

### Ledger Explorer

```
GET /admin/ledger?reference=JRN-B25R&from=2026-10-01&to=2026-10-16&page=1
GET /admin/ledger/export?from=2026-10-01&to=2026-10-16
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance

**List:** journals newest first, 20 per page (fixed), each with its entries (every entry repeats its journal).

| Query | Required | Description |
|---|---|---|
| `reference` | No | Journal reference, partial and case-insensitive (references look like `JRN-B25RAULM5HB3-261016`) |
| `from` | No | Journals posted on or after this date |
| `to` | No | Journals posted up to the end of this date |
| `page` | No | Page number |

**Response 200:**
```json
{
  "journals": [
    {
      "id": "01a1421a-6c66-7103-b8dd-4c4e7313f430",
      "reference": "JRN-B25RAULM5HB3-261016",
      "description": "Wallet top-up via Paystack",
      "metadata": { "tx_ref": "TOPUP-8F2K4M9Q1ZXA" },
      "posted_at": "2026-10-16T00:26:30+00:00",
      "entries": [
        {
          "id": "01a1421a-6c6c-7391-85cd-845f855956eb",
          "type": "debit",
          "type_label": "Debit",
          "amount": 500000,
          "running_balance": -500000,
          "created_at": "2026-10-16T00:26:30+00:00",
          "source_type": null,
          "source_id": null,
          "journal": { "id": "01a1421a-6c66-7103-b8dd-4c4e7313f430", "reference": "JRN-B25RAULM5HB3-261016", "...": "..." }
        },
        {
          "id": "01a1421a-6c6f-7210-adca-4f16034d13ab",
          "type": "credit",
          "type_label": "Credit",
          "amount": 500000,
          "running_balance": 500000,
          "created_at": "2026-10-16T00:26:30+00:00",
          "source_type": null,
          "source_id": null,
          "journal": { "id": "01a1421a-6c66-7103-b8dd-4c4e7313f430", "reference": "JRN-B25RAULM5HB3-261016", "...": "..." }
        }
      ]
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

**Export:** a CSV download (`ledger-export-YYYY-MM-DD.csv`) of every entry posted between `from` and `to`, both **required** (`422` without them). One row per entry, including the account it hit:

```csv
Reference,Description,"Account ID","Account Type","Entry Type","Amount (kobo)","Running Balance","Posted At"
JRN-OF9URBRSSFAR-261016,"Wallet top-up via Paystack",01a1421a-2a41-73c5-856e-064baa04724b,psp_clearing,debit,500000,-500000,2026-10-16T00:26:13+00:00
JRN-OF9URBRSSFAR-261016,"Wallet top-up via Paystack",01a1421a-2a78-73a4-b9f4-bfde68c5d57f,passenger_wallet,credit,500000,500000,2026-10-16T00:26:13+00:00
```

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

`GET /admin/adjustments?status=pending` lists adjustments newest first, 20 per page (fixed). `status` is `pending`, `approved` or `rejected`. Create, approve and reject return the adjustment under `adjustment` with their message. `approved_by` and `approved_at` appear once an adjustment has been approved.

**Response 200 (approve):**
```json
{
  "message": "Adjustment approved and posted.",
  "adjustment": {
    "id": "01a1421f-574d-7397-9535-0b816a98b438",
    "account_id": "01a1421f-572d-7176-a118-adc96379603d",
    "type": "credit",
    "type_label": "Credit",
    "amount": 150000,
    "reason": "Goodwill credit for a cancelled trip",
    "status": "approved",
    "status_label": "Approved",
    "created_by": {
      "id": "01a1421f-5721-7088-a20f-868013afdd6c",
      "first_name": "Ngozi",
      "last_name": "Eze",
      "type": "admin",
      "admin_role": "finance",
      "...": "..."
    },
    "approved_by": {
      "id": "01a1421f-5724-73c7-b071-85cf1df56c5c",
      "first_name": "Kunle",
      "last_name": "Adeyemi",
      "type": "admin",
      "admin_role": "finance",
      "...": "..."
    },
    "approved_at": "2026-10-16T00:31:52+00:00",
    "created_at": "2026-10-16T00:31:52+00:00"
  }
}
```

The list wraps the same objects as `{ "adjustments": [ ... ], "meta": { "current_page", "last_page", "per_page", "total" } }`.

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
Each payout in the list has `bank_account_block` (`null`, or `code`, `message`, `payouts_held_until`): use it to disable **Approve**/**Retry** and link to the account. The weekly auto-payout skips drivers whose account is blocked and pays them in a later week. The account is checked once more just before the transfer is sent: if it was blocked after approval, the payout fails with the block's message as `failure_reason` and the money goes back to the driver. Finance can retry it once the account is resolved.

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

`GET` returns the global rate (0.20 when none is set) and the active per-driver overrides.

**Response 200:**
```json
{
  "global_rate": 0.18,
  "overrides": [
    {
      "id": "01a1421a-2b41-73c6-a95e-8bfbec8048c5",
      "rate": 0.15,
      "driver_id": "01a1421a-2a72-73e2-afb3-c114f8d4f4e2",
      "is_global": false,
      "is_active": true,
      "created_at": "2026-10-16T00:26:13+00:00"
    }
  ]
}
```

`PUT` sets the global rate, or a driver's override when `driver_id` is sent. It is audit-logged.

| Field | Type | Required | Rules |
|---|---|---|---|
| `rate` | number | Yes | 0–1 (e.g. `0.18` = 18%) |
| `driver_id` | string (UUID) | No | An existing driver; omit or `null` for the global rate |

**Response 200:**
```json
{
  "message": "Global commission rate updated.",
  "commission": {
    "id": "01a1421a-2b39-7229-8fe6-e00823649ada",
    "rate": 0.18,
    "driver_id": null,
    "is_global": true,
    "is_active": true,
    "created_at": "2026-10-16T00:26:13+00:00"
  }
}
```

With `driver_id`, the message is `"Driver commission override updated."` and `is_global` is `false`.

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
    "card_verification_amount": 10000,
    "min_payout": 100000,
    "settlement_delay": "instant",
    "max_negative_balance": -500000,
    "hold_expiry_hours": 4,
    "abandoned_topup_minutes": 30,
    "payout_account_hold_hours": 24,
    "payout_account_hold_first_account": false,
    "payout_account_name_check": "review",
    "block_booking_with_balance_due": true
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `min_topup` | integer | Minimum top-up amount (kobo) |
| `max_balance` | integer | Maximum wallet balance (kobo) |
| `daily_topup_cap` | integer | Daily top-up limit per user (kobo) |
| `card_verification_amount` | integer | Charged when a passenger adds a card, and credited to their wallet (kobo, default 10000 = ₦100) |
| `min_payout` | integer | Minimum payout amount (kobo) |
| `settlement_delay` | string | `instant`, `24h`, or `weekly` |
| `max_negative_balance` | integer | Max negative balance for driver cash-ride commission (kobo, ≤ 0) |
| `hold_expiry_hours` | integer | Hours before an unreleased hold expires (1–48) |
| `abandoned_topup_minutes` | integer | Minutes before a pending top-up is marked abandoned (5–1440) |
| `payout_account_hold_hours` | integer | Hours payouts wait after a driver replaces their bank account (0 = no hold) |
| `payout_account_hold_first_account` | boolean | Also hold a driver's first bank account |
| `payout_account_name_check` | string | What a bank account name mismatch does: `off` (record only), `review` (save, pause payouts until finance approves), `block` (refuse the account) |
| `block_booking_with_balance_due` | boolean | Refuse new bookings while the passenger has an outstanding [balance due](#passenger--balance-due) (default `true`) |

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
| `card_verification_amount` | integer | 5000–100000 (₦50–₦1,000) |
| `min_payout` | integer | Min: 10000 |
| `settlement_delay` | string | `instant`, `24h`, or `weekly` |
| `max_negative_balance` | integer | Max: 0 (must be ≤ 0) |
| `hold_expiry_hours` | integer | 1–48 |
| `abandoned_topup_minutes` | integer | 5–1440 |
| `payout_account_hold_hours` | integer | 0–168 (applies to accounts saved after the change) |
| `payout_account_hold_first_account` | boolean | |
| `payout_account_name_check` | string | `off`, `review` or `block` (applies to accounts saved after the change; use `payout-accounts:check-names` for existing ones) |
| `block_booking_with_balance_due` | boolean | |

**Response 200:**
```json
{
  "message": "Wallet settings updated.",
  "settings": { /* same shape as GET response */ }
}
```

**Response 422:** Validation error, including `min_topup` errors when the combined settings would make top-ups impossible: `"The minimum top-up cannot exceed the maximum wallet balance."` / `"The minimum top-up cannot exceed the daily top-up cap."`

### Passenger Balances Due

```
GET /admin/balances-due
POST /admin/balances-due/{balanceDue}/waive
Authorization: Bearer {admin_token}
```

**Allowed Roles:** Finance and Support can list; only Finance can waive.

Money passengers owe (see [Passenger — Balance Due](#passenger--balance-due)). Filters: `status` (`outstanding`, `paid`, `waived`), `reason` (`late_cancellation_fee`, `wallet_shortfall`, `lost_item_return_fee`), `passenger_id`, `per_page` (1–100, default 25). Newest first. `summary` counts everything outstanding.

**Response 200:**
```json
{
  "balances": [
    {
      "id": "01a142ed-dd38-724f-a9ba-e9a1e08397c2",
      "reason": "late_cancellation_fee",
      "reason_label": "Late cancellation fee",
      "amount": 600,
      "currency": "NGN",
      "status": "outstanding",
      "ride": {
        "id": "01a142ed-dce0-71fc-97ef-2327192038f7",
        "reference_number": "TRP-NG-000001",
        "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
        "destination_address": "Murtala Muhammed International Airport, Ikeja"
      },
      "passenger": {
        "id": "01a142ed-dc80-715f-af5b-828a2891ae9f",
        "reference_number": "P-000001",
        "first_name": "Ade",
        "last_name": "Ogunleye",
        "phone": "+2348100000001",
        "email": "ade@demo.etigo.com",
        "type": "passenger",
        "phone_verified_at": "2026-10-19T08:00:00.000000Z",
        "is_active": true,
        "profile_photo_url": null,
        "created_at": "2026-10-19T08:00:00.000000Z"
      },
      "paid_at": null,
      "paid_via": null,
      "waived_at": null,
      "waive_reason": null,
      "created_at": "2026-10-20T04:05:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 1
  },
  "summary": {
    "outstanding_count": 1,
    "outstanding_total": 600
  }
}
```

**Waive** writes a balance off (nobody is paid; the passenger can book again if nothing else is outstanding). Body: `reason` (required, max 500). Audit-logged. A late-cancellation fee's `late_cancellation_fee_status` becomes `waived`.

**Response 200:**
```json
{
  "message": "Balance waived.",
  "balance": {
    "id": "01a142ed-ddab-7008-9169-57157ab902ed",
    "reason": "late_cancellation_fee",
    "reason_label": "Late cancellation fee",
    "amount": 600,
    "currency": "NGN",
    "status": "waived",
    "ride": {
      "id": "01a142ed-dd87-73ee-a0bd-5f480fba50f0",
      "reference_number": "TRP-NG-000002",
      "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
      "destination_address": "Murtala Muhammed International Airport, Ikeja"
    },
    "passenger": {
      "id": "01a142ed-dc80-715f-af5b-828a2891ae9f",
      "reference_number": "P-000001",
      "first_name": "Ade",
      "last_name": "Ogunleye",
      "phone": "+2348100000001",
      "email": "ade@demo.etigo.com",
      "type": "passenger",
      "phone_verified_at": "2026-10-19T08:00:00.000000Z",
      "is_active": true,
      "profile_photo_url": null,
      "created_at": "2026-10-19T08:00:00.000000Z"
    },
    "paid_at": null,
    "paid_via": null,
    "waived_at": "2026-10-20T05:40:00.000000Z",
    "waive_reason": "Goodwill: first late cancellation",
    "created_at": "2026-10-20T05:40:00.000000Z"
  }
}
```

**Response 422:** `errors.balance_due` — `This balance is no longer outstanding.`

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
| Transfers | Successful payout transfers confirmed by webhook (Paystack `transfer.success`, Flutterwave `transfer.completed`), plus payouts marked `paid` that day with no such webhook (settled by *Check Payout Status* or payout reconciliation), at the amount Paystack's transfer verify API reports for them | Payouts marked `paid` that day |

Any difference is listed in `mismatches` with `type`, `gateway_total`, `ledger_total` and `difference`, and the report `status` is `mismatched`. Ride card payments are not part of this report. A webhook received just before midnight and processed just after it can show as a one-day timing difference. A paid payout that Paystack does not confirm as successful, or that cannot be checked because Paystack is unreachable, adds nothing to the gateway side and shows as a mismatch.

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

Live totals from the ledger, in kobo: money held in passenger wallets, commission collected, driver earnings still owed, and payouts sent.

**Response 200:**
```json
{
  "reconciliation": {
    "currency": "NGN",
    "wallet_liability": 500000,
    "commission_collected": 0,
    "driver_earnings_payable": 320000,
    "total_payouts_processed": 0,
    "generated_at": "2026-10-16T00:26:13+00:00"
  }
}
```

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

Scheduled rides still waiting for their pickup time (status `scheduled`) are left out: they have no outcome yet.

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
| 422  | `BALANCE_DUE`              | `POST /rides` while the passenger has an outstanding balance (`balance_due` = amount); pay it with `POST /passenger/balance-due/pay` |
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
| `support`         | Passenger/driver complaints, refunds, fare adjustments (dispute tier 1) |
| `support_lead`    | Dispute tier 2: escalations, appeals, larger refunds; also everything `support` can do |
| `finance`         | Payments, wallets, payouts, financial reports |
| `safety_operator` | SOS incidents, safety investigations, suspensions |

### Admin Role Permissions Matrix

Super Admins always bypass role checks and have full access. Dispute tiers decide which cases each dispute role can work (see [Admin — Dispute Management](#admin--dispute-management)).

| Endpoint Group | Operations | Support | Support lead | Finance | Safety |
|----------------|:----------:|:-------:|:------------:|:-------:|:------:|
| `/admin/drivers` | Yes | — | — | — | Yes |
| `/admin/cities` (incl. readiness, launch status) | Yes | — | — | — | — |
| `/admin/settings/cities` | Yes | — | — | — | — |
| `/admin/vehicle-classes` | Yes | — | — | — | — |
| `/admin/pricing` | Yes | — | — | Yes | — |
| `/admin/surge-rules` | Yes | — | — | — | — |
| `/admin/rides` (assign) | Yes | — | — | — | — |
| `/admin/rides` (refund) | — | — | — | Yes | — |
| `/admin/fleet-vehicles` | Yes | — | — | Yes | — |
| `/admin/fleet-agreements` | Yes | — | — | Yes | — |
| `/admin/gamification` | Yes | — | — | — | — |
| `/admin/sos` | — | — | — | — | Yes |
| `/admin/offline-flags` | Yes | — | — | — | Yes |
| `/admin/ev-stations` | Yes | — | — | — | — |
| `/admin/passengers` | — | Yes | Yes | — | Yes |
| `/admin/disputes` (support queue) | — | Yes | Yes | — | Yes (view) |
| `/admin/disputes` (Safety queue) | — | — | — | — | Yes |
| `/admin/dispute-refund-requests` | — | Yes | Yes | Yes | Yes |
| `/admin/settings/disputes` | — | — | — | — | — |
| `/admin/wallets` (view) | Yes | Yes | Yes | Yes | — |
| `/admin/wallets` (freeze) | — | — | — | Yes | — |
| `/admin/driver-ledgers` | — | Yes | Yes | Yes | — |
| `/admin/ledger` | — | — | — | Yes | — |
| `/admin/adjustments` | — | — | — | Yes | — |
| `/admin/payouts` | — | — | — | Yes | — |
| `/admin/settings` | — | — | — | Yes | — |
| `/admin/reports` (financial) | — | — | — | Yes | — |
| `/admin/reports` (operational) | Yes | — | — | Yes | — |
| `/admin/admins` | — | — | — | — | — |
| `/admin/lost-items` | Yes | Yes | Yes | — | — |
| `/admin/driver-shifts`, `/admin/availability-coverage`, `/admin/fleet-vehicles/{vehicle}/shift-mode` | Yes | — | — | — | — |
| `/admin/vehicle-maintenance`, `/admin/vehicles/{vehicle}/…`, `/admin/vehicle-documents` | Yes | — | — | — | — |
| `/admin/settings/driver-scheduling`, `/admin/settings/vehicle-maintenance` | Yes | — | — | — | — |

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
| `roadworthiness_certificate` | Roadworthiness certificate (vehicle; expiry required) |
| `vehicle_licence`        | Vehicle licence (vehicle; expiry required) |

`vehicle_registration`, `insurance_certificate`, `roadworthiness_certificate` and `vehicle_licence` are vehicle papers, linked to the car (`vehicle_id`).

### Driver Shift Status
| Value | Description |
|-------|-------------|
| `scheduled` | Assigned, not started |
| `active` | The driver went online in the shift window |
| `completed` | Ended |
| `missed` | Not online 30 min after the start (can still start late) |
| `cancelled` | Cancelled by operations |

### Maintenance Item Type
| Value | Applies to |
|-------|-----------|
| `service` | All vehicles |
| `tyres_brakes` | All vehicles |
| `insurance` | All vehicles (paper) |
| `roadworthiness` | All vehicles (paper) |
| `vehicle_licence` | All vehicles (paper) |
| `battery_health` | Electric and plug-in hybrid |

### Maintenance Item Status
| Value | Description |
|-------|-------------|
| `unknown` | No record yet |
| `ok` | Up to date |
| `due_soon` | Due within 14 days or 500 km |
| `overdue` | Past due (maintenance; warns only) |
| `expired` | Paper expired (blocks driving after the grace period) |

### Maintenance Record Status
`pending` (awaiting operations), `approved`, `rejected`.

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
| `acknowledged`      | User confirmed they are safe; label "Confirmed Safe" (an operator can still take over or close it) |
| `escalated`         | No response — escalated to safety console  |
| `operator_assigned` | Safety operator took over the incident     |
| `dispatched`        | Emergency services dispatch initiated      |
| `resolved`          | Incident closed by operator (terminal)     |
| `cancelled`         | Incident cancelled by the user or an admin before an operator took over (terminal) |

### SOS Queue Status (admin console)
| Value            | Incident statuses                                   |
|------------------|-----------------------------------------------------|
| `unacknowledged` | `triggered`, `check_in_sent`, `acknowledged`, `escalated` |
| `acknowledged`   | `operator_assigned`, `dispatched` (an operator has taken it) |
| `resolved`       | `resolved`, `cancelled`                             |

### SOS Trigger Type
| Value       | Description                        |
|-------------|------------------------------------|
| `passenger` | Triggered by the passenger         |
| `driver`    | Triggered by the driver            |

### Ride Status
| Value             | Description                                      |
|-------------------|--------------------------------------------------|
| `scheduled`       | Booked for later; matching has not started (not an active ride) |
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
- `scheduled` -> `searching` (the dispatcher starts matching), `cancelled` (passenger, admin, or the dispatcher when the ride cannot start)
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
| `paid`               | Reserved (not set by current flows; wallet rides settle as `captured`, or `failed` with a shortfall, which becomes a balance due; once the passenger pays it the payment becomes `captured`) |

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

### Lost Item Status
| Value              | Description                                                     |
|--------------------|-----------------------------------------------------------------|
| `reported`         | Filed, no driver could be asked; with support                   |
| `driver_notified`  | Driver asked to check the car                                   |
| `driver_confirmed` | Driver has the item; return being arranged                      |
| `driver_denied`    | Driver did not find it                                          |
| `returned`         | Driver says it was handed back; awaiting the passenger          |
| `resolved`         | Passenger has it back (terminal)                                |
| `unresolved`       | Closed without the item being returned (terminal)               |
| `cancelled`        | Passenger withdrew the report (terminal)                        |

### Lost Item Category
`phone`, `wallet_or_purse`, `bag`, `keys`, `id_or_documents`, `electronics`, `clothing`, `glasses`, `jewellery`, `other` (labels in `GET /config` → `lost_and_found.categories`).

### Lost Item Closure Reason
`not_found`, `unclaimed`, `passenger_unreachable`, `driver_unreachable`, `other`.

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
| `scheduled_dispatch_failed` | A scheduled ride could not start; `cancellation_details` explains why to the passenger. Set by the system only, never offered as a choice |
| `city_paused` | E-tiGo paused rides in the city and cancelled upcoming bookings; `cancellation_details` explains it to the passenger. Set by the system only |

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

### Offline Flag Status
| Value        | Description                                           |
|--------------|-------------------------------------------------------|
| `flagged`    | Raised by detection; not disputed or reviewed yet     |
| `disputed`   | Driver disputed it; awaiting review                   |
| `upheld`     | Admin upheld it                                       |
| `overturned` | Admin overturned it; sanction lifted                  |

### Promo Status
| Value       | Description                                   |
|-------------|-----------------------------------------------|
| `active`    | Running now                                   |
| `scheduled` | Starts in the future                          |
| `paused`    | Paused by an admin; can be resumed            |
| `expired`   | Past its end date                             |
| `cancelled` | Cancelled permanently                         |

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
| `confirmed` | Charge request operations accepted for a slot (`confirmed_slot_at`); a stall is held when the slot starts |
| `reserved`  | Stall locked, awaiting driver arrival (expires after 30 min by default) |
| `queued`    | Waiting for stall to become available   |
| `active`    | Charging session in progress            |
| `completed` | Charging session finished (terminal)    |
| `expired`   | Reservation timed out (terminal)        |
| `cancelled` | Cancelled by the driver, declined by operations, or cancelled at the slot (terminal) |

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
| Scheduled ride reminder | Passenger | `scheduled_ride_reminder` | Sent `reminder_minutes` before pickup (data: `ride_id`, `scheduled_pickup_at`); warns a wallet passenger whose balance would not cover the fare |
| Scheduled ride dispatched | Passenger | `scheduled_ride_dispatched` | Matching has started for a scheduled ride; fetch the ride for its PIN (not sent when it went straight to a reserved driver) |
| Driver reserved the booking | Passenger (and the driver when operations assigned it) | `scheduled_ride_reserved` | Shows the driver's first name and the pickup time |
| Reservation dropped | Passenger and/or driver | `scheduled_ride_reservation_released` | The reserved driver released it, operations removed it, the pickup changed, or the driver was unavailable at dispatch |
| Reserved pickup reminder | Driver | `scheduled_ride_reminder` | 60 minutes before a reserved pickup: "be online by …" |
| Reserved booking cancelled | Driver | `ride_cancelled` | The passenger cancelled a booking the driver had reserved |
| Scheduled ride could not start | Passenger | `ride_cancelled` | The body explains why (e.g. wallet could not cover the fare) |
| Rider not reached (third-party ride) | Booker | `third_party_rider_unreachable` | WhatsApp and SMS to the rider failed; the PIN is now on the ride for the booker to pass on (bypasses notification preferences) |
| Lost item reported / driver reminder | Driver | `lost_item_report` | A passenger may have left something in the car; open the report to answer (data: `lost_item_id`, `reference_number`, `ride_id`, `status`). See [Lost & Found](#lost--found) |
| Lost item update | Passenger or driver | `lost_item_update` | The report moved on (driver found it or not, returned, resolved, closed, support following up) |
| Lost item message | Passenger, driver | `lost_item_message` | New message on the report (data adds `message_id`) |
| City launched | Passenger on the waitlist | `city_launched` | "E-tiGo is now in Ibadan" (data: `city_id`). Sent once per wait. See [City Waitlist](#city-waitlist) |
| City launched / paused | Approved drivers in the city | `city_status_changed` | Live: "go online to start getting trips". Paused: passengers cannot book; trips in progress and (unless cancelled) booked rides go ahead (data: `city_id`, `launch_status`) |
| Booking cancelled by a city pause | Passenger (and reserved driver) | `ride_cancelled` | Body: "Your scheduled ride was cancelled because E-tiGo has paused rides in Lagos." |
| Shift assigned / changed / cancelled | Driver | `driver_shift_assigned` / `driver_shift_updated` / `driver_shift_cancelled` | Shared fleet car shifts (data: `shift_id`). See [Driver Scheduling](#driver-scheduling-phase-2) |
| Shift missed | Driver | `driver_shift_missed` | Not online 30 min after the start; operations were told; going online now still works |
| Offers stopping / shift ended | Driver | `driver_shift_ending` / `driver_shift_ended` | 15 min before the end no new trips; at the end the driver was set offline (unless on a trip) and should hand the car over |
| Car running late | Driver | `shift_handover_delayed` | The previous driver is still on a trip with the shared car |
| Vehicle check due | Driver | `vehicle_maintenance_due` | Items became due soon, overdue, expired or still need a record; once per change (data: `vehicle_id`). See [Vehicle Maintenance](#vehicle-maintenance-phase-2) |
| Car off the road / back on | Driver | `vehicle_blocked` / `vehicle_unblocked` | Papers expired past the grace period (bypasses preferences; reserved pickups released) / a renewal was approved |
| Maintenance record checked | Driver | `maintenance_record_reviewed` | Operations approved or rejected a submitted record (data: `vehicle_id`, `record_id`) |
| Moved to another city | Driver | `driver_city_changed` | Operations moved the driver (data: `city_id`); says when they were taken offline, and that they can go online from launch day when the city is still onboarding |
| EV stall ready     | Driver     | `ev_reservation_ready` | Queued reservation promoted, or a confirmed charge request's slot started — stall assigned |
| EV reservation cancelled | Driver | `ev_reservation_cancelled` | A stall freed up but the reservation fee could not be taken, so the queued reservation was cancelled; also a confirmed charge request cancelled at its slot (fee unpaid, station closed, or check-in time already passed) |
| Charging request confirmed | Driver | `ev_charge_request_confirmed` | Operations confirmed a charge request for a slot (data: `reservation_id`, `station_name`). See [Admin — EV Charge Requests](#admin--ev-charge-requests) |
| Charging request declined | Driver | `ev_charge_request_declined` | Operations declined a charge request (the reason is in the body and `decline_reason`), or nobody reviewed it before its day ended |
| Fleet agreement paused / resumed | Driver | `fleet_agreement_paused` / `fleet_agreement_resumed` | Operations or Finance paused or resumed the driver's fleet agreement (data: `fleet_agreement_id`); no remittance is due while paused |
| Fuel type needed | Driver | `vehicle_fuel_type_required` | The driver's vehicle has no fuel type; open the vehicle screen so they can set it (sent by `php artisan vehicles:request-fuel-types`) |
| Location missing | Driver | `driver_location_missing` | The driver's app sent no location for 5 minutes (`MATCHING_SILENT_DRIVER_WARNING_MINUTES`), so they get no ride requests (or, with `on_trip: true`, their passenger can't see them). Once per silent spell; the driver stays online. Restart location updates and show a banner |
| Set offline | Driver | `driver_set_offline` | The driver's app sent no location for 30 minutes (`MATCHING_SILENT_DRIVER_OFFLINE_MINUTES`), so they were set offline and get no ride requests; open the home screen so they can go online again (which restarts location updates). Never sent to drivers on a trip |

**Wallet and payout notifications** (stored in-app — see `GET /notifications` — and pushed):

| Event | Recipient | Notification Type |
|-------|-----------|-------------------|
| Top-up credited / failed | Passenger | `topup_success` / `topup_failed` |
| Balance due recorded (unpaid late-cancellation fee or wallet fare; data: `balance_due_id`, `ride_id`, `amount`) | Passenger | `balance_due` |
| Ride paid from wallet | Passenger | `ride_wallet_payment` |
| Refund credited to wallet | Passenger | `wallet_refund` |
| Cash change credited to wallet | Passenger | `cash_change_credit` |
| Payout approved / paid / failed | Driver | `payout_approved` / `payout_paid` / `payout_failed` |
| Payout request rejected (with reason) | Driver | `payout_rejected` |
| Payout bank account added or changed (data: `bank_account_id`, `replaced_another`); also sent by WhatsApp/SMS regardless of preferences. Says when payouts start or that they are paused for review | Driver | `payout_account_changed` |
| Finance approved or rejected a payout account after the name check (data: `bank_account_id`, `review_status`) | Driver | `payout_account_reviewed` |

**Compliance (off-app trip) notifications** (pushed; `data.type` is `compliance_warning`; the compliance category cannot be turned off):

| Event | Recipient | Title | `data` |
|-------|-----------|-------|--------|
| Level 1 flag | Driver | Compliance Warning | — |
| Level 2 flag (or escalation to it): suspended | Driver | Account Suspended | `suspension_hours` |
| Level 3 flag (raised, escalated or verified cash-taking): suspended pending review; the body says how long the driver has to dispute | Driver | Account Suspended — Under Review | `pending_deactivation: true`, `response_hours` |
| Automatic suspension ended | Driver | Suspension Lifted | `reinstated: true` |
| De-escalated L3 → L2 / L2 → L1 | Driver | Sanction Reduced / Suspension Lifted | `suspension_hours` / `reinstated: true` |
| Flag overturned, nothing else keeps the driver suspended (including a deactivation overturned on appeal) | Driver | Account Reinstated | `reinstated: true` |
| Flag overturned, another flag keeps the driver suspended | Driver | Flag Overturned | `reinstated: false` |
| Deactivation confirmed | Driver | Account Deactivated | `deactivated: true` |

On any of these, the driver app refreshes `GET /driver/compliance`.

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
| `private-ride.{rideId}` | Passenger or assigned driver | Ride participants | `ride.status.updated`, `driver.location.updated` (left off for the booker of a third-party ride whose tracking excludes them), `payment.updated`, `ride.no-driver-found` |
| `private-driver.{userId}` | That driver | Driver app | `ride.request.dispatched` (ride offers) |
| `private-user.{userId}` | Authenticated user (self) | User apps | `sos.check_in.requested`, `tier.upgraded`, `ride.rider_notification.updated` |
| `private-admin.rides` | Admin users | Admin dashboard | `ride.status.updated`, `driver.location.updated` |
| `private-admin.sos` | Safety operators, super admins | SOS console | `sos.incident.triggered`, `sos.incident.escalated`, `sos.incident.updated` |
| `private-admin.ev-stations` | Operations admins, super admins | EV stations screens | `ev.station.availability_changed` |
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

#### `ride.rider_notification.updated`

Sent to the booker of a [third-party ride](#third-party-bookings) on `private-user.{bookerId}` whenever the rider's message status changes (`pending` → `sent_whatsapp` / `sent_sms`, or `failed`). When `booker_sees_pin` turns `true`, fetch `GET /rides/{ride}` for the PIN; the PIN itself is never broadcast.

```json
{
  "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
  "rider_notification_status": "failed",
  "rider_notification_channel": null,
  "rider_notified_at": null,
  "booker_sees_pin": true,
  "booker_sees_tracking": true
}
```

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
| `{"type": "location", "lat": 6.5244, "lng": 3.3792, "heading": 90, "speed": 32.5, "accuracy": 12.5, "ride_id": "optional"}` (`accuracy` in metres, optional) | `{"type": "location_ack", "timestamp": 1760540400, "eta": { "distance_km": 3.2, "duration_minutes": 8.0 }}` (`eta` is null without an active ride) |
| `{"type": "ping"}` | `{"type": "pong", "timestamp": 1760540400}` |

Errors arrive as `{"type": "error", "code": <status>, "message": "..."}`: `401` invalid/expired token or a `location` frame before authenticating (the connection is closed for a bad token), `403` driver not online/approved, `422` invalid coordinates, `400` malformed JSON or unknown frame type. Location frames sent less than `TELEMETRY_RATE_LIMIT_MS` (500 ms) apart are dropped silently. `ride_id` is optional — the gateway finds the driver's active ride itself.
