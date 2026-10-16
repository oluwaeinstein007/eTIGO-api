# Adding a Card Now Tops Up the Wallet: Passenger App Updates

**Date:** October 16, 2026  
**Backend:** PR `feat/add-card-credits-wallet`; live on staging once merged  
**Prepared for:** Passenger app team

When a passenger added a card, they were charged ₦100 and that money went nowhere: it was not credited to their wallet or refunded. Now the add-card payment is a wallet top-up. The ₦100 lands in the passenger's wallet and the card is saved.

Request and response details are in `API_REFERENCE.md` → **Payment Methods** → **Initialize Payment (add a card)** and **Verify Payment**. Base URL: `/api/v1`.

---

## Summary

| Must do (breaking) | Should do | Nice to have |
|--------------------|-----------|--------------|
| Nothing. The current add-card flow keeps working | Stop sending `amount`/`currency`; tell the passenger the amount goes to their wallet; refresh the wallet balance after adding a card | "Save this card" toggle on the top-up screen |

---

## 1. Add-card screen (should do)

`POST /payments/initialize` now sets the amount itself (₦100 by default; finance can change it). Anything you send in `amount` or `currency` is ignored, so you can stop sending them. Only `redirect_url` is required.

The response has two new fields. Use `amount` in the copy rather than hard-coding ₦100:

```json
{
  "payment_link": "https://checkout.paystack.com/7xk2abc9mq",
  "tx_ref": "TOPUP-RC20VVPGGCCI",
  "amount": 100,
  "currency": "NGN"
}
```

Suggested copy: "We'll charge ₦100 to confirm your card. It goes straight into your E-tiGo wallet."

`tx_ref` now starts with `TOPUP-` instead of `ETIGO-`. Treat it as an opaque string.

## 2. After checkout (should do)

Call `GET /payments/{tx_ref}/verify` as before. New fields in the response:

| Field | Meaning |
|-------|---------|
| `card_saved` | `true` when the card was saved. `false` if the passenger paid by bank transfer/USSD or the card can't be charged again |
| `payment_method_id` | The saved card's ID in `GET /payment-methods` |
| `wallet_credit` | Naira credited to the wallet (`0` if the payment failed) |

`authorization_code` and `transaction_id` are no longer returned. They were never meant for the app.

The wallet credit is applied within a few seconds of the verify call (or of Paystack's webhook if the app never calls verify), so refresh `GET /passenger/wallet` after a short delay. The passenger also gets the usual "Top-up Successful" notification.

## 3. "Save this card" on wallet top-up (nice to have)

`POST /passenger/wallet/topup` accepts `save_card: true`. The card the top-up is paid with is then saved as a payment method. Nothing is saved if they pay by bank transfer or USSD.
