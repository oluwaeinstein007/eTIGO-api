# Admin Dashboard API Updates

**Date:** October 12, 2026  
**Backend Version:** Current branch `feature/notification-service-industry-standards`  
**Prepared for:** Frontend Engineering Team

---

## Summary

This update addresses all mismatches between the admin dashboard Figma designs and the API responses across **Drivers**, **Passengers**, and **Ride Disputes**. Changes include new response fields, new filters, new endpoints, and breaking changes to suspend endpoints.

---

## Breaking Changes

These require immediate frontend updates:

### 1. Driver Suspend Now Requires a Reason

**Endpoint:** `POST /admin/drivers/{driver_id}/suspend`

**Before:** No request body needed.  
**Now:** Request body is **required**:
```json
{
  "reason": "Multiple passenger complaints about unsafe driving."
}
```
- `reason` is required, string, max 500 characters
- The frontend must show a text input/modal when suspending a driver

### 2. Passenger Suspend Now Requires a Reason

**Endpoint:** `POST /admin/passengers/{passenger_id}/suspend`

**Before:** No request body needed.  
**Now:** Request body is **required**:
```json
{
  "reason": "Account abuse detected."
}
```
- Same rules as driver suspend: required, string, max 500 characters

---

## New Response Fields (Non-Breaking)

These are additive changes. Existing fields remain unchanged.

### All Entities Now Have Reference Numbers

Short, human-readable IDs for display in the UI (instead of UUIDs):

| Entity    | Field              | Format Example    |
|-----------|--------------------|-------------------|
| Driver    | `reference_number` | `D-000001`        |
| Passenger | `reference_number` | `P-000001`        |
| Dispute   | `reference_number` | `DPT-000048`      |
| Ride      | `reference_number` | `TRP-NG-000142`   |

These appear in all list and detail responses for their respective entities.

### Driver Resource Updates

New fields on every driver response (list + detail):

| Field                | Type     | Description |
|----------------------|----------|-------------|
| `reference_number`   | string   | Short ID (D-XXXXXX) |
| `rejection_reason`   | string?  | Always visible (was only shown when status=rejected) |
| `suspension_reason`  | string?  | Shown when status=suspended |
| `suspended_at`       | datetime? | When suspended |
| `vehicle_ownership_type` | string | `own_vehicle` or `fleet_vehicle` |
| `bank_account`       | object?  | Primary bank account (id, bank_code, account_number masked, account_name, is_verified) |
| `updated_at`         | datetime | Last updated timestamp |

### Driver Detail: Performance Data

The `GET /admin/drivers/{driver_id}` response now includes a `performance` object alongside `driver`:

```json
{
  "driver": { "..." },
  "performance": {
    "rating": 4.75,
    "total_trips": 142,
    "completed_trips": 135,
    "cancelled_trips": 7,
    "cancellation_rate": 4.9,
    "total_earnings": 285000.00
  }
}
```

### Driver Documents: File Access

Documents now include:

| Field              | Type     | Description |
|--------------------|----------|-------------|
| `file_size`        | integer? | File size in bytes |
| `file_url`         | string?  | URL to download/view the document |
| `rejection_reason` | string?  | Always visible (was conditional) |

### Passenger Resource Updates

| Field              | Type     | Description |
|--------------------|----------|-------------|
| `reference_number` | string   | Short ID (P-XXXXXX) |
| `suspension_reason`| string?  | Shown when is_active=false |
| `total_rides`      | integer? | Total ride count (on list endpoint, via withCount) |

### Dispute Resource Updates

| Field              | Type     | Description |
|--------------------|----------|-------------|
| `reference_number` | string   | Short case ID (DPT-XXXXXX) |
| `resolution_notes` | string?  | Always present (was conditional, null when unresolved) |
| `refund_amount`    | string?  | Always present (was conditional) |
| `refund_currency`  | string?  | Always present (was conditional) |
| `notes`            | array    | Internal admin notes (on detail view) |

### Ride Resource Updates

| Field              | Type     | Description |
|--------------------|----------|-------------|
| `reference_number` | string   | Short trip ID (TRP-NG-XXXXXX) |
| `payment_details`  | object?  | Payment info (on dispute detail): amount, currency, method, status, card_brand, card_last_four, tip_amount |

---

## New Filters

### Driver List (`GET /admin/drivers`)

| Filter           | Type          | Description |
|------------------|---------------|-------------|
| `city_id`        | string (UUID) | Filter by city |
| `vehicle_class_id` | string (UUID) | Filter by vehicle class |
| `per_page`       | integer       | Control pagination size |

### Dispute List (`GET /admin/disputes`)

| Filter    | Type          | Description |
|-----------|---------------|-------------|
| `city_id` | string (UUID) | Filter disputes by ride's city |

