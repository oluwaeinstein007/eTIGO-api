<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Paystack's transfer approval URL. With it set in the Paystack dashboard
 * (Settings → Preferences → Transfer Approval), Paystack asks this endpoint
 * about every transfer before sending it: 200 lets the transfer go, 400
 * rejects it, and no answer within a few seconds blocks it.
 *
 * Only a transfer our payout job started is approved: its reference, amount
 * and recipient must match a payout that is being sent. A transfer started
 * any other way — with a leaked secret key, or by hand in the dashboard — is
 * rejected. The endpoint changes nothing and approves only what is already
 * in our database, so it does not need to trust the caller.
 */
class PaystackTransferApprovalController extends Controller
{
    private const CRITICAL_ALERTS_PER_MINUTE = 5;

    public function handle(Request $request): JsonResponse
    {
        // Paystack sends the payload the transfer was started with; accept it
        // wrapped in "data" too, as its webhooks are.
        $transfer = is_array($request->input('data')) ? $request->input('data') : $request->all();

        $rejection = $this->rejectionReason($transfer);

        $context = [
            'reference' => $this->loggable($transfer['reference'] ?? null),
            'amount' => $this->loggable($transfer['amount'] ?? null),
            'recipient' => $this->loggable($this->recipientCode($transfer)),
        ];

        if ($rejection !== null) {
            $this->logRejection($request, $rejection, $context);

            return response()->json(['message' => 'Transfer rejected.'], 400);
        }

        Log::info('Paystack transfer approval: approved a payout transfer', $context);

        return response()->json(['message' => 'Transfer approved.']);
    }

    private function rejectionReason(array $transfer): ?string
    {
        $reference = $transfer['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return 'no reference';
        }

        $payout = Payout::where('gateway_reference', $reference)->first();

        if (! $payout) {
            return 'no payout has this reference';
        }

        // Processing too: Paystack can answer the transfer request ("received")
        // before it asks for approval, and the job then marks the payout processing.
        if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Processing], true)) {
            return "the payout is {$payout->status->value}";
        }

        $amount = $transfer['amount'] ?? null;

        if (! is_numeric($amount) || (float) $amount !== (float) $payout->amount) {
            return 'the amount does not match the payout';
        }

        if (isset($transfer['currency']) && (! is_string($transfer['currency']) || strtoupper($transfer['currency']) !== 'NGN')) {
            return 'the currency is not NGN';
        }

        if ($payout->gateway_recipient_code === null || $this->recipientCode($transfer) !== $payout->gateway_recipient_code) {
            return 'the recipient is not the payout\'s bank account';
        }

        return null;
    }

    /**
     * A rejection may be a transfer started with a leaked secret key, so it is
     * critical. The endpoint is public, though: past a few rejections a minute
     * from one address the rest are logged as warnings, so junk requests
     * cannot bury the alert.
     */
    private function logRejection(Request $request, string $rejection, array $context): void
    {
        $message = "Paystack transfer approval: rejected a transfer ({$rejection})";
        $key = 'paystack-transfer-approval-alert:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CRITICAL_ALERTS_PER_MINUTE)) {
            Log::warning("{$message}; critical alerts from this address are paused for a minute", $context);

            return;
        }

        RateLimiter::hit($key);
        Log::critical("{$message}. If no one at E-tiGo started it, rotate the Paystack secret key.", $context);
    }

    /** Caller-supplied values are logged, so keep them short. */
    private function loggable(mixed $value): mixed
    {
        if (is_array($value)) {
            return '[array]';
        }

        return is_string($value) ? Str::limit($value, 100) : $value;
    }

    /**
     * The recipient code, sent as "recipient" when the transfer is started,
     * or inside a recipient object.
     */
    private function recipientCode(array $transfer): ?string
    {
        $recipient = $transfer['recipient'] ?? $transfer['recipient_code'] ?? null;

        if (is_array($recipient)) {
            $recipient = $recipient['recipient_code'] ?? null;
        }

        return is_string($recipient) && $recipient !== '' ? $recipient : null;
    }
}
