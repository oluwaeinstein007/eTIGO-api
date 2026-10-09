# Fleet Management Feature — Developer Handoff

**Date:** 9 October 2026
**Backend Status:** Phase 1 Complete (API ready for integration)
**For:** Designer, Frontend, and Mobile Teams

---

## What Was Built

E-tiGo now supports **internal fleet vehicles** with a **hire-to-own** model. Fleet drivers use E-tiGo-owned vehicles and pay a daily remittance toward eventual ownership. This is the standard Nigerian fleet/taxi model (same as Bolt fleet partners).

### The Remittance-First Model

Every ride a fleet driver completes, the fare is split in real-time:

```
┌────────────────────────────────────────────────────────┐
│  Daily Target: ₦40,000                                 │
│                                                        │
│  Ride 1: ₦3,200 fare → ALL to E-tiGo (₦3,200/40,000) │
│  Ride 2: ₦5,800 fare → ALL to E-tiGo (₦9,000/40,000) │
│  ...                                                   │
│  Ride 9: ₦6,000 fare → ALL to E-tiGo (₦38,000/40,000)│
│  Ride 10: ₦5,500 fare →                               │
│     ₦2,000 to E-tiGo (target met ✅)                   │
│     ₦3,500 to DRIVER                                   │
│  ──────────────────────────────────────────────        │
│  After target met:                                     │
│  Ride 11: ₦4,200 → ALL to driver                      │
│  Ride 12: ₦3,800 → ALL to driver                      │
└────────────────────────────────────────────────────────┘
```

**Key concept:** The driver earns ₦0 until the daily target is met. After that, every fare is 100% theirs. No commission is charged to fleet drivers — E-tiGo's revenue comes entirely from the daily remittance.

---

## API Endpoints Available

Base URL: `{APP_URL}/api/v1`

### Driver Endpoints (3)

These are what the **mobile app** consumes.

#### 1. Today's Remittance Progress
```
GET /driver/remittance/today
Authorization: Bearer {driver_token}
```

Returns the real-time remittance progress for today. This is the primary data source for the driver's daily dashboard.

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `target_amount` | string (decimal) | Daily target, e.g. `"40000.00"` |
| `remitted_amount` | string (decimal) | Amount collected toward target so far |
| `shortfall_amount` | string (decimal) | How much is still needed (`target - remitted`, 0 if met) |
| `target_met` | boolean | `true` once daily target is fully met |
| `target_met_at` | datetime / null | Timestamp when target was met |
| `ride_count` | integer | Number of rides completed today |
| `total_fares` | string (decimal) | Sum of all ride fares today |
| `driver_earnings` | string (decimal) | What the driver has earned AFTER target was met |

Returns `{"remittance": null}` if the driver has no active fleet agreement.

#### 2. Remittance History
```
GET /driver/remittance/history
Authorization: Bearer {driver_token}
```

Paginated list of daily remittance records, most recent first. Same fields as today's endpoint, plus `settled` (boolean) and `date`.

Standard pagination via `meta` block (`current_page`, `last_page`, `per_page`, `total`).

#### 3. Fleet Agreement
```
GET /driver/fleet-agreement
Authorization: Bearer {driver_token}
```

Returns the driver's active fleet agreement summary.

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `daily_remittance_target` | string (decimal) | The daily amount they need to remit |
| `total_remitted` | string (decimal) | Total amount remitted across all days |
| `progress_percentage` | float | 0–100, progress toward vehicle ownership |
| `agreement_start_date` | date string | When the agreement started |
| `status` | string | `active`, `paused`, `completed`, `terminated` |
| `shortfall_streak_days` | integer | Consecutive days where target was not met |
| `vehicle` | object | Vehicle details (make, model, plate, etc.) |

**Important business rule:** The driver does NOT see `total_vehicle_cost` or `remaining_amount`. They see only the percentage progress. This is a deliberate business decision.

Returns `{"agreement": null}` if no active fleet agreement.

### Admin Endpoints (7)

