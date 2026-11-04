<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Sends text messages through the Smart Messaging Suite (MEMS) HTTP API configured in config/services.php. */
class SmsService
{
    public function isConfigured(): bool
    {
        return filled(config('services.smart_sms.api_id')) && filled(config('services.smart_sms.api_key'));
    }

    /**
     * A Philippine mobile number in international form (639XXXXXXXXX), or null for anything else (landlines,
     * typos), so only numbers that can receive a text are sent to the gateway.
     */
    public function mobileNumber(?string $contact): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $contact);

        return match (true) {
            (bool) preg_match('/^639\d{9}$/', $digits) => $digits,
            (bool) preg_match('/^09\d{9}$/', $digits) => '63'.substr($digits, 1),
            (bool) preg_match('/^9\d{9}$/', $digits) => '63'.$digits,
            default => null,
        };
    }

    /** @throws RuntimeException when the gateway is not configured or does not accept the message */
    public function send(string $mobileNumber, string $text): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The SMS gateway is not configured (SMART_SMS_API_ID / SMART_SMS_API_KEY).');
        }

        $response = Http::asForm()->timeout(15)
            ->withHeaders([
                'Accept' => 'text/plain',
                'X-MEMS-API-ID' => (string) config('services.smart_sms.api_id'),
                'X-MEMS-API-KEY' => (string) config('services.smart_sms.api_key'),
            ])
            ->post((string) config('services.smart_sms.url'), ['destination' => $mobileNumber, 'text' => $text]);

        // A sent message answers HTTP 200 "0 001 OK\nMessage-ID: …" (seen 2026-11-04); a non-zero first code is
        // treated as a refusal even when the HTTP status is 200.
        if (! $response->successful() || ! str_starts_with(trim($response->body()), '0 ')) {
            throw new RuntimeException("The SMS gateway answered HTTP {$response->status()}: ".Str::limit(trim($response->body()), 200));
        }
    }
}
