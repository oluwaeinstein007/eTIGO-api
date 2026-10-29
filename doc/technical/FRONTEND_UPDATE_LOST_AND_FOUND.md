# Lost & Found: App and Dashboard Updates

**Date:** October 18, 2026  
**Backend:** branch `feature/lost-and-found`; on staging once merged  
**Prepared for:** Passenger app, driver app and admin dashboard teams

A passenger who left something in the car can now report it from the trip. The driver is asked to check and answers "I have it" or "I don't have it". If the driver has it, the two arrange the return in the app. The driver marks it returned and the passenger confirms. Support steps in when the passenger asks, when the driver does not answer within 24 hours, or when the return is late. Drivers can also hand in items they find.

Request and response details are in `API_REFERENCE.md` → **Lost & Found**. Every endpoint has a saved example in the "E-tiGo API v1" Postman collection: **Passenger › Lost & Found**, **Driver › Driver — Lost & Found** and **Admin › Admin — Lost & Found**. Base URL: `/api/v1`.

Every rule is a setting operations can change, so read them from the API (`GET /config`, the report's `available_actions`) instead of hard-coding them.

---

## Summary

| App | Must do | Should do |
|-----|---------|-----------|
| Passenger | Nothing breaks. The new notification types `lost_item_update` and `lost_item_message` should open the report, not crash an unknown-type handler | "Report a lost item" on past trips; "My lost items" list and detail with messages |
| Driver | Handle the `lost_item_report` push: it needs an answer | Answer screen, messages, "Mark returned"; "Report a found item" on past trips |
| Admin dashboard | — | Lost & Found queue with the intervention form; settings page |

Disputes still accept `item_left_behind` for older app versions. New versions should send lost items to this flow instead: it notifies the driver and tracks the return.

---

## Passenger App

### Reporting
- `GET /config` → `lost_and_found`: `enabled`, `report_window_days` (30), `return_fee` (0 = none), `categories` (`value` + `label`). On a completed trip's detail screen, show **Report a lost item** while `enabled` is true and the trip ended less than `report_window_days` ago.
- Check `GET /lost-items?ride_id={ride}` first. If a report exists, show **View report** instead.
- `POST /rides/{ride}/lost-items`:
  - `category`: one of `categories`.
  - `description`: 5–1000 characters. Prompt for what it is, its colour and where in the car it was.
  - `contact_phone` (optional): "Another number we can reach you on". Ask for it especially when the category is `phone`. Updates are texted to it. E.164 or Nigerian local format.
  - `photos[]` (optional, multipart): up to 3 images, for example a photo of a similar item.
- When `return_fee` is above 0, show "If your driver returns it, a ₦X return fee is charged, and the driver keeps all of it".
- `422` errors come with a ready-to-show `message` (trip not completed, past the window, a report already open for the trip, 3 reports already open). Show it as it is.

### My lost items
- `GET /lost-items` (newest first; `open=1` for open ones only). Detail: `GET /lost-items/{id}`, which includes `messages`.
- Show `status_label`, `category_label`, `description`, `driver_response` (the driver's note) and the trip (`ride.pickup_address`, `ride.destination_address`, `ride.completed_at`, `ride.vehicle`).
- **Buttons: use `available_actions`.**

| Action | Button | Call |
|--------|--------|------|
| `message` | Message box | `POST /lost-items/{id}/messages` `{ body }` |
| `confirm_received` | **I got it back** | `POST /lost-items/{id}/received` |
| `escalate` | **Ask support to help** (ask for a reason) | `POST /lost-items/{id}/escalate` `{ reason }` |
| `cancel` | **I found it / cancel report** | `POST /lost-items/{id}/cancel` `{ reason? }` |

- Once the driver has the item, `contact` holds `{ first_name, phone }`. Show a **Call {name}** button and a safety tip: meet in a public place, or ask the driver to drop it at a staffed place.
- `return_fee`: `{ amount, currency, status }`. After **I got it back**, `status` is `charged` (taken from the wallet) or `balance_due` (it appears under balance due and must be paid before the next booking).
- `escalated: true` means support has the report. Show "Our support team is looking into this".
- Messages: `sender_role` is `passenger`, `driver` or `support`. Show `sender_name` ("E-tiGo Support" for support). Use `is_mine` to align the bubbles.

### Notifications
| Type | When | Do |
|------|------|----|
| `lost_item_update` | Driver found it or not, returned it, report resolved or closed, support following up, driver handed in an item you left | Open the report (`data.lost_item_id`) |
| `lost_item_message` | New message from the driver or support | Open the report's messages |

---

## Driver App

### Answering a report
- Push `lost_item_report` ("Lost item report", and a reminder after 24 hours without an answer). Open `GET /lost-items/{data.lost_item_id}`.
- Show the category, the description, the photos, the trip (`ride.pickup_address`, `ride.destination_address`, `ride.completed_at`) and the passenger's first name (`passenger.first_name`; on a trip booked for someone else, the rider's).
- **I have it** / **I don't have it**: `POST /lost-items/{id}/respond` `{ found: true|false, note? }`. Suggest a note such as "I am in Ikeja until 6pm". A driver who answered "I don't have it" can still send `found: true` later (the button stays in `available_actions` as `respond`).
- After **I have it**, `contact` holds the passenger's `first_name` and `phone`, and messages are open. Once it is handed back: **Mark returned** → `POST /lost-items/{id}/returned`.
- When `return_fee.amount` is above 0, show "You will receive ₦X when the passenger confirms".
- Buttons come from `available_actions`: `respond`, `mark_returned`, `message`.

### Handing in a found item
- On a past trip (within `lost_and_found.report_window_days`): **Report a found item** → `POST /rides/{ride}/lost-items` with `category`, `description` and `photos[]`. The passenger is told at once. The report starts as `driver_confirmed`, and the steps above apply.

### List
- `GET /lost-items` lists the reports on the driver's trips (`open=1` for the ones that need something).

---

## Admin Dashboard

### Lost & Found queue (roles: support, operations)
- `GET /admin/lost-items`. Order: open reports that need attention, then other open ones, then finished ones, newest first within each. Each report has `needs_attention` (use it for the red badge, not `escalated`, which stays `true` after a report closes). Use `summary` (`open`, `needs_attention`, `by_status`) for the badges. Filters: `status` (comma list), `needs_attention=1`, `open`, `category`, `city_id`, `ride_id`, `passenger_id`, `driver_id`, `search` (LF or trip reference, description), `from`, `to`.
- Detail: `GET /admin/lost-items/{id}`, with full passenger and driver (with vehicle), every message and internal notes (`is_internal: true`). Show `escalation_reason` and `escalation_source` (`passenger` or `system`).
- **Intervention form**: `POST /admin/lost-items/{id}/intervene` with `action`. Show only the buttons in the report's `available_actions`, and replace the report with the `lost_item` in the response (its actions change with the status):

| `action` | Fields | Use |
|----------|--------|-----|
| `note` | `body` | Internal note, never shown to the passenger or driver |
| `message` | `body` | Message to both, shown as "E-tiGo Support" |
| `mark_found` | `notes?` | The driver (or the office) has the item |
| `resolve` | `notes?`, `waive_return_fee?` | The item is back with the passenger |
| `close` | `closure_reason`, `notes?` | Not recovered: `not_found`, `unclaimed`, `passenger_unreachable`, `driver_unreachable`, `other` |

`available_actions` by status: `reported`, `driver_notified`, `driver_denied` → `note`, `message`, `mark_found`, `resolve`, `close`; `driver_confirmed`, `returned` → `note`, `message`, `resolve`, `close`; finished → `note`, `message`.

### Settings page (role: operations)
- `GET/PUT /admin/settings/lost-and-found`: `enabled`, `report_window_days` (30), `max_open_reports_per_passenger` (3), `driver_response_hours` (24), `return_deadline_days` (7), `auto_resolve_hours` (72), `denied_auto_close_days` (7), `share_contact_details` (true), `return_fee` (₦0; the client has not decided, OQ-36).

### Balances due
- The `reason` filter on `GET /admin/balances-due` has a new value, `lost_item_return_fee`.
