# Frontend Issues Audit - Resolution Summary

**Date:** 2026-10-14
**Branch:** `feature/frontend-issues-fixes`
**Base:** `staging`

---

## Overview

This document summarises the resolution of all issues raised in the frontend audit (Figma vs Live API comparison). Items fall into three categories:

1. **Fixed** - Backend changes made
2. **Already Exists** - Endpoint or feature already exists; no backend change needed
3. **Pushback** - No change required; rationale provided

---

## 1. Disputes

### 1.1 Dispute Detail 500 Error - FIXED
**Issue:** Viewing certain dispute details returned a 500 Internal Server Error.
**Root Cause:** Two data integrity issues:
- A dispute record had `category = 'service_quality'` which is not a valid `DisputeCategory` enum value. This caused the cast to fail.
- The `payments.gateway_payment_method_id` column contained Flutterwave tokens (`FLW_PM_...`) instead of UUID references. When eager-loading `ride.payment.paymentMethod`, PostgreSQL failed comparing these non-UUID strings against the UUID column.

**Fix:** Created migration `2026_10_14_015158_fix_invalid_dispute_category_and_payment_method_ids.php` that:
- Updates any invalid dispute categories to `'other'`
- Nulls out any non-UUID `gateway_payment_method_id` values in the payments table

The `Payment` model also has an existing accessor that safely returns `null` for non-UUID values as a defensive layer.

### 1.2 Dispute Status Change Endpoint - ALREADY EXISTS
**Issue:** "No endpoint for changing dispute status."
**Existing Endpoint:** `PUT /api/v1/admin/disputes/{dispute}/status`
- Accepts `status` parameter (e.g., `under_review`)
- Controller: `AdminDisputeController@updateStatus`

### 1.3 Dispute Resolution - ALREADY EXISTS
**Issue:** "No endpoint for resolving/dismissing disputes."
**Existing Endpoint:** `PUT /api/v1/admin/disputes/{dispute}/resolve`
- Accepts `resolution` (`resolved` or `dismissed`), `resolution_notes`, optional `refund_amount`
- Controller: `AdminDisputeController@resolve`

### 1.4 Dispute Internal Notes - ALREADY EXISTS
**Issue:** "No CRUD for internal notes."
**Existing Endpoints:**
- `GET /api/v1/admin/disputes/{dispute}/notes` - List notes
- `POST /api/v1/admin/disputes/{dispute}/notes` - Create note
- `PUT /api/v1/admin/disputes/{dispute}/notes/{note}` - Update note
- `DELETE /api/v1/admin/disputes/{dispute}/notes/{note}` - Delete note
- Controller: `AdminDisputeNoteController`

---

## 2. Drivers

### 2.1 Driver Acceptance Rate - FIXED
**Issue:** "No acceptance_rate in driver detail performance data."
**Fix:** Added `acceptance_rate` to the performance object in `GET /api/v1/admin/drivers/{id}`. Computed from `AuditLog` records of `ride_accepted` and `ride_rejected` events for the driver. Defaults to `100.0` if no offers have been recorded.

**Response change:**
```json
{
  "performance": {
    "rating": 4.7,
    "total_trips": 150,
    "completed_trips": 142,
    "cancelled_trips": 8,
    "cancellation_rate": 5.3,
    "acceptance_rate": 92.5,
    "total_earnings": 450000.00
  }
}
```

### 2.2 Driver Profile Completeness - FIXED
**Issue:** "No is_profile_complete field."
**Fix:** Added `is_profile_complete` boolean to the `DriverResource`. A profile is considered complete when the driver has: user account, city assigned, vehicle, KYC verified, at least one bank account, and at least one document uploaded.

**Response change:** The driver object now includes:
```json
{
  "is_profile_complete": true
}
```

### 2.3 Suspension Reason - ALREADY EXISTS
**Issue:** "Suspend endpoint doesn't accept a reason."
**Existing behaviour:** `PUT /api/v1/admin/drivers/{id}/suspend` already accepts a `reason` parameter (required, string, max 500 chars) and stores it as `suspension_reason`. The `DriverResource` already conditionally returns `suspension_reason` when the driver status is `Suspended`.

### 2.4 Driver Trip History - ALREADY EXISTS
**Issue:** "No trip history endpoint for drivers."
**Existing Endpoint:** `GET /api/v1/admin/drivers/{id}/trip-history`
- Supports `status` and date range filters (`from`, `to`)
- Returns paginated rides with full `RideResource`
- Controller: `DriverManagementController@tripHistory`

