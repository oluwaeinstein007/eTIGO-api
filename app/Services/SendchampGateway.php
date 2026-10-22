<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sendchamp (https://sendchamp.readme.io): WhatsApp first, then SMS.
 *
 * WhatsApp only lets a business start a conversation with a Meta-approved
 * template, so codes go through the OTP template and every other message
 * through the notification template, whose {{1}} carries the whole text.
 * Without a sender number and the template code, WhatsApp is skipped.
 */
class SendchampGateway implements SmsGateway
{
    private const NIGERIA_PREFIX = '234';

    /** Sendchamp's status code when the wallet cannot pay for the message. */
    private const LOW_BALANCE = 407;

    /**
     * Sendchamp's own expiry for codes sent through its verification service.
     * The app checks codes itself (OtpService), so this only has to cover the
     * app's 5 minutes.
     */
    private const VERIFICATION_EXPIRY_MINUTES = 5;

    /** Template bodies are capped at 1024 characters, fixed text included. */
    private const WHATSAPP_VARIABLE_MAX = 900;

    /**
     * Characters outside the GSM alphabet bill every SMS part at 70 instead
     * of 160 characters, so common typographic ones are swapped for plain ones.
     */
    private const GSM_REPLACEMENTS = [
        '—' => '-', '–' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"',
        '…' => '...', '₦' => 'N', "\u{00A0}" => ' ',
    ];

    private string $accessKey;

    private string $baseUrl;

    private string $senderName;

    private string $smsRoute;

    private ?string $whatsappSender;

    private ?string $otpTemplate;

    private ?string $notificationTemplate;

    private bool $otpViaVerification;

    private ?string $lastError = null;

    public function __construct()
    {
        $this->accessKey = (string) config('services.sendchamp.access_key');
        $this->baseUrl = rtrim((string) config('services.sendchamp.base_url', 'https://api.sendchamp.com/api/v1'), '/');
        $this->senderName = (string) config('services.sendchamp.sender_name', 'Sendchamp');
        $this->smsRoute = (string) config('services.sendchamp.sms_route', 'dnd');
        $this->whatsappSender = config('services.sendchamp.whatsapp_sender') ?: null;
        $this->otpTemplate = config('services.sendchamp.whatsapp_otp_template') ?: null;
        $this->notificationTemplate = config('services.sendchamp.whatsapp_template') ?: null;
        $this->otpViaVerification = (bool) config('services.sendchamp.whatsapp_otp_via_verification', false);
    }

    public function send(string $phone, string $message): bool
    {
        return $this->deliver($phone, $message) !== null;
    }

    public function deliver(string $phone, string $message): ?string
    {
        if ($this->sendWhatsAppTemplate($phone, $this->notificationTemplate, ['1' => $this->whatsAppText($message)])) {
            return 'whatsapp';
        }

        return $this->sendSms($phone, $message) ? 'sms' : null;
    }

    public function sendOtp(string $phone, string $code, string $message): bool
    {
        return $this->sendWhatsAppTemplate($phone, $this->otpTemplate, ['1' => $code])
            || $this->sendWhatsAppVerification($phone, $code)
            || $this->sendSms($phone, $message);
    }

    /**
     * Send our code through Sendchamp's verification service (POST
     * /verification/create with our own token), which delivers it by
     * WhatsApp from Flutterwave's account as "{code} is your verification
     * code." It needs no WhatsApp number or template of our own. Sendchamp's
     * confirm endpoint is not used: OtpService checks the code.
     */
    public function sendWhatsAppVerification(string $phone, string $code): bool
    {
        if (! $this->otpViaVerification || $this->whatsAppOtpTemplateEnabled()) {
            return false;
        }

        $data = $this->request('post', '/verification/create', [
            'channel' => 'whatsapp',
            'token_type' => ctype_digit($code) ? 'numeric' : 'alphanumeric',
            'token_length' => strlen($code),
            'expiration_time' => self::VERIFICATION_EXPIRY_MINUTES,
            'customer_mobile_number' => $this->number($phone),
            'meta_data' => ['first_name' => 'Customer'],
            'token' => $code,
        ], ['channel' => 'whatsapp_verification', 'phone' => $phone]);

        if ($data !== null && ($data['status'] ?? null) !== 'sent') {
            $this->lastError = 'verification status '.($data['status'] ?? 'missing');
            Log::error('Sendchamp did not send the WhatsApp code', ['phone' => $phone, 'status' => $data['status'] ?? null]);

            return false;
        }

        return $data !== null;
    }

    public function sendSms(string $phone, string $message): bool
    {
        $number = $this->number($phone);

        return $this->request('post', '/sms/send', [
            'to' => [$number],
            'message' => strtr($message, self::GSM_REPLACEMENTS),
            'sender_name' => $this->senderName,
            'route' => str_starts_with($number, self::NIGERIA_PREFIX) ? $this->smsRoute : 'international',
        ], ['channel' => 'sms', 'phone' => $phone]) !== null;
    }

    /**
     * @param  array<string, string>  $variables  template body variables keyed '1', '2', ...
     */
    public function sendWhatsAppTemplate(string $phone, ?string $templateCode, array $variables): bool
    {
        if ($this->whatsappSender === null || $templateCode === null) {
            return false;
        }

        return $this->request('post', '/whatsapp/message/send', [
            'sender' => $this->number($this->whatsappSender),
            'recipient' => $this->number($phone),
            'type' => 'template',
            'template_code' => $templateCode,
            'custom_data' => ['body' => $variables],
        ], ['channel' => 'whatsapp', 'phone' => $phone, 'template_code' => $templateCode]) !== null;
    }

    public function whatsAppEnabled(): bool
    {
        return $this->whatsappSender !== null && $this->notificationTemplate !== null;
    }

    public function whatsAppOtpEnabled(): bool
    {
        return $this->whatsAppOtpTemplateEnabled() || $this->otpViaVerification;
    }

    public function whatsAppOtpTemplateEnabled(): bool
    {
        return $this->whatsappSender !== null && $this->otpTemplate !== null;
    }

    /**
     * @return array<string, mixed>|null e.g. ['business_name' => ..., 'wallet_balance' => '0'], or null on failure
     */
    public function walletBalance(): ?array
    {
        return $this->request('get', '/wallet-balance', [], ['action' => 'wallet_balance']);
    }

    /**
     * Ask Sendchamp to approve an SMS sender name. It starts as 'pending'.
     *
     * @param  string  $useCase  Transactional, Marketing, or "Transactional & Marketing"
     * @return array<string, mixed>|null the new sender, or null when refused
     */
    public function registerSenderId(string $name, string $sample, string $useCase): ?array
    {
        return $this->request('post', '/sms/create-sender-id', [
            'name' => $name,
            'sample' => $sample,
            'use_case' => $useCase,
        ], ['action' => 'create_sender_id', 'name' => $name]);
    }

    /**
     * Why the last request was refused, for the console commands.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context  logged with the outcome (never the message text, which may hold a code)
     * @return array<string, mixed>|null the response's data, or null when Sendchamp refused the request
     */
    private function request(string $method, string $path, array $payload, array $context): ?array
    {
        $this->lastError = null;
        $http = Http::withToken($this->accessKey)->acceptJson()->connectTimeout(5)->timeout(15);

        try {
            $response = $method === 'get'
                ? $http->get($this->baseUrl.$path)
                : $http->post($this->baseUrl.$path, $payload);
        } catch (ConnectionException $e) {
            $this->lastError = $e->getMessage();
            Log::error('Sendchamp connection failed', $context + ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed() || $response->json('status') !== 'success') {
            $this->lastError = (string) ($response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}");

            if ($response->status() === self::LOW_BALANCE) {
                Log::critical('Sendchamp wallet is out of funds: no SMS or WhatsApp message can be sent until it is topped up', $context);
            } else {
                Log::error('Sendchamp request failed', $context + [
                    'status' => $response->status(),
                    'message' => $this->lastError,
                    'errors' => $response->json('errors'),
                ]);
            }

            return null;
        }

        Log::info('Sendchamp request accepted', $context + [
            'reference' => $response->json('data.reference') ?? $response->json('data.provider_reference'),
        ]);

        return (array) ($response->json('data') ?? []);
    }

    /**
     * Sendchamp takes numbers in international format without the plus.
     */
    private function number(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    /**
     * Meta refuses template variables that hold line breaks, tabs or runs of
     * spaces, so lines are joined with a separator.
     */
    private function whatsAppText(string $message): string
    {
        $lines = array_filter(array_map('trim', preg_split('/\R/u', $message)), fn (string $line) => $line !== '');
        $text = preg_replace('/[\t ]{2,}/', ' ', implode(' | ', $lines));

        return Str::limit($text, self::WHATSAPP_VARIABLE_MAX);
    }
}
