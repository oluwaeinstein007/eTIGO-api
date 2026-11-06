# Admin Change Password: What the Frontend Needs to Know

**Date:** November 6, 2026
**Backend:** branch `feat/admin-change-password`
**Prepared for:** Admin dashboard (Timonwa)

Signed-in admins can now change their password without logging out and using Forgot Password. Request and response details are in `API_REFERENCE.md` (**Change Password**) and the Postman collection (**Admin Change Password**). Base URL: `/api/v1`.

## The endpoint

```
POST /admin/auth/change-password
Authorization: Bearer {admin token}
```

```json
{
  "current_password": "OldPassword1",
  "password": "NewPassword2",
  "password_confirmation": "NewPassword2"
}
```

`200` → `{ "message": "Password changed successfully." }`

## What happens on the backend

1. The current password is checked first. A wrong one returns `422` on `current_password` and changes nothing.
2. The new password must be 8-72 bytes (bcrypt ignores the rest; set `maxlength` accordingly), match the confirmation, and differ from the current one.
3. **This device stays signed in.** Do not log the admin out or ask for a new token after success.
4. Every **other** session (other browsers, other devices) is signed out, so a stolen session cannot outlive the change. Those devices get `401` on their next call.
5. Any pending forgot-password link stops working.
6. The admin gets a "your password was changed" email with a reset link, in case it was not them.
7. The change and wrong-current-password attempts are written to the audit log.

## Errors to handle

| Status | When | Show |
|---|---|---|
| 422 `current_password` | Wrong current password | Under the current-password field |
| 422 `password` | Too short, over 72 bytes, not confirmed, same as current | Under the new-password field |
| 429 | More than 5 attempts a minute | Use the `Retry-After` header |
| 401 | Session expired or signed out elsewhere | Send to login |
| 403 | Not an admin token (cannot happen from the dashboard) | Generic error |

## Suggested form

- Three fields: current password, new password, confirm new password. Show/hide toggles on each.
- Check the confirmation on the client, but rely on the server response for everything else.
- On success: toast "Password changed. Other devices were signed out." and clear the form. Keep the user on the page.
- Do not store or log the passwords.

## Other apps

**Driver app and passenger app: no changes.** They sign in with a phone code and have no passwords; this endpoint is admin-only and rejects their tokens with `403`.
