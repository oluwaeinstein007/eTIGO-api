# Admin Dashboard Design Gaps: Backend Changes

**Date:** October 17, 2026  
**Backend:** PR #102 (`fix/admin-dashboard-design-gaps`); live on staging once merged  
**Prepared for:** Admin dashboard team (also read §7 if you work on the passenger or driver app)

This answers the "Admin dashboard: designs compared with the API" document, item by item, for promos, Safety and SOS, anti-offline compliance, EV charging stations and passenger wallets. Most gaps are now closed in the API. A few stay as they are on purpose; those are in §6 with the reason, and need a design change instead.

Full request and response details are in `API_REFERENCE.md` (sections **Admin — Promo Management**, **Admin — SOS Management**, **Admin — Offline Trip Flags**, **Admin — EV Station Management**, **Admin — Wallet & Ledger Management**). New and changed requests are in the "E-tiGo API v1" Postman collection. Base URL: `/api/v1`.

---

## Summary

| Must do (breaking) | Should do | Nice to have |
|--------------------|-----------|--------------|
| Promos: read `city_ids` / `cities` instead of `city_id`; `redemptions_by_day` is now an array | Use the new `status` fields, readable references and pause/resume | Live EV availability over WebSocket |
| Compliance: send `notes` when escalating | Use search, wallet transactions and audit trail endpoints | Log operator calls on SOS alerts |
| Passenger app: promo `city_id` is gone (see §7) | Show `permissions.can_freeze` instead of checking roles | Show GPS accuracy once the driver app sends it |

---

## Backend response for each item

Paste these into the **Backend response** column of the comparison document.

