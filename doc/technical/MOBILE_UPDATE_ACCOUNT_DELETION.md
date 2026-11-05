# Account Deletion Grace Period: What the Apps Should Know

**Date:** November 5, 2026
**Backend:** PR #146, branch `feat/account-deletion-grace-period`; live on staging once merged
**Prepared for:** Driver app team and passenger app team

`DELETE /auth/account` no longer deletes the account at once. The account is kept for **30 days** and then erased; logging in again within those 30 days cancels the deletion. **No endpoint, field or status value was removed or renamed**, and the request is the same, so the current app keeps working. The changes below are about telling the user the right thing.

Request and response details are in `API_REFERENCE.md` (Delete Account). Base URL: `/api/v1`.

---

## Summary

| App | Must do | Should do | Nice to have |
|---|---|---|---|
| Passenger | Nothing | 1. Show the message the delete returns (and the new 422 reasons); 2. Update the confirmation text | 3. Show "welcome back" when `account_restored` is `true` |
| Driver | Nothing | 1. Show the message the delete returns (and the new 422 reasons); 2. Update the confirmation text | 3. Show "welcome back" when `account_restored` is `true` |

---

## 1. Show the message the delete returns (should do)

**Before:**
```json
{ "message": "Account deleted successfully." }
```

**Now:**
```json
{
  "message": "Your account will be deleted on 5 December 2026. Log in again before then to keep it.",
  "deletion_scheduled_for": "2026-12-05T10:00:00+00:00",
  "grace_days": 30
}
```

- Show `message` as it arrives instead of a fixed "Account deleted" text. If you build your own text, use `deletion_scheduled_for` (ISO 8601, UTC; show it in Lagos time) and `grace_days`.
- As before, every token for the user is revoked by the request, so clear the local session and go to the welcome or login screen. The old token returns 401 from now on.
- Drivers are taken offline by the backend; stop sending location updates as you would on logout.
- Device push tokens are removed, so the user gets no pushes during the 30 days. After logging back in, register the device token again as you do after any login.

The delete is refused with 422 when the user has an active ride or trip, a scheduled ride that has not been dispatched, an outstanding balance (passengers), an assigned scheduled ride or an EV charge in progress (drivers), as before. **New 422 reasons:** money in the passenger wallet; for drivers, a fleet vehicle agreement that is active or paused, earnings not yet withdrawn (pending or available) or money owed to E-tiGo. Show `message` as sent, for example "Cannot delete account while your wallet has ₦2,500.00. Use it on a ride or contact support for a refund first."

## 2. Update the confirmation text (should do)

The confirmation dialog shown before calling the endpoint should say what now happens. Suggested text:

> **Delete your account?**
> You will be logged out on all your devices. Your account will be deleted permanently after 30 days. If you change your mind, just log in again before then and your account will be kept.
> Your trip and payment history is kept without your name or contact details, as the law requires.

The backend refuses the delete while the wallet holds money (passengers) or there are earnings to withdraw (drivers), so no money is lost. The app can mention this in the dialog: "Spend or withdraw any balance first."

## 3. "Welcome back" after a cancelled deletion (nice to have)

Every login response now has an extra field, `account_restored`:

- `POST /auth/otp/verify` (existing user)
- `POST /auth/social` (existing user)
- `POST /auth/social/exchange` (existing user)

```json
{
  "message": "Logged in successfully.",
  "is_new_user": false,
  "account_restored": true,
  "user": { "...": "..." },
  "token": "1|abc123..."
}
```

`true` means this login cancelled a pending deletion (this works until the account is actually erased, at most a day after the date given). Show something like "Welcome back. Your account will not be deleted." It is `false` on every ordinary login, and absent on new-account responses (`is_new_user: true`).

---

## Testing on staging

1. Log in with a test account and delete it. Check the message and that you land on the login screen.
2. Log in again with the same phone number (OTP `123456` outside production). The response has `account_restored: true` and the account works as before.
3. Erasure happens 30 days after the request, at 03:00 Lagos time; there is nothing to test for it on the app side.
