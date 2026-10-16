# E-tiGo — Scheduled Rides: Decisions Report

**Prepared by:** Engineering Team  
**Date:** 16 October 2026  
**Status:** Implemented (PR #91). All values below are defaults chosen by engineering to match industry practice. **None has been confirmed by the client yet.** Operations can change every one of them at any time, without a release, from **Admin › Settings › Scheduled rides** (`PUT /api/v1/admin/settings/scheduled-rides`).

---

## Executive Summary

Passengers can now book a ride for later. The design follows the scheduled-ride products of Uber (Uber Reserve), Bolt and Lyft. The passenger books and is quoted a fixed price. Drivers can commit to the booking in advance. Shortly before pickup the ride either goes to the committed driver or is matched like any other ride.

This report lists every product decision we had to make, what competitors do, what we chose and why. Section 4 lists the decisions we need from the client.

Competitor terms vary by country and change over time. The figures below come from the companies' public pages and launch announcements (sources at the end); treat them as typical practice, not exact current terms in Nigeria.

---

## Table of Contents

1. [Booking Rules](#1-booking-rules)
2. [Price and Payment](#2-price-and-payment)
3. [Dispatch and Matching](#3-dispatch-and-matching)
4. [Drivers Reserving Bookings in Advance](#4-drivers-reserving-bookings-in-advance)
5. [Fees](#5-fees)
6. [Decisions Needed From the Client](#6-decisions-needed-from-the-client)
7. [Settings Reference](#7-settings-reference)

---

## 1. Booking Rules

| Decision | Industry practice | E-tiGo default | Why |
|----------|-------------------|----------------|-----|
| Earliest pickup | Uber: 30 minutes to 2 hours ahead, depending on market | **30 minutes** after booking | Long enough for a driver to commit and for dispatch to start on time; short enough to cover "later today" trips |
| Latest pickup | Lyft: 7 days. Bolt: 3 days (London launch), 72 hours (South Africa), 90 days (New Zealand). Uber: 30–90 days | **7 days** ahead | The price is locked at booking, so a short horizon keeps it close to current rates. Matches Lyft and the middle of Bolt's range |
| Upcoming bookings per passenger | Not published | **5** | Stops one account from holding many slots (and many reserved drivers) speculatively |
| Gap between one passenger's bookings | Not published | **30 minutes** | Catches accidental double bookings without blocking a return trip |
| Booking while on a trip | Allowed by all three | **Allowed** | A scheduled booking is not an active ride, so it never blocks riding now, and riding now never blocks booking for later |
| Timezone | — | Pickup time must carry a UTC offset | Avoids a pickup silently shifting by an hour |
| Switch | — | Operations can turn new bookings off (`enabled`) | Existing bookings still go ahead |

## 2. Price and Payment

| Decision | Industry practice | E-tiGo default | Why |
|----------|-------------------|----------------|-----|
| When the price is fixed | Lyft locks the rate at booking, including predicted Prime Time. Bolt fixes it when a driver accepts | **At booking**, an upfront fare like every E-tiGo ride | The passenger knows the price when they commit |
| Surge on a future pickup | Lyft predicts it | **Time-based and manual surge in effect at the pickup time** apply; **demand surge never does** | Live demand says nothing about next Tuesday. Scheduled bookings are also left out of the live demand count |
| Promo codes | — | Must be valid **at the pickup time**; peak/off-peak rules follow the pickup time | The discount is for the trip, so the trip time decides |
| Editing | Uber and Lyft allow edits until shortly before pickup | Time, pickup, destination and payment method can change **until matching starts**. The fare is quoted again | Same as booking a new ride, without losing the booking |
| Wallet payment | — | Balance checked at booking, **held only at dispatch** | Holding money for up to a week would be unfriendly. A reminder warns if the balance is short |
| Ride PIN | — | Issued **at dispatch** | PINs are valid for 6 hours, so one issued days earlier would expire |

## 3. Dispatch and Matching

| Decision | Industry practice | E-tiGo default | Why |
|----------|-------------------|----------------|-----|
| When matching starts | Varies; aims for the driver to arrive on time | **15 minutes** before pickup | Typical pickup ETA in Lagos traffic, plus margin |
| How long to keep searching | — | Until **10 minutes after** the pickup time (never less than the standard 5-minute search) | A scheduled passenger has planned around the ride; giving up early is worse than a short wait |
| Search pacing | — | A new search wave every **30 seconds** | Keeps trying as drivers come online without spamming drivers |
| A driver who declined | — | Not offered the same booking again for 5 minutes | Avoids repeated pings |
| Passenger reminder | Uber and Lyft remind before pickup | **60 minutes** before pickup. Warns a wallet passenger whose balance is short | Time to top up before the hold is placed |
| If the ride cannot start | — | Cancelled with a clear reason sent to the passenger: wallet short or frozen, account suspended, ride type withdrawn, or the window missed | Better than sending a driver to a ride that cannot be paid for |
| Durability | — | Bookings live in the database and a job checks them every minute | Server restarts and deploys never lose a booking |

## 4. Drivers Reserving Bookings in Advance

Uber Reserve lets drivers see upcoming reservations and commit to them; Bolt confirms a driver ahead in some markets. We built the same.

| Decision | E-tiGo default | Why |
|----------|----------------|-----|
| Who can reserve | Approved drivers in the **same city and vehicle class**, not blocked by a negative balance | Same eligibility as receiving a ride offer |
| Until when | Until matching starts (15 minutes before pickup) | After that the normal flow takes over |
| Reservations per driver | **5** upcoming | Prevents one driver hoarding bookings |
| Gap between a driver's reservations | **60 minutes** | A driver needs time to finish one trip and reach the next pickup |
| Protecting the reserved pickup | Drivers get **no on-demand offers in the 60 minutes** before a reserved pickup | Uber does the same so a trip in between doesn't make the driver late |
| At dispatch | The ride goes **straight to the reserved driver** if they are online, free and in the right vehicle; otherwise the reservation is released and normal matching runs at once | The passenger is never left waiting for a driver who isn't there |
| Driver reminder | 60 minutes before pickup: "be online by …" | — |
| Driver gives it up | Allowed until dispatch; the passenger is told and the booking returns to the pool | No penalty for now (the cancellation proposal recommends tracking, not fining, driver cancellations) |
| Passenger changes time or pickup | The reservation is released (destination or payment changes keep it) | The driver committed to a time and place |
| Operations | Can **assign, move or remove** the driver on any booking before dispatch | Covers VIP, airport and corporate bookings |
| What the passenger sees | Driver first name, photo, rating and vehicle once reserved | As with Uber Reserve |

## 5. Fees

Both fees are built and work, but **default to ₦0 (off)**.

**Why off:** the client decided on *no cancellation fees in Phase 1* (OQ-05, see the Cancellation Fee Proposal). Competitors also differ: Uber Reserve charges a reservation fee (for example £3–£5 in the UK); we have not seen one for Lyft or Bolt scheduled rides. We will not start charging passengers without the client's decision.

| Fee | How it works when switched on | Recommended value if enabled |
|-----|-------------------------------|------------------------------|
| **Reservation fee** (`reservation_fee`) | Flat amount added to every scheduled ride's quote, shown in the estimate and on the receipt. Paid with the fare by any payment method | ₦0 to stay level with Bolt. If the client wants to monetise the feature, ₦200–₦300 (about 10% of a typical economy trip) |
| **Late-cancellation fee** (`late_cancellation_fee`) | Charged only when the passenger cancels a booking **a driver has reserved**, within the late window before pickup. Free before the window and free if no driver has reserved it (as Uber does) | **₦1,500**, roughly the economy minimum fare. Uber charges the trip's minimum fare in this case |
| Late window (`late_cancellation_window_minutes`) | — | **60 minutes**: Uber Reserve and Bolt (UK) use one hour; Lyft uses 30 minutes |

Rules for the late-cancellation fee (they follow the Cancellation Fee Proposal):

- **Who gets it:** the reserved driver, **in full**, with no commission. It pays for the slot they blocked.
- **Never charged** when the passenger cancels for an emergency, a safety concern, a vehicle mismatch or a driver no-show, or when operations or the system cancel.
- **Collected** from the wallet or the saved card. Cash and bank-transfer bookings **cannot be charged** after the fact, so the fee is waived for them. The proposal suggests a "balance due" for cash users; that is a separate build, if wanted.
- **Shown in advance:** the app shows the fee as `cancellation_fee_if_cancelled_now` before the passenger confirms a cancellation.

## 6. Decisions Needed From the Client

| # | Question | Our default |
|---|----------|-------------|
| SR-1 | Confirm the booking window: 30 minutes to 7 days ahead? | 30 min – 7 days |
| SR-2 | Confirm dispatch 15 minutes before pickup, searching until 10 minutes after? | 15 / 10 |
| SR-3 | Charge a reservation fee for booking ahead? If yes, how much? | No (₦0) |
| SR-4 | Charge a late-cancellation fee on reserved bookings (an exception to the Phase 1 no-fee rule)? If yes, how much and from how long before pickup? | No (₦0); window 60 min |
| SR-5 | Cash passengers who cancel late: waive (current) or record a balance due on their next ride? | Waive |
| SR-6 | Should drivers who often give up reservations lose the ability to reserve (Bolt does this)? | Not built; tracked in the audit log |
| SR-7 | Limits per passenger (5 bookings, 30 min apart) and per driver (5 reservations, 60 min apart)? | As stated |

## 7. Settings Reference

All values can be changed at runtime by operations (`GET`/`PUT /api/v1/admin/settings/scheduled-rides`). Defaults live in `config/scheduled_rides.php` (environment variables `SCHEDULED_RIDES_*`).

| Setting | Default |
|---------|---------|
| `enabled` | true |
| `min_lead_minutes` | 30 |
| `max_advance_days` | 7 |
| `dispatch_lead_minutes` | 15 |
| `reminder_minutes` | 60 |
| `matching_grace_minutes` | 10 |
| `retry_wave_delay_seconds` | 30 |
| `max_upcoming_per_passenger` | 5 |
| `min_minutes_between_bookings` | 30 |
| `driver_reservations_enabled` | true |
| `max_reservations_per_driver` | 5 |
| `driver_reservation_gap_minutes` | 60 |
| `driver_reservation_buffer_minutes` | 60 |
| `reservation_fee` | ₦0 |
| `late_cancellation_fee` | ₦0 |
| `late_cancellation_window_minutes` | 60 |

---

**Sources** (public pages and launch coverage; terms vary by market):

- Uber Reserve: [uber.com/blog/uberreserve](https://www.uber.com/blog/uberreserve), [uber.com/en-CZ/blog/uber-reserve](https://www.uber.com/en-CZ/blog/uber-reserve), [uber.com/za/en/blog/schedule-rides-in-advance-with-uber-reserve](https://www.uber.com/za/en/blog/schedule-rides-in-advance-with-uber-reserve/), [cittimagazine.co.uk](https://www.cittimagazine.co.uk/news/multimodal-systems-ride-sharing/uber-expands-reserve-feature-to-most-affordable-rides.html)
- Bolt: [taxi-point.co.uk (London launch)](https://www.taxi-point.co.uk/post/bolt-introduces-advanced-taxi-and-phv-scheduling-for-londoners), [dailynews.co.za (South Africa launch)](https://dailynews.co.za/technology/mobile-apps/2024-04-16-bolt-launches-scheduled-rides-feature-in-joburg-cape-town-durban-gqeberha), [idealog.co.nz (New Zealand launch)](https://idealog.co.nz/venture/2025/11/bolt-launches-scheduled-rides-and-flight-tracking-at-auckland-airport), [bolt.eu driver guide](https://bolt.eu/en-sa/driver/guide/schedule-rides/)
- Lyft: [autorentalnews.com](https://www.autorentalnews.com/137193/lyft-now-offers-scheduled-ride-feature)
- E-tiGo: [Cancellation Fee Proposal](CANCELLATION_FEE_PROPOSAL.md) (OQ-05)