### 2.5 Bank Name in Bank Account - FIXED
**Issue:** "Bank account response only shows bank_code, not bank_name."
**Fix:** Updated `BankAccountResource` to resolve `bank_name` from the cached Paystack bank list using the `bank_code`.

**Response change:**
```json
{
  "bank_code": "058",
  "bank_name": "Guaranty Trust Bank",
  "account_number": "****1234",
  "account_name": "John Doe"
}
```

---

## 3. Passengers

### 3.1 Passenger Suspension Reason - ALREADY EXISTS
**Issue:** "Suspend endpoint doesn't accept a reason."
**Existing behaviour:** `PUT /api/v1/admin/passengers/{id}/suspend` already accepts a `reason` parameter and stores it as `suspension_reason`.

### 3.2 Passenger Trip History - ALREADY EXISTS
**Issue:** "No trip history endpoint for passengers."
**Existing Endpoint:** `GET /api/v1/admin/passengers/{id}/trip-history`
- Controller: `AdminPassengerController@tripHistory`

---

## 4. Operational Reports

### 4.1 Previous Period Comparison - FIXED
**Issue:** "No comparison with previous period for trends/KPIs."
**Fix:** All four report endpoints now return a `previous_period` object when `from` and `to` query params are provided. The previous period is the same length as the requested period, immediately preceding it.

**Affected endpoints:**
- `GET /api/v1/admin/reports/ride-volume` - Returns `previous_period.total_rides`, `change_pct`
- `GET /api/v1/admin/reports/completion-rate` - Returns `previous_period.completed`, `completion_rate`, `change_pct`
- `GET /api/v1/admin/reports/revenue` - Returns `previous_period.total_revenue`, `revenue_change_pct`, `count_change_pct`
- `GET /api/v1/admin/reports/driver-utilisation` - Returns `previous_period.total_active_drivers`, `drivers_change_pct`, `trips_change_pct`

### 4.2 Completion Rate by City - FIXED
**Issue:** "No city-level breakdown in completion rate report."
**Fix:** Added `by_city` array to the completion rate response with per-city: `city_id`, `city_name`, `total`, `completed`, `cancelled`, `completion_rate`.

### 4.3 Driver Utilisation Rate & City Breakdown - FIXED
**Issue:** "No utilisation_rate percentage. No city breakdown."
**Fix:** Added:
- `total_approved_drivers` - Total approved drivers in the system/filtered city
- `utilisation_rate` - Percentage of approved drivers who had at least one completed trip in the period
- `by_city` array with per-city: `city_id`, `city_name`, `trip_count`, `active_drivers`, `total_earnings`

### 4.4 Trend Gap Filling - FIXED
**Issue:** "Trend data skips days with no rides, making charts incomplete."
**Fix:** Both ride volume and revenue trend data now fill empty periods (days/weeks/months) with zero-count entries when `from` and `to` params are provided. Revenue trends also fill `total: 0`.

### 4.5 Combined Export (type=all) - FIXED
**Issue:** "Export only supports one report type at a time."
**Fix:** The export endpoint now accepts `type=all` which exports all ride data in a single CSV with columns covering all report dimensions.

**Endpoint:** `GET /api/v1/admin/reports/export?type=all&from=2026-01-01&to=2026-10-14`

---

## 5. Gamification

### 5.1 Aggregate Filters - FIXED
**Issue:** "No date range or city filter on aggregate stats."
**Fix:** The `GET /api/v1/admin/gamification/aggregate` endpoint now accepts:
- `from` (date) - Filter carbon score data from this date
- `to` (date) - Filter carbon score data to this date
- `city_id` (UUID) - Filter profiles/scores by city

Also added to the response:
- `total_points_awarded` (int) - Sum of all points awarded in the period
- `average_co2_per_trip` (float) - Average CO2 saved per trip

### 5.2 Users List - City Filter & User Names - FIXED
**Issue:** "No city filter on users list. No user name in response."
**Fix:**
- Added `city_id` query param to `GET /api/v1/admin/gamification/users` (filters by users who have rides in that city)
- Response profiles now include `first_name`, `last_name`, `email` fields

---

## 6. Live Monitoring

### 6.1 Active Rides - Driver Rating, SOS, Stage Timestamps - FIXED
**Issue:** "Active rides missing driver rating, SOS info, and stage timestamps."
**Fix:** The `GET /api/v1/admin/monitoring/active-rides` response now includes per ride:
- `driver_rating` (float, nullable) - The driver's average rating
- `stage_timestamps` object:
  - `requested_at` - When the ride was requested
  - `matched_at` - When a driver was matched
  - `driver_arrived_at` - When the driver arrived at pickup (from state transitions)
  - `started_at` - When the trip started
