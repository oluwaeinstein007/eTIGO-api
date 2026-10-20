# Compliance Flags: Driver App Updates

**Date:** October 20, 2026  
**Backend:** PR `feature/offline-flags-design-alignment`; live on staging once merged  
**Prepared for:** Driver app team. **Passenger app: no changes.**

The admin compliance screens changed (appeals, verified cash-taking, a reason for every deactivation, route maps), and the driver's compliance data changed with them. Nothing here breaks the current app. Two things show the wrong state until you update: the active sanction after an uphold, and a flag reversed on appeal.

Request and response details are in `API_REFERENCE.md` → **Anti-Offline Trip Detection — Driver Compliance**. Base URL: `/api/v1`.

---

## Summary

| Must do (breaking) | Should do | Nice to have |
|---|---|---|
| None | Show the Level 3 response deadline; read the new `active_sanction` fields; don't treat `upheld` as final; handle the new push texts | Show `deactivation` and `cash_taking` on a flag |

---

## 1. Active sanction (should do)

`GET /driver/compliance` → `compliance.active_sanction` used to be `null` as soon as an admin upheld the flag, even though the driver was still suspended. It now comes from the driver's **latest flag that has not been overturned**, upheld or not, flagged in the last 30 days (or any age once its deactivation was confirmed).

New fields on `active_sanction`:

```json
{
  "flag_id": "uuid",
  "tier": { "value": 2, "label": "48-Hour Suspension" },
  "action": "suspended_48h",
  "status": { "value": "upheld", "label": "Upheld" },
  "flagged_at": "2026-10-19T19:58:49.000000Z",
  "is_disputed": true,
  "deactivation_confirmed": false,
  "deactivation_response_due_at": null
}
```

- `flag_id`: open that flag from the banner.
- `status`: `flagged`, `disputed`, `upheld` (or `overturned`, which never appears here).
- `deactivation_confirmed`: `true` once operations confirmed a Level 3 deactivation. Show the deactivated state, not a countdown.
- `deactivation_response_due_at`: see section 2a.
- `action` can now be `deactivated` as well as `warning_issued`, `suspended_48h` and `pending_admin_review`.

For the suspension countdown, the 48 hours run from the driver profile's `suspended_at` (not `flagged_at`: a flag escalated to Level 2 later suspends from the escalation).

## 2. Flags can change after review (should do)

- An **upheld** flag can later become **overturned**: operations can overturn it on appeal. Refresh the flag list whenever a `compliance_warning` push arrives; don't cache `upheld` as final.
- An **overturned** flag is final.
- A flag can jump to **Level 3** after the driver saw it at Level 1 or 2: operations verified that the driver took cash for the trip.

The driver still disputes in the app (`POST /driver/flags/{flag}/dispute`), once, before review. A driver who wants to appeal an upheld flag contacts support; there is no appeal endpoint for drivers.

## 2a. Time to respond to a Level 3 flag (should do)

When a flag reaches Level 3, the driver has **48 hours** to dispute it before operations can decide on deactivation. The deadline is `deactivation_response_due_at`, on `active_sanction` and on the flag in `recent_flags` (Level 3 only).

- Show it prominently: "You have until 22 Oct, 15:10 to dispute this flag", with the Dispute button.
- Disputing within the window means operations must review the dispute before any decision.
- After the deadline the driver can still dispute until operations reviews the flag, but they may decide at any point after it.
- The "Account Suspended — Under Review" push now says how long the driver has, and its `data` has `response_hours` (48).

## 3. New fields on each flag (nice to have)

Flags in `recent_flags` have these when they apply (no admin notes or names are sent to the driver):

| Field | Shape | Show as |
|---|---|---|
| `deactivation` | `{ "confirmed_at": "...", "reversed_at": null }` | "Account deactivated on 18 Oct". When `reversed_at` is set: "Deactivation reversed on appeal" |
| `cash_taking` | `{ "verified_at": "..." }` | "Confirmed that cash was taken for this trip" (explains why the flag is Level 3) |

## 4. Push notifications (should do)

All have `data.type: "compliance_warning"`. New or changed texts:

| Title | When | `data` |
|---|---|---|
| Account Reinstated | A flag was overturned and nothing else keeps the driver suspended. On appeal of a deactivation the body says "Your appeal was successful…" | `reinstated: true` |
| Flag Overturned | **New.** A flag was overturned but another flag keeps the driver suspended | `reinstated: false` |
| Account Suspended — Under Review | Body now says the driver has 48 hours to dispute in the app; also sent when cash-taking is verified (flag moved to Level 3) | `pending_deactivation: true`, `response_hours: 48` |
| Account Deactivated | Unchanged | `deactivated: true` |

On any of these, refresh `GET /driver/compliance` and the driver's status.

## 5. Location after a cancellation (no change, worth knowing)

The driver's positions for 15 minutes after a cancellation near the pickup are now **kept with the flag** and shown to operations on a map, with GPS accuracy. Keep sending `accuracy` with location updates (`POST /driver/location`). Low-accuracy points are shown as such, which helps drivers in a dispute.

Product/legal: the driver terms and privacy notice should say that location after a cancellation near the pickup is used to check for off-app trips.
