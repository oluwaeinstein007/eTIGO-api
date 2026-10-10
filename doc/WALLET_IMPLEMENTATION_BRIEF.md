# E-tiGo Wallet Implementation Brief

**Date:** 10 October 2026
**Version:** 2.0
**Prepared for:** E-tiGo Product & Client Team

---

## 1. Executive Summary

The E-tiGo Passenger Wallet system is now fully integrated into the ride lifecycle. Passengers can pay for rides using their wallet balance, with holds placed at ride creation and settled at ride completion. The system uses double-entry bookkeeping with optimistic concurrency control, ensuring financial accuracy at scale.

This brief documents the key architectural decisions made during implementation, answers to Open Questions (OQs) raised in the task breakdown, and the current configuration values.

---

## 2. What Was Built

### 2.1 Wallet Ride Payment Flow

1. **Ride Creation** — When a passenger selects `wallet` as payment method, the system places a hold on their wallet equal to the fare estimate. If the balance is insufficient, the ride is rejected with a 422 error.

2. **Ride Cancellation / No Driver Found** — The hold is automatically released, restoring the passenger's available balance.

3. **Ride Completion** — The hold is captured and settled for the final fare amount. The fare is split into platform commission and driver earnings via journal entries.

4. **Partial Settlement (Shortfall)** — If the final fare exceeds the passenger's current balance (e.g., due to route changes or waiting time), the system captures whatever balance is available, records the shortfall amount, and marks the payment as Failed for admin review.

### 2.2 Abandoned Top-up Expiry

A scheduled job (`ExpireAbandonedTopupsJob`) runs every 15 minutes and marks any pending top-up transactions older than 30 minutes as abandoned. This prevents stale pending states in the wallet transaction table.

### 2.3 Top-up Transaction Tracking

A new `wallet_transactions` table tracks the full lifecycle of top-up payments (Pending → Completed or Abandoned), providing a clear audit trail separate from the ledger journal.

---

## 3. Open Questions — Decisions Made

### OQ-25: Wallet Limits

**Question:** Minimum top-up, maximum balance, and daily top-up cap values?

**Decision:** All values are configurable via environment variables with sensible defaults:

| Limit | Default | Config Key |
|-------|---------|------------|
| Minimum top-up | ₦500 (50,000 kobo) | `WALLET_MIN_TOPUP` |
| Maximum balance | ₦500,000 (50,000,000 kobo) | `WALLET_MAX_BALANCE` |
| Daily top-up cap | ₦100,000 (10,000,000 kobo) | `WALLET_DAILY_TOPUP_CAP` |
| Max top-ups per hour | 5 | `WALLET_MAX_TOPUPS_PER_HOUR` |
| Max top-ups per day | 10 | `WALLET_MAX_TOPUPS_PER_DAY` |

**Rationale:** These limits balance usability with fraud prevention. The ₦500 minimum prevents micro-transactions that generate disproportionate payment gateway fees. The ₦500,000 max balance and ₦100,000 daily cap align with common Nigerian fintech wallet tier limits (Tier 1 BVN-verified). All values are adjustable without code changes.

**Client action needed:** Review and confirm these default values before production launch.

---

### OQ-26: Fallback When Wallet Balance Is Insufficient for Final Fare

**Question:** What happens when the final fare exceeds the passenger's wallet balance?

**Decision:** Partial settlement with admin flagging.

