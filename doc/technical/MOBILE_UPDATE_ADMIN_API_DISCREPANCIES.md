# Admin Dashboard Fixes: What the Apps Should Know

**Date:** October 29, 2026
**Backend:** branch `fix/admin-dashboard-api-discrepancies`; live on staging once merged
**Prepared for:** Driver app team. **Passenger app: no changes.**

These fixes are for the admin dashboard. **Nothing in the driver app has to change**: no field was removed or renamed and no driver endpoint changed. Two fields were added to responses the driver app already reads, and one admin action now affects drivers directly. Both are below.

Request and response details are in `API_REFERENCE.md`. Base URL: `/api/v1`.

---

## Summary

| Must do | Should do | Nice to have |
|---|---|---|
| Nothing | 1. Handle being taken offline and losing the car when a fleet agreement ends | 2. Show the payout reference (`PO-000123`)<br>3. Show the agreement reference (`FA-000123`) |

---

## 1. A fleet agreement that ends takes the car back (should do)

**Before:** when Operations terminated a fleet driver's hire-purchase agreement, the car stayed on the driver's account until someone unassigned it separately.

**Now:** terminating (`/terminate` or `/terminate-settle`) takes the car off the driver's account in the same step and takes the driver offline. Operations can't do it while the driver is on a trip.

What the driver app sees:

- Anything that returns the driver's vehicle shows none: `vehicle: null` on the driver profile, and `GET /driver/vehicle/status` answers `404` "Vehicle not found." as it does for a driver with no car.
- The driver is offline (`is_online: false`). `POST /driver/toggle-online` is refused for the same reason as for any driver without a car.
- `GET /driver/fleet-agreement` shows the agreement with `status: "terminated"`, `terminated_reason` and `terminated_at`, as before.

The app already handles a driver with no car (new fleet drivers waiting for one), so this should work with no code change. Please check that a driver who loses their car while the app is open sees the "no vehicle" state, not a stale car, the next time the profile is fetched.

---

## 2. Payout reference (nice to have)

Every payout in `GET /driver/payouts` (and the payout returned by `POST /driver/payouts`) now has `reference_number`, for example `"PO-003101"`. Finance uses it when they talk to a driver about a payout. Show it on the payout detail or history row so the driver can quote it to support.

A rejected payout now also has `rejected_at` (when Finance turned it down). The reason is still in `failure_reason`.

```json
{
  "id": "5e4d3c2b-1a09-4f8e-9d7c-6b5a4f3e2d1c",
  "reference_number": "PO-003101",
  "amount": 500000,
  "status": "rejected",
  "failure_reason": "Bank details do not match the account holder",
  "rejected_at": "2026-10-29T10:00:00+00:00",
  "requested_at": "2026-10-29T08:00:00+00:00"
}
```

---

## 3. Agreement reference (nice to have)

The fleet agreement the driver sees (`GET /driver/fleet-agreement`) now has `reference_number`, for example `"FA-002208"`. Show it on the agreement screen so drivers and Operations can name the same agreement.