The dispute list also now eager-loads `ride.city`, `ride.passenger`, and `ride.driver` so city name and user phones are available on list rows.

---

## New Endpoints

### 1. Driver Trip History

```
GET /admin/drivers/{driver_id}/trips
```

Query params: `status`, `from` (date), `to` (date), `per_page`

Returns paginated rides with passenger, city, vehicle class. Use this for the "Trip History" tab on driver detail view.

### 2. Passenger Trip History

```
GET /admin/passengers/{passenger_id}/trips
```

Query params: `status`, `from` (date), `to` (date), `per_page`

Returns paginated rides with driver, city, vehicle class.

### 3. Update Dispute Status

```
PATCH /admin/disputes/{dispute_id}/status
```

Request body: `{ "status": "under_review" }`

Transitions an open dispute to "Under Review". Only works when the dispute is currently `open`.

### 4. Dispute Internal Notes (CRUD)

```
GET    /admin/disputes/{dispute_id}/notes          -- List notes
POST   /admin/disputes/{dispute_id}/notes          -- Add note
PUT    /admin/disputes/{dispute_id}/notes/{note_id} -- Update note
DELETE /admin/disputes/{dispute_id}/notes/{note_id} -- Delete note
```

**Add/Update body:** `{ "content": "Note text here (max 2000 chars)" }`

Notes are internal admin-only and include the admin who wrote them.

---

## Design Decisions

1. **Passenger City** — Dropped from design. Passengers are not tied to a city in the data model.

2. **Passenger KYC** — Dropped from design. Passengers only have Active/Suspended status. No KYC system for passengers.

---

## Live Monitoring (WebSocket — New)

Real-time monitoring uses **Laravel Reverb** (already configured). The frontend subscribes to the private `admin.rides` channel to receive live updates.

### WebSocket Events on `private-admin.rides`

| Event | Payload | Description |
|-------|---------|-------------|
| `driver.location.updated` | `driver_id, lat, lng, heading, speed, timestamp, ride_id, eta` | Driver GPS update (every ~1 second per active driver) |
| `ride.status.updated` | `ride_id, passenger_id, driver_id, previous_status, status, actor_type, occurred_at` | Ride state change (requested → searching → matched → en_route → arrived → in_progress → completed) |

### REST Endpoints for Initial Load

#### Active Rides

```
GET /admin/monitoring/active-rides
```

Query params: `city_id` (UUID), `status`, `per_page` (default 50)

Returns paginated active rides with `driver_location` (current GPS from Redis) and a `summary` block with total counts by status.

#### Online Drivers

```
GET /admin/monitoring/online-drivers
```

Query params: `city_id` (UUID)

Returns all online approved drivers with their current GPS `location`, `vehicle` details, and `active_ride` if on a trip. Includes a `summary` with `total_online`, `on_trip`, and `available` counts.

### Frontend Integration

1. On page load, call both REST endpoints to populate the map
2. Subscribe to `private-admin.rides` via Reverb/Echo
3. On `driver.location.updated` → update the driver marker on the map
4. On `ride.status.updated` → update the ride card/row; remove when terminal (completed/cancelled)

---

## Manual Ride Assignment (New Endpoints)

### Pending Rides

```
GET /admin/rides/pending
```

Query params: `city_id` (UUID), `vehicle_class_id` (UUID), `per_page`

Returns rides with status `requested`, `searching`, or `no_driver_found` that have no driver assigned, ordered **oldest first**. Use this for the unassigned rides queue.

### Available Drivers for a Ride

```
GET /admin/rides/{ride_id}/available-drivers
```

Query params: `radius_km` (default 5), `limit` (default 20)

Returns drivers that are:
- Online and approved
- Not currently on a trip
- In the same city as the ride
- Have the matching vehicle class
- Within the specified radius of the pickup point
- Sorted **nearest first**

Each driver includes `location` (current GPS), `vehicle` details, and `distance_km` from pickup.

### Assign Driver (Existing)

```
POST /admin/rides/{ride_id}/assign
```

Body: `{ "driver_id": "<user_id>" }`

Transitions the ride through Searching → Matched → DriverEnRoute and notifies the driver via WebSocket.

---

## Migration Required

A database migration is included. After deploying, run:

```sh
php artisan migrate
```

This adds: `suspension_reason` columns (drivers + users), `reference_number` columns (drivers, users, disputes, rides), and the `dispute_notes` table.

New records auto-generate reference numbers. Existing records need a one-time backfill:

```sh
php artisan reference-numbers:backfill
```

Options:
- `--model=driver` — backfill only drivers (also accepts: passenger, dispute, ride)
- `--chunk=500` — records per batch (default 500)
