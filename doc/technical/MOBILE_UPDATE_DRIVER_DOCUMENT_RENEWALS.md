# Driver Document Renewals: Driver App Updates

**Date:** November 7, 2026  
**Backend:** PR #162 (`feature/driver-document-renewals`); live on staging once merged  
**Prepared for:** Driver app team. **Passenger app: no changes.**

Drivers renew their papers when the date printed on them runs out, not every year. Nigerian licences run 3 or 5 years. A renewal is a document check by E-tiGo operations, **not a new KYC**: the driver doesn't redo NIN, licence verification or the selfie, and keeps their account, ratings and earnings.

What the backend does now:

- **The licence on file stays valid while the renewal is checked.** Before this change, uploading a new licence replaced the approved one at once.
- **Reminders** go out 30, 14, 7 and 1 days before expiry, on the day, and after expiry, by push, in-app and email.
- **An expired driving licence takes the driver off the road** from the day after it expires. They can't go online and get no offers until operations approve the renewed licence. A trip in progress is never ended.
- Operations can **withdraw** a licence found invalid (for example, suspended by FRSC); that blocks at once.
- Government ID expiry only reminds. It never blocks.

Request and response details: `API_REFERENCE.md` → **Driver Document Renewals**, **Upload KYC Document**, **List Documents** and **Driver — Online Status**. Postman: **Driver → Driver — Onboarding → Upload KYC Document / List Documents**, and **Driver → Driver — Online Status → Toggle Online**. Base URL: `/api/v1`.

---

## Summary

| Must do | Should do | Nice to have |
|---|---|---|
| 1. Renewal upload: send `expires_at` (and `document_number`) for a renewed licence<br>2. Read the paper in force and `renewal` from `GET /driver/documents`<br>3. Show the go-online refusal reason and `document_notice` | 4. A "Documents" screen with the expiry state and an upload button<br>5. Handle the new pushes<br>6. Stop editing the licence number in the profile after approval | 7. A banner on the home screen from `compliance.action_required` |

---

## 1. Uploading a renewal (must do)

Same endpoint, same `type`: `POST /driver/documents` (multipart).

| Field | When |
|---|---|
| `expires_at` | **Required for a renewed driving licence** (an approved one is already on file), and still for `roadworthiness_certificate` / `vehicle_licence`. Must be after today, and for a licence **later than the licence on file**. Send it at first upload too whenever the paper shows one |
| `document_number` | Optional, the number printed on the paper. Send it when a renewed licence has a new number. E-tiGo makes it the driver's licence number when they approve it |

Errors to show as they come (422, under `errors.expires_at`):

- `The expires at field is required.` for a renewed licence without a date.
- `Your licence on file is valid until 20 Nov 2026. Upload the renewed licence, which expires later.`

Collect the date with a date picker that only allows future dates. The response's `document.status` is now `"pending"` (it used to come back `null`), and `document_number` comes back masked (`"•••• ZZ09"`).

## 2. Reading the documents list (must do)

`GET /driver/documents` still returns one item per required type. Changes:

- While a renewal waits for review, the item shows the **approved paper in force** (`status: "approved"`). The upload is under **`renewal`**: `{ document_id, status: "pending" | "rejected", expires_at, rejection_reason, uploaded_at }`. Don't treat the item as "under review" just because a renewal exists.
- New per item (approved papers only, else `null`): `expiry_status` (`no_expiry`, `valid`, `expiring_soon`, `expired`) and `days_until_expiry` (0 on the expiry date, which is still valid; negative after).
- `total_uploaded` counts types, so it no longer goes above `total_required`.
- New top-level **`compliance`**:

```json
"compliance": {
  "can_drive": false,
  "block_reason": "Your driving licence expired on 7 Nov 2026.",
  "action_required": true,
  "documents": [
    {
      "type": "driving_licence",
      "label": "Driving licence",
      "document_id": "01a1b824-d3e8-729f-9ce6-5a45e6e8143e",
      "expires_at": "2026-11-07",
      "expiry_status": "expired",
      "days_until_expiry": -1,
      "blocks_driving": true,
      "revoked_at": null,
      "renewal": null,
      "action_required": true
    }
  ]
}
```

`renewal.status: "rejected"` means operations turned the renewal down: show `renewal.rejection_reason` and let the driver upload again.

## 3. Going online (must do)

`POST /driver/toggle-online`:

- **Blocked** → 422 with the reason in `reasons`, e.g. `"Your driving licence expired on 7 Nov 2026. Upload your renewed licence; you can go online once E-tiGo approves it."` For a withdrawn licence: `"Your driving licence was withdrawn by E-tiGo: <reason> Upload a valid driving licence; …"`. Show it with a button to the upload screen.
- **Allowed, with a warning** → 200 with a new **`document_notice`** (string or `null`). It's set only when the licence expires within 7 days or is in a grace period; in that window, a pending renewal changes it to "Your renewed driving licence is being checked by E-tiGo." Further out, or with no expiry date on file, it's `null` even if a renewal is pending, so read pending renewals from `GET /driver/documents` (`renewal`). Show it like `vehicle_notice`.

The driver object (`DriverResource`) also has `documents_blocked` (bool), `documents_blocked_at` and `documents_block_reason`.

## 4. Documents screen (should do)

Use `compliance.documents`:

| `expiry_status` | Show |
|---|---|
| `valid` | Valid until {expires_at} |
| `expiring_soon` | "Expires in {days} days", plus an **Upload renewed licence** button |
| `expired` | "Expired {expires_at}". If `blocks_driving` and `can_drive` is false: "You can't go online until your renewed licence is approved." |
| `no_expiry` | Valid (no expiry date on file) |

When `renewal` is pending: "Renewal under review, uploaded {date}". Hide the upload button. When `revoked_at` is set: "Withdrawn by E-tiGo", with the reason from the matching top-level checklist item (`documents[].rejection_reason`, status `rejected`) and an upload button.

## 5. Pushes (should do)

All are in the **Compliance** category. Data values are strings.

| `type` | When | Data |
|---|---|---|
| `document_expiring` | Reminder steps (30/14/7/1 days, the day itself, after expiry while still allowed to drive or for government ID) | `document_id`, `document_type`, `expires_at` |
| `document_reviewed` | Operations approved, rejected or withdrew a paper | `document_id`, `document_type`, `status`, `revoked` (`"true"`/`"false"`) |
| `driver_documents_blocked` | Licence expired past the grace period, or withdrawn. Sent even if notifications are turned off. The app was taken offline unless on a trip | `driver_id` |
| `driver_documents_unblocked` | Renewed licence approved; can go online again | `driver_id` |

On a tap, open the Documents screen and refetch `GET /driver/documents`. On `driver_documents_blocked`, also refresh the online state: the backend may have set the driver offline. Reserved scheduled pickups are released, and the existing `scheduled_ride_reservation_released` push says why.

## 6. Licence number in the profile (should do)

After approval, `PUT /driver/profile` with a **different** `licence_number` returns 422: `To change your licence number, upload your renewed licence with its number. E-tiGo updates it once the licence is approved.` Make the field read-only once the driver is approved, and send the new number as `document_number` with the renewed licence instead.

## 7. Home banner (nice to have)

When `compliance.action_required` is true, show a banner such as "Your driving licence expires in 7 days. Upload the renewed licence." It can link to the Documents screen.

---

**Testing on staging:** ask operations (or use an admin token) to set an approved licence's expiry with `PATCH /admin/drivers/{driver}/documents/{document}` (`expires_at` a few days ahead, or in the past to see the block). Then run `php artisan drivers:check-document-expiry` on the server, or wait for the 06:00 Lagos run.
