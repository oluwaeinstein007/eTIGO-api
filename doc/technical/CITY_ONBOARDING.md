# Opening a New City: Operations Runbook

**Date:** October 18, 2026
**Applies to:** Admin dashboard (Operations and Super Admin roles), API `/api/v1`
**Background:** OQ-22 in `doc/client/PHASE2_DECISIONS.md`. That recommendation (Lagos → Abuja → Port Harcourt → Ibadan, each gated by a readiness checklist) is not yet confirmed by the client. **No city goes live without a launch date from the client.**

This runbook takes a city from "not in the system" to "live", and covers pausing, resuming and moving drivers. Every step is an admin endpoint. Request details are in `API_REFERENCE.md` → **Admin — City Management**, and saved examples are in the Postman collection under **Admin › Admin — City Management**.

---

## How a city launch works

| Status | Drivers | Passengers |
|--------|---------|------------|
| **Onboarding** | Sign up, do KYC, get approved. Cannot go online yet | Cannot book. Can join the waitlist |
| **Live** | Normal | Normal |
| **Paused** | Can go online for rides booked before the pause. No new sign-ups | Cannot book. Trips in progress finish. Upcoming bookings go ahead unless you cancel them |

A new city starts in **onboarding**. That gives you a few weeks to recruit and approve drivers before passengers can book, so the first passengers find cars. A city goes live once it passes the readiness checklist.

---

## Step 1: Create the city

`POST /admin/cities`

- `name`, `state` (the region is filled in from the state).
- `boundary`: draw the service area as a polygon (preferred) or a centre point with `radius_km`.
  - **Boundaries cannot overlap another city's boundary.** The API refuses the save and names the city it overlaps. Two cities may share a border; draw the shared edge with the same points.
  - **Do not draw a district (Ikeja, Lekki, Oshodi) as its own city inside a city.** Bookings are placed in the city whose boundary holds the pickup, so a district city takes that area's bookings, drivers and prices away from the main city. Boundaries saved before overlaps were refused can still overlap: the admin city list flags them (`overlapping_cities`) and the city page shows a warning naming the other city. Fix one by redrawing a boundary so the two only share a border. If a city should stop taking bookings, pause it: clearing its boundary alone does not stop bookings, since a live city with no boundary can still be found by its geocoded name. A paused city's boundary still claims the pickups inside it, so redraw or clear that too.
  - Draw the commuter belt into the boundary where passengers live just outside the city limits (for example, include Mowe, Arepo and Ibafo in Lagos). A trip that starts outside every boundary cannot be booked.
- `launch_date`: the client's target date (`YYYY-MM-DD`). Leave it empty until the client gives one. The checklist will not pass without it.
- `timezone` and `currency_code` default to `Africa/Lagos` and `NGN`, and only those are accepted. Payout days, promo peak hours and fleet business days follow Lagos time, so a city outside Nigeria needs engineering work first.
- `vehicle_class_ids`: the classes to offer (you can change them later in Step 2).

The city is now **onboarding**. Drivers see it in the sign-up city list (`GET /cities?include_onboarding=1`). Passengers who open the app there are told "E-tiGo is not available in {city} yet" and can join the waitlist.

## Step 2: Vehicle classes and prices

1. `PUT /admin/cities/{city}/vehicle-classes`: turn on the classes the city will offer.
2. `POST /admin/pricing`: add pricing for **every** class you turned on (base fare, per km, per minute, minimum, waiting rate). The city's `warnings` list any class without pricing.
3. Review surge rules for the city (`/admin/surge-rules`). New cities start with none.
4. If any approved drivers in the city have electric or plug-in hybrid vehicles, add at least one active charging station (`/admin/ev-stations`).

## Step 3: Recruit and approve drivers

- Drivers choose the city when they sign up and during onboarding, then go through the usual KYC and review.
- A driver can change their own city only before they submit their application. After that, Operations moves them (Step 7).
- The checklist needs **at least 30 approved, KYC-verified drivers** in the city. Change the number in `PUT /admin/settings/cities` (`launch_min_approved_drivers`) if the client agrees a different minimum.

## Step 4: Confirm the manual gates

`POST /admin/cities/{city}/readiness/{gate}`

| Gate | When to confirm | Body |
|------|-----------------|------|
| `support_cover` | Support and Safety staff cover the city's operating hours (rota agreed, escalation contacts known) | `{ "note": "Rota agreed with the Abuja support lead" }` |
| `permits` | Legal has confirmed the state's transport permits or registrations, or that none are needed | `{ "value": "obtained", "note": "Permit ref …" }` or `{ "value": "not_required" }` |

