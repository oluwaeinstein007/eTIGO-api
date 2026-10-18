# Advanced Disputes: App and Dashboard Updates

**Date:** October 18, 2026  
**Backend:** branch `feature/advanced-disputes`; on staging once merged  
**Prepared for:** Passenger app, driver app and admin dashboard teams

Disputes now follow a support ladder. Every new case goes to a support agent (tier 1). Safety concerns go straight to the Safety team. A case moves up to a support lead (tier 2) or management (tier 3) when it is escalated or misses its response target. Refunds above an agent's limit, and every refund back to a card, wait for a second admin. Reporters can add evidence while the case is open and appeal a decision once, within 7 days. The rules follow the OQ-21 recommendation, which the client has not confirmed yet; every limit and target is a setting.

Request and response details are in `API_REFERENCE.md` → **Disputes** and **Admin — Dispute Management**. Every endpoint has a saved example in the "E-tiGo API v1" Postman collection: **Passenger › Ratings & Disputes** (filing), **Passenger › My Disputes**, **Driver › Driver — Disputes** and **Admin › Admin — Dispute Management** (queue, actions, refund requests, settings). Base URL: `/api/v1`.

---

## Summary

| App | Must do | Should do |
|-----|---------|-----------|
| Passenger | Nothing breaks: the old fields and `/passenger/disputes` still work. A `dispute_update` push should open the dispute | Show `stage_label`, "Add evidence" and "Appeal this decision" on the dispute screen |
| Driver | Nothing breaks | "My reports" list (`GET /disputes`); same dispute screen as the passenger |
| Admin dashboard | **Refunds now go to the wallet by default** (before: always to the card). Add the `support_lead` role to the invite and edit forms. Safety cases are now `403` for support roles | Queue filters and counts, take/assign, escalate / send back, refund approvals, appeal and evidence views, settings page |

---

## Passenger and Driver Apps

### Filing
- No change to `POST /rides/{ride}/dispute`. Drivers file the same way.
- Show the `message` from the response: a safety concern now says "Our safety team has been alerted and will contact you shortly."
- The response now includes `evidence` (all files with `type`, `url`, `caption`) next to the older `evidence_photos` / `evidence_audio`.
- **New (Phase 2 review, 18 Oct 2026), passenger app:** show **Dispute this fee** on a cancelled scheduled booking whose `scheduled.late_cancellation_fee` is above 0 and whose `scheduled.late_cancellation_fee_status` is not `waived`. It posts to the same `POST /rides/{ride}/dispute` (suggest the `fare_dispute` category). Any other cancelled ride still returns 422 on `ride`.

### My reports
- `GET /disputes` (passenger or driver; `status`, `ride_id` filters). Passengers can keep using `GET /passenger/disputes`.
- `GET /disputes/{id}` for the detail.
- Show `stage_label` as the case status line: "With our support team", "With our senior team", "With our safety team" or "Decision made". Never show admin names (the API does not send them).
- After a decision, show `resolution_notes`, and `refund_amount` with "credited to your E-tiGo wallet" when `refund_method` is `wallet` (or "refunded to your card" for `card`).

### Adding evidence
- Show **Add evidence** while `can_add_evidence` is `true` (`evidence_slots_left` files left).
- `POST /disputes/{id}/evidence`, multipart: `files[]` (1–5 per upload; photos and PDFs up to 5 MB, audio up to 10 MB) and optional `captions[]` in the same order. Show the `422` `message` as it is.

### Appeals
- When `appeal.can_appeal` is `true`, show **Appeal this decision** with the deadline (`appeal.deadline`).
- Ask for a reason (10–2000 characters) and `POST /disputes/{id}/appeal` `{ reason }`. The case reopens with `stage_label` "With our senior team".
- When `can_appeal` is `false` after a decision, `appeal.reason_unavailable` says why (already appealed, window passed, decision final). Show it in place of the button.

### Notifications
| Type | When | Do |
|------|------|----|
| `dispute_update` | Someone took the case, it was passed to the senior or safety team, the appeal was received, a decision was made | Open the dispute (`data.dispute_id`); `data.stage` holds the new stage label |

The decision email (`Report DPT-… — Resolved`) is unchanged. Moving a case to under review no longer sends that email (it said a decision had been made); it sends the push instead.

---

## Admin Dashboard