- `has_active_sos` (boolean) - Whether the ride has an active SOS incident
- `sos` object (when `has_active_sos` is true): `id`, `status`, `trigger_type`, `created_at`

### 6.2 Online Drivers - Rating - FIXED
**Issue:** "No driver rating in online drivers list."
**Fix:** Added `rating` (float, nullable) field to each driver in the `GET /api/v1/admin/monitoring/online-drivers` response.

### 6.3 Available Drivers - Rating - FIXED
**Issue:** "No driver rating in available drivers for manual assignment."
**Fix:** Added `rating` (float, nullable) field to each driver in the `GET /api/v1/admin/monitoring/available-drivers/{ride}` response.

---

## 7. Manual Assignment

### 7.1 Manual Assignment Endpoints - ALREADY EXISTS
**Issue:** "No endpoints for manual ride assignment."
**Existing Endpoints:**
- `GET /api/v1/admin/monitoring/pending-rides` - List unassigned rides
- `GET /api/v1/admin/monitoring/available-drivers/{ride}` - Find nearby available drivers (with distance, vehicle class matching)
- `POST /api/v1/admin/rides/{ride}/assign` - Assign a driver to a ride

These are fully operational with Redis-based driver location search, vehicle class matching, and availability checking.

---

## 8. Pricing

### 8.1 Pricing Config - PUSHBACK
**Issue:** References to pricing config endpoints.
**Status:** Pricing endpoints already exist and are functional:
- `GET /api/v1/admin/pricing/configs` - List all pricing configs
- `POST /api/v1/admin/pricing/configs` - Create new config (append-only versioning)
- `GET /api/v1/admin/pricing/configs/{id}` - View config detail

The pricing system uses append-only versioning (new versions are created, old ones are never modified). The 5-minute free waiting time is configurable via the `free_waiting_time_minutes` field in pricing configs.

---

## Summary of Changes

| Area | Issue | Resolution |
|------|-------|------------|
| Disputes | 500 error on detail | **Fixed** - Data migration for invalid categories and payment method IDs |
| Disputes | Status change endpoint | **Already exists** - `PUT /disputes/{id}/status` |
| Disputes | Resolution endpoint | **Already exists** - `PUT /disputes/{id}/resolve` |
| Disputes | Internal notes CRUD | **Already exists** - 4 endpoints on `/disputes/{id}/notes` |
| Drivers | Acceptance rate | **Fixed** - Added `acceptance_rate` to performance data |
| Drivers | Profile completeness | **Fixed** - Added `is_profile_complete` to driver resource |
| Drivers | Suspension reason | **Already exists** - `reason` param on suspend endpoint |
| Drivers | Trip history | **Already exists** - `GET /drivers/{id}/trip-history` |
| Drivers | Bank name | **Fixed** - Added `bank_name` resolved from Paystack |
| Passengers | Suspension reason | **Already exists** |
| Passengers | Trip history | **Already exists** |
| Reports | Previous period comparison | **Fixed** - All 4 reports now return `previous_period` |
| Reports | Completion rate by city | **Fixed** - Added `by_city` array |
| Reports | Utilisation rate | **Fixed** - Added `utilisation_rate` and `by_city` |
| Reports | Trend gap filling | **Fixed** - Empty periods filled with 0 counts |
| Reports | Combined export | **Fixed** - Added `type=all` option |
| Gamification | Aggregate filters | **Fixed** - Added date range and city filters |
| Gamification | User names & city | **Fixed** - Added name fields and city filter |
| Monitoring | Driver rating | **Fixed** - Added to active rides, online & available drivers |
| Monitoring | SOS info | **Fixed** - Added `has_active_sos` and `sos` object |
| Monitoring | Stage timestamps | **Fixed** - Added `stage_timestamps` with arrival time |
| Manual Assignment | Endpoints | **Already exists** - 3 endpoints fully operational |
| Pricing | Config endpoints | **Already exists** / No change needed |

---

## Migration Required

After deploying this branch, run:
```bash
php artisan migrate
```

This will execute the data fix migration that corrects invalid dispute categories and payment method IDs in the database.

---

## Updated Documentation

- Postman Collection: `doc/technical/postman_collection.json` (updated)
- API Reference: `doc/technical/API_REFERENCE.md` (updated)
