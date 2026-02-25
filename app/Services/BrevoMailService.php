<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoMailService
{
    public function sendHtml(string $toEmail, string $subject, string $html): array
    {
        $key = (string) config('services.brevo.key');
        $senderEmail = (string) config('services.brevo.sender_email');
        $senderName = (string) config('services.brevo.sender_name', 'GrowTech Central');

        if (!$key) {
            Log::error('Brevo sendHtml failed: missing API key');
            return [
                'ok' => false,
                'status' => 0,
                'body' => ['message' => 'BREVO_API_KEY is missing'],
            ];
        }

        if (!$senderEmail) {
            Log::error('Brevo sendHtml failed: missing sender email');
            return [
                'ok' => false,
                'status' => 0,
                'body' => ['message' => 'BREVO_SENDER_EMAIL is missing'],
            ];
        }

        $response = Http::timeout(20)
            ->connectTimeout(5)
            ->withHeaders([
                'api-key' => $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'email' => $senderEmail,
                    'name' => $senderName,
                ],
                'to' => [
                    ['email' => $toEmail],
                ],
                'subject' => $subject,
                'htmlContent' => $html,
            ]);

        if ($response->failed()) {
            Log::error('Brevo sendHtml failed', [
                'to' => $toEmail,
                'subject' => $subject,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        Log::info('Brevo sendHtml success', [
            'to' => $toEmail,
            'subject' => $subject,
            'status' => $response->status(),
        ]);

        return [
            'ok' => true,
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }
}