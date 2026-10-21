# Ride for Someone Else — "Who gets tracking & the PIN?"

**Date:** October 21, 2026
**Branch:** `feature/third-party-share-with`
**For:** Passenger app team
**Driver app:** not affected.

Full details: `API_REFERENCE.md` → **Create Ride** and **Third-Party Bookings**. Postman: **Passenger → Rides → Book Ride for Someone Else**.

---

## What's new

`POST /rides` takes a new optional key, **`share_with`**, for the "Who gets tracking & the PIN?" choice. It works for rides now and for scheduled rides (`scheduled_pickup_at`).

| Design option | Send | Rider gets (WhatsApp, SMS fallback) | Booker gets in the app |
|---------------|------|--------------------------------------|------------------------|
| **Rider only** | `"share_with": "rider"` | PIN + tracking link | Live tracking, **no PIN** |
| **Both of us** | `"share_with": "both"` | PIN + tracking link | Live tracking + PIN |

**The booker always keeps live tracking.** This is how Uber and Bolt work: the booker paid for the trip, handles support, lost items and disputes for it, and their in-app SOS covers the rider. The choice only decides who holds the PIN.

```json
{
  "city_id": "…",
  "vehicle_class_id": "…",
  "pickup_lat": 6.5244,
  "pickup_lng": 3.3792,
  "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
  "destination_lat": 6.4541,
  "destination_lng": 3.3947,
  "destination_address": "456 Broad Street, Lagos Island, Lagos",
  "payment_method": "card",
  "rider_name": "Amina Okafor",
  "rider_phone": "+2348035550142",
  "rider_age_confirmed": true,
  "share_with": "rider"
}
```

- Any other value returns `422` on `share_with`.
- Leaving it out uses the admin default, so older app builds keep working.
- On the booker's own ride (no `rider_phone`, or their own number), `share_with` is ignored. You can always send it.

## Showing the question

`GET /config` → `third_party_bookings` now has:

```json
"share_with_options": ["rider", "both"],
"default_share_with": "both"
```

- Pre-select `default_share_with`. Operations sets it, and today it's **Both of us**. The design pre-selects "Rider only", so please read it from config instead of hard-coding.
- If `share_with_options` is `[]`, **hide the question** (`default_share_with` is then `null`). This means operations has set PINs to go to bookers only, and anything you send is ignored.

## What the response tells you

The ride keeps what applied:

- `ride.third_party.pin_recipient`: `rider` or `both`.
- `ride.third_party.tracking_recipient`: `both`.
- `ride.third_party.booker_sees_pin` and `booker_sees_tracking`: whether to show each one. Drive the UI from these, not your own copy of the choice.

With **Rider only**:
- `pin_code` in the booking response is `null`, and `booker_sees_pin` is `false`.
- Show "We've sent Amina the PIN" where the PIN would be. The map still shows.
- **Fallback:** if WhatsApp and SMS both fail, `rider_notification_status` becomes `failed` and the booker gets a `third_party_rider_unreachable` push. `booker_sees_pin` turns `true` so the booker can pass the PIN on.

---

## Design notes (please pass to the designer)

1. **Age confirmation is missing, and booking fails without it.** The API requires `rider_age_confirmed: true` ("the rider is 18 or over, or an adult is travelling with them") and returns `422` otherwise. Add a checkbox above "Confirm ride for Amina".
2. **"Rider only" copy**: the booker still follows the trip. Suggest:
   - Rider only: "Amina gets the PIN and a tracking link by WhatsApp or SMS. You can still follow the trip."
   - Both of us: "You and Amina both get the PIN and live tracking."
3. **"by SMS"**: messages go by WhatsApp first and SMS only if that fails.
4. **"Riding link"** → "tracking link".
5. **Default selection**: pre-select from `default_share_with` (currently Both of us), not Rider only.
6. Labels: "Rider's Full name" → "Rider's full name"; "Rider's Phone number" → "Rider's phone number".
