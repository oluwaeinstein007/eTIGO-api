# Ride Offers: Driver App Updates

**Date:** October 27, 2026  
**Backend:** branch `ride-offer-recovery`; live on staging once merged  
**Prepared for:** Driver app team. **Passenger app: no changes.**

On 27 October, staging sent 14 ride offers to a test driver between 3:54 and 4:07 PM, and none of them showed up in the driver app. The backend sent every one: the WebSocket event went through Reverb, Firebase accepted the push, and both of the driver's push tokens were valid. The test used one phone for both apps, so the driver app sat in the background while the passenger app booked. Android pauses a background app's WebSocket, so only the push could reach it. Opening the app from that push showed nothing, because at that time the push carried only the ride ID and the app had no way to fetch the offer.

Real drivers hit the same thing when they switch to Maps, take a call, or lock the phone. This update makes sure the app can always show an offer it hasn't answered.

Request and response details are in `API_REFERENCE.md` → **Get Pending Ride Offer (Driver)** and **Matching Engine**. Postman: **Driver → Ride Actions → Get Pending Ride Offer (Driver)**. Base URL: `/api/v1`.

---

## Summary

| Must do | Should do | Nice to have |
|---|---|---|
| 1. Fetch the pending offer on open, resume, notification tap and reconnect<br>2. Handle the `ride_request` push whether the app is open, in the background or closed<br>3. Read push booleans as `"true"` / `"false"` | 4. Show ride-request notifications on screen (heads-up)<br>5. Check the WebSocket listener name and resubscribe after a reconnect<br>6. Show each offer once, whichever route it came by | 7. Keep sending locations in the background, at most one a second |

---

## 1. Fetch the pending offer (must do)

**New:** `GET /driver/ride-offer` returns the offer the driver hasn't answered yet:

```json
{
  "offer": {
    "ride_id": "01a10e6f-45fc-724e-9a7d-83ba9df90e36",
    "pickup_address": "123 Herbert Macaulay Way, Yaba, Lagos",
    "destination_address": "456 Broad Street, Lagos Island, Lagos",
    "pickup_lat": 6.5244,
    "pickup_lng": 3.3792,
    "destination_lat": 6.4541,
    "destination_lng": 3.3947,
    "fare_estimate": 3750.00,
    "currency": "NGN",
    "vehicle_class": "Economy",
    "response_timeout_seconds": 20,
    "payment_method": "card",
    "passenger_name": "Ade Ogunleye",
    "passenger_rating": 4.9,
    "distance_km": 8.5,
    "duration_minutes": 15,
    "scheduled_pickup_at": null,
    "is_third_party": false,
    "dispatched_at": "2026-10-27T16:42:03+00:00",
    "expires_at": "2026-10-27T16:42:23+00:00",
    "seconds_remaining": 14
  }
}
```

The fields are the same as in the `ride.request.dispatched` event, plus `expires_at` and `seconds_remaining`. When there's nothing to answer, you get `{ "offer": null }`. That covers no offer at all, an offer that lapsed, one that went to another driver, and a ride the passenger cancelled.

Call it:

- when the app starts (after login, once the driver is online);
- when the app comes back to the foreground (`AppLifecycleState.resumed`);
- after a ride-request notification is tapped (see section 2);
- after the WebSocket reconnects, because events sent while it was down are not replayed.

If `offer` is not null, show the request sheet and **count down from `seconds_remaining`**. Use that rather than working the time out from `expires_at` with the phone's clock, which can be off by minutes. If it is null, close any request sheet still showing.

Accepting and declining work as before: `POST /rides/{ride_id}/accept` and `POST /rides/{ride_id}/reject`.

---

## 2. Handle the `ride_request` push in every state (must do)

Every offer comes with a push (`title: "New Ride Request"`). Its `data` now carries the whole offer:

- `type: "ride_request"`;
- every field from section 1 that isn't null, except `expires_at` and `seconds_remaining`;
- `response_timeout`, which is the same as `response_timeout_seconds`.

FCM sends every value as a **string**, so parse them: `double.parse(data['pickup_lat'])`, `int.parse(data['response_timeout_seconds'])`, `data['is_third_party'] == 'true'`.

With `firebase_messaging`, an offer can arrive in three ways, and the app has to handle all of them:

| App state | Firebase API | What to do |
|---|---|---|
| Open, in the foreground | `FirebaseMessaging.onMessage` | Android **does not** show the notification for you. Show the request sheet straight away (or a local notification if the driver is on another screen). |
| In the background, notification tapped | `FirebaseMessaging.onMessageOpenedApp` | Open the request sheet. |
| Closed, notification tapped | `FirebaseMessaging.instance.getInitialMessage()` at startup | Open the request sheet. |

