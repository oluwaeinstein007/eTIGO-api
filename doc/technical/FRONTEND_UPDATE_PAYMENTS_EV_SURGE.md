# Payments, Payouts, EV and Surge: App and Dashboard Updates

**Date:** October 16, 2026  
**Backend:** `staging`, PR #75 (Phase 1 audit) and PR #81 (follow-ups), both live on staging  
**Prepared for:** Passenger app, driver app and admin dashboard teams

Request and response details are in `API_REFERENCE.md`; every new response has a saved example in the "E-tiGo API v1" Postman collection (use the "E-tiGo — Staging" environment). Base URL: `/api/v1`.

---

## Summary

| App | Must do (breaking) | Should do |
|-----|--------------------|-----------|
| Passenger | Verify top-ups with `tx_ref`; send the same `type` you verified with at registration | Tip wallet and bank-transfer rides (wallet/card choice); show the card-tip messages; optional priority-matching perk; PIN regenerate |
| Driver | Adding or changing the bank account needs an OTP; `fuel_type` required when registering a vehicle; EV charging only when `vehicle.can_charge` | Fuel type picker and prompt; EV fee and free-cancellation countdown; payout statuses; `ev_reservation_cancelled`, `vehicle_fuel_type_required` and `payout_account_changed` notifications |
| Admin dashboard | `fuel_type` required on fleet vehicle creation | Payout **Check status** button with `needs_attention` / `attention_reason`; Retry for `reversed`; fuel type on drivers and fleet; live surge demand |
| All | New error codes; session expiry (app 30 days, admin 8 hours) | |

---

## Breaking Changes

### 1. Top-up verification uses `tx_ref` (passenger)

