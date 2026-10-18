# Driver Scheduling and Vehicle Maintenance: App and Dashboard Updates

**Date:** October 18, 2026
**Backend:** branch `feature/driver-scheduling-vehicle-maintenance`; on staging once merged
**Prepared for:** Driver app and admin dashboard teams (the passenger app has nothing to do)

Two Phase 2 features, built to the OQ-19 and OQ-20 recommendations (not yet confirmed by the client; every number below is an operations setting):

- **Driver scheduling.** Every driver can plan the hours and area they expect to drive. Plans are optional, never stop anyone going online, and passengers never see them. Shifts exist only for **E-tiGo fleet cars in shift mode**, which two or more drivers share. A shift driver can go online from 15 minutes before their shift. New offers stop 15 minutes before it ends. If they are not online 30 minutes after the start, the shift is marked missed and operations are told.
- **Vehicle maintenance.** Every car is tracked for routine service (5,000 km or 3 months), tyres & brakes (6 months) and its papers (insurance, roadworthiness certificate, vehicle licence). Electric and plug-in hybrid cars also get a battery health check (12 months) and a charge level. Overdue maintenance only warns. **When a paper has been expired for more than 7 days, the car is off the road:** the driver cannot go online until a renewed paper is approved. A trip in progress is never ended.

Request and response details are in `API_REFERENCE.md`: **Driver Scheduling (Phase 2)** and **Vehicle Maintenance (Phase 2)**, plus the updated **Toggle Online**, **Upload KYC Document** and **Review Document**. Saved examples are in the "E-tiGo API v1" Postman collection: **Driver › Driver — Shifts & Availability**, **Driver › Driver — Vehicle Maintenance**, **Admin › Admin — Driver Shifts & Availability** and **Admin › Admin — Vehicle Maintenance**. Base URL: `/api/v1`.

---

## Summary

| App | Must do | Should do |
|-----|---------|-----------|
| Passenger | Nothing. Reserved drivers can now be released for two more reasons (`vehicle_blocked`, `shift_changed`); the passenger gets the usual "Your booking is still on" notification | — |
| Driver | Show every entry of `reasons` when **Go online** returns 422. Two new kinds: the car is off the road (expired papers), and shift-window messages for shared cars. Handle the new notification types without crashing (list below). The document type picker gains `roadworthiness_certificate` and `vehicle_licence`, which **require `expires_at`** | Vehicle status screen; maintenance record upload (own cars); odometer entry; charge-level prompt for plug-in cars; "My shifts" screen; "My availability" screen; show `vehicle_notice` after going online |
| Admin dashboard | Nothing breaks. Document review can send `expires_at` | Shift timetable per shared car (shift mode switch, assign/rota, edit, cancel); availability coverage view; fleet maintenance overview; car maintenance page; records review queue; upload a car's papers; unlinked papers list; two settings pages |

---

## Driver App

### Going online
`POST /driver/toggle-online` can now refuse for these reasons as well (422 `reasons`, show them as given):

- Car off the road: `"Insurance expired on 13 Oct 2026. Upload the renewed paper; you can go online once E-tiGo approves it."` Link to the documents screen.
- Shared fleet cars only:
  - `"Your car is shared on shifts. Your next shift starts Tue 20 Oct, 6:00 AM; you can go online from Tue 20 Oct, 5:45 AM."`
  - `"Your car is shared on shifts and you have no shift now. Contact fleet operations for your next shift."`
  - `"LAG-100AA is still with Kemi, who is finishing a trip. You can go online once the car is handed over."`

A 200 response has two new fields:
- `vehicle_notice`: a warning to show in a banner. Examples: `"Insurance expired on 13 Oct 2026; upload the renewed paper by 20 Oct 2026 to keep driving."` or `"Vehicle licence expires on 25 Oct 2026; upload the renewed paper."` It is `null` when there is nothing to warn about.
- `shift`: the shift being worked on a shared car (else `null`). Show `ends_at` and `offers_stop_at`.

