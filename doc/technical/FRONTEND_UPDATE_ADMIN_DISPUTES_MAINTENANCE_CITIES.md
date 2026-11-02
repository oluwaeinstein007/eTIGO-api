# Admin Dashboard: Fixes for "What the API is still missing" (disputes, vehicle maintenance, cities)

**Date:** November 2, 2026
**Backend:** branch `fix/admin-disputes-maintenance-cities-gaps`; live on staging once merged
**Prepared for:** Admin dashboard team. Driver and passenger apps: nothing to change (see the end).

This answers the 9 items in "What the API is still missing", one by one. All 9 are done in the API. For the question (#1) we opened reading, not editing; see the item. A few things work slightly differently from what the document assumed; those are marked **Note**.

Full request and response details are in `API_REFERENCE.md`. Every changed request is in the "E-tiGo API v1" Postman collection, plus two new ones: **List Disputes — Search (Trip Reference, Name, Phone)** and **Remove a Fuel Type Override**. Base URL: `/api/v1`.

---

## Summary

| Must do (behaviour change) | Should do | Nice to have |
|---|---|---|
| **Dispute settings:** show the form read-only unless `can_edit` is `true` (#1)<br>**Maintenance settings:** remove the `[]` → `{}` workaround; send `null` to remove a fuel type's interval (#2, #3) | Show the case history and the named admins (#4)<br>Show make and model in the review queue (#6)<br>Pre-select `suggested_vehicle_id` when linking a paper (#7)<br>After an upload, show `pending_documents` (#8)<br>Use `GET /admin/cities` for Finance and Support pickers (#9) | Placeholder text for the dispute search: "Case, trip, name or phone" (#5) |

Nothing here is breaking: every change adds a field, adds access, or accepts a value that used to be refused.

---

## Backend response for each item

| # | Item | Backend response |
|---|------|------------------|
| 1 | Only super admins can open the dispute settings | **Partly, on purpose.** Support, support leads, safety operators and Finance can now **read** `GET /admin/settings/disputes`. Changing them (`PUT`) stays **super admin only**. **Note:** the refund limits and targets bind the people who would otherwise change them; letting a tier raise its own refund limit defeats the second-approval rule, so editing stays with super admins (maker-checker). Finance is included because it approves card refunds above the limits. The response now has `can_edit` (`true` only for super admins): show the form read-only when it is `false`. Operations still gets `403`. |
| 2 | A fuel type's own interval can't be removed | **Fixed.** In `PUT /admin/settings/vehicle-maintenance`, send the item as `null` to put it back on the main interval: `{"fuel_type_intervals": {"electric": {"service": null}}}`. An interval with neither `km` nor `months` does the same, so clearing both fields in the form works. `{"electric": null}` removes all of that fuel type's intervals. A fuel type with none left is no longer listed. **Note:** the main `intervals` still need a `km` or `months` value each; there is nothing to fall back on. |
| 3 | No fuel type intervals come as a list | **Fixed.** `fuel_type_intervals` (and `intervals`) are always JSON objects, `{}` when empty, on both `GET` and `PUT`. You can drop the dashboard workaround. |
| 4 | A case's history isn't sent | **Fixed.** `GET /admin/disputes/{id}` now has `history`: oldest first, each step with `event`, `description` ("Took the case", "Escalated the case to Tier 2 — Support lead", "Dismissed the case"…), `actor` (`{id, name, role}`), `reason`, `assignee`, `routing` (where the case went), `amount` and `created_at`. It covers submitted, taken or assigned, returned to the queue, marked under review, escalated (by an admin or automatically), sent back, moved with the linked case, missed targets, evidence added, the refund approval steps, the decision and the appeal. The admins named by ID are now named too: `escalation.escalated_by`, `appeal.original_decided_by` and `appeal.original_refund_approved_by` (`{id, first_name, last_name, admin_role}`). **Note:** the design's "triaged" isn't a separate step in the API: it is "Took the case" or "Marked the case under review". `actor` is `null` for the system (`by_system: true`) and for an admin who has since been deleted (`by_system: false`), so show "System" only when `by_system` is `true`. Evidence views and notes aren't steps (notes are already in `notes`). History is on the case page only, not in the list, and holds the newest 100 steps. Times are UTC: format them in Lagos time. |
| 5 | Search doesn't find a ride reference | **Fixed.** `search` on `GET /admin/disputes` now matches the trip reference (`TRP-…`) as well as the case reference and description, and the reporter's, **passenger's or driver's** name (full name too, "Maya Okafor"), email or phone (`0803 123 4567` works). A case or trip reference typed in full comes first. **Note:** `%` and `_` now match only themselves; before, they were wildcards. `search` is capped at 100 characters (`422` above). The `summary` counts still ignore search, as before. |
| 6 | The review queue names a car by its plate only | **Fixed.** Each record's `vehicle` in `GET /admin/vehicle-maintenance/records` now has `make` and `model` next to `plate_number` and `is_fleet`: "LND-557PQ · Toyota Camry". |
| 7 | A paper not linked to a car doesn't say which car it is for | **Fixed.** Each paper in `GET /admin/vehicle-documents/unlinked` now has `candidate_vehicles` (the cars its driver holds now: `id`, `plate_number`, `make`, `model`, `is_fleet`) and `suggested_vehicle_id` (the car when there is exactly one, else `null`). **Note:** the candidates are only the cars the driver holds **now**. A paper for a shared fleet car the driver has since handed back has no candidate; pick the car from the fleet list. Linking still accepts any car. |
| 8 | Uploading a paper deletes a driver's renewal waiting for review | **Fixed.** `POST /admin/vehicles/{id}/documents` now replaces only the car's **approved** paper of that type. A renewal the driver sent that is still pending is kept, and returned in the new `pending_documents` list (the `message` then says one is waiting). Approve or reject it as usual from the driver's documents. **Note:** if you later approve a renewal that expires **before** the paper you uploaded, your paper is kept rather than replaced, and the car goes by the latest expiry. |
| 9 | Finance and support can't read the list of cities | **Fixed.** `GET /admin/cities` is now open to Finance, Support and Support leads (read only), as well as Operations and Safety. The city page, readiness and every change stay Operations only (`403` otherwise). **Note:** use `per_page=100` for a picker (there are 22 cities). The list includes readiness and overlap fields that only Operations needs; ignore them in pickers. |

---

## Edge cases also fixed

- **City search:** `search` on `GET /admin/cities` treated `%` and `_` as wildcards; they now match only themselves.
- **Deleted admins in history:** a step by an admin who was deleted since keeps its time and description, with `actor: null` and `by_system: false`.
- **Renewal approval order:** approving a driver's renewal never deletes an approved paper that expires later (for example one Operations uploaded while the renewal waited).
- **Fuel type intervals:** a stored interval now always has both `km` and `months` keys, so the form always gets the same shape.

---

## Driver and passenger apps

Nothing to change. These are admin-only changes. When Operations uploads a car's paper, a renewal the driver sent now stays "pending" in the driver's documents instead of disappearing, which the driver app already shows.
