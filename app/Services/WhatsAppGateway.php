<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppGateway implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');
        $otpTemplateName = config('services.whatsapp.otp_template_name', 'otp_verification');

        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout(5)
                ->timeout(10)
                ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($phone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $otpTemplateName,
                        'language' => ['code' => 'en'],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $message],
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::error('WhatsApp connection failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('WhatsApp OTP delivery failed', [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }
}
