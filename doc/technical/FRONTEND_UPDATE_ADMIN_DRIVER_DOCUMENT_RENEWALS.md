# Driver Document Renewals: Admin Dashboard Updates

**Date:** November 7, 2026  
**Backend:** PR #162 (`feature/driver-document-renewals`); live on staging once merged  
**Prepared for:** Admin dashboard team.

Drivers now renew papers when the date on them runs out, not yearly. A renewal is a **document check, not a new KYC**. While a renewal is checked, the approved paper stays in force. An **expired driving licence takes the driver off the road** from the day after expiry, until operations approve the renewed licence. Operations can also **withdraw** a licence found invalid, which blocks at once. Government ID expiry only reminds.

Details: `API_REFERENCE.md` → **Driver Document Renewals**, **Review Document**, **Withdraw an Approved Document**, **Correct an Approved Document's Details**, **Admin — Driver Documents (Renewals Queue)**, **Admin — Driver Document Settings**. Postman: **Admin → Admin — Driver Management**.

---

## Summary

| Must do | Should do | Nice to have |
|---|---|---|
| 1. Send `expires_at` when approving a licence (now required)<br>2. A renewals queue screen<br>3. Show the licence block on the driver page | 4. Withdraw and Correct actions on approved papers<br>5. Settings screen<br>6. History entries | 7. "Blocked" filter on the drivers list |

---

## 1. Approving a document (must do, **breaking**)

`POST /admin/drivers/{driver}/documents/{document}/review` with `action: approve`:

- **`expires_at` is required** for a `driving_licence` (and, as before, roadworthiness and vehicle licence) when the paper has none. Without it: 422 `Enter the expiry date printed on the driving licence before approving it.` Put a date field, prefilled from `document.expires_at`, on the approve dialog.
- An **expired date** is refused: 422 `This driving licence has already expired. Reject it and ask the driver for the renewed one.`
- New optional **`document_number`**: prefilled from `document.document_number` (admins see the full number). On a licence it becomes the driver's `licence_number`.
- Approving a renewal **replaces** the earlier approved paper of that type and lifts a licence block. The response adds `compliance` (same shape as the driver page's `document_compliance`).

Review guidance for operations:
- **FRSC temporary licence:** approve it with the temporary paper's own expiry date.
- **Renewal payment receipt:** this is not a licence. Reject it.
- **Name differs slightly:** check by hand.
- **Someone else's licence:** reject it and escalate to Safety.

## 2. Renewals queue (must do)

`GET /admin/driver-documents` (Operations, Safety). Suggested tabs:

| Tab | Query |
|---|---|
| Renewals to check | `?status=pending&renewals_only=1` (oldest first) |
| Expiring soon | `?expiry=expiring_soon` (next 30 days, soonest first) |
| Expired | `?expiry=expired` |
| Missing expiry date | `?type=driving_licence&expiry=no_expiry` |

Also `type`, `city_id`, `search` (driver name, email, phone, reference), `per_page`. Each row is the document plus `expiry_status`, `days_until_expiry`, `is_renewal`, `vehicle` and `driver` (`id`, `reference_number`, `name`, `phone`, `status`, `city`, `documents_blocked`). Use `summary` (`pending_renewals`, `expiring_soon`, `expired`, `licences_missing_expiry`, `blocked_drivers`) for tab badges. Open a row with the existing review dialog.

**Backfill:** licences approved before today mostly have no expiry date. They show as `no_expiry` and are never blocked. The "Missing expiry date" tab plus **Correct** (§4) lets operations add the dates.

## 3. Driver page (must do)

`GET /admin/drivers/{driver}`:

- `driver.documents_blocked`, `documents_blocked_at`, `documents_block_reason`: show a red banner, e.g. "Off the road: Your driving licence expired on 7 Nov 2026."
- New top-level `document_compliance`: `can_drive`, `block_reason`, `action_required`, and `documents[]`. Each entry has `type`, `label`, `document_id`, `expires_at`, `expiry_status`, `days_until_expiry`, `blocks_driving`, `revoked_at`, `renewal`, `action_required`. Show the expiry state next to each paper, and the pending renewal under the paper it replaces.
- Each document now has `document_number` (full) and `revoked_at`.
- A driver can now have **two rows of the same type** in `driver.documents`: the approved paper and its pending renewal. Group by type and show both.
- **Manual ride assignment** and **shift booking** refuse a blocked driver with 422 under `driver_id`. Show the message.

## 4. Withdraw and Correct (should do)

On an **approved** paper:

- **Withdraw**: `POST /admin/drivers/{driver}/documents/{document}/revoke` `{ "reason": "FRSC reports this licence as suspended." }`. Confirm first, since a withdrawn licence blocks the driver at once and they are told the reason. The paper becomes `rejected` with `revoked_at` set.
- **Correct**: `PATCH /admin/drivers/{driver}/documents/{document}` `{ "expires_at": "2030-05-31" }` and/or `{ "document_number": "..." }`. A changed date restarts the reminders; a past date on a licence blocks at once.

Both: Operations and Safety. 422 for papers that aren't approved.

## 5. Settings (should do)

`GET`/`PUT /admin/settings/driver-documents` (Operations):

| Field | Default | Control |
|---|---|---|
| `reminders_enabled` | `true` | Toggle |
| `reminder_days` | `[30, 14, 7, 1]` | Up to 6 numbers, 1–90 |
| `licence_grace_days` | `0` | Number, 0–30: days after expiry before the block |
| `block_on_expired_licence` | `true` | Toggle (off: expired licences only remind) |

## 6. History (should do)

The driver history (`history` on the driver page) adds these events:

- `document_replaced` ("Driving licence renewed"), with `old.expires_at` and `new.expires_at`
- `document_revoked` ("Driving licence withdrawn", with `reason`)
- `document_details_updated`
- `licence_number_changed` (`licence_number_last4` only)
- `driver_documents_blocked` (with `reason`) and `driver_documents_unblocked`

`actor: null` on blocks means the system did it.

## 7. Drivers list (nice to have)

`GET /admin/drivers?documents_blocked=true` lists drivers off the road on their licence.

---

**Fleet drivers:** when a fleet driver's licence block starts, operations also get an email ("Fleet driver off the road").