**How it works:**
1. The system captures whatever balance the passenger currently has (even if it's less than the full fare).
2. The shortfall amount is recorded in the ride's `pricing_snapshot` as `wallet_shortfall_kobo`.
3. The payment is marked as `Failed`.
4. A warning is logged for admin review.

**Rationale:** This approach prioritizes recovering as much of the fare as possible rather than charging nothing. The driver still receives their share of whatever was collected. Admin review is required to decide the follow-up action (contact passenger, write off, etc.).

**Future enhancement:** A secondary payment method (card fallback) could be added in Phase 2 to automatically charge the shortfall. For V1, manual admin resolution keeps the system simple and auditable.

---

### OQ-27: Refund Policy

**Question:** Wallet credit only, or back to original payment method (card)?

**Decision:** Deferred to Phase 2. The refund infrastructure (`WalletPaymentService` refund methods, `Refunds` system account) is in place but the policy question remains open. The system currently supports wallet credit refunds only.

**Client action needed:** Decide whether refunds should go back to the original payment method or always be wallet credits.

---

### OQ-30: Minimum Payout Amount for Driver Withdrawals

**Question:** What is the minimum amount a driver can withdraw?

**Decision:** ₦1,000 (100,000 kobo), configurable via `WALLET_MIN_PAYOUT`.

**Rationale:** Prevents micro-withdrawals that cost more in transfer fees than they're worth. This aligns with typical Nigerian bank transfer minimums and reduces Flutterwave transfer API costs.

---

### OQ-31: Driver Payouts — On-demand or Scheduled?

**Question:** Should driver payouts be on-demand request or fixed schedule?

**Decision:** On-demand in V1, with scheduled payouts as a Phase 2 option.

**Rationale:** Nigerian ride-hail drivers overwhelmingly prefer immediate access to earnings. On-demand payouts with a minimum threshold (₦1,000) is the industry standard (Bolt, inDrive). Scheduled payouts can be layered on later for drivers who prefer batch withdrawals.

---

### OQ-32: Tips in V1 Wallet Flow

**Question:** Are tips included in the V1 wallet flow?

**Decision:** Deferred. Card-based tipping exists in `PaymentService::addTip()` but wallet-funded tips are not yet implemented. This is intentional — wallet tips require a separate journal posting flow to avoid mixing tip and fare settlement.

**Client action needed:** Confirm if wallet tips are needed for launch or can be Phase 2.

---

### OQ-33: Regulatory — Fund Custody

**Question:** Custody of funds / safeguarding requirements for a closed-loop wallet in Nigeria?

**Decision:** The technical implementation treats the wallet as a closed-loop prepaid system (funds can only be used for E-tiGo rides, not transferred peer-to-peer). Under CBN guidelines, closed-loop wallets operated by a licensed payment service provider (Flutterwave) have lighter regulatory requirements than open-loop wallets.

**Client action needed:** Confirm with legal counsel that the current closed-loop model with Flutterwave as PSP satisfies CBN licensing requirements. If the product scope expands to peer-to-peer transfers or multi-merchant use, additional licensing may be required.

---

## 4. Configuration Reference

All wallet behavior is controlled via `config/wallet.php` with environment variable overrides:

| Parameter | Env Variable | Default | Description |
|-----------|-------------|---------|-------------|
| Currency | `WALLET_CURRENCY` | NGN | Wallet currency code |
| Min top-up | `WALLET_MIN_TOPUP` | 50,000 kobo (₦500) | Minimum single top-up amount |
| Max balance | `WALLET_MAX_BALANCE` | 50,000,000 kobo (₦500,000) | Maximum wallet balance |
| Daily top-up cap | `WALLET_DAILY_TOPUP_CAP` | 10,000,000 kobo (₦100,000) | Max total top-ups per day |
| Hold expiry | `WALLET_HOLD_EXPIRY_HOURS` | 4 hours | Auto-release orphaned holds after this |
| Abandoned top-up | `WALLET_ABANDONED_TOPUP_MINUTES` | 30 min | Mark pending top-ups abandoned after this |
| Min payout | `WALLET_MIN_PAYOUT` | 100,000 kobo (₦1,000) | Minimum driver withdrawal |
| Settlement delay | `WALLET_SETTLEMENT_DELAY` | instant | Driver earnings availability |
| Max negative balance | `WALLET_MAX_NEGATIVE_BALANCE` | -500,000 kobo (-₦5,000) | Allowed negative driver balance |
| Commission rate | `WALLET_DEFAULT_COMMISSION_RATE` | 20% (0.2000) | Platform commission on fares |
| Top-ups/hour | `WALLET_MAX_TOPUPS_PER_HOUR` | 5 | Fraud guardrail |
| Top-ups/day | `WALLET_MAX_TOPUPS_PER_DAY` | 10 | Fraud guardrail |

---

## 5. Open Questions — Additional Decisions (v2.0)

### OQ-28: Driver Earnings Settlement Delay

**Question:** Should driver earnings be available immediately, or held for 24 hours / weekly?

**Decision:** All three options supported, configurable via `config('wallet.settlement_delay')`. Default is `instant`.

**How it works:**
- **instant** (default) — Earnings go directly to `DriverEarningsAvailable` on ride completion. No pending period.
- **24h** — Earnings go to `DriverEarningsPending`. `SettleDriverEarningsJob` (runs hourly) moves entries older than 24 hours to `DriverEarningsAvailable`.
- **weekly** — Same as 24h but with a 7-day holding period.
- Cash rides always go directly to `DriverEarningsAvailable` regardless of setting (driver already has the cash; only commission is debited).

**Runtime configurable:** Admins can change the delay at `PUT /admin/settings/wallet` without code changes.

---

### OQ-29: Cash-Ride Commission Policy

**Question:** How are commissions handled when the driver collects cash directly from the passenger?

**Decision:** Debit commission from `DriverEarningsAvailable`. Allow negative balance up to configurable threshold.

**How it works:**
1. When a cash ride completes, the driver has already collected the full fare in person.
2. The platform debits commission from `DriverEarningsAvailable` — this can push the balance negative.
3. A configurable threshold (`max_negative_balance`, default −₦5,000) triggers a warning log.
4. `hasExcessiveNegativeBalance()` helper is available for ride-blocking logic in future.
5. `NegativeDriverBalanceReportJob` runs weekly (Mondays 08:00) and logs all drivers with negative balances for finance review.

**Edge cases handled:**
- Driver with no prior card/wallet ride earnings: balance goes negative from first cash ride commission. This is expected and tracked.
- Multiple cash rides in succession: balance becomes progressively negative. The threshold warning fires each time.
- Mixed payment methods: card/wallet rides build up available balance; cash rides debit commission against it.

---

## 6. Reconciliation & Monitoring

### Daily Reconciliation (BE-WADM-21)

`DailyReconciliationJob` runs at 02:00 daily. It compares:
- **Charges:** Flutterwave `charge.completed` webhook amounts vs ledger credits to passenger wallets
- **Transfers:** Flutterwave `transfer.completed` webhook amounts vs payout records marked paid

Results are stored in `reconciliation_reports` with status `clean` or `mismatched`. Mismatches include the type (charges/transfers), gateway total, ledger total, and difference.

**Admin endpoint:** `GET /admin/reports/reconciliation?date=YYYY-MM-DD`

### Stuck Transaction Sweeper (BE-WADM-22)

`StuckTransactionSweeperJob` runs every 30 minutes. For any `WalletTransaction` still `pending` beyond the abandoned threshold (default 30 min):
1. Calls Flutterwave Verify Transaction API
2. If successful: credits the wallet and marks completed
3. If failed/error: marks as abandoned or failed

This recovers funds from missed webhooks without manual intervention.

### Negative Balance Report (BE-WADM-23)

`NegativeDriverBalanceReportJob` runs weekly (Monday 08:00). Logs all drivers with negative `DriverEarningsAvailable` balance, highlighting those exceeding the threshold.

---

## 7. Wallet Notifications (BE-WADM-26/27)

The `NotificationType` enum now includes all wallet events. Four notification classes dispatch on key wallet lifecycle events:

| Event | Notification Class | Trigger Point |
|-------|-------------------|---------------|
| Top-up success | `WalletTopupNotification` | `ProcessTopupWebhookJob` on journal credit |
| Top-up failed | `WalletTopupNotification` | Available for manual dispatch |
| Ride wallet payment | `WalletRidePaymentNotification` | `WalletPaymentService@settle()` after transaction |
| Wallet refund | `WalletRefundNotification` | `WalletRefundService@refundToWallet()` |
| Payout approved | `PayoutStatusNotification` | `AdminPayoutController@approve()` |
| Payout paid | `PayoutStatusNotification` | `ProcessPayoutWebhookJob` on success |
| Payout failed | `PayoutStatusNotification` | `ProcessPayoutWebhookJob` on failure |

All notifications use the `database` channel (stored in `notifications` table). Push notification delivery via Firebase is available but depends on FCM credentials being configured.

---

## 8. Fraud Guardrails (BE-WADM-29)

| Guardrail | Limit | Enforcement |
|-----------|-------|-------------|
| Top-ups per hour | 5 | Cache counter in `WalletTopupController` |
| Top-ups per day | 10 | Cache counter in `WalletTopupController` |
| Daily top-up amount | ₦100,000 | Cache + validation in `StoreTopupRequest` |
| Max wallet balance | ₦500,000 | Pre-top-up balance check |
| Route rate limit (top-up) | 10 req/min | `throttle:10,1` middleware |
| Route rate limit (payout) | 3 req/min | `throttle:3,1` middleware |

All limits are configurable via `config/wallet.php` and overridable at runtime via the Admin Wallet Settings endpoint.

---

## 9. Tasks Completed

| Task ID | Description | Status |
|---------|-------------|--------|
| BE-WAL-08 | ExpireAbandonedTopupsJob — marks stale pending top-ups as abandoned | Done |
| BE-WAL-12 | Insufficient balance handling with partial settlement | Done |
| BE-WAL-14 | Wallet payment integration in ride creation with hold placement | Done |
| BE-WAL-15 | Wallet settlement in ride completion flow | Done |
| BE-EARN-04 | SettleDriverEarningsJob — configurable settlement delay | Done |
| BE-EARN-05 | Cash-ride commission handling with negative balance threshold | Done |
| BE-EARN-06 | Driver earnings endpoint with balances and summaries | Done |
| BE-EARN-07 | Per-ride earnings breakdown endpoint | Done |
| BE-WADM-20 | Admin wallet settings endpoint (runtime config) | Done |
| BE-WADM-21 | Daily reconciliation job with mismatch detection | Done |
| BE-WADM-22 | Stuck transaction sweeper with gateway verification | Done |
| BE-WADM-23 | Negative driver balance weekly report | Done |
| BE-WADM-24 | Admin reconciliation report endpoint | Done |
| BE-WADM-26 | NotificationType enum with all wallet events | Done |
| BE-WADM-27 | Wallet notification dispatches integrated into services | Done |
| BE-WADM-28 | Rate limiting on funding and payout endpoints | Done |
| BE-WADM-29 | Fraud guardrails (velocity + amount limits) | Done |
| BE-WADM-30 | RBAC verification for all wallet admin routes | Done |

**Additional integration work:**
- Wallet hold release on ride cancellation
- Wallet hold release on matching timeout (no driver found)
- Wallet arm in `PaymentService::processRidePayment()`
- `WalletTransaction` model and migration for top-up tracking
- Job scheduling for all 6 scheduled jobs
- `AppSetting` model for runtime-configurable wallet settings
- `ReconciliationReport` model and migration
- 22 feature tests covering wallet ride payments and admin functionality

---

## 10. Scheduled Jobs Summary

| Job | Schedule | Description |
|-----|----------|-------------|
| `ExpireStaleHoldsJob` | Hourly | Releases holds older than 4 hours with no active ride |
| `ExpireAbandonedTopupsJob` | Every 15 min | Marks pending top-ups older than 30 min as abandoned |
| `SettleDriverEarningsJob` | Hourly | Moves pending earnings to available (when delay is 24h/weekly) |
| `StuckTransactionSweeperJob` | Every 30 min | Verifies stuck pending transactions with gateway |
| `DailyReconciliationJob` | Daily at 02:00 | Compares gateway vs ledger totals |
| `NegativeDriverBalanceReportJob` | Weekly Mon 08:00 | Reports drivers with negative earnings balance |

---

## 11. Client Action Items

1. **Review wallet limits** (OQ-25) — Confirm min top-up (₦500), max balance (₦500,000), daily cap (₦100,000)
2. **Refund policy** (OQ-27) — Wallet credit only, or refund to original payment method?
3. **Wallet tips** (OQ-32) — Include in V1 launch or defer to Phase 2?
4. **Regulatory check** (OQ-33) — Confirm closed-loop wallet model satisfies CBN requirements
5. **Shortfall policy** (OQ-26) — Current: partial settlement + admin flag. Future: consider card fallback auto-charge?
6. **Settlement delay** (OQ-28) — Default is `instant`. Confirm whether 24h or weekly delay is preferred for launch.
7. **Negative balance threshold** (OQ-29) — Default −₦5,000. Confirm acceptable threshold for cash-ride commission debt.