**Plug-in cars** (`vehicle.can_charge`): ask for the charge level before going online and send `battery_level_percent` (0–100) in the same request. It is optional.

### Vehicle status screen (new)
`GET /driver/vehicle/status` returns:
- `items`: one per check. Show `label`, a coloured `status` (`ok`, `due_soon`, `overdue`, `expired`, `unknown` = "No record yet") and `message`, which is ready to show the driver. Use `due_at`, `days_until_due` and `km_until_due` for detail. `blocks_driving_from` is the date an expired paper stops the car.
- `can_drive` / `block_reason`: whether the car is off the road.
- `vehicle.estimated_odometer_km`: the last reading plus trips since.
- `charging` (plug-in cars only): `battery_level_percent` and any `active_reservation`.
- `records_managed_by`: `driver` (own car: show the upload buttons) or `operations` (fleet car: show "E-tiGo keeps this car's records" and hide the upload buttons).
- `pending_records`: records awaiting review.

### Maintenance records (own cars)
- `POST /driver/vehicle/maintenance-records` (multipart): `item_type` (`service`, `tyres_brakes`, or `battery_health` for plug-in cars only), `performed_at`, `odometer_km` (required for a service), `workshop`, `notes`, `driver_repair_id` (optional link to a `REP-` request), and `proof` (receipt or report, required). The record is `pending` until operations approve it. The driver then gets `maintenance_record_reviewed`.
- `GET /driver/vehicle/maintenance-records`: history.
- `POST /driver/vehicle/odometer` `{ "odometer_km": 50210 }`: a quick reading. A number lower than the last one gets 422 on `odometer_km`; show the message.
- Fleet-car drivers get 403 on the record upload.

### Documents
- New types: `roadworthiness_certificate` and `vehicle_licence`. **`expires_at` is required for both** (422 otherwise). Ask for the expiry date for insurance too; it is optional there, but without it E-tiGo cannot remind the driver.
- Vehicle papers now belong to the car. Renewing one no longer removes the approved paper while the new one waits for review, so the driver keeps driving meanwhile. `GET /driver/documents` may therefore show an approved and a pending paper of the same type. Show both.
- Fleet-car drivers with no car assigned get 422 when uploading a vehicle paper; operations upload fleet papers.

### My shifts (shared fleet cars only)
`GET /driver/shifts`. Show the screen only when `shift_driver` is true. It returns `current_shift`, `rules` (`online_lead_minutes`, `offer_cutoff_minutes`, `missed_after_minutes`) and upcoming `shifts`; `include_past=1` adds the last 30 days. Each shift has `starts_at`, `ends_at`, `online_from` (when Go online works), `offers_stop_at`, `status` and the car.

### My availability (every driver, optional)
- `GET /driver/availability-plans`: upcoming plans.
- `POST /driver/availability-plans` with `starts_at`, `ends_at` (30 min – 12 h), optional `area_label` ("Lekki") and `repeat_weekly` (0–3). Plans can go up to 14 days ahead. One that overlaps an existing plan gets 422 on `starts_at`.
- `DELETE /driver/availability-plans/{id}`.
- Say clearly that plans are optional: nothing happens if the driver changes or skips one.

### New notification types
All are in-app and push. Data carries `shift_id` or `vehicle_id`.

| Type | Open |
|------|------|
| `driver_shift_assigned`, `driver_shift_updated`, `driver_shift_cancelled` | My shifts |
| `driver_shift_missed` | Home (Go online still works) |
| `driver_shift_ending` | Home: new offers have stopped; finish the trip and hand the car over |
| `driver_shift_ended` | Home: the driver was set offline |
| `shift_handover_delayed` | My shifts: the previous driver is finishing a trip |
| `vehicle_maintenance_due` | Vehicle status |
| `vehicle_blocked` | Documents. The car is off the road; this one ignores notification preferences |
| `vehicle_unblocked` | Home: the driver can go online again |
| `maintenance_record_reviewed` | Vehicle status / records |

