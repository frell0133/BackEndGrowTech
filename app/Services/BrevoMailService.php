<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoMailService
{
    public function sendHtml(string $toEmail, string $subject, string $html): array
    {
        $key = (string) config('services.brevo.key');

        if (!$key) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => ['message' => 'BREVO_API_KEY is missing'],
            ];
        }

        $response = Http::withHeaders([
            'api-key' => $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'email' => (string) config('services.brevo.sender_email'),
                'name' => (string) config('services.brevo.sender_name'),
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
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return [
            'ok' => true,
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }
}