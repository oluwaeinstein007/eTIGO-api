# Driver App: Shift Reference Number

**Date:** November 2, 2026
**Backend:** branch `fix/admin-driver-shifts-api-gaps`; on staging once merged
**Prepared for:** driver app team. The passenger app has nothing to do.

## Summary

| App | Must do | Should do |
|-----|---------|-----------|
| Passenger | Nothing | — |
| Driver | Nothing. The change is one new field | Show the shift's reference on "My shifts" and the shift details, so the driver can quote it to fleet operations |

## What changed

Every shift object now has `reference_number`, e.g. `"SH-000123"`. This is the number operations see on the dashboard for that shift. It comes back from:

- `GET /driver/shifts` (`current_shift` and each entry of `shifts`)
- `POST /driver/toggle-online`, in `shift` when a shift driver goes online

```json
{
  "id": "01a19cb5-7e05-71c1-a85e-9cc81d493a5d",
  "reference_number": "SH-000123",
  "status": "scheduled",
  "starts_at": "2026-11-03T05:00:00+00:00",
  "ends_at": "2026-11-03T13:00:00+00:00"
}
```

Show it as sent (six digits, zero-padded). Treat it as display text. Keep using `id` for anything the app looks up.

Shift push notifications are unchanged: their data still carries `shift_id`.