The API records who confirmed each gate and when. To withdraw a confirmation, send `{ "confirmed": false }`.

## Step 5: Check readiness

`GET /admin/cities/{city}/readiness`

| Gate | Passes when | Required |
|------|-------------|----------|
| Boundary | Drawn, and overlaps no other city | Yes |
| Pricing | At least one class offered, and every offered class has pricing in effect | Yes |
| Drivers | Approved, KYC-verified drivers ≥ the minimum (30) | Yes |
| EV charging | At least one active station, when approved drivers have plug-in vehicles; otherwise not applicable | Yes |
| Support cover | Confirmed in Step 4 | Yes |
| Permits | Confirmed in Step 4 | Yes |
| Launch date | The client's target date is set on the city | Yes |
| Launch promos | Active promo codes limited to the city | No (optional) |

`ready: true` means every required gate passes. `missing` lists the gates still failing. `counts.waitlist` shows how many passengers are waiting to hear about the launch.

Optional: create launch promo codes limited to the city (`/admin/promos` with `city_ids`).

## Step 6: Go live

`POST /admin/cities/{city}/launch-status` with `{ "status": "live" }`

- The API refuses if a required gate is failing, and returns the checklist.
- On success, passengers can book, drivers can go online, every passenger on the waitlist gets a "E-tiGo is now in {city}" notification, and approved drivers in the city are told to go online.

---

## Pausing a city

`POST /admin/cities/{city}/launch-status`

```json
{ "status": "paused", "reason": "Flooding on the mainland", "scheduled_rides": "honour" }
```

- `reason` is required and is shown to admins.
- Trips in progress always finish normally.
- `scheduled_rides`:
  - `honour` (default): bookings already made still go ahead, and drivers can stay online for them.
  - `cancel`: every upcoming booking is cancelled, and each passenger (and any driver who reserved it) is told why. No cancellation fee is charged.
  - The default when you leave it out is the `pause_scheduled_rides` setting.
- The response shows `active_rides` (trips still finishing) and `scheduled_rides_to_cancel`. To see these numbers before you pause, check `counts` in the readiness report.
- Drivers in the city are told rides are paused.

**Resume:** `{ "status": "live" }`. A resume does not re-run the checklist, because the city has already launched. Check the readiness report before resuming after a long pause; drivers may have left.

**Close completely (back to onboarding):** `{ "status": "onboarding", "reason": "…" }` from live or paused. Use this when a city will not reopen soon; passengers can then join the waitlist again.

- `reason` is required.
- It is refused while any trip is running or any upcoming booking is open. Pause first with `scheduled_rides: cancel`, let the trips finish, then close. `counts.active_rides` and `counts.upcoming_scheduled_rides` in the readiness report show what is left.
- Closing clears the Support cover and Permits confirmations and the launch date. Before relaunching, confirm both checks again and enter the client's new launch date.
- Drivers still online in the city are taken offline, and every approved driver there is told rides are closed. They cannot go online until the city relaunches.

The old on/off switch (`PATCH /admin/cities/{city}/status`) still works: it pauses a live city (following the `pause_scheduled_rides` setting) and launches or resumes the others.

## Step 7: Moving a driver to another city

`PUT /admin/drivers/{driver}/city` with `{ "city_id": "…", "reason": "Relocated" }`

- Refused while the driver is on a trip, holds reserved scheduled bookings (release those first in **Scheduled Rides**), or has upcoming shifts on a shared fleet car (cancel or reassign them first in **Driver Shifts**: the car stays in its city).
- An online driver is taken offline, because their location is in the old city. They are told about the move.
- The driver's upcoming availability plans for the old city are removed; they plan again for the new city.
- Warnings tell you when the new city does not offer the driver's vehicle class (they would get no trips), has not launched yet, or how many plans were removed.

Once a city's boundary is drawn, passengers cannot book a pickup inside it under another city's name: estimates and bookings are refused with a message pointing them to the right city (or saying it has not opened yet).

---

## Settings

`GET/PUT /admin/settings/cities` (Operations):

| Setting | Default | Meaning |
|---------|---------|---------|
| `launch_min_approved_drivers` | 30 | Drivers a city needs before its first launch |
| `require_ev_charging` | true | Require a charging station when the city has plug-in drivers |
| `pause_scheduled_rides` | `honour` | What a pause does to upcoming bookings when you do not choose |

Every status change, gate confirmation and settings change is in the audit log (`city_launched`, `city_paused`, `city_resumed`, `city_returned_to_onboarding`, `city_readiness_confirmed`, `city_readiness_cleared`, `city_settings.updated`, `driver_city_changed`).
