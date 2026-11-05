# Private File Links: What the Apps Should Know

**Date:** November 5, 2026
**Backend:** branch `fix/encrypt-kyc-and-private-uploads`; live on staging once merged
**Prepared for:** Driver app, passenger app and admin dashboard teams

Uploaded documents and evidence are now private. **No endpoint, field name or upload request changed.** Only the links in the responses look and behave differently: they are signed and expire after 30 minutes. Profile photos are unchanged.

Request and response details are in `API_REFERENCE.md` (see **File links** at the top). Base URL: `/api/v1`.

---

## Summary

| App | Must do | Should do |
|---|---|---|
| Driver | Nothing, if the app shows links straight from the latest response | 1. Don't store file links; fetch again after 30 minutes or on a failed load |
| Passenger | Nothing, if the app shows links straight from the latest response | 1. Same |
| Admin dashboard | Nothing, if pages show links from the latest response | 1. Same, plus 2. reload a detail page that has been open over 30 minutes |

Nobody has to change how files are **uploaded**.

---

## Which links changed

| Private now (signed, expires in 30 minutes) | Still public (unchanged) |
|---|---|
| Driver documents and vehicle papers (`file_url`) | `profile_photo_url` (passengers and drivers) |
| Dispute evidence (`evidence[].url`, `evidence_photos`, `evidence_audio`) | |
| Lost & Found photos (`photos`) | |
| Repair request attachments (`attachments[].file_url`) | |
| Vehicle maintenance proofs (`proof_url`) | |

**Before:** `https://res.cloudinary.com/{cloud}/image/upload/driver-documents/…/licence.jpg`. It never expired, and anyone with the link could open the file.

**Now:** `https://api.cloudinary.com/v1_1/{cloud}/image/download?…&expires_at=…&signature=…&type=authenticated`.

- It opens the file inline with the right content type (`image/jpeg`, `application/pdf`, audio), so image views, PDF viewers and audio players work as before.
- It returns **401** after it expires. The old public link now returns **404**.

---

## 1. Don't keep file links (should do, all apps)

- Show the link from the response you just got. Don't save it to local storage, a database or app state that outlives the screen.
- If an image or file fails to load (401), fetch the record again (for example `GET /driver/documents`, the dispute, the lost item or the repair request) and use the new link.
- Each response carries a new link for the same file. An image cache keyed by URL downloads the file again, which is fine.
- Don't add Cloudinary transformations (such as resizing with `w_200`) to these links; the signature would no longer match. Resize on the device if you need to.

## 2. Admin dashboard: long-open pages (should do)

A driver review, dispute or lost-item page left open for more than 30 minutes shows broken images until it reloads. Reloading the record when an image fails, or when the tab regains focus, is enough.

---

## Also in this change (no app work)

Driver ID numbers (NIN, licence, plate) and the personal details KYC matched are now encrypted in the database. The API returns the same fields and values as before.