| # | Item | Backend response |
|---|------|------------------|
| 1 | Promo list: status | **Changed.** Each promo now has `status: { value, label }`: `active`, `scheduled`, `paused`, `expired` or `cancelled`. The `status` filter takes the same values (`inactive` still works and means `paused`). `scheduled` stays (a promo that starts later is not active); please add a "Scheduled" badge to the design (§6). |
| 2 | Create/edit: several cities | **Changed.** Send `city_ids: string[]` (empty or left out = every city). Responses return `city_ids` and `cities`. `city_id` is still accepted on input but no longer returned. |
| 2 | Create/edit: rider eligibility | **Changed.** Send `rider_eligibility`: `all`, `first_ride` or `returning`. Responses return `rider_eligibility: { value, label }`; `custom` means the counts were set to something else through the API. |
| 2 | Create/edit: timing | **Changed.** Send `timing`: `any_time`, `peak_only` or `off_peak_only`. Responses return `timing: { value, label }`. |
| 2 | Fields not on the design | **Stays.** `vehicle_class_id`, `minimum_fare_amount`, `geo_fence`, `description` and `is_active` are all optional. Leave them off the form if the design does not need them. |
| 3 | Pause and resume | **Changed.** New `POST /admin/promos/{promo}/pause` and `/resume`, safe to call twice. `PATCH …/status` still works but is deprecated. |
| 4 | Cancel | Matches. No change. |
| 5 | Usage "32% used" beside date-range figures | **Stays, clarified.** Usage is measured against a lifetime limit, so it is lifetime by nature. The response now says so explicitly: `lifetime_redemptions`, `total_redemption_limit`, `remaining_redemptions`, `usage_percentage`. Label the bar "Lifetime usage"; show "No limit" when they are `null`. |
| 5 | Daily bars for every day | **Changed (breaking).** `redemptions_by_day` is now an array with one `{ date, count, total_discount }` per day in the range, zeros included, in Lagos days. `performance.period` echoes the range used. Defaults: promo start to today; max 366 days. |
| 5 | Extra figures | **Stays.** `unique_users`, `average_discount`, `recent_redemptions` cost nothing to ignore. |
| 6 | SOS status (3 values) | **Changed.** New `queue_status: { value, label }`: `unacknowledged`, `acknowledged` (an operator has taken it), `resolved`. Filter with `queue_status`. The detailed `status` stays. Its `acknowledged` value is now labelled "Confirmed Safe". |
| 6 | Alert ID | **Changed.** `reference_number`, e.g. `SOS-000123`. Existing alerts were numbered too. |
| 6 | Passenger on history rows | **Fixed.** History now loads passenger, driver and vehicle class like the active list. |
| 6 | Wait time | **Changed.** `wait_seconds` (until an operator took or closed it; counting while waiting) and `operator_assigned_at`. |
| 6 | Search | **Changed.** `search` on both lists: SOS reference, ride reference, passenger/driver name or phone. |
| 7 | Route line, live vehicle | **Changed.** Details now return `route` (encoded polyline) and `driver_location`. Live positions: subscribe to `admin.rides` and filter `driver.location.updated` by `ride_id`. |
| 7 | "Automatic escalation in 00:18" | **Changed.** `escalates_at` timestamp, and the console now gets `sos.incident.updated` when the check-in goes out. |
| 7 | Audit trail | **Changed.** Each `event_logs` entry has a readable `description` and `actor_type` (`system`, `operator`, `passenger`, `driver`). |
| 8 | Call the passenger | **Partly.** New `POST /admin/sos/{incident}/calls` logs a call in the audit trail. Calls are placed from the operator's phone (use a `tel:` link). Recorded calls are not built (§6). |
| 9 | Take the alert | **Stays, simplified.** Taking ownership is required for accountability. Wire the design's "Acknowledge" button to `POST …/assign` (§6). Dispatch now also takes an untaken alert automatically. |
| 10 | Dispatch: confirmation only | **Stays.** `emergency_service_type` is required (§6). |
| 11 | Resolve: no field | **Stays.** Resolution notes are required (§6). The field is `notes`, not `resolution_notes` as the comparison document says. |
| 12 | Live status changes | **Changed.** New `sos.incident.updated` on `admin.sos` for every change (taken, dispatched, resolved, confirmed safe, cancelled, check-in sent, reassigned). |
| 13 | Flag ID | **Changed.** `reference_number`, e.g. `FLG-000045`. |
| 13 | Status (Flagged/Disputed/Upheld/Overturned) | **Changed.** `status: { value, label }`, and the `status` filter takes those four values (`active` still works). |
| 13 | Search | **Changed.** `search`: flag reference, ride reference, driver name, phone or driver ID. |
| 14 | Driver's city | **Changed.** `driver_city` on the details. |
| 14 | "Why this was flagged" | **Changed.** `detection_summary`: one sentence built from the evidence. |
| 14 | Distance from pickup at cancellation | **Changed.** `evidence.cancel_distance_from_pickup_km`. Recorded for new flags; older flags show `null`. |
| 14 | Time the phones stayed together | **Partly.** `evidence.on_route_duration_seconds`: how long the **driver's** phone stayed on the passenger's route. We don't track the passenger's phone after a cancellation, so please relabel (§6). Older flags show `null`. |
| 14 | GPS accuracy | **Partly.** `evidence.average_gps_accuracy_meters`. `null` until the driver app sends `accuracy` with location updates (§7). |
| 14 | Audio log | **Not built.** We don't record audio (§6). |
| 14 | Dispute time and channel | **Changed.** `dispute: { notes, filed_at, channel }`; `channel` is `driver_app`. |
| 14 | Earlier flags in 30 days | **Changed.** `previous_flags` on the details response (no separate endpoint needed). |
| 14 | Reason for uphold/overturn | **Changed.** `review_notes`, and `history` lists every step with its reason and who did it. |
| 15 | Review any flag | **Changed.** Uphold/overturn now works on any flag that has not been reviewed yet, disputed or not. |
| 16 | Escalate with a reason | **Changed (breaking).** `notes` is now required (10–2000) and shown in `history`. |
| 17 | De-escalate | **Changed.** New `POST /admin/offline-flags/{flag}/de-escalate` with required `notes`. |
| 17 | Remove all sanctions | **Use overturn.** Overturning removes the flag's sanctions and stops it counting towards later ones (§6). |
| 18 | Confirm deactivation | **Stays.** Level 3 must be confirmed by a person; please add the step to the design (§6). |
| 19 | Station status | **Stays.** `maintenance` ("Under Maintenance") is a real state; please add it to the design (§6). |
| 19 | Live availability | **Changed.** New `admin.ev-stations` channel with `ev.station.availability_changed`. |
| 20 | City and map position | **Partly.** `city_id` is now optional (worked out from `lat`/`lng`). `lat`/`lng` stay required (§6). |
| 20 | Stall count on edit | **Changed.** `PUT /admin/ev-stations/{station}` accepts `total_stalls`: adds stalls, or removes the highest-numbered free ones (422 if in use). |
| 20 | Connector, power, price, hours not returned | **Fixed.** Now returned on every station. |
| 21 | Queue with drivers | **Changed.** `queue` on the details: position, driver, vehicle, plate, `joined_at`, `wait_seconds`. Stall `current_driver` now includes the vehicle. |
| 22 | Delete | **Stays.** Not on the design; no change needed. Also fixed: delete is now refused while a reservation is pending review. |
| 23 | Utilisation | **Stays.** Not on the design; no change needed. |
| 24 | Passenger on the list | **Changed.** `owner: { id, reference_number, name, first_name, last_name, phone, email }`. Search also matches full name and passenger ID. |
| 24 | Last activity | **Changed.** `last_activity_at`. |
| 24 | Status `closed` | **Stays.** Closed wallets exist; show a "Closed" badge (§6). |
| 25 | Passenger details | **Changed.** Same `owner` block on wallet details. |
| 25 | Hold details | **Changed.** Each hold has `ride: { reference_number, status, status_label, vehicle_class, pickup_address, destination_address }`. The hold's `id` is its reference. |
| 26 | Audit trail | **Changed.** New `GET /admin/wallets/{account}/audit-trail`: freezes, unfreezes and manual adjustments with reason, who and their role. |
| 26 | Frozen banner | **Changed.** `wallet.frozen: { frozen_at, reason, frozen_by: { id, name, role } }` while frozen. |
| 27 | Transactions tab | **Changed.** New `GET /admin/wallets/{account}/transactions`: paged, `search`, `type`, `status`, `direction`, `date_from`/`date_to`. |
| 27 | Type | **Changed.** `type: { value, label }`: Wallet Top-up, Ride Payment, Ride Refund, Driver Tip, plus Cancellation Fee, Balance Due, Cash Change Credit, Manual Adjustment, Reversal. `recent_transactions` entries also carry `transaction_type`. |
| 27 | Status | **Changed.** `success`, `pending`, `failed`; pending and failed are top-ups the card provider hasn't confirmed. |
| 28 | View-only for operations | **Changed.** Operations can now open wallets read-only (list, details, transactions, audit trail), like Support. Only Finance and Super Admin can freeze. `permissions.can_freeze` is `false` for Operations and Support: use it to show the view-only notice. |

