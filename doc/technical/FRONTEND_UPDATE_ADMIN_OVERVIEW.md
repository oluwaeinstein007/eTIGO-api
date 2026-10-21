# Admin Overview Page — API Update

**Date:** October 21, 2026
**Branch:** `feature/admin-overview`
**For:** Admin web (dashboard) team
**Mobile apps:** not affected. All changes are admin-only endpoints.

Full request and response details: `API_REFERENCE.md` → **Admin — Overview**. Postman: **Admin → Admin — Overview**.

---

## New endpoints

| Endpoint | Who | Feeds |
|----------|-----|-------|
| `GET /admin/overview` | Any admin | The four cards, "Needs your attention", "Recent activity" |
| `GET /admin/overview/live-operations?city_id=` | Operations, Super Admin | "Live operations" map and trip list |
| `GET /admin/search?q=` | Any admin | Top-bar "Search trips, people or cases" |

No breaking changes. Nothing existing changed shape.

---

## Design element → field

| On the design | Field | Notes |
|---------------|-------|-------|
| "Good Morning Tessy" | `user.first_name` from `GET /admin/auth/me` | Pick morning/afternoon/evening from the browser clock |
| Role under the name ("Super admin") | `user.admin_role` from `GET /admin/auth/me` | Already available |
| Bell + red dot | `GET /notifications/unread-count` | Already available; see "Not covered" below |
| Active rides `248` | `stats.active_rides.count` | |
| `+12.4% vs. this time yesterday` | `stats.active_rides.change_pct` | `null` → hide the line (no comparison yet) |
| Drivers online `1,482` | `stats.drivers_online.count` | |
| `+12.4%` | `stats.drivers_online.change_pct` | `null` → hide |
| `68% utilized` | `stats.drivers_online.utilisation_pct` | Share of online drivers on a trip |
| Open disputes `18` | `stats.open_disputes.count` | |
| `3 urgent` | `stats.open_disputes.overdue` | Cases past their response/resolution target; same as the dispute queue's "Overdue" filter (`sla=breached`) |
| Revenue `₦84.6m` | `stats.revenue.amount` | Gross fares today since midnight Lagos time. Format compactly on the client |
| `+6.7%` | `stats.revenue.change_pct` | Against the same hours of yesterday |
| `1,946 completed rides` | `stats.revenue.completed_rides` | |
| Safety alert — `3 Critical alert` | `attention.safety_alerts.critical` (and `.count`) | Critical = not yet picked up by an operator → Safety & SOS |
| Compliance reviews — `6 high-severity` | `attention.compliance_reviews.high_severity` (and `.count`) | Level 3 offline flags → Anti-offline Compliance |
| Pending KYC reviews — `3 cases` | `attention.pending_kyc.count` | → Driver & Passenger, pending review |
| Unmatched requests — `3 waiting` | `attention.unmatched_requests.count` | → Manual assignment |
| Recent activity rows | `recent_activity[]` | `title`, `subtitle`; icon from `category`; "4 min" from `created_at`; click → `subject.type` + `subject.id` |
| Live operations — "Lagos", "168 rides" | `city.name`, `summary.active_rides` | City switcher: `cities[]` |
| Map pins (green / red) | `markers[]` → `lat`, `lng`, `needs_attention` | |
| Trip rows | `rides[]` → `reference_number`, `pickup_address` → `destination_address`, `passenger.name` · `driver.name`, `status_label` | Shorten addresses on the client |
| "Open live view" | Live Monitoring page | `GET /admin/monitoring/active-rides?city_id=…` |

---

## Role-based display

Every admin lands on the overview, but a card is `null` when the admin's role can't open the page it links to:

- **Operations:** active rides, drivers online, revenue, compliance, KYC, unmatched, live operations.
- **Finance:** revenue only.
- **Support / Support lead:** open disputes (support queue only).
- **Safety:** open disputes, safety alerts, compliance, KYC.
- **Super admin:** everything.

When a card is `null`, **hide it** and let the rest of the row reflow. Showing `0` would read as "nothing to do". Hide the Live operations panel when `can_view_live_operations` is `false`. The same goes for search groups: `null` means not searched, `[]` means nothing found.

---

## Behaviour notes

- **"vs. yesterday" on rides and drivers** compares with a snapshot the server takes every 5 minutes. After this ships, those two `change_pct` values are `null` for the first 24 hours. Hide the line until then.
- **Revenue** is gross fares (what passengers paid), the same figure as the Revenue report, not E-tiGo's commission. Suggested label: "Revenue today".
- **Refresh:** poll `/admin/overview` every 30–60 s. For the map, poll `/live-operations` or apply the `admin.rides` WebSocket events you already use on Live Monitoring.
- **Search:** debounce about 300 ms and only send at 2+ characters (the API returns `422` below that). Rate limit is 60/min.
- **Recent activity** leaves out per-trip events such as "Ride completed" (the design's third row) on purpose: at thousands a day they would push everything else off the list. The Revenue card already counts them.

---

## Not covered by this update

- **Admin notifications (bell):** the endpoints work for admins, but today admin alerts (SOS escalations, missed dispute targets, operations alerts) go out by email only, so an admin's in-app list stays empty. Putting those alerts in the in-app inbox is a separate backend change; until then, show the bell without a dot.

---

## Design copy suggestions (for the designer)

- "Good Morning Tessy" → "Good morning, Tessy".
- "3 Critical alert" → "3 critical alerts" (plural, lower case).
- "3 urgent vs. this time yesterday" mixes two ideas → "3 overdue".
- "Revenue" has no period → "Revenue today".
- Mock data: trip references are `TRP-NG-000123` (not `TRP-482861` / `RD-982104`), disputes `DPT-000123`; "Bodija → Ring Road" are Ibadan places on a Lagos map; the three trip rows repeat one reference; both percentages are `+12.4%`.
- Sidebar: "Passengers wallet" → "Passenger wallets".