These are what the **admin dashboard/frontend** consumes.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/admin/fleet-agreements` | List all agreements (filter by `status`, `driver_id`) |
| `POST` | `/admin/fleet-agreements` | Create new agreement |
| `GET` | `/admin/fleet-agreements/{id}` | Agreement detail + last 30 remittance records |
| `PUT` | `/admin/fleet-agreements/{id}` | Update daily target (active only) |
| `POST` | `/admin/fleet-agreements/{id}/terminate` | Terminate with reason |
| `POST` | `/admin/fleet-agreements/{id}/pause` | Pause (e.g., vehicle maintenance) |
| `POST` | `/admin/fleet-agreements/{id}/resume` | Resume paused agreement |

Admin responses include **all** financial fields (`total_vehicle_cost`, `remaining_amount`) — these are hidden from drivers.

#### Creating an Agreement (Admin Flow)

1. Mark a vehicle as fleet: `PATCH /admin/drivers/{driver_id}/vehicle/fleet`
2. Create the agreement: `POST /admin/fleet-agreements` with:
   - `driver_id` (UUID)
   - `vehicle_id` (UUID, must be `is_fleet: true`)
   - `daily_remittance_target` (number, min 1000)
   - `total_vehicle_cost` (number, min 100000)
   - `agreement_start_date` (date, today or future)

A driver can only have **one active agreement** at a time.

---

## UI/UX Guidance

### Driver App — Screens Needed

#### 1. Daily Remittance Dashboard (Primary Screen for Fleet Drivers)

This should be prominently visible when the driver is online. Think of it like a fuel gauge.

**States to handle:**

| State | Visual Suggestion |
|-------|-------------------|
| Target not yet met | Progress bar/circle showing `remitted_amount / target_amount`. Show "₦28,500 of ₦40,000" |
| Target met | Celebration moment. Switch to "You're earning! ₦X earned so far" |
| No agreement | Hide the remittance widget entirely — this is a regular (non-fleet) driver |

**Data to display:**
- Progress toward daily target (bar, circle, or percentage)
- Number of rides completed today
- Amount still needed: `shortfall_amount`
- Driver earnings (only meaningful after target met)
- Time target was met (`target_met_at`) — e.g., "Target met at 2:22 PM"

#### 2. Remittance History

Calendar or list view showing daily records. Each day shows:
- Date
- Target met (yes/no — use checkmark/cross)
- Amount remitted vs target
- Number of rides
- Driver earnings for that day
- Shortfall (if any, highlighted)

Consider colour-coding: green for target met, red/amber for shortfall days.

#### 3. Fleet Agreement Summary

A card or screen showing:
- Vehicle info (make, model, plate)
- Daily remittance target
- Progress toward ownership (percentage — this is the **only** progress indicator, per business rules)
- Agreement status
- Shortfall streak (if > 0, show as a warning)
- Agreement start date

**Do NOT show:** total vehicle cost, remaining amount, or any absolute naira figure related to vehicle price. Show percentage only.

### Admin Dashboard — Screens Needed

#### 1. Fleet Agreements List

Table/list view with columns:
- Driver name
- Vehicle (make + plate)
- Daily target
- Total remitted / Total cost (admin sees both)
- Progress %
- Status (with colour badges: green=active, yellow=paused, grey=completed, red=terminated)
- Shortfall streak (flag if > 0)

Filters: status dropdown, driver search

#### 2. Fleet Agreement Detail

- All financial data (admin sees everything)
- Recent remittance records (last 30 days shown by default)
- Action buttons: Pause, Resume, Terminate (context-dependent — only show valid actions for current status)
- Audit trail (agreement history)

#### 3. Create Agreement Form

Fields:
- Driver selector (searchable dropdown)
- Vehicle selector (filtered to fleet vehicles only)
- Daily remittance target (number input, default 40000)
- Total vehicle cost (number input)
- Agreement start date (date picker, today or future)

Validation messages to handle:
- "Driver already has an active fleet agreement"
- "Vehicle must be marked as fleet before creating an agreement"

---

## Enum Values

### Fleet Agreement Status

| Value | Label | Description |
|-------|-------|-------------|
| `active` | Active | Agreement is in force, remittance is being tracked |
| `paused` | Paused | Temporarily suspended (e.g., vehicle maintenance, driver leave) |
| `completed` | Completed | Total vehicle cost fully remitted — ownership transfers |
| `terminated` | Terminated | Agreement ended early by admin (with reason) |

---

## Key Business Rules to Enforce in UI

1. **Fleet drivers earn ₦0 until daily target is met.** The UI should make this crystal clear — show it as "Working toward today's target" not "Earnings: ₦0".

2. **No vehicle price shown to drivers.** Progress is always shown as percentage (e.g., "25% toward ownership"), never as "₦1,250,000 of ₦5,000,000".

3. **One active agreement per driver.** The create agreement form should validate this and show a clear error if violated.

4. **Fleet vehicles skip plate KYC verification.** When a vehicle is marked as fleet, the KYC flow changes — NIN + Driver's License is sufficient (no plate verification needed since E-tiGo owns the vehicle).

5. **Zero commission for fleet drivers.** The earnings display should NOT show any commission deduction line. Fleet drivers see only: remittance vs. personal earnings.

6. **Agreement auto-completes.** When `total_remitted >= total_vehicle_cost`, the agreement status automatically changes to `completed`. The driver should see a congratulatory state.

---

## What's NOT Built Yet

| Feature | Why | Impact on UI |
|---------|-----|-------------|
| Ownership transfer workflow | Awaiting Chairman meeting decision (Q8) | Don't build a "Transfer ownership" button yet |
| Grace period auto-escalation | Grace period duration not decided (Q2) | Shortfall streak is tracked but no automated actions happen yet |
| Vehicle swap endpoint | Deferred — admin can manually terminate + recreate | No "Swap vehicle" button needed yet |
| Daily settlement cron | Reset time not decided | Data is accurate but `settled` flag won't flip automatically yet |
| Shortfall alert notifications | Depends on Q2 | No admin notifications for shortfall streaks yet |

---

## Testing

### Postman Collection

Import `doc/postman_collection.json` — new folders:
- **Admin — Fleet Agreement Management** (8 requests with example responses)
- **Driver — Fleet Remittance** (3 requests with example responses for all states)

### Postman Variables Needed

| Variable | Description | Example |
|----------|-------------|---------|
| `admin_token` | Admin auth token | From admin login |
| `driver_token` | Driver auth token | From driver OTP verify |
| `fleet_agreement_id` | UUID of a fleet agreement | From create or list response |
| `driver_uuid` | UUID of a driver | From admin driver list |
| `vehicle_uuid` | UUID of a fleet vehicle | From driver vehicle endpoint |

### Quick Test Flow

1. Login as admin → get `admin_token`
2. Login as driver → get `driver_token`
3. Mark vehicle as fleet: `PATCH /admin/drivers/{id}/vehicle/fleet`
4. Create agreement: `POST /admin/fleet-agreements`
5. Check driver view: `GET /driver/fleet-agreement` (should show agreement)
6. Check today: `GET /driver/remittance/today` (should be null until a ride happens or remittance is created)

---

## Full API Documentation

See `doc/API_REFERENCE.md` — sections:
- **Admin — Fleet Agreement Management**
- **Driver — Fleet Remittance**
- **Enums → Fleet Agreement Status**

All endpoints include request/response schemas with example payloads.

---

*Questions? Ping the backend team. The Postman collection has all endpoints ready for testing.*