`POST /passenger/wallet/topup` returns `payment_link` and `tx_ref`. After the passenger returns from the Paystack checkout, call `GET /passenger/wallet/topup/{tx_ref}/verify` with that `tx_ref` (not Paystack's transaction id). Another passenger's reference returns 404. Calling it twice is safe ("Top-up has already been credited."), and Paystack's webhook credits the top-up even if the app never calls it.

### 2. Adding or changing the payout bank account needs a code (driver)

Every `POST /driver/bank-account` requires `otp_code`, including the driver's **first** account (updated after the first version of this guide, which exempted the first account). Request it with `POST /driver/bank-account/otp` (3 per minute), then send it with `POST /driver/bank-account`. Without it the request returns 422 `errors.otp_code`. Use one flow: resolve → confirm the name → send the code → enter the code → save. If Paystack cannot verify the account on save, the code is not used up: let the driver fix the details and retry with the same code (while it is still valid, 5 minutes).

After every save the driver gets a **new notification** `payout_account_changed` (in-app and push, data: `bank_account_id`, `replaced_another`) and a WhatsApp/SMS message that is sent whatever their notification settings are. On tap, open the bank account screen.

### 3. Registration is bound to the verified type (both apps)

`POST /auth/register/complete`: `type` must match the type the phone was verified for, and one verification completes one registration. Otherwise 403.

### 4. Vehicles must state their fuel type (driver, admin)

- `fuel_type` is **required** on `POST /driver/vehicle` and `POST /admin/fleet-vehicles`: `petrol`, `diesel`, `cng`, `hybrid`, `plug_in_hybrid` or `electric`. Missing → 422 `errors.fuel_type`.
- Vehicle responses include `fuel_type` and `can_charge` (true for `electric` and `plug_in_hybrid`).
- After the application is submitted, `PUT /driver/vehicle` refuses a new `vehicle_class_id` (422 `errors.vehicle_class_id`) and a change to a declared `fuel_type` (422 `errors.fuel_type`). Lock those fields and point the driver to support.
- `POST /driver/onboarding/submit` returns 422 "Your vehicle's fuel type is required." for a vehicle without one, and `GET /driver/onboarding/status` reports `can_submit: false` until it is set.

### 5. EV charging is for plug-in vehicles only (driver)

Stall reservations and charge requests are refused (422) unless the vehicle can charge. Use `vehicle.can_charge` to show or hide the EV screens.

### 6. Error codes and sessions (all)

- 409 `INVALID_STATE_TRANSITION` with `current_status` when a ride or SOS changed state under the request: refresh and redraw.
- 422 `INVALID_PARAMETER` for malformed ids or dates (previously 500). Cash over-payment errors are 422 (previously 500).
- App tokens expire after 30 days, admin tokens after 8 hours (the telemetry WebSocket enforces the same). On 401, send the user back to sign in.

---

## Payouts and Paystack

The apps never call Paystack for payouts: the server sends every transfer and the apps read the payout's `status`. When Paystack times out or answers with a server error, the transfer may or may not exist, so the money stays reserved and the payout stays `approved` or `processing` until Paystack's answer is known. The server checks every 15 minutes, finance can check at any time, and a transfer is never sent twice. Payouts can therefore stay in progress longer than before.

| Status | Meaning | Driver app could say | Admin action |
|--------|---------|----------------------|--------------|
| `requested` | Waiting for finance | Waiting for approval | Approve or reject |
| `approved` | Being sent; money reserved | Approved, sending to your bank | Check status if flagged |
| `processing` | Paystack accepted it; waiting for the bank | On its way to your bank | Check status if flagged |
| `paid` | The bank confirmed it (`paid_at`) | Paid | None |
| `failed` | Paystack refused it; money going back to the balance | Didn't go through. The money is back in your balance. | Retry |
| `reversed` | Money back in the driver's balance | Returned. The money is back in your balance. | Retry |
| `rejected` | Finance declined; nothing left the balance | Declined | None |

### Admin dashboard (Finance › Payouts)

- **`needs_attention`** (boolean) on every admin payout: show a warning badge.
- **`attention_reason`** explains it:
  - `awaiting_otp`: Paystack is holding the transfer for an OTP. Turn off transfer OTP in the Paystack dashboard, then check status. Flagged immediately.
  - `not_sent`: approved over 10 minutes ago and not sent.
  - `unconfirmed`: processing for over 30 minutes without the bank's confirmation.
- **`provider_status`**: Paystack's last reported status (`pending`, `otp`, `success`, ...).
- **Check status**: `POST /admin/payouts/{payout}/verify` (finance). Show `message` as the toast and replace the row with `payout`.

| `outcome` | `message` | Payout afterwards |
|-----------|-----------|-------------------|
| `paid` | The transfer went through; the payout is now paid. | `paid` |
| `failed` | The transfer failed; the money was returned to the driver. You can retry the payout. | `failed`, then `reversed` seconds later; show Retry |
| `processing` | The transfer is still being processed by the payment provider. | `processing` |
| `resent` | The transfer was never created; it has been sent again. | `approved`, then `processing` |
| `unknown` | The transfer status could not be confirmed; it will be checked again automatically. | Unchanged |

502 "Could not reach the payment provider. Try again shortly." means nothing changed; keep the button enabled. 422 with `current_status` means the row is stale; refresh it.

- **Retry** (`POST /admin/payouts/{payout}/retry`) works for `failed` and `reversed`; other statuses get 422 "Only failed or reversed payouts can be retried." It checks the earlier transfer first, so it never pays twice.
- Please don't add a "mark as paid" control; Check status is the safe, audited way to settle a stuck payout.

### Driver app

- Show `approved` and `processing` as in progress however long they take; show a failure only for `failed` or `reversed`, and say the money is back in the balance.
- `failure_reason` is the provider's wording for support; show your own short message.
- Push types: `payout_approved`, `payout_paid`, `payout_failed`, `payout_rejected`. Refresh `GET /driver/payouts` when one arrives.

---

## Tips (passenger)

`POST /rides/{ride}/tip` with `amount` (naira, within `GET /rides/tip-settings`) and optional `source`: `wallet`, `card` (default saved card) or `cash`. `source` defaults to the ride's payment method, so card, wallet and cash rides need nothing new. **Bank-transfer rides must send `source`** (`wallet` or `card`): ask "Tip from wallet or card?". The driver receives the full amount; `payment.tip_method` shows how the tip was paid.

| Response | `message` | Suggested handling |
|----------|-----------|--------------------|
| 422 | Choose how to pay the tip: wallet or card. (`errors.source`) | Show the wallet/card choice |
| 422 | Cash tips can only be recorded on cash rides. (`errors.source`) | Only offer cash on cash rides |
| 422 | Your wallet balance is too low for this tip. | Offer a top-up or a smaller amount |
| 422 | Wallet is frozen or closed. | Point to support |
| 422 | No default payment method on file to charge tip. | Ask the passenger to add a card |
| 422 | Failed to charge tip amount. | The card was declined; nothing was charged; they can try again |
| 422 | **We could not confirm your tip payment with the bank yet. Please try again in a few minutes; you will not be charged twice.** | Show as is. The bank did not answer; the tip is held while the server checks. Let them try again later |
| 422 | **Your earlier tip went through, so you were not charged again.** | Treat as success; refresh the ride's payment |
| 422 | A tip has already been added to this ride. | Show the existing tip |

---

## EV Charging (driver)

- Only vehicles with `can_charge: true` can reserve a stall (`POST /driver/ev-reservations/stations/{station}/reserve`) or submit a charge request.
- **Fee:** `fee_amount` (₦500) is taken from the driver's earnings when a stall is actually held: at once for a `reserved` outcome, or when a queued driver is given a stall. Nothing is charged while queued. Platinum and Diamond drivers pay nothing (`fee_waived: true`). The fee may take earnings below zero down to the configured limit (₦5,000 today).
- New reservation fields: `fee_charged_at`, `fee_refunded_at`, `free_cancellation_until`. Cancelling before `free_cancellation_until` refunds the fee; show a countdown from that field rather than hard-coding 2 minutes. After it passes, warn that cancelling keeps the fee; a no-show keeps it too.

| Response | `message` |
|----------|-----------|
| 422 | EV charging is only available for electric and plug-in hybrid vehicles. Set your vehicle's fuel type if it is one. |
| 422 | Your earnings balance cannot cover the ₦500.00 reservation fee. |
| 422 | Your earnings account is on hold, so the reservation fee cannot be paid. Contact support. |

- Push `ev_reservation_cancelled` (data: `reservation_id`, `station_name`): a stall became free but the fee could not be taken, so the queued reservation was cancelled; the reason is in the reservation's `notes`. `ev_reservation_ready` is unchanged.
- Earnings history (`GET /driver/ledger/transactions`): the fee is a debit with `journal.metadata.category` `ev_reservation_fee`; a refund is a credit described "EV stall reservation fee refund…"; tips are credits with category `tip`.

---

## Fuel Type (driver, admin)

- **Driver app:** fuel type picker on vehicle registration (required) and the vehicle screen. Vehicles registered earlier have `fuel_type: null`: prompt those drivers to set it (a driver can declare it once; after that only E-tiGo can change it).
- **New notification** `vehicle_fuel_type_required` (push and in-app): on tap, open the vehicle screen. It was sent to the drivers concerned on staging on 15 October.
- **Admin dashboard:**
  - Show the fuel type on the driver's vehicle; operations change it with `PUT /admin/drivers/{driver}/vehicle-class`, which takes `vehicle_class_id`, `fuel_type` or both. A class change is refused while the driver is on a ride (409); a fuel type change is not.
  - Fleet vehicle forms must send `fuel_type`.
  - `GET /admin/drivers` and `GET /admin/fleet-vehicles` take `fuel_type`; `fuel_type=none` lists vehicles without one ("Fuel type missing" filter). Unknown values → 422.

---

## Surge (admin)

Demand-based surge rules now work. Demand is the passengers who booked in the city (and class) in the last 10 minutes; supply is the free, recently located drivers there.

- `GET /admin/surge-rules/current-multiplier` returns `demand`: `requests`, `available_drivers`, `ratio`, `window_minutes`. `available_drivers` and `ratio` are `null` when driver locations could not be read (demand rules do not fire then). Show these figures on the surge screen.
- Demand rules accept `conditions.min_requests` (default 5): add it to the rule form next to `min_demand_supply_ratio`.

---

## Smaller Changes

- **Priority matching (passenger, optional):** Gold, Platinum and Diamond passengers get priority matching, locked in at booking. Nothing to call; show it as a perk from `gamification.benefits.priority_matching` on `GET /gamification`.
- `POST /rides/{ride}/pin/regenerate` (passenger) issues a new ride PIN; a PIN is valid for 6 hours.
- `GET /driver/ledger/transactions/{entry}` returns one transaction; the list takes `per_page` and `page`.
- Fare estimates include `booking_fee_discount`; rides include `reference_number`.
- Promo codes are checked against the ride (city, class, fare, time window, tier, usage limits); show the 422 `message` when one doesn't apply.
- `is_fleet` is always a boolean.
