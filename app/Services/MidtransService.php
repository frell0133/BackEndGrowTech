<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MidtransService
{
    public function createSnapTransaction(array $payload): array
    {
        $simulate = filter_var(env('MIDTRANS_SIMULATE', true), FILTER_VALIDATE_BOOL);
        $serverKey = (string) env('MIDTRANS_SERVER_KEY', '');

        // kalau simulate atau server key kosong → return dummy
        if ($simulate || $serverKey === '') {
            return [
                'mode' => 'simulate',
                'token' => 'SIMULATED-' . Str::upper(Str::random(18)),
                'redirect_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/topup/simulated',
            ];
        }

        $snapUrl = (string) env('MIDTRANS_SNAP_URL', 'https://app.sandbox.midtrans.com/snap/v1/transactions');

        $res = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->post($snapUrl, $payload);

        if (!$res->ok()) {
            return [
                'mode' => 'real',
                'error' => true,
                'status' => $res->status(),
                'body' => $res->json(),
            ];
        }

        $json = $res->json();

        return [
            'mode' => 'real',
            'token' => $json['token'] ?? null,
            'redirect_url' => $json['redirect_url'] ?? null,
        ];
    }
}
