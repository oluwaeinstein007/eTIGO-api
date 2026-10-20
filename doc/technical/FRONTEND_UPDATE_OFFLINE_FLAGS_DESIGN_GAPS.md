# Anti-Offline Compliance Design Gaps: Backend Changes

**Date:** October 20, 2026  
**Backend:** PR `feature/offline-flags-design-alignment`; live on staging once merged and the seeder is re-run  
**Prepared for:** Admin dashboard team and designer (driver app changes are in `MOBILE_UPDATE_OFFLINE_FLAGS.md`)

This answers "Anti-offline compliance: For the backend". Of the six design items, four change the system to match the design, one changes the design, and for one (item 4) both sides meet in the middle. All four backend-only questions are answered with changes.

Full request and response details are in `API_REFERENCE.md` (**Admin — Offline Trip Flags**). The "E-tiGo API v1" Postman collection has real example responses for every action. Base URL: `/api/v1`.

---

## Design flow vs the system

Paste these into the gaps document.

| # | What the design does | Backend response |
|---|---|---|
| 1 | The sanction starts when an admin upholds the flag | **Design changes to match the system.** The sanction starts when detection raises the flag. That is the client's specification (PRD §9.3; BE-AO-05), and in OQ-14 the client approved a false-positive target "under 1% for automatic penalties", so automatic penalties are the agreed model. They also stop a driver doing more off-app trips while the flag waits in the queue. Fairness comes from the safeguards: a fast dispute path, overturning lifts everything, and Level 3 never deactivates without a person confirming it. **Wording:** Uphold: "John Doe has been suspended since 20 Oct, 14:05. Upholding keeps the suspension; it ends automatically at 22 Oct, 14:05." Overturn: "This lifts the suspension, reinstates John Doe and stops this flag counting towards future sanctions." For L1: "A warning was sent when the flag was raised." Take the end time from the new `flag.driver_suspension_ends_at` (null unless the driver is on an automatic 48-hour suspension). |
| 2 | An upheld or overturned flag can still be changed (appeal) | **System changes, partly.** An **upheld** flag can now be overturned (an appeal; history reads "Overturned on appeal"), escalated and de-escalated. An **overturned** flag stays final. Overturning tells the driver they are cleared and lifts the sanction, and re-imposing it later would be unfair to them. On an overturned flag, "escalate", "de-escalate" and "remove sanctions" mean nothing because it has no sanction. If new evidence turns up, the next detection raises a new flag. Use `flag.allowed_actions` to show the buttons. |
| 3 | Level 3 for verified cash-taking, with who verified it and when | **System changes to match.** New `POST /admin/offline-flags/{flag}/verify-cash-taking` with `notes` (how it was verified, 10–2000). It moves the flag straight to Level 3 whatever the earlier flag count and suspends the driver pending review, like any Level 3. Who and when are on the flag as `cash_taking.verified_by` / `verified_at` / `notes` and in history. Deactivation is still a separate confirmation (item 4). It works on an existing flag. A cash complaint with no detection flag goes through disputes/support, not this screen. |
| 4 | Upholding or escalating to Level 3 deactivates in one step | **Both sides move.** Escalating to Level 3 still does not deactivate: an escalation is a judgement about the pattern, and deactivation must be a separate, deliberate step (as agreed earlier). But **confirm-deactivation now upholds an unreviewed flag in the same call**, so "Uphold and permanently deactivate" on a Level 3 flag is one button and one request. Plain "Uphold" on a Level 3 flag keeps the driver suspended pending confirmation. Both wait until the driver's 48 hours to respond are over (see below). |
| 5 | Every action asks for a reason, deactivation included | **System changes to match.** `confirm-deactivation` now **requires** `notes` (10–2000), shown in history and in `flag.deactivation.notes`. Keep the Reason box. Breaking: calls without `notes` now get 422. |
| 6 | A route map: booked route vs the driver's GPS path, cancel point, "+4 min" markers | **System changes to match.** Show now returns `route_map`: pickup and destination, the `route` polyline, `corridor_meters` (200), `cancelled_at`, `cancel_point` and `driver_path`, with each point's `seconds_after_cancellation`, `on_route` and `distance_from_route_meters`. The detection was also changed to measure against the **planned driving route**, not a straight line from pickup to destination, so the line on the map is the one the evidence was measured against (`route.source`: `planned_route`, or `straight_line` when the maps provider had none). Flags raised before Oct 20 have no route or path; show pickup and destination only. |

## Backend only

