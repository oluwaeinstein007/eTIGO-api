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
 * in our database, so it does not need to trust the caller. A rejection logs
 * the request's shape (keys and types, no values) for diagnosis.
 */
class PaystackTransferApprovalController extends Controller
{
    private const CRITICAL_ALERTS_PER_MINUTE = 5;

    /** More transfers than this in one request are rejected outright. */
    private const MAX_TRANSFERS = 100;

    private const MAX_NODES_VISITED = 1000;

    public function handle(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $transfers = $this->transfersIn($payload);

        $rejection = match (true) {
            $transfers === [] => 'no transfer found in the request',
            count($transfers) > self::MAX_TRANSFERS => 'too many transfers in one request',
            default => null,
        };

        // Several transfers (a bulk transfer) are approved only if every one is ours.
        foreach ($rejection === null ? $transfers : [] as $transfer) {
            $rejection ??= $this->rejectionReason($transfer);
        }

        $first = $transfers[0] ?? [];
        $context = [
            'event' => $this->loggable($payload['event'] ?? null),
            'transfers' => count($transfers),
            'reference' => $this->loggable($first['reference'] ?? null),
            'amount' => $this->loggable($first['amount'] ?? null),
            'recipient' => $this->loggable($this->recipientCode($first)),
        ];

        if ($rejection !== null) {
            // Key names and value types only: the request carries the
            // recipient's bank details. Enough to see where Paystack put things.
            $context['payload_shape'] = $this->shape($payload);
            $context['content_type'] = $this->loggable($request->header('Content-Type'));
            $this->logRejection($request, $rejection, $context);

            return response()->json(['message' => 'Transfer rejected.'], 400);
        }

        Log::info('Paystack transfer approval: approved a payout transfer', $context);

        return response()->json(['message' => 'Transfer approved.']);
    }

    /**
     * The JSON body, read from the raw content too in case Paystack does not
     * send a JSON content type.
     */
    private function payload(Request $request): array
    {
        $payload = $request->all();

        if ($payload === []) {
            $decoded = json_decode($request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return $payload;
    }

    /**
     * Paystack documents the approval request only as carrying "the payload
     * used to initiate the transfer", so the transfer is looked for rather
     * than expected at one place: every object with a "reference", at the top
     * level, under "data" or in a list (a bulk transfer), a few levels deep.
     *
     * @return list<array<string, mixed>>
     */
    private function transfersIn(array $payload): array
    {
        $transfers = [];
        $queue = [[$payload, 0]];
        $visited = 0;

        // The endpoint is public: bound the work an oversized body can cause.
        while ($queue !== [] && ++$visited <= self::MAX_NODES_VISITED) {
            [$node, $depth] = array_shift($queue);

            if (! array_is_list($node) && array_key_exists('reference', $node)) {
                $transfers[] = $node;

                continue;
            }

            if ($depth >= 3) {
                continue;
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $queue[] = [$child, $depth + 1];
                }
            }
        }

        return $transfers;
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

    /**
     * The payload with every value replaced by its type, and a list shown by
     * its first item.
     */
    private function shape(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }

        if ($depth >= 4) {
            return 'array';
        }

        if (array_is_list($value)) {
            return $value === [] ? [] : ['list of '.count($value) => $this->shape($value[0], $depth + 1)];
        }

        $shape = [];

        foreach (array_slice($value, 0, 50, true) as $key => $child) {
            $shape[Str::limit((string) $key, 50)] = $this->shape($child, $depth + 1);
        }

        return $shape;
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