---

## 1. Promos

**Status badge.** Use `promo.status.value` for colour and `promo.status.label` for text. Order of precedence is fixed by the API: cancelled → expired → paused → scheduled → active.

**Form mapping:**

| Design control | Send | Read back |
|----------------|------|-----------|
| City multi-select | `city_ids: [uuid, …]` (`[]` for all) | `city_ids`, `cities[]` |
| Rider eligibility radio | `rider_eligibility: all \| first_ride \| returning` | `rider_eligibility.value` (`custom` = API-set counts; show it read-only) |
| Timing radio | `timing: any_time \| peak_only \| off_peak_only` | `timing.value` |

On edit, leave `city_ids` out to keep the cities as they are. Send `[]` only when the admin picks "All cities".

**Pause / resume:** `POST /admin/promos/{id}/pause`, `POST /admin/promos/{id}/resume`. Both return the updated promo. Resume answers 422 for an expired promo ("This promo code has expired. Move its end date later to run it again.").

**Performance:** request `?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`. Draw one bar per entry of `redemptions_by_day` (already zero-filled, Lagos days). Show the usage bar from the lifetime fields, labelled as lifetime.

```ts
type PromoStatus = 'active' | 'scheduled' | 'paused' | 'expired' | 'cancelled';
type DayBar = { date: string; count: number; total_discount: number };
```

