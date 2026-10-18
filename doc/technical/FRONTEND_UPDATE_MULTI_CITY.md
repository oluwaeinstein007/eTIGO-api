# Multi-City Support: App and Dashboard Updates

**Date:** October 18, 2026
**Backend:** branch `feature/multi-city-support`; on staging once merged
**Prepared for:** Passenger app, driver app and admin dashboard teams

Cities now move through three states instead of an on/off switch: **onboarding** (drivers sign up and get approved; passengers cannot book but can join a waitlist), **live**, and **paused** (no new bookings; trips in progress finish; upcoming bookings go ahead unless operations cancels them). A city goes live for the first time only when it passes a readiness checklist. City boundaries can no longer overlap, and Operations can move a driver to another city.

Request and response details are in `API_REFERENCE.md`: **Public — Cities**, **City Detection**, **City Waitlist**, **Admin — City Management** and **Move Driver to Another City**. Saved examples are in the "E-tiGo API v1" Postman collection: **Public › Public — Cities**, **Passenger › City Waitlist**, **Admin › Admin — City Management**, and **Admin › Admin — Driver Management › Move Driver to Another City**. Base URL: `/api/v1`. The operations procedure is in `CITY_ONBOARDING.md`.

---

## Summary

| App | Must do | Should do |
|-----|---------|-----------|
| Passenger | Nothing breaks: `GET /cities` still returns only bookable cities, and `is_active` still means bookable. New notification type `city_launched` should open the home screen, not crash an unknown-type handler | "Coming soon to {city}" screen with **Notify me** (waitlist), driven by the detect endpoint's `unavailable_city` |
| Driver | Sign-up city picker: call `GET /cities?include_onboarding=1`, or drivers cannot choose a city that is recruiting before launch | Show the `reasons` and `city_notice` from **Go online**; handle `city_status_changed` and `driver_city_changed`; hide the city field after the application is submitted |
| Admin dashboard | The city on/off toggle still works, but it now pauses (not deactivates), and launching an onboarding city can fail with the readiness checklist | Launch status badge and filter, readiness checklist page, pause dialog, waitlist count, "Move to another city" on the driver page, city settings page |

---

## Passenger App

### Cities and detection
- `GET /cities` is unchanged: live cities only. Each city now also has `launch_status` and `launch_status_label`.
- `GET /cities/detect?lat&lng` still returns 404 outside a live city. Inside a city that is onboarding or paused, the 404 adds:
  ```json
  "unavailable_city": { "id": "uuid", "name": "Ibadan", "launch_status": "onboarding", "waitlist_open": true }
  ```
  and `message` is passenger-ready ("E-tiGo is not available in Ibadan yet. Join the waitlist and we will tell you when we launch." or "Rides in Lagos are paused for now. Please try again later."). Show that message, and a **Notify me** button when `waitlist_open` is true.

### Waitlist
- `GET /cities/{id}/waitlist` → `on_waitlist`. `POST` joins (201, or 200 if already on it), `DELETE` leaves.
- When the city goes live, the passenger gets one `city_launched` notification (data: `city_id`): "E-tiGo is now in Ibadan". Open the home screen and refresh the city.

### Booking errors
- Estimates and bookings in a city that is not live return 422 on `city_id` with the same passenger-ready message as above. Show it as is.
- A booking cancelled because operations paused the city arrives as the usual `ride_cancelled` notification. The ride has `cancellation_reason: city_paused` and `cancellation_details` with the text to show. `city_paused` is a system reason: never offer it in the cancel-reason picker (it is not in `GET /lookup/cancellation-reasons`).

---

## Driver App

### Sign-up and onboarding
- **Must do:** the sign-up city picker should call `GET /cities?include_onboarding=1`. Cities in onboarding (recruiting drivers before launch) are only in that list. Show `launch_status_label` ("Onboarding" → e.g. "Launching soon") next to them.
- `POST /auth/register/complete` (as a driver) and `PUT /driver/profile` refuse a paused city: 422 on `city_id`, "The selected city is not open for driver sign-ups." `GET /cities/{id}/vehicle-classes` works for onboarding cities, so the preferred-class picker keeps working before launch.
- A driver can change their city only while their status is `onboarding` or `rejected`. After they submit, `PUT /driver/profile` with a different `city_id` returns 422 "Your city can only be changed before you submit your application. Contact support to move city." Hide or lock the city field once the application is submitted.

