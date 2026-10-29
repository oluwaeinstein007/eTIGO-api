# Admin Dashboard: Fixes for "What the API is still missing"

**Date:** October 29, 2026
**Backend:** branch `fix/admin-dashboard-api-discrepancies`; live on staging once merged
**Prepared for:** Admin dashboard team. Driver app: see `MOBILE_UPDATE_ADMIN_API_DISCREPANCIES.md` (nothing required). Passenger app: no changes.

This answers the 29 items in "What the API is still missing", one by one. All 29 are fixed in the API. A few work slightly differently from what the document assumed; those are marked **Note** and explained.

Full request and response details are in `API_REFERENCE.md`. Every new or changed request is in the "E-tiGo API v1" Postman collection. Base URL: `/api/v1`.

---

## Summary

| Must do (breaking or behaviour change) | Should do | Nice to have |
|---|---|---|
| **SOS:** send `expected_operator_id` on every take; handle `409`. Drop the `/assign` call after logging a call (#1)<br>**Fleet:** send `reason` when pausing (now required) (#10). Drop the `/unassign` call after terminating (#13)<br>**Dates:** `from`/`to` on payouts, ledger, exports and gamification must be `Y-m-d` and are Lagos days (#3, #21)<br>**Promos:** the city filter now includes promos for every city (#5) | Show references `FA-`/`PO-`/`AD-` (#8, #14)<br>Use the new filters and searches (#6, #7, #17, #23)<br>Use the new endpoints: history, maintenance, single payout/adjustment, ledger pages, remove a driver's rate, withdraw an excuse (#9, #6, #15, #20, #24, #28)<br>Show tip errors beside their fields (#27) | Use `limits` on tip settings for client-side checks (#27)<br>Use `items` on reconciliation to link to the payout or wallet (#19) |

---

## Backend response for each item

| # | Item | Backend response |
|---|------|------------------|
| 1 | SOS: logging a call doesn't take the alert | **Fixed.** `POST /admin/sos/{id}/calls` now takes an untaken alert in the same request, under the same row lock, like dispatch. Remove the follow-up `/assign`. An alert another operator holds stays with them. `POST /admin/sos/{id}/assign` also takes `expected_operator_id` (the holder the console showed; `null` = nobody). If someone else has it by then: `409`, `error_code: "SOS_INCIDENT_TAKEN"`, with the incident as it is now. **Send it on every take.** Leaving it out is an explicit take-over, as before. |
| 2 | Wallet audit trail: added or taken? | **Fixed.** Adjustment entries now have `direction` (`credit` = added, `debit` = taken) and `adjustment: { id, reference_number, type, status }`. **Note:** requested and rejected adjustments are also in `GET /admin/adjustments?account_id={wallet}`. |
| 3 | Gamification standings ignore the date range | **Fixed.** `GET /admin/gamification/users` takes `from`/`to`. With a range it lists only passengers who earned points in it, adds `period: { points, co2_saved_kg, trips }` to each, and ranks by `period.points`. The response's `period` echoes the range (`null` = all-time). Tiers and `total_*` stay lifetime. `/aggregate` now uses the same Lagos-day bounds. |
| 4 | Live operations names the booker | **Fixed.** Each trip has `is_third_party` and `rider: { user_id, name }`. Show `rider.name` as the passenger. **Note:** `passenger` stays the booker, as on every other ride payload; renaming it would break that. |
| 5 | Promo city filter leaves out every-city promos | **Fixed.** `city_id` now returns promos limited to that city and promos with no cities. `include_all_cities=false` gives the old behaviour. |
| 6 | Fleet cars: no class or status filter, no maintenance | **Fixed.** `GET /admin/fleet-vehicles` takes `vehicle_class_id` and `status` (`available`, `assigned`, `maintenance`, `blocked`). Each car has `fleet_status`, and `maintenance: { started_at, reason }` while in. New `PUT /admin/fleet-vehicles/{id}/maintenance` (`in_maintenance`, `reason` when true). A car in maintenance can't be assigned, put on an agreement or swapped in. **Note:** use `status=available` for the "free cars" picker; `unassigned_only` still includes cars in maintenance. |
| 7 | Waiting drivers and agreements can't be searched | **Fixed.** `drivers-awaiting`: `search` (name, phone, email, driver reference), `city_id`. `fleet-agreements`: `search` (agreement reference, driver name/phone/reference, plate), `vehicle_class_id`. |
| 8 | Agreement has no reference | **Fixed.** `reference_number`, e.g. `FA-002208`. Existing agreements were numbered in creation order. **Note:** six digits, zero-padded (`FA-002208`, not `FA-2208`), the same format as `SOS-000123`. Please match the design to this. |
| 9 | Agreement history not sent | **Fixed.** `GET /admin/fleet-agreements/{id}/history`: every event, newest first, with `description`, `reason`, `changes` (from/to on edits), `actor` and time. |
| 10 | Pause takes no reason | **Fixed, breaking.** `reason` is now **required** on `/pause` (422 without it). Shown as `paused_reason` while paused; kept in the history. |
| 11 | Only the daily target can change | **Fixed.** `PUT /admin/fleet-agreements/{id}` also takes `total_vehicle_cost` and `agreement_start_date`, on active **or paused** agreements; `reason` is required with either. **Note:** the cost must be more than the driver has already paid (to end it, terminate or settle). The start date can't be before the day the agreement was entered or after the first day the driver paid. These come back as validation errors on the field. |
| 12 | Finance can't see vehicle classes | **Fixed.** Finance can list and view classes (not create or edit). Agreement lists and detail now load `vehicle.vehicle_class` for everyone. |
| 13 | Terminate leaves the car with the driver | **Fixed.** `/terminate` and `/terminate-settle` take the car back and take the driver offline in the same transaction. Remove the follow-up `/unassign`: it would now answer 422 "not assigned". **Note:** terminating is refused while the driver is on a trip (422 with a message). |
| 14 | Payouts and adjustments have no reference | **Fixed.** `reference_number`: `PO-003101`, `AD-000001` (same six-digit format). Existing rows were numbered. Searchable on both lists. |
| 15 | No single payout or adjustment endpoint | **Fixed.** `GET /admin/payouts/{id}` and `GET /admin/adjustments/{id}`. **Note:** links use the id (UUID), not the reference. |
| 16 | Driver ledgers: `owner` is always null | **Fixed.** `owner_type: "driver"` and `owner: { id, user_id, reference_number, name, first_name, last_name, phone, email }`. `id` is the driver profile id. |
| 17 | Adjustment names only its account id | **Fixed.** Each adjustment has `account: { id, type, type_label, owner_type, owner }`, plus `rejected_by`, `rejected_at`, `rejection_reason` once rejected (older rejections back-filled from the audit log). List filters: `search`, `owner_type`, `type`, `account_id`. To adjust a driver, use the driver ledger's `id` as `account_id`. **Note:** only passenger wallets and driver ledgers (available earnings) can be adjusted; E-tiGo's own accounts are refused. |
| 18 | Ledger entries don't name their account | **Fixed.** Each entry has `account: { id, type, type_label, owner_type, owner }`; `owner` is `null` for E-tiGo's own accounts (`owner_type: "system"`). The CSV gains an "Account Owner" column. New `account_id` filter on the list. |
| 19 | Reconciliation mismatches aren't by transaction | **Fixed.** Each mismatch has `items`: `{ reference, issue, gateway_amount, ledger_amount, difference }` plus `payout_id`/`payout_reference` or `account_id`/`journal_id`. `issue`: `missing_in_ledger`, `missing_at_gateway`, `amount_mismatch`. **Note:** at most 200 per kind (`items_count`, `items_truncated`). Reports made before this change have `items: null`. Reports now cover Lagos days. |
| 20 | Driver ledger shows only the latest entries | **Fixed.** `GET /admin/driver-ledgers/{id}/entries` and `/payouts`, paginated (`per_page` up to 100; entries also take `from`, `to`, `type`). |
| 21 | Payout and ledger dates are UTC days | **Fixed, breaking.** `from`/`to` on payouts, ledger and both CSV exports are Lagos days and must be `Y-m-d`. A day's CSV now matches that day's list. |
| 22 | Rejected payout: who and when | **Fixed.** `rejected_at` and `rejected_by` (older rejections back-filled). Reject now returns the payout. |
| 23 | Payout account search: bank, account number | **Fixed.** `search` also matches the account number (or part of it) and the bank name. **Note:** bank-name search needs 3+ letters. |
| 24 | Can't remove a driver's own rate | **Fixed.** `DELETE /admin/settings/commission/drivers/{driver_id}` puts the driver back on the global rate. They then follow later changes to it. |
| 25 | Drivers on their own rate aren't named | **Fixed.** Each override has `driver: { id, reference_number, user_id, name, phone }`. |
| 26 | Finance can't look up drivers | **Fixed.** Finance can call `GET /admin/drivers`. **Note:** only the list, read-only. Driver detail and actions stay with Operations and Safety. |
| 27 | Tip errors don't name the field | **Fixed.** Standard validation errors on `min_amount`, `max_amount` or `presets.{index}`. GET returns `limits`. Presets must be distinct. **Note:** the ₦1 minimum tip and the ₦50 preset floor are deliberate. Presets are the suggested buttons; the minimum applies to amounts typed in. |
| 28 | Excusing a day can't be undone | **Done (answers the question: yes).** `POST /admin/fleet-agreements/{id}/remittances/{remittance}/withdraw-excuse` with `reason`. A settled short day counts towards the shortfall streak again. Logged in the history. |
| 29 | Excusing twice answers 500 | **Fixed.** `422` "This remittance day is already excused." |
