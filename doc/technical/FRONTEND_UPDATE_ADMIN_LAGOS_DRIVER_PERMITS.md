# Lagos LASDRI and LASSRA Cards: Admin Dashboard Updates

**Date:** November 8, 2026  
**Backend:** PR #171 (`feature/lagos-driver-permits-v2`); live on staging once merged  
**Prepared for:** Admin dashboard team.

Drivers in Lagos now also need the **LASDRI card** and the **LASSRA card**. Which papers a city requires is a city setting, and Lagos has both turned on.

Details: `API_REFERENCE.md` → **City-Required Driver Documents (Lagos LASDRI and LASSRA)** and **Update City**. Postman: **Update City** (example "Lagos Requires LASDRI and LASSRA").

---

## 1. New document types (must do)

Anywhere you map document types to labels or icons (driver page, review dialog, renewals queue type filter), add:
- `lasdri_card` → "LASDRI card". It has an expiry date: approving one without a date returns 422, as for a licence, so keep the date field on the approve dialog.
- `lassra_card` → "LASSRA card".

Review them with the usual approve/reject dialog. They never block a driver.

## 2. City setting (should do)

Every city response now has `required_driver_documents` (an array, empty when none). On the city edit form, add checkboxes for the LASDRI card and the LASSRA card, sent as `PUT /admin/cities/{city}` with `{ "required_driver_documents": ["lasdri_card", "lassra_card"] }`. Send `[]` to require none. Any other value returns 422.

## 3. Driver page (nice to have)

`document_compliance.documents` entries now have `missing` and `required_by_city`. Show a missing Lagos card as "Missing (required in Lagos)". Drivers already working in Lagos aren't blocked; the app reminds them every 14 days.

**Applications already submitted** before this went live can still be approved without the cards; those drivers then get the reminder.