### Going online
- A driver whose city is still onboarding cannot go online. `POST /driver/toggle-online` returns 422 with the reason "E-tiGo has not launched in {city} yet. You can go online from launch day." in `reasons`. Show the reasons list as you do today.
- In a paused city the driver can go online, and the response has `city_notice`: "Rides in {city} are paused. You will only get rides passengers booked before the pause." Show it as a banner. It is `null` otherwise.

### Notifications
| Type | When | Do |
|------|------|----|
| `city_status_changed` | The driver's city went live ("go online to start getting trips") or was paused (data: `city_id`, `launch_status`) | Show it; refresh the driver profile |
| `driver_city_changed` | Operations moved the driver to another city (data: `city_id`); they were taken offline if they were online | Refresh the driver profile and the home screen |

---

## Admin Dashboard

### City list and detail
- Each city has `launch_status` (`onboarding`, `live`, `paused`), `launch_status_label`, `launch_date`, `launched_at`, `paused_at`, `pause_reason`, `pause_scheduled_rides` and `waitlist_count`. Replace the active/inactive badge with the launch status, and add a `launch_status` filter (comma list) to `GET /admin/cities`. `is_active` still works and means live.
- `GET /admin/cities/{id}` adds `readiness`: `{ ready, missing, counts }`.
- Create and edit forms: add **Target launch date** (`launch_date`). A boundary that overlaps another city returns 422 on `boundary` with the city's name; show it on the map field. Edits that send the unchanged boundary back are not checked. Time zone and currency are fixed to Africa/Lagos and NGN, so hide or disable those fields.

### Readiness checklist page
- `GET /admin/cities/{id}/readiness` → `gates[]`, each with `label`, `kind` (`computed` or `manual`), `required`, `status` (`passed`, `failed`, `not_applicable`) and a `detail` sentence. Show a tick, cross or dash per gate with the detail.
- Manual gates (`support_cover`, `permits`): **Confirm** / **Clear** buttons → `POST /admin/cities/{id}/readiness/{gate}`. `permits` needs `value` (`obtained` or `not_required`); both take an optional `note`. Show who confirmed and when from `confirmation`.
- **Launch city** button, enabled when `ready` is true → `POST /admin/cities/{id}/launch-status` `{ "status": "live" }`. A 422 returns the checklist.

### Pause and resume
- **Pause** dialog → `POST /admin/cities/{id}/launch-status` `{ "status": "paused", "reason", "scheduled_rides": "honour" | "cancel" }`. Before confirming, show `counts.active_rides` (they finish normally) and `counts.upcoming_scheduled_rides` (honoured or cancelled) from the readiness report.
- **Resume** → `{ "status": "live" }` (no checklist). **Close (back to onboarding)** → `{ "status": "onboarding" }` from paused; it cancels upcoming bookings.
- The old toggle (`PATCH /admin/cities/{id}/status`) still works: it pauses a live city with the default booking choice, and launches or resumes the others. Its 422 now carries `readiness`.

### Drivers
- On the driver page, **Move to another city** → `PUT /admin/drivers/{id}/city` `{ "city_id", "reason" }`. 409 when the driver is on a trip, or has reserved bookings (`upcoming_reservations`; release them first). Show `warnings` (class not offered in the new city, city not launched yet).

### Settings
- City settings page → `GET/PUT /admin/settings/cities`: `launch_min_approved_drivers` (30), `require_ev_charging` (true), `pause_scheduled_rides` (`honour` or `cancel`).

### Audit log
New events: `city_launched`, `city_resumed`, `city_paused`, `city_returned_to_onboarding`, `city_readiness_confirmed`, `city_readiness_cleared`, `city_settings.updated`, `driver_city_changed`. Toggling a city used to log `city_activated` / `city_deactivated`; it now logs these.
