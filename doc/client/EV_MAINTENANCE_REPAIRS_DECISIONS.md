# E-tiGo — EV Charging, Vehicle Maintenance and Repairs: Decisions Report

**Prepared by:** Engineering Team  
**Date:** 28 October 2026  
**Status:** Implemented (EV charging and maintenance in Phase 2; Operations actions, repair requests and the hire-purchase fee waiver in PR #137). One value is marked **client**: drivers still paying off an E-tiGo car do not pay the EV reservation fee (set on 28 October 2026). Every other value is a default chosen by engineering and **has not been confirmed by the client yet**. Operations can change each one at any time, without a release, from the admin settings (`PUT /api/v1/admin/settings/ev-charging` and `PUT /api/v1/admin/settings/vehicle-maintenance`). The apps read the current values from `GET /api/v1/config`, so a change shows in the app copy straight away.

---

## Executive Summary

Three driver features need the client's confirmation of a few numbers and rules:

- **EV charging.** Drivers of electric and plug-in hybrid cars reserve a stall at an E-tiGo station, either straight away or by sending a charging request that Operations confirm. A ₦500 fee holds the stall for the driver.
- **Vehicle maintenance.** Each car has a checklist of service items and legal papers. Drivers get reminders, and a car whose papers have expired is kept off the road after a grace period.
- **Repair and service requests.** Drivers ask E-tiGo for a repair or a service visit from the app. Operations book the workshop, complete the request with a report, or cancel it.

Section 4 lists the decisions we need, each with our recommendation. If the recommendation is accepted, nothing needs to change: it is already the default.

---

## Table of Contents

1. [EV Charging](#1-ev-charging)
2. [Vehicle Maintenance](#2-vehicle-maintenance)
3. [Repair and Service Requests](#3-repair-and-service-requests)
4. [Decisions Needed From the Client](#4-decisions-needed-from-the-client)
5. [Settings Reference](#5-settings-reference)

---

## 1. EV Charging

| Decision | E-tiGo default | Why |
|----------|----------------|-----|
| Reservation fee | **₦500**, taken from the driver's earnings when a stall is actually held for them | Stops drivers holding stalls they don't use. Nothing is charged while a request waits for review or while the driver waits in the queue |
| Who doesn't pay the fee | Drivers **still paying off an E-tiGo car** on a hire-purchase agreement (**client**), plus Platinum and Diamond tiers | Set by the client: drivers on the ₦40,000 daily remittance shouldn't pay extra to charge the car they are buying. Drivers who have paid off their car, and drivers with their own car, pay the fee |
| A **paused** hire-purchase agreement | Still counts as paying, so no fee | Operations pause an agreement for a short break (illness, repairs); the driver is still buying the car |
| How long a stall is held | **30 minutes** for the driver to arrive and start charging; after that the stall is released and the fee kept | Long enough to cross most of Lagos at off-peak times; short enough that a no-show doesn't block a stall for long |
| Free cancellation | Cancelling within **2 minutes** of the stall being held refunds the fee | Covers a mistaken tap without letting drivers hold stalls for free |
| Queue | At most **10** drivers per station; a full station takes a queue only when a stall is due to free within **15 minutes**, otherwise the driver is told to try again in **15 minutes** | Avoids queues that move too slowly to be useful |
| Open reservations per driver | **1** at a time | One car, one charge |
| Operations cancel a reservation | Allowed at any stage, with a reason the driver sees. **Refunding the fee is Operations' choice** (off by default) | Lets Operations refund when E-tiGo is at fault (e.g. a broken charger) and keep the fee when the driver is (e.g. a no-show) |
| Operations end a session the driver didn't finish | Allowed; **the fee is kept** unless Operations tick "refund" | The driver used the stall; a refund stays available for a fault on E-tiGo's side |
| How far ahead Operations can confirm a request | Up to **7 days** ahead | Matches how far drivers plan their week |

## 2. Vehicle Maintenance

| Decision | E-tiGo default | Why |
|----------|----------------|-----|
| Routine service | Every **5,000 km or 3 months**, whichever comes first | Common interval for cars driven all day in city traffic |
| Tyres and brakes check | Every **6 months** | Ride-hailing cars wear tyres and brakes fast |
| Battery health check (electric and plug-in cars) | Every **12 months** | Annual check is typical for EV batteries |
| Reminder lead | **14 days** (or **500 km**) before an item is due | Time to book a workshop without rushing |
| Expired insurance, roadworthiness or vehicle licence | **7 days** of grace (warnings only), then the driver **can't go online** until a renewed paper is approved | Driving with expired legal papers is a legal and insurance risk; the grace period covers renewals in progress. A driver on a trip always finishes it |
| Overdue service | **Warning only**, never blocks | Service timing is a recommendation, not a legal requirement |

## 3. Repair and Service Requests

| Decision | E-tiGo default | Why |
|----------|----------------|-----|
| Stages | **New → Booked → Completed**, or **Cancelled** | Matches how a workshop visit happens |
| Rebooking | Operations can move a booked visit while it hasn't happened | Workshops reschedule often |
| Who can cancel | The driver (any time before completion, reason optional) and Operations (reason required, shown to the driver) | The driver may get the car fixed elsewhere; Operations may find the work is under warranty |
| Driver notifications | When the visit is booked, completed or cancelled | The three moments the driver needs to act on |
| Reports | Operations can attach a workshop report or quote; the driver can't delete files E-tiGo added | Keeps a record of the work on the car |

## 4. Decisions Needed From the Client

Each line shows our recommendation (the current default). Reply "agree" or give the value you want.

| # | Question | Recommendation |
|---|----------|----------------|
| EV-1 | What should the EV reservation fee be? | **₦500** |
| EV-2 | Does a driver whose hire-purchase agreement is **paused** still charge for free? | **Yes**: they are still buying the car |
| EV-3 | How long should a reserved stall wait for the driver? | **30 minutes** |
| EV-4 | How long after reserving can a driver cancel and get the fee back? | **2 minutes** |
| EV-5 | When Operations cancel a reservation, should the fee be refunded automatically? | **No**: Operations decide each time (refund when it was E-tiGo's fault) |
| EV-6 | When Operations end a session the driver didn't finish, is the fee kept? | **Yes**, unless Operations choose to refund |
| EV-7 | Queue size per station, and the "try again" wait | **10 drivers**, **15 minutes** |
| VM-1 | Service intervals: routine service, tyres and brakes, battery health | **5,000 km / 3 months**, **6 months**, **12 months** |
| VM-2 | How early should drivers be reminded? | **14 days** (or 500 km) before |
| VM-3 | How long can a driver keep driving after a legal paper expires? | **7 days**, then blocked until a renewal is approved |
| RP-1 | Can a booked workshop visit be moved? | **Yes**, until the visit happens |

## 5. Settings Reference

Operations can change these at any time. Changes apply to reservations made or confirmed afterwards; a reservation keeps the fee it was given.

**EV charging** (`/admin/settings/ev-charging`):

| Setting | Default | Range |
|---------|---------|-------|
| `reservation_fee` | 500 (naira) | 0–100,000 |
| `reservation_expiry_minutes` | 30 | 5–240 |
| `free_cancellation_minutes` | 2 | 0–60 |
| `max_queue_size` | 10 | 0–100 |
| `imminent_departure_minutes` | 15 | 0–240 |
| `retry_delay_minutes` | 15 | 1–240 |
| `max_active_reservations_per_driver` | 1 | 1–5 |
| `hire_purchase_fee_waiver` | on | on / off |
| `waive_fee_while_agreement_paused` | on | on / off |

**Vehicle maintenance** (`/admin/settings/vehicle-maintenance`):

| Setting | Default | Range |
|---------|---------|-------|
| `intervals` | service 5,000 km / 3 months; tyres and brakes 6 months; battery health 12 months | 500–100,000 km; 1–60 months |
| `fuel_type_intervals` | none | per fuel type overrides |
| `due_soon_days` / `due_soon_km` | 14 / 500 | 0–90 / 0–5,000 |
| `legal_grace_days` | 7 | 0–60 |
| `block_on_expired_papers` | on | on / off |
| `enabled` | on | on / off |
