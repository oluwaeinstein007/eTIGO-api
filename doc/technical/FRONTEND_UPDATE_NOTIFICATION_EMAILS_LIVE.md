# Notification Emails: Now Actually Sending

**Date:** November 7, 2026
**Backend:** PR #160 (`feat/wire-dead-notifications`); live on staging once merged
**Prepared for:** Passenger app, driver app, and admin dashboard teams

An audit of every email/push notification in the backend found several email templates that were fully built (and visually correct) but never actually triggered by any real action — they only existed as test tooling. This PR wires 6 of them into the real flows that should send them. **No API request or response shapes changed** — these are side effects of existing endpoints, not new endpoints or fields. No Postman or `API_REFERENCE.md` update was needed.

---

## Summary

| Email | Now fires when | Link in the email | Team affected |
|---|---|---|---|
| Driver approved | Admin approves a pending driver | `{FRONTEND_URL}/driver` | Driver app — confirm this route/deep link exists |
| Passenger welcome | Passenger completes registration (if they gave an email) | `{FRONTEND_URL}/rides/book` | Passenger app — confirm this route exists |
| Driver onboarding received | Driver completes registration (if they gave an email) | `{FRONTEND_URL}/driver/application` | Driver app — confirm this route exists |
| Account deactivated | Admin deactivates an admin account, **or** a driver is permanently deactivated after a compliance review | No link (informational only) | None — no action needed |
| Payment receipt | A ride payment is captured — cash, wallet, card, or bank transfer | `{FRONTEND_URL}/payments/{reference}` | Passenger app — confirm this route exists |
| Driver document expiring | Daily job (06:00 Lagos): 30, 14, 7 and 1 days before expiry, on the day, and once expired, with a matching push (updated 7 Nov 2026, see `MOBILE_UPDATE_DRIVER_DOCUMENT_RENEWALS.md`) | `{FRONTEND_URL}/driver/documents` | Driver app — confirm this route exists |

`{FRONTEND_URL}` must be set to the deployed frontend origin before these emails go out for real — it isn't set in `.env.example`, and if it's left unset in an environment, every link above falls back to `http://localhost:3000/...`. Confirm the actual deployed value with backend/DevOps.

---

## Action needed

Nothing is breaking. This is a heads-up, not a migration:

1. If any of the five routes above don't exist yet in your app, the email's link/button will 404 for real users starting now (previously these emails never sent, so nobody noticed). Prioritize `/payments/{reference}` and `/driver/documents` since those are the two most likely to be missing.
2. If you were relying on these email types being silent for testing, they are no longer silent — expect real emails from `alerts@useanthyx.com` once this merges and these flows run on staging/production.

No other changes. Ping backend if a route doesn't line up with what's listed here.
