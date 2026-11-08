# FRSC Check on Renewed Licences: Admin Dashboard Updates

**Date:** November 8, 2026  
**Backend:** branch `feature/licence-renewal-frsc-check`; live on staging once merged  
**Prepared for:** Admin dashboard team. **Driver app: no changes.**

When a working driver uploads a renewed driving licence, the backend now looks it up in FRSC records through QoreID, the same licence lookup used at onboarding. The reviewer sees whether FRSC knows the licence in the driver's name and what expiry date FRSC has on record. It's a flag for the reviewer: it never approves or rejects on its own, and it isn't a new KYC.

Details: `API_REFERENCE.md` → **FRSC Licence Check (Renewals)**. Postman: **Admin → Admin — Driver Management → Re-run FRSC Licence Check**.

---

## 1. Show the FRSC result in the review dialog (must do)

Every driving licence document now has `frsc_check` for admins (driver page `driver.documents[]`, review responses, renewals queue rows). It is `null` when there's no record.

| `status` | Show |
|---|---|
| `matched` | ✅ "Found in FRSC records in the driver's name" |
| `name_mismatch` | ⚠️ "FRSC has this licence under a different name": check carefully before approving |
| `unavailable` | ⚠️ "FRSC couldn't confirm this licence": check the photo by hand (not found and provider-down look the same) |

Next to the expiry date field, show `frsc_expires_at`. When `expiry_matches` is `false`, highlight it: "The driver entered 20 Dec 2031; FRSC says 20 Nov 2031." Prefill the date field with `frsc_expires_at` when the document has none. If the reviewer approves without a date, the backend uses FRSC's date anyway.

`source: "onboarding_kyc"` means the result comes from the driver's onboarding licence check, not a renewal lookup.

## 2. Renewals queue (should do)

- New tab or filter **"Flagged by FRSC"**: `GET /admin/driver-documents?status=pending&frsc_flagged=true`.
- Use `summary.frsc_flagged` for its badge.

## 3. Re-run button (should do)

On a pending licence whose `frsc_check.status` is `unavailable`, add **Check FRSC again**: `POST /admin/drivers/{driver}/documents/{document}/frsc-check`. It returns the updated `document`, or 422 for anything other than a pending licence. Each run is a paid lookup, so don't call it automatically.

## 4. Settings (nice to have)

`frsc_check_on_renewal` (default `true`) on `GET`/`PUT /admin/settings/driver-documents`: a toggle "Check renewed licences against FRSC".