### Roles
- New role **`support_lead`** (tier 2). Add it to the invite (`POST /admin/admins/invite`) and edit (`PUT /admin/admins/{id}`) forms. A support lead has everything a support agent has, plus tier 2 cases and refunds up to ₦50,000.
- `safety_operator` now sees the dispute queue: every case read-only, and safety cases to work.
- Support roles get `403` on safety cases ("Safety cases are only visible to the Safety team.").

### Queue (`GET /admin/disputes`)
- New filters: `queue`, `tier`, `assigned` (`me`, `unassigned` or an admin id), `sla` (`breached`, `at_risk`), `appealed`, `pending_refund`, `open`; `status` takes a comma list; `search` also matches the reference number; `sort=due` puts the soonest target first.
- `summary` gives the counts for the tabs: `open`, `unassigned`, `mine`, `overdue`, `safety`, `tier_1`, `tier_2`, `tier_3`, `appeals`, `pending_refunds`.
- Each row has `tier_label`, `assigned_admin`, `is_mine`, `sla.status` (`on_track`, `at_risk`, `breached`; `met` / `missed` when closed) with `sla.next_due_at` for a countdown, `appeal`, `evidence_count`, `pending_refund_request`.
- The list no longer contains evidence files. Use `evidence_count` and open the detail.

### Case detail (`GET /admin/disputes/{id}`)
- **Buttons: use `available_actions`** (empty when the admin cannot work the case).

| Action | Button | Call |
|--------|--------|------|
| `take` | **Take case** | `POST /admin/disputes/{id}/assign` (no body) |
| `assign` | **Assign to…** (admins who can work it) | `POST /admin/disputes/{id}/assign` `{ admin_id }` |
| `unassign` | **Return to queue** | `DELETE /admin/disputes/{id}/assign` |
| `mark_under_review` | **Mark under review** | `PATCH /admin/disputes/{id}/status` `{ status: "under_review" }` |
| `escalate` | **Escalate** (reason required) | `POST /admin/disputes/{id}/escalate` `{ reason }` |
| `send_to_safety` | **Send to Safety team** | `POST /admin/disputes/{id}/escalate` `{ reason, to_safety: true }` |
| `deescalate` | **Send back down** (instructions required) | `POST /admin/disputes/{id}/deescalate` `{ note }` |
| `resolve` | **Resolve / Dismiss** | `POST /admin/disputes/{id}/resolve` |
| `add_note` | Notes box | `POST /admin/disputes/{id}/notes` |

- **Evidence:** when `evidence_locked` is `true`, show "Take this case to see the evidence" instead of the files. Each time the detail shows evidence, it is audit-logged.
- `linked_dispute` is the other person's dispute on the same trip; link to it. `sos_incident_id` links a safety case to its SOS incident.
- `appeal.original_decision` holds the first decision (status, notes, refund). Show it on appealed cases.

### Resolving and refunds
- New fields: `refund_method` (`wallet` default, or `card`) and `clawback_driver_earnings` (default `true`).
- **Breaking:** a refund without `refund_method` now goes to the passenger's wallet. Send `refund_method: "card"` for a card refund; it always goes to Finance for approval.
- Show `refund_limit` (the admin's limit; `null` = none) next to the amount field.
- `202` means the refund waits for approval and the case stays open: show the `message` and the `pending_refund_request`.
- **Late-cancellation fee cases** (the case's ride is a cancelled scheduled booking; `ride.scheduled` is now loaded on the case detail): offer the wallet only. If `ride.scheduled.late_cancellation_fee_status` is `charged`, the refund (up to the fee) goes to the wallet and is taken back from the driver who received it. If it is `balance_due`, the refund waives the unpaid fee: prefill the full fee, because a part amount returns 422.

### Refund approvals (`/admin/dispute-refund-requests`)
- New page for support leads, Safety, Finance and super admins: `GET /admin/dispute-refund-requests` (`status=pending` by default). Show **Approve** when `can_approve`, **Withdraw** when `can_withdraw`.
- Approve: `POST /admin/dispute-refund-requests/{id}/approve` `{ notes? }` → pays and resolves the case.
- Reject or withdraw: `POST /admin/dispute-refund-requests/{id}/reject` `{ notes }` → the case stays open.

### Settings (super admin)
- `GET/PUT /admin/settings/disputes`: refund limits per tier, response targets (0 = no target), tier 1 auto-escalation, appeal window, evidence limit. See the API reference for ranges.

### Alerts
- New safety cases email the active safety operators (super admins if there are none). Safety and tier 2 cases that miss a target email the super admins. Tier 1 cases that miss a target move to tier 2 on their own (`escalation.source: "system"`).
