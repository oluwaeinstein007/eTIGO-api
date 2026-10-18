# E-tiGo — Phase 2 Decisions: Quick Reference

**Prepared by:** Engineering Team  
**Date:** 16 October 2026  
**Updated:** 18 October 2026 (version 2.0: every Phase 2 decision with its reasons, alternatives and trade-offs; scheduled rides added; Lost & Found, the build choices and the Phase 2 review added)  
**Status:** All Phase 2 features are **built** (scheduled rides 16 October; third-party bookings, Lost & Found, disputes, multi-city, driver scheduling and vehicle maintenance 18 October) and were reviewed on 18 October 2026. The client decided the scheduled-ride booking window and fees. **Every other decision here is an engineering default awaiting client confirmation.** Each one is a setting operations can change without a release, so confirming a different option is a configuration change, not new work. Two things wait for the client before they take effect: **no new city goes live without its launch date (OQ-22)**, and **no Lost & Found return fee is charged until the client sets one (OQ-36)**.

---

## Executive Summary

Phase 2 added seven features: scheduled rides, rides booked for someone else, Lost & Found, dispute tiers, new cities, driver scheduling and vehicle maintenance. Building them needed answers to the open questions below and to many smaller questions along the way. This report gives, for each decision, **what we built, why, the other options the client can pick, and what our choice gives up**.

The recommendations follow two principles:

- **Industry practice.** We follow what Uber, Bolt, Lyft and DoorDash do where their approach is public, adjusted for Nigeria.
- **Flexibility.** Each answer is a setting with a safe default, not a hard-coded rule.

**Labels** (as in the [Open Questions Decision Report](OPEN_QUESTIONS_DECISIONS.md)): <span class="badge client">CLIENT</span> decided by the client · <span class="badge owner">PRODUCT OWNER</span> approved by E-tiGo's product owner, not yet confirmed by the client · <span class="badge eng">ENGINEERING</span> engineering default, awaiting client confirmation.

| # | Question | Decision | Decided by | Main trade-off | Client action |
|---|----------|----------|------------|----------------|---------------|
| SR-1 | Scheduled booking window | 30 min – 3 days ahead, 3 upcoming bookings (30 min apart) | Client (gap: engineering) | No bookings more than 3 days ahead | Confirm the 30-min gap |
| SR-2 | When matching starts | 15 min before pickup; search until 10 min after | Engineering | Earlier ties drivers up; later risks late pickups | Confirm |
| SR-3 | Reservation fee | None | Client | Drivers are not paid for committing unless the passenger cancels late | — |
| SR-4 | Late-cancellation fee | Economy base fare, within 30 min; can be disputed | Client (reading and disputes: engineering) | A low fee may not cover the driver's blocked slot | Confirm "base fare" reading |
| SR-5 | Fee that cannot be charged | Balance due | Client | Some balances may never be paid | — |
| SR-6 | Drivers who often release | No penalty; tracked | Engineering | Unreliable drivers can keep reserving | **Decide** |
| SR-7 | Driver reservation limits | 5, 60 min apart, no offers 60 min before | Engineering | Driver loses on-demand trips before a reserved pickup | Confirm |
| OQ-34 | Collecting a balance due | No booking until paid | Engineering | A small debt stops a passenger riding | Confirm |
| OQ-35 | When the driver is paid | When the passenger pays | Engineering | Drivers wait, and may never get some money | Confirm |
| OQ-18 | Who gets the PIN and tracking | Both rider and booker | Engineering | One message per ride; the booker sees the PIN | Confirm, or B / C |
| OQ-19 | Maintenance scope | General for all + EV items by fuel type | Engineering | More papers to keep up to date | Confirm intervals |
| OQ-20 | Shifts or availability | Voluntary plans; shifts for shared fleet cars | Engineering | Two systems; operations keep a timetable | Confirm; list shared cars |
| OQ-21 | Dispute tiers | 3 tiers + Safety; ₦5k / ₦50k | Engineering | A new role to staff; extra step on large refunds | Confirm; name Support Leads |
| OQ-22 | Cities and dates | Checklist-gated, driver-first launches | Engineering; **dates: client** | A launch waits until the gates pass | **Give the dates** |
| OQ-36 | Lost & Found return fee | ₦2,000 to the driver; built off | Engineering | Passengers pay for a return | **Decide** |
| OQ-37 | Sharing phone numbers | Yes, once the driver has the item | Engineering | Numbers exposed until masked calling exists | Confirm, or messages only |
| RV-01–07 | Phase 2 review fixes | See the last section | Engineering | See the last section | Confirm |

---

## Scheduled Rides (SR-1 to SR-7, OQ-34, OQ-35)

*Built 16 October 2026. Full benchmarks and settings: [Scheduled Rides Decisions Report](SCHEDULED_RIDES_DECISIONS.md).*

Passengers book up to 3 days ahead at a price fixed at booking. Drivers can reserve a booking in advance; 15 minutes before pickup the ride goes to that driver or is matched like any other ride.

