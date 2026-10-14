<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiGateway implements SmsGateway
{
    private string $apiKey;

    private string $senderId;

    private string $whatsappFrom;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.termii.api_key');
        $this->senderId = config('services.termii.sender_id', 'Etigo');
        $this->whatsappFrom = config('services.termii.whatsapp_from', $this->senderId);
        $this->baseUrl = config('services.termii.base_url', 'https://v3.api.termii.com');
    }

    public function send(string $phone, string $message): bool
    {
        $sent = $this->sendViaChannel($phone, $message, 'whatsapp');

        if (! $sent) {
            Log::info('Termii WhatsApp delivery failed, falling back to SMS', ['phone' => $phone]);
            $sent = $this->sendViaChannel($phone, $message, 'dnd');
        }

        return $sent;
    }

    public function sendViaChannel(string $phone, string $message, string $channel): bool
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->post("{$this->baseUrl}/api/sms/send", [
                    'to' => ltrim($phone, '+'),
                    'from' => $channel === 'whatsapp' ? $this->whatsappFrom : $this->senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => $channel,
                    'api_key' => $this->apiKey,
                ]);
        } catch (ConnectionException $e) {
            Log::error('Termii connection failed', [
                'phone' => $phone,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Termii message delivery failed', [
                'phone' => $phone,
                'channel' => $channel,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        Log::info('Termii message sent', [
            'phone' => $phone,
            'channel' => $channel,
            'message_id' => $response->json('message_id'),
        ]);

        return true;
    }
}
