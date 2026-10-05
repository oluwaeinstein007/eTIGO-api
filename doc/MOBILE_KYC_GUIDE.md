# E-tiGo Mobile KYC Integration Guide

Integration guide for the mobile team to implement driver KYC verification using the E-tiGo API and QoreID SDK.

---

## Overview

Driver KYC verification requires three mandatory checks before `kyc_status` becomes `verified`:

| Check | Type | How It Works |
|-------|------|-------------|
| NIN | API call | Driver submits NIN, backend verifies via QoreID |
| Driver's License | API call | Driver submits license number, backend verifies |
| Vehicle Plate | Auto + manual | Auto-triggered on vehicle registration; also manual |
| Liveness | SDK-based | Mobile SDK captures selfie, webhook delivers result |

**Base URL:** `{APP_URL}/api/v1`
**Auth:** `Authorization: Bearer {token}` (driver token)

---

## Recommended UI Flow

```
1. Registration / Login
2. Profile Setup (name, phone)
3. Vehicle Registration → auto-triggers plate verification
4. KYC Screen:
   a. NIN Verification (text input → API call)
   b. Driver's License Verification (text input → API call)
   c. Vehicle Plate status (auto-verified or manual retry)
   d. Liveness Check (opens QoreID SDK)
5. KYC Complete → driver can proceed to document upload and approval
```

---

## API Endpoints

### 1. Get KYC Status

Poll this on the KYC screen to show progress.

```
GET /driver/kyc/status
Authorization: Bearer {token}
```

**Response:**
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
      "status": "processing",
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

**Status values per verification type:**

| Status | Meaning | UI Guidance |
|--------|---------|-------------|
| `not_started` | Not attempted | Show input field / button |
| `pending` | Created, not sent yet | Show spinner |
| `processing` | Awaiting provider result | Show spinner |
| `verified` | Passed | Show green checkmark |
| `failed` | Failed (see `failure_reason`) | Show error + retry button |
| `expired` | Superseded (plate changed) | Show re-verify prompt |

**Aggregate `kyc_status` values:**

| Status | Meaning |
|--------|---------|
| `not_started` | No verifications submitted |
| `in_progress` | At least one submitted, not all done |
| `verified` | NIN + License + Plate all verified |
| `failed` | At least one required check failed |

---

### 2. Verify NIN

```
POST /driver/kyc/verify-nin
Authorization: Bearer {token}
Content-Type: application/json

{
  "nin_number": "12345678901"
}
```

**Validation:** `nin_number` must be exactly 11 digits.

**Responses:**
- `200` — Verified successfully
- `422` — Verification failed (show `verification.failure_reason`)
- `409` — Already verified or in progress (show current status)

---

### 3. Verify Driver's License

```
POST /driver/kyc/verify-license
Authorization: Bearer {token}
Content-Type: application/json

{
  "license_number": "ABC123DEF"
}
```

**Validation:** `license_number` must be 6-20 characters.

**Responses:** Same as NIN.

---

### 4. Verify Vehicle Plate (Manual)

```
POST /driver/kyc/verify-vehicle
Authorization: Bearer {token}
Content-Type: application/json

{
  "plate_number": "LAG-123AB"
}
```

**Validation:** `plate_number` must be 3-20 characters.

**Note:** This is automatically dispatched when the driver registers a vehicle or changes the plate number. Only call manually if the auto-verification failed and the driver wants to retry.

**Pre-requisite:** Driver must have a registered vehicle. Returns `422` with `"Register a vehicle before verifying its plate."` otherwise.

---

### 5. Liveness Verification (QoreID SDK)

This is a two-step process:

#### Step 1: Create Session (API call)

```
POST /driver/kyc/liveness-session
Authorization: Bearer {token}
```

No request body needed.

**Response (201):**
```json
{
  "message": "Liveness session created. Use the SDK token in the mobile app.",
  "session_id": "etigo_kyc_1_1696500000",
  "sdk_token": "eyJhbGciOi...",
  "expires_at": "2026-10-05T11:00:00.000000Z",
  "verification": {
    "id": 4,
    "type": "liveness",
    "status": "processing"
  }
}
```

#### Step 2: Launch QoreID SDK (Native)

Use the `sdk_token` from Step 1 to initialize the QoreID SDK.

**Android (Kotlin):**

```kotlin
// Add to build.gradle (module):
// implementation("com.qoreid:android-sdk:latest")

import com.qoreid.sdk.QoreIDParams
import com.qoreid.sdk.QoreIDSDK

val params = QoreIDParams()
    .sessionToken(sdkToken)  // from API response

QoreIDSDK.launch(this, params) { result ->
    when (result.status) {
        "completed" -> {
            // Liveness check done — backend processes via webhook
            // Poll GET /driver/kyc/status to check result
        }
        "cancelled" -> {
            // User cancelled — show retry option
        }
        "error" -> {
            // SDK error — show retry option
            Log.e("KYC", "Liveness error: ${result.errorMessage}")
        }
    }
}
```

**iOS (Swift):**

```swift
// Add via SPM or CocoaPods: QoreIDSDK

import QoreIDSDK

let params = QoreIDParams()
params.sessionToken = sdkToken  // from API response

QoreIDSDK.launch(from: self, params: params) { result in
    switch result.status {
    case .completed:
        // Poll GET /driver/kyc/status
        break
    case .cancelled:
        // User cancelled
        break
    case .error:
        // Handle error
        break
    }
}
```