| # | Decision | Why | Alternatives | What our choice gives up |
|---|----------|-----|--------------|--------------------------|
| SR-1 <span class="badge client">CLIENT</span> | 30 minutes to 3 days ahead; 3 upcoming bookings per passenger | Matches Bolt's London and South Africa launches; a short horizon keeps the locked price close to current rates | Lyft: 7 days. Uber: 30–90 days | Trips more than 3 days away (an airport run next week) cannot be booked yet |
| SR-1 gap <span class="badge eng">ENGINEERING</span> | One passenger's bookings at least 30 minutes apart (bookings for different riders never clash) | Catches accidental double bookings | No gap; 60 minutes | Two short trips less than 30 minutes apart cannot both be booked ahead |
| SR-2 <span class="badge eng">ENGINEERING</span> | Matching starts 15 minutes before pickup and searches until 10 minutes after; reminder 60 minutes before | Covers a typical pickup drive in Lagos traffic, with a margin; a passenger who planned around the ride would rather wait a little than lose it | Start 20–30 minutes before; stop at the pickup time | Starting earlier keeps drivers waiting; starting later risks late pickups in traffic |
| SR-3 <span class="badge client">CLIENT</span> | No reservation fee | Booking ahead costs the passenger nothing extra | Uber Reserve charges one (£3–£5 in the UK) | Drivers are not paid for committing to a booking unless it is cancelled late |
| SR-4 <span class="badge client">CLIENT</span> | Late-cancellation fee = the economy base fare, within 30 minutes of the ride, only for bookings a driver reserved; paid to that driver in full | Pays the driver for the slot they blocked; safety, emergency, wrong-car and no-show cancellations never pay | Uber: the trip's minimum fare, 1 hour before. Lyft: a flat fee, 30 minutes before | A base fare may not fully cover a driver who turned down other trips |
| SR-4 reading <span class="badge eng">ENGINEERING</span> | "Base economy fee" read as the economy **base fare**, not the minimum fare | The base fare is the fare's fixed part, and is lower | The economy minimum fare | If the client meant the minimum fare, the fee is lower than intended |
| SR-5 <span class="badge client">CLIENT</span> | A fee that cannot be charged (cash, short wallet, declined card), and a wallet fare the wallet cannot cover, becomes a **balance due** | Cash bookings cannot be charged after the fact | Waive it; add it to the next ride's fare | Some balances may never be paid |
| SR-6 <span class="badge eng">ENGINEERING</span> | No penalty for drivers who often release reservations; every release is in the audit log | The Cancellation Fee Proposal recommends tracking driver cancellations, not fining them | Bolt stops repeat offenders from reserving | Unreliable drivers can keep reserving until operations step in |
| SR-7 <span class="badge eng">ENGINEERING</span> | Up to 5 reservations per driver, 60 minutes apart; no on-demand offers in the 60 minutes before a reserved pickup | Stops hoarding and protects the pickup, as Uber does | Fewer or more; a shorter protection window | The driver gives up on-demand trips in the hour before the pickup |
| OQ-34 <span class="badge eng">ENGINEERING</span> | A passenger who owes a balance cannot book until they pay it, all at once, by wallet or card; Finance can waive it or switch the block off | Uber and Bolt do the same | Add it to the next fare (needs the driver to collect cash on cash rides) | A passenger with a small debt cannot ride until they pay |
| OQ-35 <span class="badge eng">ENGINEERING</span> | The driver is paid when the passenger pays | E-tiGo does not carry passengers' debts | Pay the driver at once and collect later (Uber) | Drivers wait for the money, and may never receive some of it |

---

## OQ-18 — Third-Party Bookings: Who Gets the PIN and Tracking?

*Decided by:* <span class="badge eng">ENGINEERING</span>

A passenger (the **booker**) books and pays for a ride for someone else (the **rider**), such as a parent, child, guest or colleague. Bolt reports that **2–6% of its rides** are already booked for someone else, usually informally.

**Industry practice.** On Uber, the rider gets a text with the driver's details and a link to follow the driver, and the booker can follow the trip in their own app. The rider does not need the app.

### Decision (built 18 October 2026)