| # | Question | Backend response |
|---|---|---|
| 1 | Test data | **Done.** `OfflineFlagsDemoSeeder` now creates 22 flags for 11 drivers, with every status at every level (flagged/disputed/upheld/overturned × L1/L2/L3), earlier flags in the 30 days before, driver disputes, an appeal, a verified cash-taking case and a confirmed deactivation. Each flag has a real route map. It runs on staging after the merge: `php artisan db:seed --class=OfflineFlagsDemoSeeder`. Who has what is in `API_REFERENCE.md` → **Test Data (staging)**. |
| 2 | Example responses | **Done.** The Show, Review, Escalate, De-escalate and Confirm Deactivation examples in Postman (and the new Verify Cash-Taking) are real responses from the seeded data, with every field. The API reference examples are updated too. |
| 3 | What shows a deactivation was confirmed | **New fields.** After confirmation: `flag.deactivation` = `{ confirmed_at, confirmed_by: { id, name }, notes, reversed_at: null }`, `sanction_action: "deactivated"`, `status: "upheld"` with `resolved_at` set, `flag.driver_status: "deactivated"`, and `allowed_actions.confirm_deactivation: false`. Hide "Confirm deactivation" on `allowed_actions`, and show "Deactivated" from `driver_status`. **Note:** `driver.is_active` is the driver's login account, not their driver status. It stays `true` when a driver is deactivated, so it can't show deactivation. Deactivations confirmed before this change get `confirmed_at` / `confirmed_by` from the audit log (their `notes` are `null`). |
| 4 | Undoing a confirmed deactivation | **Overturn only, by a super admin** (OQ-14: "Super admin can overturn L3 deactivation"). Overturning reactivates the driver (their status is set from their remaining flags, usually approved), sets `deactivation.reversed_at`, and sends an "Account Reinstated" push. **De-escalate is refused** (422): a permanent deactivation is not turned into a suspension, so if it was wrong, overturn it. Driver management's Reactivate does not work on deactivated drivers either. For an Operations or Safety admin, `allowed_actions.overturn` is `false` on such a flag; for a super admin it is `true`. |

---

## Nitpicks and things we found

1. **`is_active` is the wrong field for "deactivated"** (backend item 3): see above. Use `flag.driver_status`.
2. **"Booked route" was not what was measured.** Before this change, detection compared the driver against a straight line from pickup to destination, so drawing a road route on the map would have shown something different from the evidence. Detection now uses the road route, and `route.source` says which line was used.
3. **"This will suspend John Doe for 48 hours"** (item 1) would be wrong even if sanctions started on uphold: the 48 hours run from when the suspension began, not from the click. Show the end time.
4. **Overturning could leave a driver suspended for good.** Overturning a driver's latest Level 2 or 3 flag left them suspended if they had any other flag in the last 30 days, even one whose suspension had ended long before. Now their status is set from the flags that still count. Overturning an older flag (not the latest) changes nothing, and a suspension an admin set by hand for another reason is never lifted.
5. **Driver app showed no sanction after an uphold.** `GET /driver/compliance` dropped `active_sanction` once a flag was reviewed, even while the driver was still suspended. Fixed. See the driver app note.
6. **"Reactivate them from driver management instead"** (the old de-escalate error) pointed to an endpoint that refuses deactivated drivers. The message now says to overturn on appeal.

## The driver's time to respond (new)

A Level 3 flag used to be confirmable the moment it was raised, before the driver could dispute it. Common practice is to hear the driver before a permanent deactivation, so now:

- When a flag reaches Level 3 (raised at Level 3, escalated, or verified cash-taking), the driver gets **48 hours** to dispute it. The deadline is `flag.deactivation_response_due_at` (also in the driver app).
- Until then, unless the driver disputes, **Uphold and Confirm deactivation are refused** for Operations and Safety (`allowed_actions.uphold` / `confirm_deactivation` are `false`). Upholding would take away the driver's chance to dispute, so it waits too. The 422 message gives the deadline in Lagos time.
- If the driver disputes, the wait ends: review the dispute as usual, then confirm.
- **A super admin can confirm early** in an urgent case. The history then reads "Upheld and deactivation confirmed before the driver's response deadline".
- Overturn and de-escalate are always allowed. The driver stays suspended during the window.
- Level 3 flags already in the queue got a deadline of `flagged_at` + 48 h.

On the flag page, while the window is open, show "Driver can respond until 22 Oct, 15:10" instead of the Confirm button (or disable it and give the time in a tooltip).

## Screen checklist

- On Level 3 flags, show the driver's response deadline (`deactivation_response_due_at`) and why Uphold and Confirm are unavailable until then.
- Show and hide every action from `flag.allowed_actions`. All action responses return the updated flag with it, so refresh the buttons from the response.
- Uphold dialog: show when the suspension ends (`driver_suspension_ends_at`, item 1 wording). On Level 3, offer "Uphold and permanently deactivate" (`confirm-deactivation`, Reason required) next to plain "Uphold".
- Overturn dialog on an upheld flag: title it "Overturn on appeal".
- New "Verified cash-taking" action with a Reason box (how it was verified). Show `cash_taking` (who, when, notes) in the evidence panel.
- Deactivated banner from `driver_status` and `deactivation` (who, when, reason). Show "Reversed on appeal" when `deactivation.reversed_at` is set.
- Map from `route_map`. Draw the route with a `corridor_meters` buffer, colour path points by `on_route`, and label them with `seconds_after_cancellation` ("+4 min"). Mark the cancel point. Old flags have no path.
- History: new descriptions "Overturned on appeal", "Upheld and deactivation confirmed", "Cash-taking verified from Level 1 to Level 3".

## Decisions to confirm with the client

These are our picks, not yet confirmed by E-tiGo:

- An overturn is final; only an uphold can be appealed.
- Verified cash-taking goes straight to Level 3 (pending confirmation), not straight to deactivation.
- Confirming a deactivation on an unreviewed flag also upholds it.
- Undoing a confirmed deactivation is a super-admin overturn only (from OQ-14); de-escalating it is refused.
- The driver gets 48 hours to respond before a Level 3 decision (see "The driver's time to respond" above); a super admin can confirm sooner.
- The driver's GPS path after a cancellation is kept with the flag as evidence. The privacy policy and driver terms should say that location after a cancellation near the pickup is used for compliance.
