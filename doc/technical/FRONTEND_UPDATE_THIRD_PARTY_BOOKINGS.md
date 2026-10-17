# Third-Party Bookings: App and Dashboard Updates

**Date:** October 18, 2026  
**Backend:** branch `feature/third-party-bookings`; on staging once merged  
**Prepared for:** Passenger app, driver app and admin dashboard teams

A passenger can now book and pay for a ride that someone else takes, such as a parent, a guest or a colleague. The person who books is the **booker** and stays the ride's passenger. The person who travels is the **rider**. The rider needs no app: they get the driver's details, the PIN and a live tracking link by WhatsApp, or by SMS if WhatsApp fails.

Request and response details are in `API_REFERENCE.md` → **Third-Party Bookings**. Every endpoint has a saved example in the "E-tiGo API v1" Postman collection: **Passenger › Rides** (Book Ride for Someone Else, Book Scheduled Ride for Someone Else) and **Admin › Admin — Third-Party Bookings**. Base URL: `/api/v1`.

The defaults follow the OQ-18 recommendation. The client has not confirmed it yet. Every choice is a setting, so read the rules from the API (`GET /config`, the ride's `third_party` object) instead of hard-coding them.

---

## Summary

| App | Must do | Should do |
|-----|---------|-----------|
| Passenger | Nothing breaks for passengers who don't use the feature. `GET /passenger/active-ride` now also returns `third_party_rides` (an array) | "Book for someone else" in booking; the booker's view of the rider's trip (PIN, map, delivery status); the "rider not reached" alert |
| Driver | On a third-party ride, `passenger` has only `id` and `first_name`. **Don't require its other fields.** Call and greet the person in `rider` instead | "Booked for Ada" badge on offers and on the trip screen |
| Admin dashboard | — | Third-party settings page; show the rider and the delivery status on ride detail |

---

## Passenger App

### Booking
- `GET /config` → `third_party_bookings`: `enabled`, `payment_methods` (by default `["wallet", "card"]`), `pin_recipient`, `tracking_recipient`, `max_active_per_booker`. Hide **Book for someone else** when `enabled` is false. For such a booking, offer only the methods in `payment_methods`.
- Book with `POST /rides` (now, or with `scheduled_pickup_at`) and add:
  - `rider_name`: the rider's name, up to 100 characters. Let the booker pick from contacts.
  - `rider_phone`: E.164 (`+2348020000002`). A Nigerian local number (`0802 000 0002`) is also accepted.
  - `rider_age_confirmed: true`: a checkbox reading "The rider is 18 or over, or an adult is travelling with them". It is required.
- If `rider_phone` is the booker's own number, the booking is a normal ride.
- The response has `ride.is_third_party: true` and a `ride.third_party` object (below). `pin_code` is `null` when the PIN goes to the rider only.
- A third-party ride does **not** block the booker's own ride, and the other way round.
- `422` errors arrive with a field and a ready-to-show message. Display them as they are:

| Field | When |
|-------|------|
| `payment_method` | Cash or bank transfer while they are not allowed |
| `rider_age_confirmed` | The box was not ticked |
| `rider_name` | The name is missing |
| `rider_phone` | Bad number; feature off; 2 third-party rides already under way; 10 different numbers in 24 hours |

### The `third_party` object on a ride (booker only)
| Field | Use |
|-------|-----|
| `rider_name`, `rider_phone` | "Ride for Ada Okafor" |
| `pin_recipient`, `tracking_recipient` | `booker`, `rider` or `both`. Saved at booking. Tells you what the booker gets |
| `booker_sees_pin`, `booker_sees_tracking` | Show the PIN or the live map only when these are true. They turn true by themselves if the rider can't be reached |
| `rider_notification_status` | `pending` → `sent_whatsapp` / `sent_sms`, or `failed`. (`delivered` is reserved; not sent yet.) `null` when the policy sends the rider nothing. Show e.g. "Sent to Ada on WhatsApp" |
| `rider_notification_channel`, `rider_notified_at` | Channel and time of the last message that got through |

When the booker isn't allowed the PIN or tracking, `pin_code` and `share_token` are `null`. `GET /rides/{ride}/location` then returns `403`, and the booker can't join the `private-ride.{id}` channel. Fall back to polling `GET /rides/{ride}` for the status.

### Following the rider's trip
- `GET /passenger/active-ride`: `ride` is the booker's **own** ride. Rides they booked for others that are under way are in `third_party_rides` (full ride objects). Show both, for example as cards on the home screen.
- `GET /rides?is_third_party=1` lists only rides booked for others.
- Real time: subscribe to `private-user.{booker_id}` and listen for `.ride.rider_notification.updated` with `{ ride_id, rider_notification_status, rider_notification_channel, rider_notified_at, booker_sees_pin, booker_sees_tracking }`. When `booker_sees_pin` turns true, fetch `GET /rides/{ride}` to get the PIN. The PIN is never broadcast.
- Push `third_party_rider_unreachable` ("We couldn't reach Ada"): open the ride and show the PIN prominently with "Share this PIN with Ada".
- The usual ride pushes mention the rider: "Your driver Bayo is on the way to pick up Ada", "The driver is waiting for Ada…", "Ada's trip has started".
- **Regenerate PIN** (`POST /rides/{ride}/pin/regenerate`) works as before. On a third-party ride the new PIN is also sent to the rider. The message says so ("…sent to Ada"), and the response has `rider_notification_status`.
- Only the booker can cancel, rate, tip or dispute. The rider's message tells them to contact the booker for changes.

### Scheduled rides for someone else
These work as normal scheduled rides. The rider also gets a reminder before pickup (no PIN), then the driver's details and the PIN at dispatch. A booking less than an hour away is sent to the rider straight away, and so is a later change of time or places. Two bookings at the same time are fine when they are for different people.

---

## Driver App

- Ride objects now have a **`rider`** object: `{ first_name, phone, is_third_party }`. It is the person you pick up: the rider on a third-party ride, otherwise the passenger. **Use it for the name on the trip screen and for the call button.** `phone` becomes `null` once the trip ends.
- On a third-party ride, `passenger` (the booker) has **only `id` and `first_name`**. Make every other `passenger` field optional in your models.
- Ride offers (`ride.request.dispatched`) have `is_third_party`. When it is true, `passenger_name` is the rider's first name. Show "Booked for Ada".
- The PIN flow doesn't change: the rider reads the PIN out to you.

---

## Admin Dashboard

- **Settings page** (operations role): `GET /admin/settings/third-party`, `PUT /admin/settings/third-party` with any of the fields below. Changes apply to new bookings only. Show that note on the page.

| Field | Default |
|-------|---------|
| `enabled` | true |
| `pin_recipient` | `both` (`booker`, `rider` or `both`) |
| `tracking_recipient` | `both` (`booker`, `rider` or `both`) |
| `allow_cash` | false |
| `allow_bank_transfer` | false |
| `max_active_per_booker` | 2 (1–10) |
| `max_rider_numbers_per_day` | 10 (1–100) |
| `rider_trip_updates` | true: also message the rider when the driver arrives or the ride is cancelled |

- **Ride detail and monitoring:** rides have `is_third_party` and, for admins, the `third_party` object. Show "Booked by Tunde for Ada (+234…)" and the delivery status, so support can help when the rider says they got nothing.
- **SOS console:** an incident on a third-party ride has `telemetry_data.third_party_rider` (`name`, `phone`) and `triggered_by_booker`. When the booker raised it, the incident position is the **car's** live position. The booker's own position is in `telemetry_data.reporter_gps`. Show the rider's number to the safety operator.

---

## Rider Tracking Page (web)

The link in the rider's message opens `GET /track/{ride}/{token}` on the API host. It is a small page that needs no login. It shows the status, the driver's photo, the car and plate, the ETA, a map of the car, a **Call 112** button and the E-tiGo safety line. It reads the public `GET /rides/{ride}/share/{token}`, which now also returns `driver.vehicle`, `driver.profile_photo_url`, `driver_location`, `eta` and `safety`. If the web team builds its own page, set `THIRD_PARTY_TRACKING_URL` (with `{ride}` and `{token}` placeholders) and use the same endpoint. Links expire 1 hour after the trip.

> **Short version for chat:** "Third-party bookings are on staging. Passenger: `POST /rides` + `rider_name`, `rider_phone`, `rider_age_confirmed`; wallet/card only (see `GET /config` → `third_party_bookings`); read `ride.third_party` for the PIN/tracking rules and the delivery status; active-ride now has `third_party_rides`. Driver: use the new `ride.rider` (name, phone) for the person you pick up. On third-party rides `passenger` has only `id` and `first_name`, so make its other fields optional. Admin: `GET/PUT /admin/settings/third-party`. Details: FRONTEND_UPDATE_THIRD_PARTY_BOOKINGS.md."