## 2. Safety and SOS

- **Queue:** filter tabs → `queue_status=unacknowledged|acknowledged|resolved`. Show `reference_number` as the alert ID and `wait_seconds` as wait time (tick it locally while `queue_status` is `unacknowledged`).
- **Acknowledge button** → `POST /admin/sos/{id}/assign`.
- **Countdown:** count down to `escalates_at`. When it is `null` there is no automatic escalation pending.
- **Map:** draw `route.encoded_polyline` (Google polyline encoding), pin the SOS at `gps_lat/gps_lng`, the vehicle at `driver_location`, then move it with `driver.location.updated` events from `private-admin.rides` where `ride_id` matches.
- **Audit trail:** render `description` for each event, the actor's name when `actor` is set, and "System" when `actor_type` is `system`.
- **Live queue:** on `private-admin.sos`, handle `sos.incident.triggered` (new row), and `sos.incident.updated` / `sos.incident.escalated` (update the row by `incident_id`, using `queue_status`, `status` and `escalates_at` from the payload, or refetch the incident).
- **Call:** after the operator calls, `POST /admin/sos/{id}/calls` with `outcome` (`reached`, `no_answer`, `busy`, `unreachable`) and optional `called_party`, `phone`, `notes`.
- **Dispatch dialog:** needs a service picker (`police`, `ambulance`, `fire`, `all`). **Resolve dialog:** needs a notes field (min 10 characters), sent as `notes`.

## 3. Anti-offline compliance

- **Queue:** `reference_number` as the flag ID, `status` badge, `search`, filters `status` and `sanction_tier`.
- **Details:** "Why this was flagged" → `detection_summary`. Evidence tiles → `evidence.route_match_percentage`, `cancel_distance_from_pickup_km`, `on_route_duration_seconds` (format as "10 min 50 s"), `average_gps_accuracy_meters` (show "±15 m"). Show "Not recorded" for `null`.
- **Driver's dispute** → `flag.dispute` (`filed_at`, `channel`: show "Driver app").
- **Earlier flags** → `previous_flags`. **Timeline** → `history` (description, notes, actor name and role, time).
- **Actions:** Uphold/Overturn → `POST …/review` `{ outcome, notes }` on any flag whose `status` is `flagged` or `disputed`. Escalate → `POST …/escalate` `{ notes }` (now required). De-escalate → `POST …/de-escalate` `{ notes }`. Remove all sanctions → overturn. Confirm deactivation → `POST …/confirm-deactivation` on level 3 flags.

## 4. EV charging stations

- **Form:** add a map pin or address autocomplete that supplies `lat`/`lng`. `city_id` can be omitted; if the API answers 422 on `city_id`, ask the admin to pick the city. The stall count is editable; show the 422 message if a stall is in use.
- **Status:** three options, `active`, `inactive`, `maintenance` (label "Under Maintenance").
- **Details:** queue list from `queue` (already in order); stall occupants from `stalls[].current_driver`. Both use one driver shape, `{ id, name, phone, vehicle: { make, model, colour, plate_number } }`: the plate is on `vehicle`, and `vehicle` is `null` if the driver has none on file. `current_driver` is `null` on a stall nobody is charging at. Use `occupied_since` and `estimated_departure_at` on the stall for "charging for" and "leaves at".
- **Queue length:** read `station.availability.queue_length`. It is on every admin station response (list, details, create, update, status, stalls). The top-level `availability` on details is deprecated.
- **Example:** `EvStationsDemoSeeder` keeps "Yaba EV Hub" filled on staging (two drivers charging, three queued). Its details response is saved in Postman under **Admin — EV Station Management → Show Station**.
- **Live:** subscribe to `private-admin.ev-stations`; on `ev.station.availability_changed`, replace that station's `availability` (or remove it when `deleted` is true).

## 5. Passenger wallets

