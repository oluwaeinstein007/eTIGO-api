# Admin Two-Step Sign-in: What the Dashboard Needs

**Date:** November 5, 2026
**Backend:** branch `fix/admin-two-factor-login`; live on staging once merged
**Prepared for:** Admin dashboard team (the driver and passenger apps are not affected)

Admins can be asked for a 6-digit code from their email after their password. **It is off until the dashboard is ready.** The server switches it on with `ADMIN_TWO_FACTOR_ENABLED=true`, and we will only do that once this screen ships. Until then, login works exactly as today.

Request and response details are in `API_REFERENCE.md` (**Admin Login**, **Verify Sign-in Code**, **Resend Sign-in Code**). Base URL: `/api/v1`.

---

## Summary

| Must do | Should do |
|---|---|
| 1. Read `two_factor_required` on the login response and show a code screen when it is `true` | 2. Resend link with a 60-second countdown; 3. clear messages for wrong and expired codes |

---

## 1. Login can return a challenge instead of a token (must do)

`POST /admin/auth/login` now always includes `two_factor_required`.

- **`false`:** the response has `user` and `token`, as today. Sign in as now.
- **`true`:** no token. The response has `challenge_token`, `email_hint` (such as `a***n@etigo.com`) and `expires_at`. Keep `challenge_token` in memory only (not local storage) and show the code screen.

**Code screen:** "Enter the 6-digit code we sent to a***n@etigo.com". It needs one field (6 digits, numeric keyboard, paste allowed) and a **Verify** button. Send:

```
POST /admin/auth/login/verify
{ "challenge_token": "…", "code": "482910" }
```

A 200 response is the same as a normal login (`user`, `token`). Continue exactly as you do after login today.

| Response | Show |
|---|---|
| 422 "That code is not right…" | The message under the field; let them try again |
| 422 "This sign-in code has expired. Sign in again." | Go back to the password screen with the message |
| 429 "Too many wrong codes. Sign in again." | Go back to the password screen with the message |
| 403 deactivated | Same as the 403 on login today |

The code lasts 10 minutes. After 5 wrong codes the admin has to enter their password again.

## 2. Resend (should do)

A "Send a new code" link, disabled for 60 seconds after the code was sent, with a countdown:

```
POST /admin/auth/login/resend
{ "challenge_token": "…" }
```

A 200 response means a new code was sent and the old one no longer works. A 429 response carries the reason in `message` (and `Retry-After` when it is just too soon). Show the message. After 3 resends, the admin has to sign in again.

## 3. Testing

On staging and locally the code is always **123456**, and the email is still sent. Turn the setting on for your environment with `ADMIN_TWO_FACTOR_ENABLED=true`, or ask the backend team to do it on staging.

Nothing else changes: the invitation accept and password reset flows are the same, and tokens last 8 hours as before.
