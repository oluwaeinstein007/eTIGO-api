# Ride for Someone Else — "Who gets tracking & the PIN?"

**Date:** October 21, 2026
**Branch:** `feature/third-party-share-with`
**For:** Passenger app team
**Driver app:** not affected.

Full details: `API_REFERENCE.md` → **Create Ride** and **Third-Party Bookings**. Postman: **Passenger → Rides → Book Ride for Someone Else**.

---

## What's new

`POST /rides` takes a new optional key, **`share_with`**, for the "Who gets tracking & the PIN?" choice. It works for rides now and for scheduled rides (`scheduled_pickup_at`).

| Design option | Send | What happens |
|---------------|------|--------------|
| **Rider only** | `"share_with": "rider"` | The rider gets the PIN and the tracking link by WhatsApp (SMS if WhatsApp fails). The booker does not see the PIN or the live map. |
| **Both of us** | `"share_with": "both"` | The rider gets them by WhatsApp/SMS, and the booker sees the PIN and the live map in the app. |

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

- One choice covers both the PIN and tracking.
- Any other value returns `422` on `share_with`.
- Leaving it out uses the admin default, so older app builds keep working.
- On the booker's own ride (no `rider_phone`, or their own number), `share_with` is ignored. You can always send it.

## Which option to pre-select

`GET /config` → `third_party_bookings` now has:

```json
"share_with_options": ["rider", "both"],
"default_share_with": "both"
```

Pre-select `default_share_with`. Operations sets it, and today it's **Both of us**. The design pre-selects "Rider only", so please read it from config instead of hard-coding.

## What the response tells you

The ride keeps the choice:

- `ride.third_party.pin_recipient` and `ride.third_party.tracking_recipient`: `rider` or `both`.
- `ride.third_party.booker_sees_pin`: whether to show the PIN.

With **Rider only**:
- `pin_code` in the booking response is `null`, and `booker_sees_pin` is `false`.
- Show something like "We've sent Amina the PIN and a tracking link" instead of the PIN and map.
- **Fallback:** if WhatsApp and SMS both fail, `rider_notification_status` becomes `failed`. The booker gets a `third_party_rider_unreachable` push, and the PIN and map are shown to them anyway, so the trip can still start. Keep reading `booker_sees_pin` rather than your own copy of the choice.

---

## Design notes (please pass to the designer)

1. **Age confirmation is missing, and booking fails without it.** The API requires `rider_age_confirmed: true` ("the rider is 18 or over, or an adult is travelling with them") and returns `422` otherwise. Add a checkbox above "Confirm ride for Amina".
2. **"by SMS"**: messages go by WhatsApp first and SMS only if that fails. Suggest "PIN and tracking link sent to the rider by WhatsApp or SMS".
3. **"Riding link"** → "tracking link".
4. **Default selection**: pre-select from `default_share_with` (currently Both of us), not Rider only.
5. **Rider only consequence**: tell the booker they won't see the PIN or the live map, e.g. "Only Amina gets the PIN. You'll still see the trip status."
6. Labels: "Rider's Full name" → "Rider's full name"; "Rider's Phone number" → "Rider's phone number".
