# Scheduled Rides: App and Dashboard Updates

**Date:** October 16, 2026  
**Backend:** PR #91 (`feat/scheduled-rides`); live on staging once merged  
**Prepared for:** Passenger app, driver app and admin dashboard teams

Passengers can now book a ride for later, and drivers can reserve those bookings in advance. Request and response details are in `API_REFERENCE.md` → **Scheduled Rides**. Every new endpoint has a saved example in the "E-tiGo API v1" Postman collection (folders **Passenger › Rides**, **Driver › Driver — Scheduled Rides** and **Admin › Admin — Scheduled Rides**). Base URL: `/api/v1`.

---

## Summary

| App | Must do (breaking) | Should do |
|-----|--------------------|-----------|
| Passenger | Handle the new `scheduled` ride status everywhere you read `status` | Schedule toggle in booking; upcoming bookings list with edit and cancel; show the reserved driver; show the cancellation fee before cancelling; four new notifications |
| Driver | Handle the `scheduled` status if you list rides; read `scheduled_pickup_at` on ride offers | "Scheduled rides" screen to browse and reserve bookings; "My reservations" with release; two new notifications |
| Admin dashboard | Handle the `scheduled` status in ride lists, filters and badges | Scheduled rides page (list, assign driver); scheduled ride settings page |

---

## Breaking Change: a New Ride Status `scheduled` (all apps)

Ride `status` can now be **`scheduled`** (label "Scheduled"). A scheduled ride is booked for later: no driver is looking for it yet, there is no PIN, and it is **not** an active ride.

Where it appears:
- `GET /rides` (passenger trip history and the admin/driver lists that reuse the ride object), `GET /rides/{ride}`, `POST /rides`, and `GET /rides/scheduled`
- `ride.status.updated` WebSocket events: `scheduled → searching` when matching starts, `scheduled → cancelled` when cancelled
- Admin reports: `by_status` in `GET /admin/reports/ride-volume` can include `scheduled`

