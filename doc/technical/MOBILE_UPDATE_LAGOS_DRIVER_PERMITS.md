# Lagos LASDRI and LASSRA Cards: Driver App Updates

**Date:** November 8, 2026  
**Backend:** PR #171 (`feature/lagos-driver-permits-v2`); live on staging once merged  
**Prepared for:** Driver app team. **Passenger app: no changes.**

Drivers in Lagos now also need two Lagos State papers: the **LASDRI card** (Lagos State Drivers' Institute) and the **LASSRA card** (Lagos State Residents Registration Agency ID). They're required per city, so only Lagos drivers see them for now. Operations can add them to other cities later.

Details: `API_REFERENCE.md` → **City-Required Driver Documents (Lagos LASDRI and LASSRA)**. Postman: **Driver → Driver — Onboarding → List Documents** (example "Lagos Fleet Driver, LASDRI Card Missing").

---

## 1. Don't hard-code the document list (must do)

`GET /driver/documents` returns the checklist for the driver's city. A Lagos driver gets 2 extra items after the usual ones:

| type | name | requires_expiry |
|---|---|---|
| `lasdri_card` | LASDRI card | `true` |
| `lassra_card` | LASSRA card | `false` |

So a Lagos fleet driver has 4 items and a Lagos own-car driver 6. `GET /driver/onboarding/status` → `required_documents` has the same list. Render every item from the response, using its `name` and `description`, rather than a fixed list of 2 or 4.

## 2. Upload (must do)

Same endpoint: `POST /driver/documents` with `type=lasdri_card` or `type=lassra_card`.
- `lasdri_card` needs `expires_at`; without it you get 422 under `errors.expires_at`. Show a date picker as you do for the licence.
- `lassra_card` takes `expires_at` only if the card shows one.

A Lagos applicant can't submit until both are uploaded: `POST /driver/onboarding/submit` returns 422 `Missing documents: lasdri_card, lassra_card`.

## 3. Drivers already working in Lagos (should do)

They are **not blocked** for missing cards; they can keep driving. In `compliance.documents`, a missing card appears as:

```json
{ "type": "lasdri_card", "label": "LASDRI card", "missing": true, "required_by_city": true, "action_required": true, "document_id": null }
```

Show it on the Documents screen with an **Upload** button, and include it in the home banner when `compliance.action_required` is true. Every compliance entry now has `missing` and `required_by_city`.

## 4. New push (should do)

`document_required` (Compliance category) is sent every 14 days while a required card is missing. Body example: "Drivers in Lagos need their LASDRI card on E-tiGo. Upload it in the app under Documents." Data: `document_types`, e.g. `"lasdri_card"` or `"lasdri_card,lassra_card"`. On tap, open the Documents screen.

An expiring LASDRI card gets the usual `document_expiring` reminders. It never blocks driving.
