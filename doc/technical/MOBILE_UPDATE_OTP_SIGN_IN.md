# Phone Sign-In Codes: What the Apps Need to Change

**Date:** November 5, 2026
**Backend:** PR #151 (`fix/otp-delivery-and-verify`); live on staging once merged
**Prepared for:** Passenger app team and driver app team

During the live test on 4 November, a rider's code was rejected twice as "invalid". The staging logs show the code itself was fine: **both verify requests reached the server without a phone number**, so the server answered `422` "The phone field is required." and the app showed it as an invalid code. Most likely the app lost the number while the rider switched to the SMS app to read the code.

The backend now also cleans up phone numbers and accepts a late code after a resend (details at the end). No endpoint, field or response shape was removed or renamed.

Request and response details are in `API_REFERENCE.md`. Base URL: `/api/v1`.

---

## Summary

| # | Change | Priority |
|---|---|---|
| 1 | Always send `phone` with `POST /auth/otp/verify`, and keep it when the app is backgrounded | **Must do** (the bug) |
| 2 | Send numbers as `+234` and the 10 digits, without the local 0 | Should do |
| 3 | Show the server's `message` instead of a fixed "Invalid code" | Should do |
| 4 | Count down 60 seconds before allowing Resend | Should do |
| 5 | Send `code` as a string | Should do |

The same applies to the passenger and driver apps.

---

## 1. Always send the phone with verify (must do)

`POST /auth/otp/verify` needs the same `phone` that `POST /auth/otp/send` used, plus `code` and `type`.

- Keep the number in state that survives the app going to the background and coming back. Riders leave the app to read the SMS, and Android can recreate the screen when they return. Save it before calling send (screen arguments, saved state or secure storage, for example), not only in a field on the screen.
- If the number is somehow missing, go back to the phone screen rather than calling verify without it.

## 2. Send `+234` and the 10 digits (should do)

Some requests arrived as `+23408…`, with the local 0 kept after the country code. Sendchamp accepts that number but the SMS never arrives. The server now converts `+2340803…`, `0803…`, `234803…` and numbers with spaces to `+234803…`. Still, strip a leading 0 before adding `+234` so the number on screen matches the one the code went to.

## 3. Show the server's message (should do)

Every error has a `message` written for the rider. Show it as it is:

| Status | `message` |
|---|---|
| 422 | `Invalid verification code.` |
| 422 | `No active verification code found. Please request a new one.` |
| 422 | `Too many attempts. Please request a new code.` |
| 422 | A validation message, e.g. `The phone field is required.` (with `error_code: "VALIDATION_ERROR"`) |
| 429 | `Please wait before requesting another code.` |
| 429 | `Failed to send verification code. Please try again.` |
| 429 | `Too Many Attempts.` (rate limit; a `Retry-After` header gives the seconds to wait) |

## 4. Resend countdown (should do)

A phone can get one code every 60 seconds and 5 codes in 10 minutes. Disable Resend for 60 seconds after each send and show the countdown. SMS can take a minute to arrive, so the screen could also say "Codes can take up to a minute to arrive".

## 5. `code` as a string (should do)

Send `"code": "048213"`, not `48213` as a number. A number loses a leading 0 and fails validation.

---

## What changed on the server

- Phone numbers are normalised on send, verify and registration (see 2). Two existing accounts saved as `+2340…` were moved to `+234…`.
- Any of the phone's last 3 unexpired codes is accepted, so a code that arrives after the rider tapped Resend still works. A phone still gets only 5 wrong guesses, and signing in cancels its other codes.
- Rate limits are now per phone number (5 codes per 10 minutes; 10 verify requests a minute), with a looser limit per IP (20 sends and 30 verifies a minute). Previously, everyone sharing one mobile-network IP shared 5 sign-in requests a minute.
- Staging runs with real SMS codes, not `123456`. Local development still uses `123456`.
