# Closing a City: What the Apps Should Know

**Date:** October 29, 2026
**Backend:** branch `fix/admin-cities-surge-gaps`; live on staging once merged
**Prepared for:** Driver app team and passenger app team

These are admin dashboard fixes. **No endpoint, field or status value was removed or renamed.** One admin action, closing a city, now reaches drivers directly, so the driver app should handle it. The passenger app only gets new message text, which it already shows as it arrives.

Request and response details are in `API_REFERENCE.md`. Base URL: `/api/v1`.

---

## Summary

| App | Must do | Should do | Nice to have |
|---|---|---|---|
| Driver | Nothing | 1. Handle the "Rides closed" push and being taken offline | 2. Show the new go-online reason as sent |
| Passenger | Nothing | Nothing | 3. Show the new "not running for now" text as sent |

---

## 1. Driver app: a city can close while drivers are online (should do)

**Before:** an admin could only close a city that was already paused, and nothing happened to drivers.

**Now:** Operations can close a live or paused city (move it back to `onboarding`). They can only do it when no trip is running in the city, so a driver is never on a trip when this happens. When the city closes:

- Every driver in the city who is online is set offline (`is_online: false`) and removed from matching. The app gets no response telling it this, because the driver made no request.
- Every approved driver in the city gets a push and an in-app notification:

| Field | Value |
|---|---|
| `type` | `city_status_changed` (existing type) |
| `title` | `Rides closed in {city}` |
| `body` | `E-tiGo has stopped rides here for now. You cannot go online until the city reopens; we will tell you when it does. If you were online, you have been taken offline.` |
| `data` | `{ "city_id": "…", "launch_status": "onboarding" }` |

Until now the app only received `city_status_changed` with `launch_status` `live` or `paused`. **`onboarding` is new for this push.**

What to do when it arrives:

1. Show the notification as you show the others.
2. Set the online switch to **offline** in the app. The server has already set the driver offline, so don't call `POST /driver/toggle-online` (that would try to put them online again, and be refused). Stop sending location updates, as you already do when the driver goes offline themselves.
3. If your code switches on `launch_status`, treat `onboarding` as "the city is closed: can't go online". Don't treat an unknown value as an error.

If the app misses the push, the next `POST /driver/toggle-online` is refused (see 2), so the driver can't get stuck online.

When the city relaunches, drivers get the existing `city_status_changed` push with `launch_status` `live` ("E-tiGo is live in {city}"), and can go online again.

## 2. Driver app: new go-online reason (nice to have)

`POST /driver/toggle-online` in a closed city answers `422` as it does for a city that has not launched yet. Only the text in `reasons` is different:

- City never launched (unchanged): `E-tiGo has not launched in {city} yet. You can go online from launch day.`
- City closed (new): `E-tiGo is not running in {city} for now. You can go online again when it reopens.`

If the app shows `reasons` as sent, nothing changes. If it matches on the old text to show its own copy, add the new one.

A driver moved by an admin into a closed city gets the existing `driver_city_changed` notification with this body: `You now drive in {city}. E-tiGo is not running there for now; you can go online when it reopens.`

## 3. Passenger app: new "not running" text (nice to have)

Estimates, bookings, booking changes and `GET /cities/detect` in a closed city are refused as they are for a city that has not launched. The status code and field are the same. Only the message differs:

- City never launched (unchanged): `E-tiGo is not available in {city} yet. Join the waitlist and we will tell you when we launch.`
- City closed (new): `E-tiGo is not running in {city} for now. Join the waitlist and we will tell you when we are back.`

The waitlist works the same for both. If the app shows the message as sent, nothing changes.

---

## Not affecting the apps

- **Surge rules:** the dashboard can now only save surge conditions that surge pricing reads, and the old seeded Lagos and Abuja rules were fixed. Fares are worked out on the server, so the apps need no change. Seeded Lagos rush-hour and Abuja weekend surges now apply on their days, so expect surged quotes on staging at those times.
- **Safety operators** can now pick a city and a vehicle class when editing a driver. That is admin only.
- **City readiness** in the admin city list. Admin only.