**React Native:**

```javascript
// npm install @qoreid/react-native-sdk

import { QoreID } from '@qoreid/react-native-sdk';

try {
  const result = await QoreID.launch({ sessionToken: sdkToken });
  if (result.status === 'completed') {
    // Poll KYC status
  }
} catch (error) {
  // Handle error or cancellation
}
```

**Flutter:**

```dart
// pubspec.yaml: qoreid_flutter_sdk: ^latest

import 'package:qoreid_flutter_sdk/qoreid_flutter_sdk.dart';

final result = await QoreID.launch(sessionToken: sdkToken);
if (result.status == 'completed') {
  // Poll KYC status
}
```

#### Step 3: Poll for Result

After the SDK completes, the result is delivered to the backend via webhook (not to the mobile app). Poll the status endpoint:

```
GET /driver/kyc/status
```

Poll every 2-3 seconds for up to 30 seconds. The `liveness` status will change from `processing` to either `verified` or `failed`.

---

### 6. List All Verifications

```
GET /driver/kyc/verifications
Authorization: Bearer {token}
```

Returns all verification attempts (including failed/expired), newest first. Useful for showing verification history.

---

## Fleet Vehicles

The client operates a fleet of company-owned vehicles. Fleet vehicles **skip plate verification** — only NIN and Driver's License are required for `kyc_status: verified`.

**How it works:**
- An admin marks a vehicle as fleet via `PATCH /admin/drivers/{id}/vehicle/fleet`
- The `GET /driver/kyc/status` response includes `"required": true/false` per verification type
- For fleet vehicles, `vehicle_plate.required` is `false`

**Mobile UI guidance:**
- Check the `required` field in the verification summary to decide which steps to show
- If `vehicle_plate.required` is `false`, show a "Fleet vehicle — plate verification not required" badge instead of the plate verification step
- The driver cannot self-mark a vehicle as fleet — this is admin-only

**Example status for fleet vehicle:**
```json
{
  "kyc_status": "verified",
  "verifications": {
    "nin": { "status": "verified", "required": true },
    "drivers_license": { "status": "verified", "required": true },
    "vehicle_plate": { "status": "not_started", "required": false },
    "liveness": { "status": "not_started", "required": false }
  }
}
```

---

## Retry Logic

| Scenario | What Happens |
|----------|-------------|
| NIN/License fails | `failure_reason` explains why. Driver can retry with correct details. |
| Vehicle plate fails | Show error. Driver can retry manually via `POST /driver/kyc/verify-vehicle`. |
| Liveness fails | Driver can create a new session and retry. |
| 409 Conflict | Already verified or in progress. Show current status instead. |

Failed verifications can always be retried — a new verification record is created alongside the old one.

---

## Testing with FakeKycGateway

When `QOREID_CLIENT_ID` and `QOREID_SECRET_KEY` are not configured (local/staging), the `FakeKycGateway` is used automatically.

**Deterministic test values:**

| Input | Result |
|-------|--------|
| NIN starting with `000` (e.g. `00012345678`) | Fails |
| License starting with `000` (e.g. `000FAIL`) | Fails |
| Plate starting with `000` (e.g. `000-FAIL`) | Fails |
| Any other valid input | Passes |
| Liveness session | Always creates successfully |

**Liveness in dev:** The FakeKycGateway returns a fake `sdk_token`. Since QoreID SDK won't work with fake tokens, simulate liveness completion by calling the webhook endpoint directly:

```
POST /webhooks/qoreid
Content-Type: application/json

{
  "event": "verification_completed",
  "sessionId": "<session_id from liveness-session response>"
}
```

No `X-QoreID-Signature` header is needed when `QOREID_WEBHOOK_SECRET` is not configured.

---

## Error Handling

| HTTP Status | Meaning | Action |
|-------------|---------|--------|
| 200 | Verification passed | Show success, update UI |
| 201 | Session created (liveness) | Launch SDK |
| 401 | Token expired | Re-authenticate |
| 403 | Not a driver account | Should not happen in driver app |
| 409 | Duplicate verification | Show current status |
| 422 | Validation or verification failed | Show `message` or `failure_reason` |
| 500 | Server error | Show generic retry message |

---

## Sequence Diagram

```
Driver App                    E-tiGo API              QoreID
    |                             |                      |
    |-- POST /kyc/verify-nin ---->|                      |
    |                             |--- verifyNin() ----->|
    |                             |<-- match result -----|
    |<-- 200 verified ------------|                      |
    |                             |                      |
    |-- POST /kyc/verify-license->|                      |
    |                             |--- verifyLicense() ->|
    |                             |<-- match result -----|
    |<-- 200 verified ------------|                      |
    |                             |                      |
    |-- POST /driver/vehicle ---->|                      |
    |<-- 201 vehicle created -----|                      |
    |                             |-- [Job] verifyPlate->|
    |                             |<-- match result -----|
    |                             |                      |
    |-- POST /kyc/liveness ------>|                      |
    |<-- 201 {sdk_token} ---------|                      |
    |                             |                      |
    |-- Launch QoreID SDK --------|------- SDK flow ---->|
    |<-- SDK completed -----------|                      |
    |                             |<-- webhook callback -|
    |                             |  (processResult)     |
    |                             |                      |
    |-- GET /kyc/status --------->|                      |
    |<-- kyc_status: verified ----|                      |
```
