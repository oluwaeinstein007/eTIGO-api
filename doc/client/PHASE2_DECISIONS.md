# E-tiGo — Phase 2 Decisions: Quick Reference

**Prepared by:** Engineering Team  
**Date:** 16 October 2026  
**Updated:** 18 October 2026 (OQ-36 and OQ-37, Lost & Found, added; OQ-21 dispute tiers built)  
**Status:** Recommendations for the open Phase 2 questions (OQ-18 to OQ-22, OQ-36 and OQ-37). **None has been confirmed by the client yet.** We ask the client to confirm each one or pick another option **before the Phase 2 build starts (Week 9)**. If there is no answer by then, engineering builds to the recommended defaults: every rule is a setting operations can change later without a release, so this locks nothing in. There are two exceptions. OQ-22: **no new city goes live without a launch date from the client.** OQ-36: **no return fee is charged until the client sets one.**

---

## Executive Summary

Phase 2 had five open questions that blocked building third-party bookings, vehicle maintenance, driver scheduling, dispute escalation and new cities. For each one, this report gives the recommended answer, other options the client can pick instead, and the edge cases we have planned for.

The recommendations follow two principles:

- **Industry practice.** We follow what Uber, Bolt and DoorDash do where their approach is public, adjusted for Nigeria.
- **Flexibility.** Each answer is a setting with a safe default, not a hard-coded rule. Operations can change it as E-tiGo learns.

| # | Question | Recommended answer | Client decision needed |
|---|----------|--------------------|------------------------|
| OQ-18 | Who gets the PIN and live tracking when someone books for another person? | **Both** the rider (by WhatsApp/SMS with a tracking link) and the booker (in the app) | Confirm, or pick rider-only / booker-only |
| OQ-19 | Vehicle maintenance: EV-only, general, or both? | **Both**: general maintenance for every vehicle, plus EV items for plug-in vehicles | Confirm the service intervals |
| OQ-20 | Driver shifts or availability planning? | **Availability planning** for all drivers (voluntary); **shifts** only for E-tiGo fleet cars shared between drivers | Confirm; say which fleet cars are shared |
| OQ-21 | Dispute escalation tiers and owners? | **3 tiers**: Support → Support Lead → Super Admin, with safety cases going straight to the Safety team | Confirm the refund limits per tier |
| OQ-22 | Which cities, and when? | **Abuja next, then Port Harcourt**, each launched only when it passes a readiness checklist | **Client must give the target dates** |
| OQ-36 | Lost & Found: should passengers pay a return fee? | **Yes, ₦2,000**, all of it to the driver (Uber/Bolt practice). Built **off** until the client decides | Confirm the amount, or no fee |
| OQ-37 | Lost & Found: do passenger and driver see each other's number? | **Yes**, once the driver has the item; messages in the app also work | Confirm, or messages only |

Scheduled rides and third-party bookings are built. Lost & Found was built on 18 October 2026 to the defaults in OQ-36 and OQ-37 below. The dispute tiers (OQ-21) were built on 18 October 2026 to the recommended option A. Their decisions (SR-1 to SR-7) are in the [Scheduled Rides Decisions Report](SCHEDULED_RIDES_DECISIONS.md).

---

## OQ-18 — Third-Party Bookings: Who Gets the PIN and Tracking?

A passenger (the **booker**) books and pays for a ride for someone else (the **rider**), such as a parent, child, guest or colleague. Bolt reports that **2–6% of its rides** are already booked for someone else, usually informally.

**Industry practice.** On Uber, the rider gets a text with the driver's details and a link to follow the driver, and the booker can follow the trip in their own app. The rider does not need the app.

### Recommendation

> **PIN: both. Tracking: both.**
>
> - The **rider** gets a WhatsApp message (SMS if WhatsApp fails) when a driver is assigned. It contains the booker's first name, the driver's name, photo, car and plate, the **ride PIN**, and a **live tracking link** that works without the app.
> - The **booker** sees the same PIN and live map in their app, so they can pass the PIN on if the rider's message doesn't arrive.
> - Operations can change either setting to *rider only* or *booker only*.