- **List:** `owner.name`, `owner.phone`, `owner.email`, `last_activity_at`, `balance`, `status` (`active`, `frozen`, `closed`).
- **Details:** passenger block from `wallet.owner` (`reference_number` is the passenger ID); frozen banner from `wallet.frozen`; holds from `active_holds[].ride`.
- **Transactions tab:** `GET /admin/wallets/{id}/transactions` with `page`, `per_page`, `search`, `type`, `status`, `date_from`, `date_to`. Amounts are in kobo; `direction` says credit or debit.
- **Audit trail tab:** `GET /admin/wallets/{id}/audit-trail`.
- **Freeze buttons:** show them when `permissions.can_freeze` is true; otherwise (Operations, Support) show the view-only notice. Freeze and unfreeze now return the updated `wallet`.

---

## 6. Push-backs (no API change; design or product decision needed)

| Item | Why it stays | Suggested design change |
|------|--------------|-------------------------|
| 1 Promo "Scheduled" | A promo whose start date is ahead is not usable yet; calling it "Active" would mislead ops. | Add a "Scheduled" badge and filter. |
| 5 Usage for the chosen dates | The redemption limit is a lifetime limit, so "% used" per date range has no meaning. | Label the bar "Lifetime usage". |
| 9 Take the alert | Safety incidents need a named owner (who is handling it, and how long it waited). The API's "take" is what the design calls "Acknowledged". | Add an "Acknowledge" button. Dispatch now takes the alert automatically if nobody has. |
| 10 Dispatch service type | The audit trail must record which service was called. Defaulting it would put false data in a safety record. | Add a picker: Police / Ambulance / Fire / All. |
| 11 Resolution notes | A safety incident can't be closed without a written outcome; it is the record investigators and insurers read. | Add a required notes field (10+ characters). |
| 8 Recorded call | Placing and recording calls needs a voice provider (e.g. Twilio, Africa's Talking), consent wording and an NDPR review of call recordings. That is a product decision, not a backend fix. | Use a `tel:` link and log the outcome with the new calls endpoint. Raise recording with the client if it is wanted. |
| 14 "Time the phones stayed together" | Only the driver's phone is tracked after a cancellation. Tracking the passenger's phone too would be a privacy change. | Relabel to "Time on the passenger's route after cancelling". |
| 14 Audio log | The apps don't record audio, and recording in-car audio needs explicit consent under the NDPR. | Remove the audio section, or raise it with the client. |
| 17 Remove all sanctions | Overturning already removes the flag's sanctions and stops it counting towards later sanctions. A second action that does the same would confuse the record. | Make "Remove all sanctions" call overturn (or drop the button). |
| 18 Confirm deactivation | Level 3 never deactivates a driver automatically; a person must confirm. This is deliberate, so a false positive can't end a driver's livelihood. | Add a "Confirm deactivation" action on level 3 flags. |
| 19 Maintenance status | "Under Maintenance" is a real station state; drivers can't reserve it. | Add the third status. |
| 20 Map position | Drivers find stations on a map, so the station needs coordinates. The city can now be worked out from them. | Add an address autocomplete or map pin that supplies `lat`/`lng`. |
| 24 Closed wallets | Wallets can be closed; hiding the state would show them as active. | Add a "Closed" badge. |

## 7. Changes outside the admin dashboard

- **Passenger app:** `GET /promos/available` and `POST /promos/validate` no longer return `promo.city_id` or `promo.city`. They return `city_ids` (empty = every city) and `cities`. Check whether the app reads `city_id` anywhere.
- **Driver app (optional):** send `accuracy` (metres; iOS `horizontalAccuracy`, Android `Location.getAccuracy()`) with `POST /driver/location` and on the telemetry socket. Until it does, GPS accuracy on flags shows `null`.
- **Driver app:** a driver can no longer dispute a flag an admin has already reviewed (422 "This flag has already been reviewed.").

## 8. Notes on the comparison document

- Item 11: the resolve field is `notes`, not `resolution_notes`.
- Item 15: the API now allows review of any unreviewed flag, so "only on a flag the driver disputed" no longer applies.
- Item 1: the old status filter value was `inactive`; it is now `paused` (both work).
- The design typos listed under "Design notes for the designers" are design-side only; nothing changes in the API.