**What to change:**
1. Anywhere code switches on `status` and has no default branch (Swift `switch`, Kotlin `when`, TypeScript exhaustive checks, enum decoding), add `scheduled`. An unknown value can crash decoding or fall into an error state.
2. In trip history, show a scheduled ride as **upcoming** (e.g. "Scheduled for Tue 7:30 AM", from `scheduled_pickup_at` in the device's local time), not as an active or failed trip.
3. Don't treat `scheduled` as active. `GET /passenger/active-ride` and `GET /driver/active-ride` never return a scheduled ride. Once it starts (`searching`), it becomes the active ride as usual.

Every ride object also has three new fields (all `false`/`null` for rides booked for now): `is_scheduled`, `scheduled_pickup_at` and `scheduled` (details below). They stay on the ride after it starts, so you can still show "Scheduled ride" on the trip and the receipt.

> **Short version for chat:** "Ride `status` can now be `scheduled` (a ride booked for later: no driver yet, no PIN, not active). If your code handles every ride status explicitly — enum decoding, `switch`/`when` without a default — add `scheduled`, or unknown values may crash. Show it as an upcoming trip using `scheduled_pickup_at`. Ride objects also gain `is_scheduled`, `scheduled_pickup_at` and `scheduled`. Details: FRONTEND_UPDATE_SCHEDULED_RIDES.md."

---

## Passenger App

### Booking for later
- `GET /config` → `scheduled_rides`: `enabled`, `min_lead_minutes` (30), `max_advance_days` (3), `dispatch_lead_minutes` (15), `reservation_fee`, `late_cancellation_fee`, `late_cancellation_window_minutes`, `driver_reservations_enabled`. Hide **Schedule** when `enabled` is false. Offer pickup times from `min_lead_minutes` from now up to `max_advance_days` ahead.
- Quote: `POST /rides/estimate` with `scheduled_pickup_at`. Prices then reflect the pickup time. Each estimate has `reservation_fee` (currently 0) already included in `fare_estimate`.
- Book: `POST /rides` with `scheduled_pickup_at`. **Send it in ISO 8601 with a UTC offset**, e.g. `2026-10-20T07:30:00+01:00`; without one you get 422. The response has `status: "scheduled"`, `message: "Ride scheduled successfully."` and **`pin_code: null`**: the PIN comes later.
- 422 messages on `scheduled_pickup_at` explain the limits (too soon, too far, too many bookings, clash with another booking, scheduling off). Show them as they are.

### The `scheduled` object on a ride
| Field | Use |
|-------|-----|
| `scheduled_pickup_at` | The booked time (UTC) |
| `dispatch_at` | When matching starts; editing closes then |
| `can_edit` | Show **Edit** only when true |
| `reserved_driver` | `null`, or `{ id, first_name, profile_photo_url, rating, vehicle: { make, model, colour, plate_number } }` once a driver has committed. Show "Bayo will pick you up" |
| `reservation_fee` | Part of the fare, if any |
| `cancellation_fee_if_cancelled_now` | **Show this in the cancel confirmation** when above 0 ("Cancelling now costs ₦1,500") |
| `late_cancellation_fee`, `late_cancellation_fee_status` | After a cancellation: fee charged (`pending`, `charged`, `waived`, `failed`) |
| `dispatch_failure_reason` | Why the system cancelled it (see notifications) |

### Upcoming bookings screen
- List: `GET /rides/scheduled` (soonest first).
- Edit: `PUT /rides/{ride}/scheduled` with only what changes (`scheduled_pickup_at`, a new pickup as `pickup_lat` + `pickup_lng` + `pickup_address`, a new destination likewise, or `payment_method`). The response has the new fare. Changing the time or pickup **releases a reserved driver**.
- Cancel: `DELETE /rides/{ride}/scheduled` (optional `reason`). Once the ride has started, use `POST /rides/{ride}/cancel` as for any ride.

### When it starts
At `dispatch_at` the ride moves to `searching` (or straight to `driver_en_route` if a driver reserved it). Open the normal ride screen. The PIN is in `pin_code` on `GET /passenger/active-ride` / `GET /rides/{ride}` from then on.

### New notifications (in-app and push)
| Type | When | On tap |
|------|------|--------|
| `scheduled_ride_reminder` | 60 min before pickup; warns if the wallet balance is short | Ride detail |
| `scheduled_ride_reserved` | A driver committed to the booking | Ride detail (show the driver) |
| `scheduled_ride_reservation_released` | The reserved driver dropped out; the booking is still on | Ride detail |
| `scheduled_ride_dispatched` | Matching started | Searching screen |
| `ride_cancelled` | Also used when the system had to cancel the booking; the body says why | Ride detail |

Wallet bookings: the balance must cover the fare when booking, but nothing is held until 15 minutes before pickup. If the balance is short then, the booking is cancelled, so surface the reminder's top-up warning.

---

## Driver App

### Ride offers
`ride.request.dispatched` (WebSocket) and the ride-request push now carry **`scheduled_pickup_at`** (null for rides booked for now). When set, show "Scheduled pickup 7:30 AM" on the offer.

### Reserving scheduled rides (new screens)
- **Available:** `GET /driver/scheduled-rides/available`. Bookings in the driver's city and vehicle class that nobody has reserved, soonest first. Each item has the trip, `scheduled_pickup_at`, `dispatch_at`, fare, passenger first name and rating (no phone).
- **Reserve:** `POST /driver/scheduled-rides/{ride}/reserve`. 422 `errors.ride` explains a refusal: already reserved, too close to another of their reservations (60 min), the limit of 5, wrong class or city, negative balance, or too late.
- **My reservations:** `GET /driver/scheduled-rides`. **Release:** `POST /driver/scheduled-rides/{ride}/release` (the passenger is told).
- At `dispatch_at` the ride is **assigned directly** to the reserving driver if they are online, recently located and free, and arrives as a normal assigned ride (`ride_assigned` / `driver_en_route`). If not, the reservation is dropped and the ride goes to normal matching. Tell drivers to be online by `dispatch_at`.
- In the hour before a reserved pickup the driver gets no on-demand offers, so they aren't late.
- If the passenger cancels late, the driver receives the late-cancellation fee in full (when the fee is switched on; it is ₦0 for now).

### New notifications
| Type | When |
|------|------|
| `scheduled_ride_reminder` | 60 min before a reserved pickup ("be online by …") |
| `scheduled_ride_reserved` | Operations assigned them a booking |
| `scheduled_ride_reservation_released` | Their reservation was dropped (offline at dispatch, passenger changed the pickup, or operations removed it) |
| `ride_cancelled` | The passenger cancelled a booking they had reserved |

---

## Admin Dashboard

- **Scheduled rides page:** `GET /admin/scheduled-rides` (filters `city_id`, `vehicle_class_id`, `from`, `to`, `reserved=true|false`). Each row has `scheduled.reserved_driver`.
- **Assign a driver** before dispatch: `POST /admin/scheduled-rides/{ride}/assign` `{ "driver_id": "<user id>" }`. Posting again with another driver moves it. **Remove:** `DELETE /admin/scheduled-rides/{ride}/assign`. The driver and passenger are notified.
- **Settings page** (operations): `GET`/`PUT /admin/settings/scheduled-rides`, 16 settings (see API reference). The fees are ₦0 until the client decides (see `doc/client/SCHEDULED_RIDES_DECISIONS.md`).
- **Pending rides** (`GET /admin/rides/pending`) never lists `scheduled` rides. A scheduled ride that found no driver stays on it for 24 hours from its pickup time.
- **Status filters and badges:** add `scheduled`. Scheduled rides are left out of the completion-rate report until they have an outcome.
- Cancellation reason `scheduled_dispatch_failed` (system only) can appear on cancelled rides.
