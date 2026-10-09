# E-tiGo Wallet Implementation Brief

**Date:** 10 October 2026
**Version:** 1.0
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

## 5. Tasks Completed

| Task ID | Description | Status |
|---------|-------------|--------|
| BE-WAL-08 | ExpireAbandonedTopupsJob — marks stale pending top-ups as abandoned | Done |
| BE-WAL-12 | Insufficient balance handling with partial settlement | Done |
| BE-WAL-14 | Wallet payment integration in ride creation with hold placement | Done |
| BE-WAL-15 | Wallet settlement in ride completion flow | Done |

**Additional integration work:**
- Wallet hold release on ride cancellation
- Wallet hold release on matching timeout (no driver found)
- Wallet arm in `PaymentService::processRidePayment()`
- `WalletTransaction` model and migration for top-up tracking
- Job scheduling for `ExpireStaleHoldsJob` (hourly) and `ExpireAbandonedTopupsJob` (every 15 min)
- 11 feature tests covering the full wallet ride payment lifecycle

---

## 6. Remaining Wallet Tasks

The following tasks from Section 19 of the task breakdown are not yet started:

| Task ID | Description | Priority |
|---------|-------------|----------|
| BE-WAL-16 | Wallet refund flow | P-15 |
| BE-WAL-17 | Wallet statement export (CSV/PDF) | P-20 |
| BE-EARN-01–15 | Driver earnings & payout system | P-15 |
| BE-WADM-01–25 | Admin wallet management dashboard | P-20 |

---

## 7. Client Action Items

1. **Review wallet limits** (OQ-25) — Confirm min top-up (₦500), max balance (₦500,000), daily cap (₦100,000)
2. **Refund policy** (OQ-27) — Wallet credit only, or refund to original payment method?
3. **Wallet tips** (OQ-32) — Include in V1 launch or defer to Phase 2?
4. **Regulatory check** (OQ-33) — Confirm closed-loop wallet model satisfies CBN requirements
5. **Shortfall policy** (OQ-26) — Current: partial settlement + admin flag. Future: consider card fallback auto-charge?
