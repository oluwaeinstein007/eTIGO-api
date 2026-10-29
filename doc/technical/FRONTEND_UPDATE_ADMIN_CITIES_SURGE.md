# Admin Dashboard: Fixes for "What the API is still missing" (cities, drivers, surge)

**Date:** October 29, 2026
**Backend:** branch `fix/admin-cities-surge-gaps`; live on staging once merged
**Prepared for:** Admin dashboard team. Driver and passenger apps: see `MOBILE_UPDATE_CITY_CLOSE.md` (nothing required).

This answers the 6 items in "What the API is still missing", one by one. All 6 are done in the API. For the two questions (#3, #4) we went with what the document suggested. A few things work slightly differently from what the document assumed; those are marked **Note**.

Full request and response details are in `API_REFERENCE.md`. Every changed request is in the "E-tiGo API v1" Postman collection. Base URL: `/api/v1`.

---

## Summary

| Must do (breaking or behaviour change) | Should do | Nice to have |
|---|---|---|
| **Close city:** send `reason` (now required) and handle `409` (#3, #4)<br>**Surge:** send only the five condition keys; send complete `conditions` on edit (#6) | Show readiness on every city (#5)<br>Tell admins to re-confirm the checks and set a new launch date after a close (#2)<br>Show `warnings` after changing a driver's class (#1) | Disable the close button while `counts.active_rides` or `counts.upcoming_scheduled_rides` is above zero (#4) |

---

## Backend response for each item

| # | Item | Backend response |
|---|------|------------------|
| 1 | Safety operators can't pick a city or a vehicle class | **Fixed.** Safety operators can now read `GET /admin/cities` and `GET /admin/vehicle-classes` (and `GET /admin/vehicle-classes/{id}`). They still can't create or change cities or classes, or open the city detail page. **Note:** use `?is_active=1` on the class list, since only an active class can be assigned. `PUT /admin/drivers/{id}/vehicle-class` now always returns `warnings`, which is non-empty when the driver's city doesn't offer the new class. The change is saved anyway, as when moving a driver to a city. |
| 2 | Closing a city keeps its manual checks | **Fixed.** Closing clears `readiness_confirmations` (Support cover, Permits) **and** `launch_date`. **Note:** we also clear the launch date: it is the client's date for the first launch, and a relaunch needs a new one. The old values are in the audit log. |
| 3 | A live city can't be closed | **Done as suggested.** `POST /admin/cities/{id}/launch-status` with `{ "status": "onboarding", "reason": "…" }` now works from **live** as well as paused. **Note, breaking:** `reason` is now required for a close, as it is for a pause. Without it you get 422 `Give a reason for pausing or closing the city.` |
| 4 | Closing isn't stopped by running trips or bookings | **Done as suggested.** A close is refused with **409** while either count is above zero: `{ "message": "…", "active_rides": 2, "upcoming_scheduled_rides": 1 }`. Get both counts before the dialog opens from `GET /admin/cities/{id}/readiness` → `counts`. **Note:** `active_rides` includes booked rides that are still looking for a driver (`requested`, `searching`). To clear upcoming bookings, pause with `scheduled_rides: "cancel"`. On a close, online drivers in the city are also taken offline and told. |
| 5 | Live and paused cities don't say if they're ready | **Fixed.** `readiness { ready, missing }` is on **every** city in `GET /admin/cities`. For a live or paused city it says whether it would still pass the checklist. It never blocks a resume. **Note:** a live city can show as not ready, for example when it has dropped below the driver minimum. That is correct data, not a bug. For live and paused cities, consider a softer label such as "Checklist: 1 missing" rather than "Not ready", since nothing is blocked. |
| 6 | Seeded surge rules use an older conditions shape | **Fixed.** A migration rewrote the seeded rules: `days` names became `days_of_week` numbers, `min_pending_rides` became `min_requests`, and `min_utilization_pct` became `min_demand_supply_ratio` **2.0**. The seeder was fixed too. **Note, breaking:** the API now refuses any condition key it doesn't read (422 on `conditions`, `Unknown condition…`). Keys used by the other type are dropped. On `PUT`, a `conditions` object replaces the saved one, so send all three time keys together. Changing `type` needs the new type's conditions in the same request. `end_time` must differ from `start_time`, and `days_of_week` can't repeat a day. |

---

## Edge cases also fixed

- **Closed vs never launched:** passengers and drivers in a closed city now get "not running for now" messages instead of "not launched yet". When you move a driver into a closed city, the warning says "is closed".
- **Surge dates on edit:** sending only `effective_until` was refused before, because it was compared with a missing `effective_from`. It is now checked against the saved start date, and a new start date alone against the saved end date.
- **Surge `00:00`:** use `00:00` as the end time for "until midnight". The seeded Abuja rule used `23:59`, which lost the last minute.
- **City list speed:** readiness for the whole page is now read in a few grouped queries instead of about a dozen per city.