In the two "tapped" cases, show the sheet from the push data straight away, then call `GET /driver/ride-offer`:

- **an offer with the same `ride_id`:** reset the countdown to its `seconds_remaining`;
- **`null`:** close the sheet and say "This request has expired";
- **a different `ride_id`:** show that offer instead.

Until the endpoint replies, you can estimate the time left as `dispatched_at` + `response_timeout_seconds` − now.

---

## 3. Push booleans are now `"true"` / `"false"` (must do)

Until now, FCM delivered booleans as `"1"` for true and `""` (empty) for false, even though the docs show `true` / `false`. They now arrive as `"true"` and `"false"`. This affects:

| Push `type` | Field |
|---|---|
| `ride_request` | `is_third_party` |
| `compliance_warning` | `reinstated`, `pending_deactivation`, `deactivated` |

Check `data['field'] == 'true'`. Code written for the old values breaks: `== '1'` now reads every value as false, and "not empty" reads every value as true, because `"false"` isn't empty. The in-app notification list (`GET /notifications`) isn't affected; it already returns real JSON booleans.

---

## 4. Show ride requests on screen (should do)

The backend sends every Android push on the channel **`etigo_updates`**. If the app never creates that channel, Android puts pushes in a default channel that may only appear in the tray, which is easy to miss with 20 seconds to respond. At startup, create the channel with `flutter_local_notifications`:

```dart
const channel = AndroidNotificationChannel(
  'etigo_updates',
  'Ride updates',
  importance: Importance.max, // heads-up banner + sound
);
await flutterLocalNotificationsPlugin
    .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
    ?.createNotificationChannel(channel);
```

Tell us if you want a separate ringing channel just for ride requests, like Uber's and Bolt's full-screen alert. The backend would then send offers on that channel instead.

---

## 5. WebSocket listener (should do)

The event is broadcast on the private channel `private-driver.{userId}` (Echo: `private('driver.{userId}')`) under the custom name **`ride.request.dispatched`**. With Laravel Echo, a custom event name needs a **leading dot**, or Echo listens for `App\Events\ride.request.dispatched` and nothing ever fires, with no error:

```dart
echo.private('driver.$userId').listen('.ride.request.dispatched', (e) { ... });
```

With a raw Pusher client, bind to `ride.request.dispatched` with no dot. To check it, log every event the socket receives while a passenger books. The payload has gained `dispatched_at` (see section 1).

When the socket reconnects (network change, app resumed), subscribe to the channel again and call `GET /driver/ride-offer` (section 1).

---

## 6. Show each offer once (should do)

The same offer can now arrive by the event, the push and the endpoint, so key it on `ride_id`:

- If a sheet for that `ride_id` is already open, update its countdown; don't open a second one.
- An offer for a new `ride_id` replaces the old one. The backend never offers a driver two rides at once, so a new offer means the old one is gone.
- Close the sheet when the countdown reaches 0, when the driver declines, or when accept fails:
  - `422` "no longer available": the passenger cancelled or the offer moved on;
  - `403` "not dispatched to you": the offer lapsed and went to another driver.

---

## 7. Location in the background (nice to have, but it affects matching)

Matching only finds drivers whose location is less than 2 minutes old.

- **Background updates:** the app stops sending locations when it goes into the background, so a driver who switches apps for a few minutes drops out of matching. Keep sending from an Android foreground service (and with the "Always" location permission on iOS).
- **Bursts:** on 27 October the app often sent up to 10 updates within a second, and the server rejected all but one (`429`; it takes **one update per second** per driver). Throttle on the device: one update every 3–5 seconds while online, and drop a send if the previous one is still in flight.

---

## How to test

**With two phones** (recommended: one driver, one passenger, standing close together):

1. Driver app open, passenger books: the sheet appears at once (WebSocket).
2. Driver app in the background: a heads-up notification appears; tapping it opens the sheet with the right time left.
3. Driver app closed (swiped away): same as 2.
4. Driver app in the background, notification **not** tapped, app reopened from the launcher within 20 s: the sheet appears (from the endpoint).
5. Let it run out: the sheet closes. The backend may offer the same ride to the same driver again a few seconds later; that opens a fresh sheet.

**With one phone:** only cases 2–4 apply. The driver has 20 seconds from the moment the request is made, including the time it takes to switch apps.

On staging you can confirm what the backend sent in `storage/logs/laravel-YYYY-MM-DD.log`: `Ride request dispatched to driver`, then `Driver response timed out` when nobody answered. A push that didn't reach a device is now logged too (`Push not delivered to every device` / `Push skipped: user has no active device`).