Scheduled rides: a reserved pickup can be released with `vehicle_blocked` (papers expired) or `shift_changed` (no longer inside the driver's shifts). Shift drivers only see and can reserve bookings inside their own shifts; any other booking returns 422 "This pickup is outside your shifts."

---

## Admin Dashboard (operations)

### Shared cars and shifts
- **Shift mode** on the fleet vehicle page: `PUT /admin/fleet-vehicles/{id}/shift-mode` `{ "shift_mode": true }`. It is refused for a car on a hire-purchase agreement, and switching it off is refused while the car has upcoming shifts. Vehicle responses now include `shift_mode`.
- **Timetable:** `GET /admin/driver-shifts?vehicle_id&driver_id&status=scheduled,missed&from&to` is paginated and returns a `summary` per status. A calendar per car works well.
- **Assign:** `POST /admin/driver-shifts` with `driver_id`, `vehicle_id`, `starts_at`, `ends_at`, `notes` and `repeat_daily_for` (0–13, which builds a rota). Overlaps on the car or the driver return 422 naming the clash. Overnight shifts work.
- **Edit:** `PUT /admin/driver-shifts/{id}`. Before a shift starts you can change the driver, car or times. Once it is in progress, only `ends_at` (extend it or end it early). **Cancel:** `POST …/cancel` `{ "reason": "…" }`, for shifts not yet started.
- Show `vehicle.held_by_driver_id` ("car currently with …") and each driver's `is_online`.
- Missed shifts are also emailed to operations ("Shift missed"); the email links to `/admin/driver-shifts?status=missed`.
- **Coverage:** `GET /admin/availability-coverage?city_id&from&to` (up to 14 days) gives planned drivers per hour and per area, the peak and lowest hour, and the number of approved drivers. A heat map by hour and area fits it.

### Maintenance
- **Overview:** `GET /admin/vehicle-maintenance` lists what needs attention, worst first. Filters: `status`, `type`, `fleet_only`, `blocked_only`, `search`. Its `summary` has counts per status, `blocked_vehicles` and `pending_records`.
- **Car page:** `GET /admin/vehicles/{id}/maintenance` returns items, records, papers, odometer and block state. It works for any car.
- **Record work** on a fleet car: `POST /admin/vehicles/{id}/maintenance-records` (approved at once; proof optional).
- **Review drivers' proof:** `GET /admin/vehicle-maintenance/records` (pending first) and `POST …/records/{id}/review` with `approve`, or `reject` plus a reason.
- **Upload a car's papers:** `POST /admin/vehicles/{id}/documents` (multipart: `type`, `document`, `expires_at`). The paper is approved at once and replaces the old one.
- **Document review** (`POST /admin/drivers/{driver}/documents/{doc}/review`) takes an optional `expires_at`. Show the expiry the driver entered and let the reviewer correct it. Approving a renewal lifts the block.
- **Unlinked papers:** `GET /admin/vehicle-documents/unlinked` and `POST /admin/vehicle-documents/{id}/link` `{ "vehicle_id": … }`. These are old papers the migration could not match to one car.
- A daily email digest ("Fleet maintenance") lists fleet cars with overdue work or expired papers. A separate email ("Fleet car off the road") is sent when a fleet car is blocked.
- Manual ride assignment refuses a driver whose car is off the road (422 on `driver_id`), and the available-drivers list leaves them out.

### Settings pages
- `GET/PUT /admin/settings/driver-scheduling`: shift lead, offer cut-off, missed threshold, shift and plan length limits, how far ahead.
- `GET/PUT /admin/settings/vehicle-maintenance`: `intervals` per item (`km` and/or `months`), `fuel_type_intervals` overrides (e.g. a longer service interval for electric cars), due-soon days and km, grace days, and whether expired papers block driving.

### Roles
Every endpoint above is for **Operations** (and super admins).