**Why both:** the person getting into the car must say the PIN, so the rider needs it. Phones in Nigeria are often off, out of data or on a different number, so the booker needs a copy as a fallback. The booker paid for the trip and is responsible for the rider, so seeing the PIN carries no real risk.

### Quick options

| Option | PIN goes to | Tracking goes to | When to choose it |
|--------|-------------|------------------|-------------------|
| **A (recommended)** | Rider + booker | Rider + booker | Fewest failed pickups; best for families and guests |
| B | Rider only | Rider + booker | If the client wants the strictest PIN control |
| C | Booker only | Booker only | Cheapest (no messages to the rider); the booker must phone the rider |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| The rider's message fails to deliver (WhatsApp and SMS both fail) | The booker is alerted in the app and shown the PIN **whatever the setting**, so the ride can still start |
| The rider's number is the booker's own number | Treated as a normal ride, not a third-party one |
| The rider already has an E-tiGo account | Still messaged by WhatsApp/SMS. We never tell the booker whether a number has an account (privacy). The trip stays in the booker's history |
| Who pays | The **booker** pays, by **wallet or card only** by default. Cash and bank transfer are off, because the payer is not in the car. Operations can turn cash on |
| The booker wants their own ride at the same time | Allowed. A third-party ride does not count as the booker's active ride. Limit: **2 active third-party rides** per booker |
| Scheduled third-party ride | Allowed. The rider gets a reminder 60 minutes before pickup. The PIN is sent at dispatch, as for every scheduled ride |
| The driver needs to call the rider | The driver sees the rider's first name and can call the rider's number while the trip is active, as with a normal passenger |
| Safety | The tracking page shows the driver, car and plate, a "Call 112" button and the E-tiGo safety line. The booker's in-app SOS works for the rider's trip and gives the Safety team the rider's number |
| The booker changes the PIN | The new PIN is sent to the rider again |
| Cancelling | Only the booker can cancel. The rider's message says to contact the booker for changes |
| Rating, disputes, lost items | Handled by the booker, who has the account |
| Someone uses the feature to spam a number | Limit of **10 different rider numbers per booker per day**. The message names the booker, so the rider knows it is not a scam |
| Children | Riders must be 18 or over unless an adult travels with them (Uber's rule). The booker confirms this when booking |
| The setting changes while rides are in progress | The setting is saved on each ride at booking, so changes apply only to new bookings |
| Tracking link after the trip | Expires 1 hour after the trip ends (the existing share-link rule) |

---

## OQ-19 — Vehicle Charging & Maintenance: EV-Only, General, or Both?

### Recommendation

> **Both, chosen automatically from the vehicle's fuel type.**
>
> - **Every vehicle:** routine service, tyres and brakes check, and the legal papers: insurance, roadworthiness certificate and vehicle licence.
> - **Plug-in vehicles only** (electric and plug-in hybrid): battery health check and charging status.
> - Records belong to the **vehicle**, not the driver, so they stay with the car if it changes hands (common for fleet cars).

**Why both:** most drivers in Phase 2 will drive petrol or CNG cars, so an EV-only feature would leave most of the fleet without reminders. Expired insurance or roadworthiness papers are a legal and safety risk for every car. Lagos already enforces roadworthiness checks on ride-hailing drivers.

### Default intervals (settings)

| Item | Applies to | Default | Source of the reading |
|------|-----------|---------|-----------------------|
| Routine service | All | Every **5,000 km or 3 months**, whichever comes first | Trip distance E-tiGo records, plus odometer readings the driver or workshop enters at each service |
| Tyres & brakes check | All | Every **6 months** | Date of the last check |
| Insurance, roadworthiness, vehicle licence | All | By **expiry date** | Uploaded document, linked to the **vehicle** (today these uploads are linked to the driver; Phase 2 links them to the car) |
| Battery health check | Electric, plug-in hybrid | Every **12 months** | Workshop record |
| Charging status | Electric, plug-in hybrid | Live | Charge level the driver enters when going online, plus E-tiGo charging-station sessions. A car-telematics link can come later |

### Reminders and enforcement

| Status | What happens |
|--------|--------------|
| Due within 14 days | Reminder to the driver (and to operations for fleet cars) |
| Overdue — service, tyres, battery | Warning only. **Never blocks driving.** Shown to operations |
| Expired — insurance, roadworthiness, licence | **7-day grace period**, then the driver cannot go online until a new document is approved |

### Quick options

| Option | Scope | When to choose it |
|--------|-------|-------------------|
| **A (recommended)** | General + EV items, by fuel type | Covers the whole fleet |
| B | EV only (charging + battery) | If Phase 2 is fleet EVs only. Petrol/CNG drivers get no reminders |
| C | General only | If E-tiGo has no EVs at launch. EV items can be switched on later |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| Papers expire during a trip | The trip is never interrupted. The block applies the next time the driver tries to go online |
| A blocked driver holds a scheduled-ride reservation | The reservation is released straight away and the passenger is told, so the booking returns to the pool in time |
| The driver changes car | The old car's records stay with it; the new car's records apply |
| The fuel type is corrected (for example petrol → electric) | EV items are added, or archived if the car is no longer plug-in |
| Non-plug-in hybrid | General items only |
| An odometer reading goes down | Rejected as a likely typo |
| Kilometres driven outside E-tiGo | Not visible to us, so the time limit (3 months) still triggers the service |
| Who updates records | **Fleet cars:** E-tiGo operations. **Own cars:** the driver uploads proof (receipt, certificate), operations approve |
| A service is booked | Uses the existing repair and maintenance request flow (`REP-` numbers), so there is one workshop process |

---

## OQ-20 — Driver Scheduling: Shifts or Availability Planning?

### Recommendation

> **Availability planning for all drivers, plus assigned shifts only for E-tiGo fleet cars that two or more drivers share.**
>
> - **Availability planning (all drivers, voluntary).** Drivers can tell E-tiGo when and where they plan to drive this week. There is **no penalty** for changing or skipping a plan, and drivers can **always go online** when they like. Operations use the plans to spot gaps (for example "only 8 drivers planned for Lekki, Friday 6–9 pm") and target incentives.
> - **Fleet shifts (shared fleet cars only).** Operations assign day/night shifts so two drivers can share one car with a clean handover. Operations switch shift mode on per car.

**Why:** Uber and Bolt do not schedule their drivers; drivers choose when to work. DoorDash lets couriers *book* time slots voluntarily, but they can still work without one. Forcing shifts on independent drivers would also make them look like employees, which is a legal risk for E-tiGo. A car shared by two drivers is the exception: it needs a timetable so both drivers don't claim it at once.

### Quick options

| Option | How it works | When to choose it |
|--------|--------------|-------------------|
| **A (recommended)** | Voluntary planning for all; shifts for shared fleet cars only | Keeps drivers independent; still runs shared cars properly |
| B | Availability planning only, no shifts | If no fleet car is shared between drivers |
| C | Assigned shifts for all drivers | Only if all drivers become E-tiGo employees. Not recommended for contractors |

### Shift rules (fleet cars in shift mode)

| Rule | Default |
|------|---------|
| Driver can go online | From **15 minutes before** to the end of their own shift |
| New ride offers stop | **15 minutes before the shift ends**, so the car is returned on time |
| A trip runs past the shift end | The driver finishes the trip; the next driver is told the car may be a little late |
| Driver not online 30 minutes after the shift starts | Marked **missed** and operations are alerted to reassign the car. No automatic fine; the fleet agreement decides any consequence |
| Overlapping shifts on the same car or the same driver | Rejected |
| Shifts after midnight (for example 22:00–06:00) | Supported. Times are stored as full date-times in Lagos time |
| Scheduled-ride reservations | A fleet driver in shift mode can only reserve bookings inside their own shifts |

### Edge cases for availability plans

| Situation | What happens |
|-----------|--------------|
| A driver plans hours and doesn't show up | Nothing happens to the driver. The plan is just a forecast |
| Plans shown to passengers | Never. Plans are for operations only and do not promise a passenger a car |
| Shared fleet car with a driver who also has their own car | Their shifts apply only to the fleet car |

---

## OQ-21 — Dispute Escalation Tiers and Ownership

Today every dispute goes to the Support team, and any support admin can refund up to the full fare. Phase 2 adds evidence uploads and escalation.

### Recommendation

> **Three tiers, plus specialist routing for safety and payment cases.**

| Tier | Owner | Handles | Refund limit per dispute |
|------|-------|---------|--------------------------|
| **1** | Support agent (existing `support` role) | Every new dispute except safety | Up to **₦5,000** |
| **2** | **Support lead** (new admin role) | Escalations, appeals, missed deadlines, repeat offenders, refunds above ₦5,000 | Up to **₦50,000** |
| **3** | Super admin | Refunds above ₦50,000, permanent driver deactivation, legal, press or regulator cases, final appeals | No limit |

| Specialist routing | Owner |
|--------------------|-------|
| **Safety concerns** (assault, harassment, dangerous driving) | Go **straight to the Safety team** (`safety_operator`), skipping tier 1, with a **1-hour** first-response target. Linked to any SOS incident on the trip |
| Payment errors (double charges, failed refunds, chargebacks) | Support keeps the case; **Finance** must approve any money movement beyond a normal wallet refund |
| Lost items | Use the Phase 2 Lost & Found flow, not disputes |

**Why:** a three-level ladder (agent, lead, management) is the standard support model. Linking refund limits to tiers keeps small cases fast and puts a second person on large refunds. Safety cases skip the queue because every hour counts.

### Response targets (settings)

| Tier | First response | Resolution | If missed |
|------|----------------|------------|-----------|
| Safety | 1 hour | 24 hours | Alert to super admin |
| 1 | 24 hours | 72 hours | Escalates to tier 2 automatically |
| 2 | 24 hours | 5 days | Alert to super admin |

### Quick options

| Option | Structure | When to choose it |
|--------|-----------|-------------------|
| **A (recommended)** | 3 tiers + Safety fast-track, refund limits per tier | Scales with the team; second pair of eyes on large refunds |
| B | 2 tiers (Support → Super admin) | A very small support team. Super admins get more cases |
| C | Single tier with Safety fast-track | Today's model with only the safety change |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| A refund above the agent's limit | The agent proposes it; it waits for a tier 2 approver. **No one approves their own refund** |
| Escalating a closed dispute | Not allowed. Closed disputes reopen only through an appeal |
| Appeals | The passenger or driver can appeal **once, within 7 days** of the decision. The appeal goes up one tier, to a **different** reviewer from the one who decided |
| An appeal reverses a refund | The driver's earnings are clawed back with the existing refund clawback |
| Escalating above tier 3 | Not possible; tier 3 is final |
| The assigned admin leaves or is deactivated | Their open disputes go back to their tier's shared queue |
| Passenger and driver both dispute the same trip | The two disputes are linked and handled by the same owner |
| Sending a case back down | Allowed (for example tier 2 → tier 1 with instructions); a note is required |
| Who sees evidence (photos, audio) | Only the assigned reviewer, higher tiers and the Safety team, as Phase 2 requires (NF-12). Every view is audit-logged |
| What the passenger sees | "Your case is with our senior team", never admin names |

### Built (18 October 2026)

Option A is built. Every limit and response target is a setting a super admin can change at any time, so a different answer from the client is a settings change, not new work. Engineering made these extra choices, which the client has not confirmed either:

| Choice | What we built | Why |
|--------|---------------|-----|
| Safety team refund limit | **₦50,000**, the same as tier 2 | Safety cases sit beside tier 2 and often end in a full-fare refund |
| Tier 1 misses its first-response target (24 h) | The case also moves to tier 2, not only after 72 h unresolved | An unanswered case is the worst experience; the table above says "if missed" for both targets |
| Appeal of a decision made by a more senior person | The appeal goes one tier above whoever decided. A super admin's decision is final | An appeal should always reach someone more senior than the first decider |
| Where refunds go | The passenger's E-tiGo wallet by default (OQ-27). A refund to the card is possible, and always needs Finance to approve | Wallet refunds are instant; card refunds move money outside the platform |
| Who sees evidence at tier 1 | Only the agent who has taken the case, not every agent | Keeps photos and recordings to the fewest people (NF-12) |
| Alerts | New safety cases email the Safety team; missed Safety and tier 2 targets email the super admins | The dashboard also shows overdue counts |

---

## OQ-22 — Which Cities, and When?

**The target dates are a business decision only the client can make.** Engineering's role is to make sure any city can be switched on safely. The current setup (each city has a map boundary, prices, vehicle classes, time zone and currency) already supports more cities without database changes.

### Recommendation

> **Launch order: Lagos (launch city) → Abuja → Port Harcourt → Ibadan.** Each city goes live only when it passes the readiness checklist below, not on a fixed date.
>
> Suggested timing for the client to confirm: **Abuja no earlier than 6 weeks after Lagos is stable**, and Port Harcourt in the following quarter.

**Why this order:** after Lagos, Abuja has the most ride-hailing demand in Nigeria and many business travellers between the two cities. Port Harcourt and Ibadan are large cities where ride-hailing already operates. Launching a city only when it has enough drivers avoids long waits, which put passengers off a new city. The client's own market data should override this order.

### City readiness checklist

| # | Gate | Default |
|---|------|---------|
| 1 | Map boundary drawn, with no overlap with other cities | Required |
| 2 | Prices set for every vehicle class offered; surge rules reviewed | Required |
| 3 | Approved, KYC-verified drivers in the city | **At least 30** |
| 4 | Support and Safety cover for the city's hours | Required |
| 5 | State transport permits or registrations, where the state requires them | Client/Legal to confirm per state |
| 6 | Charging stations live, if EV classes are offered | Required for EV classes |
| 7 | Launch promo codes limited to the city | Optional |

### Driver-first soft launch

We recommend three city states instead of today's on/off switch:

| State | Drivers | Passengers |
|-------|---------|------------|
| **Onboarding** | Can sign up, do KYC and be approved | Cannot book; can join a waitlist |
| **Live** | Normal | Normal |
| **Paused** | No new trips | Cannot book; trips in progress finish normally |

This lets E-tiGo recruit drivers for a few weeks before passengers can book, so the first passengers find cars.

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| A trip from one city ends in another or outside any city | The pickup city's prices apply, and the passenger sees a warning (already built) |
| A driver moves city | Operations move them. Not allowed while they have an active trip or upcoming reservations |
| A city is paused with scheduled bookings | **Existing bookings still go ahead by default**; operations can choose to cancel them all with a notice to each passenger |
| A city is paused while trips are in progress | They finish normally. Today the city switch has no such check, so we will add it |
| Commuters living outside the city limits (for example Lagos–Ogun: Mowe, Arepo, Ibafo) | We recommend drawing the Lagos boundary to include the commuter belt |
| Boundaries overlap | Rejected when saved, so a pickup always maps to one city |
| Time zone and currency | All Nigerian cities use Lagos time and naira, so nothing changes. A city outside Nigeria would need some Lagos-time rules (payout day, promo peak hours) moved to the city's own time zone |

---

## OQ-36 and OQ-37 — Lost & Found: Return Fee and Contact Details

*Added 18 October 2026, when Lost & Found was built.*

Lost & Found follows the Uber, Bolt and Lyft model:

1. The passenger reports the item from the trip, up to **30 days** after it.
2. The driver is notified and says whether they have it.
3. If they do, the two arrange the return through messages in the app.
4. The driver marks the item returned and the passenger confirms.

Support steps in when the passenger asks, when the driver does not answer within **24 hours**, or when the return has not happened within **7 days**. Drivers can also hand in items they find. Two choices need the client.

**OQ-36: Should passengers pay a return fee?** Uber and Bolt charge the passenger a fee when the driver brings an item back, and the driver keeps all of it. The fee pays for the driver's time and fuel, and it is why drivers return items at all.

| Option | What happens |
|--------|--------------|
| **A — Fee of ₦2,000 (recommended)** | Charged once the item is back: from the wallet, or as a balance due if the wallet is short. All of it goes to the driver. Support can waive it |
| B — Another amount | Any amount; operations can change it at any time. A new amount applies only to items found after the change |
| C — No fee (built default until the client decides) | Drivers return items for free |

**OQ-37: Do passenger and driver see each other's phone number?**

| Option | What happens |
|--------|--------------|
| **A — Yes, once the driver has the item (recommended, built default)** | Each sees the other's first name and number until the item is back. Messages in the app also work |
| B — Messages in the app only | No phone numbers are shared. Better for privacy, but returns are slower |

The recommendation is A because E-tiGo has no masked calling yet. Once masked calling exists, the apps can use it instead.

**Edge cases planned for**

| Situation | What happens |
|-----------|--------------|
| The lost item is the passenger's phone | The passenger can give another number when reporting. Updates are also sent to it by SMS |
| The driver says "not found" | The passenger can ask support to look into it. With no follow-up within 7 days, the report closes |
| The driver later finds it | The driver can change the answer to "found" |
| The driver says it was returned but the passenger disagrees | The passenger tells support. Otherwise the report closes as resolved after 72 hours |
| The driver's account is suspended or deleted | The report goes straight to support |
| A trip booked for someone else | The booker reports it, as for ratings and disputes |
| Misuse | One open report per trip, and at most 3 open reports per passenger |

---

## Next Steps

| # | Action | Owner |
|---|--------|-------|
| 1 | Confirm or change OQ-18 to OQ-21 (pick option A, B or C for each) before Week 9; otherwise the recommended defaults are built | Client / Product |
| 2 | Give the target date for each new city, and confirm the driver minimum (30) | Client |
| 3 | Confirm the dispute refund limits (₦5,000 / ₦50,000, Safety ₦50,000) and the choices under OQ-21 "Built", and say who will hold the new Support Lead role | Client / Operations |
| 4 | Say which fleet cars are shared between drivers (for shift mode) | Operations |
| 5 | Confirm the permits needed in Abuja (FCT) and Rivers State | Legal |
| 6 | Lost & Found: choose the return fee (OQ-36) and whether phone numbers are shared (OQ-37) | Client / Product |
| 7 | Build Phase 2 to these defaults; any later change is a settings update | Engineering |

---

**Sources** (public pages; terms vary by market and change over time):

- Uber, booking for someone else: [uber.com/ng/en/blog/request-a-ride-for-a-loved-one-today](https://www.uber.com/ng/en/blog/request-a-ride-for-a-loved-one-today/), [uber.com/blog/request-a-ride-for-friends-and-loved-ones](https://www.uber.com/blog/request-a-ride-for-friends-and-loved-ones)
- Bolt, rides booked for others (2–6%): [citinewsroom.com](https://www.citinewsroom.com/2025/07/bolts-new-family-profile-feature-lets-you-book-and-pay-for-rides-for-up-to-nine-people/)
- DoorDash, voluntary scheduling for independent couriers: [help.doordash.com — New Dasher Guide](https://help.doordash.com/en-us/dashers/article/new-dasher-roadmap), [dasher.doordash.com — scheduling guide](https://dasher.doordash.com/en-AU/blog/dashing-schedule)
- Lagos roadworthiness checks on ride-hailing drivers: [nairametrics.com](https://nairametrics.com/tag/uber)
- E-tiGo: [Open Questions Decision Report](OPEN_QUESTIONS_DECISIONS.md), [Scheduled Rides Decisions Report](SCHEDULED_RIDES_DECISIONS.md)
