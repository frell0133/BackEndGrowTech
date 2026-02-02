<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MidtransService
{
    public function createSnapTransaction(array $payload): array
    {
        $simulate = (bool) config('services.midtrans.simulate', false);

        if ($simulate) {
            $fake = 'SIM-' . \Illuminate\Support\Str::uuid();
            return [
                'mode' => 'simulate',
                'ok' => true,
                'status' => 201,
                'token' => $fake,
                'redirect_url' => "https://app.sandbox.midtrans.com/snap/v4/redirection/{$fake}",
                'body' => [
                    'token' => $fake,
                    'redirect_url' => "https://app.sandbox.midtrans.com/snap/v4/redirection/{$fake}",
                ],
            ];
        }

        $serverKey = (string) config('services.midtrans.server_key');
        $url       = (string) config('services.midtrans.snap_url', 'https://app.sandbox.midtrans.com/snap/v1/transactions');

        $res = \Illuminate\Support\Facades\Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $status = $res->status();
        $body   = $res->json() ?? [];

        $ok = in_array($status, [200, 201], true);

        return [
            'mode' => 'real',
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'token' => $body['token'] ?? null,
            'redirect_url' => $body['redirect_url'] ?? null,
            'error' => !$ok,
        ];
    }

}
