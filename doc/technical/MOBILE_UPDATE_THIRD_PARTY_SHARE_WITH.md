# Ride for Someone Else — "Who gets the PIN?"

**Date:** October 21, 2026
**Branch:** `feature/third-party-share-with` (PR #120)
**For:** Passenger app team
**Driver app:** not affected.

Full details: `API_REFERENCE.md` → **Create Ride**, **App Configuration** and **Third-Party Bookings**. Postman: **Passenger → Rides → Book Ride for Someone Else** and **Public → App Configuration**.

---

## What's new

`POST /rides` takes a new optional key, **`share_with`**. It works for rides now and for scheduled rides (`scheduled_pickup_at`).

| Option | Send | PIN goes to |
|--------|------|-------------|
| **Rider only** | `"share_with": "rider"` | The rider only (WhatsApp, SMS fallback) |
| **Both of us** | `"share_with": "both"` | The rider and the booker |

**The choice is about the PIN only.** Live tracking is an operations setting, and by default both people get it: the rider gets a tracking link and **the booker keeps following the trip in the app**. That's how Uber and Bolt work, because the booker paid, handles support for the trip, and their in-app SOS covers the rider.

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
- Leaving it out uses the operations default, so older app builds keep working.
- On the booker's own ride (no `rider_phone`, or their own number), `share_with` is ignored. You can always send it.

---

## Building the question from config

`GET /config` → `third_party_bookings`:

```json
"share_with_options": [
  {
    "value": "rider",
    "label": "Rider only",
    "description": "The rider gets the PIN and a tracking link by WhatsApp or SMS. You can still follow the trip in the app."
  },
  {
    "value": "both",
    "label": "Both of us",
    "description": "You and the rider both get the PIN and live tracking."
  }
],
"default_share_with": "both"
```

- **Show one radio per option**, using its `label` and `description` as given, and send its `value`. The server writes the descriptions from the current tracking setting, so they stay accurate if operations change it. For example, if operations stop showing the booker the map, "Rider only" reads "…You won't see the trip's live location." Don't hard-code the copy. You may swap "The rider" for the rider's first name.
- **Pre-select `default_share_with`** (currently Both of us). The design pre-selects "Rider only"; please read it from config instead.
- **If `share_with_options` is `[]`, hide the question.** Operations has set PINs to go to bookers only, and anything you send is ignored.
- Suggested heading: **"Who gets the PIN?"**. Tracking isn't part of the choice, so "Who gets tracking & the PIN?" would mislead.

---

## After booking

The ride keeps what applied:

- `ride.third_party.pin_recipient`: `rider` or `both`.
- `ride.third_party.tracking_recipient`: normally `both`.
- `ride.third_party.booker_sees_pin` and `booker_sees_tracking`: whether to show the PIN and the map. **Drive the UI from these**, not from your own copy of the choice.

With **Rider only**, `pin_code` in the booking response is `null` and `booker_sees_pin` is `false`. Messages to the rider go out **when a driver is assigned**, not at booking. A scheduled ride also gets a reminder without the PIN. So word the PIN area from `ride.third_party.rider_notification_status`:

| `rider_notification_status` | Suggested copy where the PIN would be |
|-----------------------------|---------------------------------------|
| `pending` | "Amina will get the PIN by WhatsApp or SMS when a driver is on the way." |
| `sent_whatsapp` / `delivered` | "PIN sent to Amina on WhatsApp." |
| `sent_sms` | "PIN sent to Amina by SMS." |
| `failed` | Show the PIN (`booker_sees_pin` is now `true`): "We couldn't reach Amina. Please share this PIN with them." The booker also gets a `third_party_rider_unreachable` push. |

Status changes arrive live as `ride.rider_notification.updated` on the booker's private `user.{id}` channel, and on `GET /rides/{id}`.

---

## Design notes (please pass to the designer)

1. **Age confirmation is missing, and booking fails without it.** The API requires `rider_age_confirmed: true` ("the rider is 18 or over, or an adult is travelling with them") and returns `422` otherwise. Add a checkbox above "Confirm ride for Amina".
2. **Heading**: "Who gets tracking & the PIN?" → "Who gets the PIN?".
3. **Option descriptions**: use the server's `description`. Today "Rider only" reads: "The rider gets the PIN and a tracking link by WhatsApp or SMS. You can still follow the trip in the app." The design's "Riding link and PIN sent to the rider by SMS" suggests the booker loses the trip, and that it's SMS only.
4. **Default selection**: pre-select `default_share_with` (currently Both of us), not Rider only.
5. **Don't say "sent" at booking.** The PIN goes out when a driver is assigned; use the table above.
6. Labels: "Rider's Full name" → "Rider's full name"; "Rider's Phone number" → "Rider's phone number".