> **PIN: both. Tracking: both.**
>
> - The **rider** gets a WhatsApp message (SMS if WhatsApp fails) when a driver is assigned. It contains the booker's first name, the driver's name, car and plate, the **ride PIN**, and a **live tracking link** that works without the app (the page shows the driver's photo).
> - The **booker** sees the same PIN and live map in their app, so they can pass the PIN on if the rider's message doesn't arrive.
> - Operations can change either setting to *rider only* or *booker only*.

**Why both:** the person getting into the car must say the PIN, so the rider needs it. Phones in Nigeria are often off, out of data or on a different number, so the booker needs a copy as a fallback. The booker paid for the trip and is responsible for the rider, so seeing the PIN carries no real risk.

### Options and trade-offs

| Option | PIN goes to | Tracking goes to | When to choose it | What it gives up |
|--------|-------------|------------------|-------------------|------------------|
| **A (built)** | Rider + booker | Rider + booker | Fewest failed pickups; best for families and guests | One WhatsApp/SMS per ride; the booker also sees the PIN |
| B | Rider only | Rider + booker | If the client wants the strictest PIN control | Little real gain: when the rider's message fails, the booker is shown the PIN anyway so the ride can start |
| C | Booker only | Booker only | Cheapest (no messages to the rider) | The booker must phone the rider with the car and PIN; a rider without the car details is easier to send to the wrong car |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| The rider's message fails to deliver (WhatsApp and SMS both fail) | The booker is alerted in the app and shown the PIN **whatever the setting**, so the ride can still start |
| The rider's number is the booker's own number | Treated as a normal ride, not a third-party one |
| The rider already has an E-tiGo account | Still messaged by WhatsApp/SMS. We never tell the booker whether a number has an account (privacy). The trip stays in the booker's history |
| Who pays | The **booker** pays, by **wallet or card only** by default. Cash and bank transfer are off, because the payer is not in the car. Operations can turn cash on |
| The booker wants their own ride at the same time | Allowed. A third-party ride does not count as the booker's active ride. Limit: **2 active third-party rides** per booker |
| Scheduled third-party ride | Allowed. The rider gets a reminder 60 minutes before pickup. The PIN is sent at dispatch, as for every scheduled ride |
| The driver needs to call the rider | The driver sees the rider's first name and can call the rider's number while the trip is active |
| Safety | The tracking page shows the driver, car and plate, a "Call 112" button and the E-tiGo safety line. The booker's in-app SOS works for the rider's trip and gives the Safety team the rider's number |
| The booker changes the PIN | The new PIN is sent to the rider again |
| Cancelling | Only the booker can cancel. The rider's message says to contact the booker for changes |
| Rating, disputes, lost items | Handled by the booker, who has the account |
| Someone uses the feature to spam a number | Limit of **10 different rider numbers per booker per 24 hours**. The message names the booker, so the rider knows it is not a scam |
| Children | Riders must be 18 or over unless an adult travels with them (Uber's rule). The booker confirms this when booking |
| The setting changes while rides are in progress | The setting is saved on each ride at booking, so changes apply only to new bookings |
| Tracking link after the trip | Expires 1 hour after the trip ends (the existing share-link rule) |

### Choices made while building <span class="badge eng">ENGINEERING</span>

| Choice | What we built | Why | Alternative | What it gives up |
|--------|---------------|-----|-------------|------------------|
| Who the ride belongs to | The booker stays the ride's passenger; the rider is a name and number on the ride | Payment, cancellation, rating, receipts and history work unchanged | Make the rider a passenger account of their own | The driver's rating of the trip goes to the booker; the rider has no trip history |
| Daily number limit | A rolling 24 hours, counting cancelled bookings | Booking and cancelling cannot be used to message many numbers | A calendar day; count completed rides only | A booker who cancels a lot reaches the limit sooner |
| Messages to the rider | Driver details, plus "driver arrived" and "cancelled" updates (on by default) | The rider is not in the app and needs to know the car is outside | Driver details only | More messages per ride; operations can turn the updates off |
| Links in booker-typed text | Removed from the rider's message (names, addresses) | Stops the feature being used to send phishing links from E-tiGo's number | Send the text as typed | A real link typed into an address is lost |
| Booker SOS location | The car's live position | The booker is not in the car, so their phone's location is wrong | The booker's phone location | Depends on the driver app sending its location |
| Tracking page | A simple page served by the backend (`/track/{ride}/{token}`) | The rider may not have the app, and the page was needed now | Wait for the web team | Basic design; the web team can replace it with one setting |
| "Delivered" status | Not used yet; we record which channel accepted the message | The SMS provider's delivery reports are not connected yet | Connect delivery reports | We know a channel accepted the message, not that the phone received it; the booker fallback covers failures |
| Scheduled bookings for others | Limited by the scheduled-ride limits (3 upcoming), not the 2-active limit; dispatch is never refused because of it | Refusing a booking at pickup time would strand the rider | Check the 2-active limit against the pickup time when booking | A booker can briefly have more than 2 rides for others on the road |

---

## OQ-19 — Vehicle Charging & Maintenance: EV-Only, General, or Both?

*Decided by:* <span class="badge eng">ENGINEERING</span>

### Decision (built 18 October 2026)

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
| Insurance, roadworthiness, vehicle licence | All | By **expiry date** | Uploaded paper, linked to the **vehicle** |
| Battery health check | Electric, plug-in hybrid | Every **12 months** | Workshop record |
| Charging status | Electric, plug-in hybrid | Live | Charge level the driver enters when going online, plus E-tiGo charging-station sessions. A car-telematics link can come later |

### Reminders and enforcement

| Status | What happens |
|--------|--------------|
| Due within 14 days | Reminder to the driver (and to operations for fleet cars) |
| Overdue — service, tyres, battery | Warning only. **Never blocks driving.** Shown to operations |
| Expired — insurance, roadworthiness, licence | **7-day grace period**, then the car cannot be driven on E-tiGo until a new paper is approved |

### Options and trade-offs

| Option | Scope | When to choose it | What it gives up |
|--------|-------|-------------------|------------------|
| **A (built)** | General + EV items, by fuel type | Covers the whole fleet | More papers and records for drivers and operations to keep up to date |
| B | EV only (charging + battery) | If Phase 2 is fleet EVs only | Petrol and CNG cars (most of the fleet) get no reminders, and expired insurance is not caught |
| C | General only | If E-tiGo has no EVs at launch | No battery-health tracking; it can be switched on later |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| Papers expire during a trip | The trip is never interrupted. After it, the driver gets no new trips, and cannot go online again until a new paper is approved |
| A blocked car's driver holds scheduled-ride reservations | They are released straight away and each passenger is told, so the booking returns to the pool in time. For a shared car, every shift driver loses the pickups that fall in their shifts on that car (RV-02) |
| The driver changes car | The old car's records stay with it; the new car's records apply |
| The fuel type is corrected (for example petrol → electric) | EV items are added, or archived if the car is no longer plug-in |
| Non-plug-in hybrid | General items only |
| An odometer reading goes down | Rejected as a likely typo |
| Kilometres driven outside E-tiGo | Not visible to us, so the time limit (3 months) still triggers the service |
| Who updates records | **Fleet cars:** E-tiGo operations. **Own cars:** the driver uploads proof (receipt, certificate), operations approve |
| A service is booked | Uses the existing repair and maintenance request flow (`REP-` numbers), so there is one workshop process |

### Choices made while building <span class="badge eng">ENGINEERING</span>

| Choice | What we built | Why | Alternative | What it gives up |
|--------|---------------|-----|-------------|------------------|
| Cars with no record yet | Shown as "No record yet" with a reminder; **only an expired paper stops a car** | Most cars have no roadworthiness or licence record on file; blocking them would take the fleet off the road on day one | Block cars with a missing paper after a deadline | A car with no paper on file can drive until one is uploaded; operations see the "no record" count |
| When the block starts | At once: an idle driver is set offline, gets no new trips or manual assignments, and reserved pickups are released | Stopping new trips straight away is safer | Block only the next time the driver goes online | A driver can be taken offline in the middle of a working day |
| Renewing a paper | The old paper stays valid until the renewal is approved; the reviewer confirms the expiry date | Uploading a renewal early should never take a car off the road | The new upload replaces the old paper at once | An expired paper keeps working until review, within the grace period; reviews must keep up |
| Roadworthiness and vehicle licence | New paper types; expiry date required | The expiry date drives the reminders and the block | Track insurance only | Drivers upload two more papers |
| Kilometres for the service | Last odometer reading plus each E-tiGo trip since | No car telematics yet | Time-based service only; telematics | Off-platform kilometres are missed (the 3-month limit covers them) |
| Reminders | One message per car when something changes; one daily operations email for fleet cars | Daily reminders teach drivers to ignore them | A reminder every day | A driver who ignores the first message hears nothing more until the status changes |

---

## OQ-20 — Driver Scheduling: Shifts or Availability Planning?

*Decided by:* <span class="badge eng">ENGINEERING</span>

### Decision (built 18 October 2026)

> **Availability planning for all drivers, plus assigned shifts only for E-tiGo fleet cars that two or more drivers share.**
>
> - **Availability planning (all drivers, voluntary).** Drivers can tell E-tiGo when and where they plan to drive. There is **no penalty** for changing or skipping a plan, and drivers can **always go online** when they like. Operations use the plans to spot gaps (for example "only 8 drivers planned for Lekki, Friday 6–9 pm") and target incentives.
> - **Fleet shifts (shared fleet cars only).** Operations assign day/night shifts so two drivers can share one car with a clean handover. Operations switch shift mode on per car.

**Why:** Uber and Bolt do not schedule their drivers; drivers choose when to work. DoorDash lets couriers *book* time slots voluntarily, but they can still work without one. Forcing shifts on independent drivers would also make them look like employees, which is a legal risk for E-tiGo. A car shared by two drivers is the exception: it needs a timetable so both drivers don't claim it at once.

### Options and trade-offs

| Option | How it works | When to choose it | What it gives up |
|--------|--------------|-------------------|------------------|
| **A (built)** | Voluntary planning for all; shifts for shared fleet cars only | Keeps drivers independent; still runs shared cars properly | Two systems; operations must keep each shared car's timetable |
| B | Availability planning only, no shifts | If no fleet car is shared between drivers | Shared cars have no handover rules, so two drivers could claim one car |
| C | Assigned shifts for all drivers | Only if all drivers become E-tiGo employees | Employment-status risk; drivers lose flexibility; likely fewer drivers at unpopular hours |

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
| The driver is suspended or deactivated | Their shift in progress ends and upcoming shifts are cancelled, and operations are told which slots to fill (RV-01) |

### Edge cases for availability plans

| Situation | What happens |
|-----------|--------------|
| A driver plans hours and doesn't show up | Nothing happens to the driver. The plan is just a forecast |
| Plans shown to passengers | Never. Plans are for operations only and do not promise a passenger a car |
| A driver is suspended, or moves city | Their plans stop counting as cover; a move removes the old city's plans (RV-03, RV-04) |
| Shared fleet car with a driver who also has their own car | Not supported: a driver drives one car on E-tiGo at a time, so a driver with their own car is not given shifts on a shared car, and a driver on shifts is not given a car of their own (RV-05) |

### Choices made while building <span class="badge eng">ENGINEERING</span>

| Choice | What we built | Why | Alternative | What it gives up |
|--------|---------------|-----|-------------|------------------|
| Handing the car over | The car passes to the next driver when they go online in their shift window, as long as the previous driver is offline and not on a trip. Until then the app tells them who still has it | No key-handover step to forget; the system always knows who has the car | Both drivers confirm the handover in the app | No record of the physical hand-over of keys |
| Cars on hire purchase | Cannot be put in shift mode, and a shared car cannot go on hire purchase | A car being bought by one driver is not shared | Allow a buyer to share their car on shifts | A buyer who wants a co-driver cannot use shifts |
| A missed shift | The driver can still go online for the rest of it; operations can reassign it | A late driver is better than an idle car | A missed shift is lost | The system does not penalise lateness; the fleet agreement decides |
| Rotas | Operations can create the same shift for up to 14 days in one go | Day and night rotas are the common case | Repeating weekly templates | Operations must create the rota again after 14 days |
| Shift ends | The driver is set offline at the end (after any trip in progress), and stays online when their next shift on the same car starts straight away | The next driver needs the car on time | Leave the driver online | A driver who wants to keep working needs another shift |
| Availability plans | Up to 14 days ahead, repeatable weekly for 3 weeks, with an optional area name | Enough for weekly planning without stale long-range plans | Longer horizons | No planning beyond 14 days |

---

## OQ-21 — Dispute Escalation Tiers and Ownership

*Decided by:* <span class="badge eng">ENGINEERING</span>

Before Phase 2 every dispute went to the Support team, and any support admin could refund up to the full fare. Phase 2 adds evidence uploads, escalation and appeals.

### Decision (built 18 October 2026)

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
| A scheduled-ride late-cancellation fee | The passenger can dispute it like a trip (RV-07) |

**Why:** a three-level ladder (agent, lead, management) is the standard support model. Linking refund limits to tiers keeps small cases fast and puts a second person on large refunds. Safety cases skip the queue because every hour counts.

### Response targets (settings)

| Tier | First response | Resolution | If missed |
|------|----------------|------------|-----------|
| Safety | 1 hour | 24 hours | Alert to super admin |
| 1 | 24 hours | 72 hours | Escalates to tier 2 automatically |
| 2 | 24 hours | 5 days | Alert to super admin |

### Options and trade-offs

| Option | Structure | When to choose it | What it gives up |
|--------|-----------|-------------------|------------------|
| **A (built)** | 3 tiers + Safety fast-track, refund limits per tier | Scales with the team; second pair of eyes on large refunds | A new role to staff (Support Lead); an extra approval step on large refunds |
| B | 2 tiers (Support → Super admin) | A very small support team | Super admins handle every escalation and large refund; senior cases wait longer |
| C | Single tier with Safety fast-track | The pre-Phase 2 model with only the safety change | No second person on large refunds, so more room for error or abuse |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| A refund above the agent's limit | The agent proposes it; it waits for a higher-tier approver. **No one approves their own refund** |
| Escalating a closed dispute | Not allowed. Closed disputes reopen only through an appeal |
| Appeals | The passenger or driver can appeal **once, within 7 days** of the decision, to a **different** reviewer one tier above whoever decided |
| An appeal reverses a refund | The driver's earnings are clawed back with the existing refund clawback |
| Escalating above tier 3 | Not possible; tier 3 is final |
| The assigned admin leaves or is deactivated | Their open disputes go back to their tier's shared queue |
| Passenger and driver both dispute the same trip | The two disputes are linked and handled by the same owner |
| Sending a case back down | Allowed (for example tier 2 → tier 1 with instructions); a note is required |
| Who sees evidence (photos, audio) | Only the assigned reviewer, higher tiers and the Safety team, as Phase 2 requires (NF-12). Every view is audit-logged |
| What the passenger sees | "Your case is with our senior team", never admin names |

### Choices made while building <span class="badge eng">ENGINEERING</span>

| Choice | What we built | Why | Alternative | What it gives up |
|--------|---------------|-----|-------------|------------------|
| Safety team refund limit | **₦50,000**, the same as tier 2 | Safety cases sit beside tier 2 and often end in a full-fare refund | A lower limit, so a second person approves | Safety operators can refund most trips without a second approval |
| Tier 1 misses its first-response target (24 h) | The case also moves to tier 2, not only after 72 h unresolved | An unanswered case is the worst experience | Escalate only after 72 hours unresolved | Tier 2 also gets simple cases tier 1 was slow to pick up |
| Appeal of a decision made by a more senior person | The appeal goes one tier above whoever decided. A super admin's decision is final | An appeal should always reach someone more senior than the first decider | Always one tier above the case's tier | Super admins receive more appeals |
| Where refunds go | The passenger's E-tiGo wallet by default (OQ-27). A refund to the card is possible, and always needs Finance to approve | Wallet refunds are instant; card refunds move money outside the platform | Refund to the original payment method | A card payer gets wallet credit unless the reviewer chooses the card; card refunds take longer |
| Who sees evidence at tier 1 | Only the agent who has taken the case, not every agent | Keeps photos and recordings to the fewest people (NF-12) | Every tier 1 agent | An agent must take the case before seeing its evidence |
| Alerts | New safety cases email the Safety team; missed Safety and tier 2 targets email the super admins | The dashboard also shows overdue counts | SMS or push to an on-call person | Email can be missed at night |
| Settings | Only super admins change the limits and targets | Refund limits are a money control | Operations can change them too | Every change waits for a super admin |

---

## OQ-22 — Which Cities, and When?

*Decided by:* <span class="badge eng">ENGINEERING</span> · dates: <span class="badge client">CLIENT</span>

**The target dates are a business decision only the client can make.** Engineering's role is to make sure any city can be switched on safely. Each city already has a map boundary, prices, vehicle classes, time zone and currency, so more cities need no database changes.

### Decision (process built 18 October 2026)

> **Launch order: Lagos (launch city) → Abuja → Port Harcourt → Ibadan.** Each city goes live only when it passes the readiness checklist below, not on a fixed date.
>
> Suggested timing for the client to confirm: **Abuja no earlier than 6 weeks after Lagos is stable**, and Port Harcourt in the following quarter.

**Why this order:** after Lagos, Abuja has the most ride-hailing demand in Nigeria and many business travellers between the two cities. Port Harcourt and Ibadan are large cities where ride-hailing already operates. Launching a city only when it has enough drivers avoids long waits, which put passengers off a new city. The client's own market data should override this order.

### Options and trade-offs

| Option | How it works | When to choose it | What it gives up |
|--------|--------------|-------------------|------------------|
| **A (built)** | Driver-first soft launch: each city goes live when its checklist passes and its date arrives | Fewest bad first impressions | A launch waits until the gates pass, so a marketing date can slip |
| B | Launch on a fixed date, checklist advisory only | Marketing needs a firm date | Passengers may find too few cars in the first weeks |
| C | Open several cities at once | Fast national footprint | Operations, support and driver recruitment spread thin |

### City readiness checklist

| # | Gate | Default |
|---|------|---------|
| 1 | Map boundary drawn, with no overlap with other cities | Required |
| 2 | Prices set for every vehicle class offered; surge rules reviewed | Required |
| 3 | Approved, KYC-verified drivers in the city | **At least 30** |
| 4 | Support and Safety cover for the city's hours | Required (confirmed by an admin) |
| 5 | State transport permits or registrations, where the state requires them | Client/Legal to confirm per state |
| 6 | Charging stations live, when the city's drivers include plug-in cars | Required in that case |
| 7 | The client's launch date | Required |
| 8 | Launch promo codes limited to the city | Optional |

### City states

| State | Drivers | Passengers |
|-------|---------|------------|
| **Onboarding** | Can sign up, do KYC and be approved | Cannot book; can join a waitlist |
| **Live** | Normal | Normal |
| **Paused** | No new trips except bookings made before the pause | Cannot book; trips in progress finish normally |

### Edge cases and how we handle them

| Situation | What happens |
|-----------|--------------|
| A trip from one city ends in another or outside any city | The pickup city's prices apply, and the passenger sees a warning |
| A pickup inside another city's boundary booked under a live city's name | Refused, pointing the passenger to the right city (RV-06) |
| A driver moves city | Operations move them. Not allowed while they have an active trip, upcoming reservations or upcoming fleet shifts (RV-03) |
| A city is paused with scheduled bookings | **Existing bookings still go ahead by default**; operations can choose to cancel them all with a notice to each passenger |
| A city is paused while trips are in progress | They finish normally |
| Commuters living outside the city limits (for example Lagos–Ogun: Mowe, Arepo, Ibafo) | We recommend drawing the Lagos boundary to include the commuter belt |
| Boundaries overlap | Rejected when saved, so a pickup always maps to one city |
| Time zone and currency | All Nigerian cities use Lagos time and naira, so nothing changes. A city outside Nigeria would need some Lagos-time rules (payout day, promo peak hours) moved to the city's own time zone |

### Choices made while building <span class="badge eng">ENGINEERING</span>

Lagos, Abuja and Port Harcourt, which were already switched on, are live; Ibadan is in onboarding.

| Choice | What we built | Why | Alternative | What it gives up |
|--------|---------------|-----|-------------|------------------|
| Resuming a paused city | No checklist the second time: a paused city can go live again at once | A pause is an emergency brake (flooding, unrest, fuel scarcity); reopening should not wait for paperwork. Operations see the checklist before resuming | Run the checklist again | A city could reopen after a permit lapsed |
| Charging stations | Required only when the city's approved drivers include electric or plug-in hybrid cars | No ride type is EV-only today | Always require stations for EV-capable classes | A city whose first EV driver joins after launch has no stations check |
| Drivers in a paused city | They can stay online, but only get rides passengers booked before the pause | Those bookings still need drivers | Set every driver offline | Drivers may wait online for very few rides |
| Drivers in an onboarding city | They can be approved but cannot go online until launch day | There are no passengers yet | Let them go online | Recruited drivers wait, idle, until launch |
| Passengers in a city that is not open | "E-tiGo is not available in {city} yet" with **Notify me**; everyone on the list gets one message on launch day | Turns early interest into first riders; the dashboard shows how many are waiting | Hide the city entirely | One message per waiting passenger on launch day |
| Pausing with bookings | Each booking that is cancelled is free, with a message saying why | The passenger did nothing wrong | Charge the late-cancellation fee | A driver who reserved the booking is not paid |
| Changing city | A driver chooses their city until they apply; after that only Operations can move them | Permits and documents are state-specific | Drivers move themselves | More work for operations |
| Boundaries | An overlap with any city is refused, including cities not yet live; cities may share a border | So a pickup always belongs to exactly one city | Check live cities only | A future city's boundary must be drawn with care before it launches |

---

## OQ-36 and OQ-37 — Lost & Found: Return Fee and Contact Details

*Decided by:* <span class="badge eng">ENGINEERING</span>

*Added 18 October 2026, when Lost & Found was built.*

Lost & Found follows the Uber, Bolt and Lyft model:

1. The passenger reports the item from the trip, up to **30 days** after it.
2. The driver is notified and says whether they have it.
3. If they do, the two arrange the return through messages in the app.
4. The driver marks the item returned and the passenger confirms.

Support steps in when the passenger asks, when the driver does not answer within **24 hours**, or when the return has not happened within **7 days**. Drivers can also hand in items they find.

**OQ-36: Should passengers pay a return fee?** Uber and Bolt charge the passenger a fee when the driver brings an item back, and the driver keeps all of it. The fee pays for the driver's time and fuel, and it is why drivers return items at all.

| Option | What happens | What it gives up |
|--------|--------------|------------------|
| **A — Fee of ₦2,000 (recommended)** | Charged once the item is back: from the wallet, or as a balance due if the wallet is short. All of it goes to the driver. Support can waive it | Passengers pay to get their own item back |
| B — Another amount | Any amount; operations can change it at any time. A new amount applies only to items found after the change | Too low and drivers may not bother; too high and passengers complain |
| C — No fee (**built default until the client decides**) | Drivers return items for free | Drivers carry the fuel and time, so fewer items come back |

**OQ-37: Do passenger and driver see each other's phone number?**

| Option | What happens | What it gives up |
|--------|--------------|------------------|
| **A — Yes, once the driver has the item (recommended, built default)** | Each sees the other's first name and number until the item is back. Messages in the app also work | The two numbers are exposed to each other until masked calling exists |
| B — Messages in the app only | No phone numbers are shared | Returns are slower, especially when the lost item is the passenger's phone |

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

### Choices made while building <span class="badge eng">ENGINEERING</span>

| Choice | What we built | Why | Alternative | What it gives up |
|--------|---------------|-----|-------------|------------------|
| Report window | 30 days after the trip | Long enough to notice something is missing | 7 or 14 days | Older reports are harder to act on |
| Timings | Driver silent 24 h → support; not returned in 7 days → support; "returned" closes after 72 h; "not found" closes after 7 days | Each case keeps moving without someone chasing it | Shorter or longer timers | A passenger who misses the 72-hour window has to contact support |
| Limits | One open report per trip; 3 open per passenger | Stops misuse | No limits | A passenger who lost several things on several trips must close one first |
| Return fee collection | Wallet first, else a balance due; one fee per trip | Same collection rules as other balances (OQ-34) | Charge the card | An unpaid fee blocks booking until paid |
| Who works the queue | Support and Operations; settings are Operations' | Operations know the drivers and cars | Support only | Two teams share one queue |
| Lost-item disputes | Disputes still accept "item left behind" for older apps | Older apps send it | Refuse it | Two routes for the same problem until all apps update |

---

## Phase 2 Review — Choices Made While Fixing Edge Cases

*Added 18 October 2026.* <span class="badge eng">ENGINEERING</span>

Engineering checked every Phase 2 task against the code: all were built. Because the features were built one after another, the review concentrated on where they meet, and found seven gaps. Each fix below needed a choice the client has not confirmed.

| # | What we built | Why (the gap) | Alternatives | What it gives up |
|---|---------------|---------------|--------------|------------------|
| RV-01 | When a driver is suspended or deactivated, their reserved scheduled pickups go back to the pool at once (passengers told), a shift in progress ends, upcoming shifts are cancelled, and operations get one email listing the slots to fill | The passenger only found out at dispatch, 15 minutes before pickup, and the car's shifts stayed blocked | Keep reservations and shifts in case the driver is reactivated soon; or only alert operations | A driver reactivated the same day must reserve again, and operations must create the shifts again |
| RV-02 | When a shared car's papers block it, every shift driver whose shift on that car covers a reserved pickup loses it; pickups covered by a shift on another car stay | Only the driver holding the car lost reservations, and a car held by nobody released none | Move the reservation to another shared car automatically | If the renewal is approved soon after, the reservations are not restored |
| RV-03 | Moving a driver to another city is refused while they have upcoming fleet shifts; their upcoming plans for the old city are removed | Shared cars stay in their city, so the shifts would point at a car the driver no longer drives | Cancel the shifts automatically on the move; keep the plans | Operations must cancel or reassign the shifts first |
| RV-04 | Planned cover counts only drivers who are approved and in the city now | A suspended driver's plans, and plans left by a moved driver, looked like cover | Count every plan and flag inactive drivers | A suspended driver who will be back tomorrow drops out of the forecast |
| RV-05 | A hire-purchase car cannot be given to a driver who has upcoming shifts on a shared car | One car per driver on E-tiGo; the opposite direction was already blocked | Cancel the shifts automatically | Operations must clear the shifts first |
| RV-06 | Estimates, bookings and booking changes are refused when the pickup lies inside another city's boundary; the passenger is pointed to that city, or told it has not opened | The app's chosen city was trusted, so a city not yet open could be booked under a live city's name and prices | Switch the ride to the right city automatically; only warn | A passenger standing just inside a neighbouring city must switch city in the app; it relies on accurate boundaries |
| RV-07 | A passenger can dispute a late-cancellation fee. If upheld, a paid fee is refunded to the wallet and taken back from the driver who received it; a fee still owed is waived in full | Disputes were for completed trips only, so the client-decided fee could not be contested in the app | E-tiGo pays the refund instead of the driver; Finance-only adjustments; part-waivers of an owed fee | The driver loses the fee when a dispute is upheld; an owed fee can only be waived whole |

---

## Next Steps

| # | Action | Owner |
|---|--------|-------|
| 1 | Give the target date for each new city, and confirm the driver minimum (30) and the OQ-22 choices | Client |
| 2 | Decide SR-6 (penalties for drivers who often release reservations) and OQ-36 (the Lost & Found return fee) | Client / Product |
| 3 | Confirm or change the engineering decisions in this report (pick option A, B or C where offered), including the Phase 2 review (RV-01 to RV-07). Each is a setting change | Client / Product |
| 4 | Confirm the dispute refund limits (₦5,000 / ₦50,000, Safety ₦50,000) and say who will hold the new Support Lead role | Client / Operations |
| 5 | Say which fleet cars are shared between drivers (shift mode), and confirm the maintenance intervals | Operations / Client |
| 6 | Confirm the permits needed in Abuja (FCT) and Rivers State | Legal |

---

**Sources** (public pages; terms vary by market and change over time):

- Uber, booking for someone else: [uber.com/ng/en/blog/request-a-ride-for-a-loved-one-today](https://www.uber.com/ng/en/blog/request-a-ride-for-a-loved-one-today/), [uber.com/blog/request-a-ride-for-friends-and-loved-ones](https://www.uber.com/blog/request-a-ride-for-friends-and-loved-ones)
- Bolt, rides booked for others (2–6%): [citinewsroom.com](https://www.citinewsroom.com/2025/07/bolts-new-family-profile-feature-lets-you-book-and-pay-for-rides-for-up-to-nine-people/)
- DoorDash, voluntary scheduling for independent couriers: [help.doordash.com — New Dasher Guide](https://help.doordash.com/en-us/dashers/article/new-dasher-roadmap), [dasher.doordash.com — scheduling guide](https://dasher.doordash.com/en-AU/blog/dashing-schedule)
- Lagos roadworthiness checks on ride-hailing drivers: [nairametrics.com](https://nairametrics.com/tag/uber)
- Scheduled rides (Uber Reserve, Bolt, Lyft): see the sources in the [Scheduled Rides Decisions Report](SCHEDULED_RIDES_DECISIONS.md)
- E-tiGo: [Open Questions Decision Report](OPEN_QUESTIONS_DECISIONS.md), [Scheduled Rides Decisions Report](SCHEDULED_RIDES_DECISIONS.md), [Cancellation Fee Proposal](CANCELLATION_FEE_PROPOSAL.md)
