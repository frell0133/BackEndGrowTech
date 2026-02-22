<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Delivery;
use App\Services\OrderFulfillmentService;
use App\Services\BrevoMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDeliveryController extends Controller
{
    /**
     * POST /api/v1/orders/{order}/delivery/reveal
     * One-time reveal: hanya sekali menampilkan license_key (qty==1).
     * Return: { message: "Revealed (one-time)", data: { product_name, license_key, payload? } }
     */
    public function reveal(Request $request, Order $order, OrderFulfillmentService $fulfill)
    {
        $user = $request->user();
        if (!$user || (int)$order->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Forbidden',
            ], 403);
        }

        // ambil 1 delivery first (untuk qty==1)
        $delivery = Delivery::query()
            ->where('order_id', $order->id)
            ->with(['license.product'])
            ->orderBy('id')
            ->first();

        if (!$delivery || !$delivery->license) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Delivery/license not found',
            ], 404);
        }

        // kalau sudah reveal, jangan kasih data lagi
        if (!empty($delivery->revealed_at)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Already revealed',
                    'data' => [],
                ],
                'meta' => (object)[],
                'error' => null,
            ]);
        }

        $payload = DB::transaction(function () use ($delivery, $fulfill) {
            $d = Delivery::query()
                ->where('id', $delivery->id)
                ->lockForUpdate()
                ->with(['license.product'])
                ->first();

            if (!$d || !$d->license) return null;

            if (!empty($d->revealed_at)) return '__ALREADY__';

            $d->revealed_at = now();
            $d->reveal_count = (int)($d->reveal_count ?? 0) + 1;
            $d->save();

            // ✅ ini yang bikin data tidak kosong lagi
            return $fulfill->formatLicense($d->license);
        });

        if ($payload === '__ALREADY__') {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Already revealed',
                    'data' => [],
                ],
                'meta' => (object)[],
                'error' => null,
            ]);
        }

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Failed',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Revealed (one-time)',
                'data' => $payload,
            ],
            'meta' => (object)[],
            'error' => null,
        ]);
    }

    /**
     * POST /api/v1/orders/{order}/delivery/close
     * Dipanggil FE saat modal ditutup / timer habis.
     * Tugas: kirim email (fallback manual) untuk order qty==1.
     */
    public function close(Request $request, Order $order, OrderFulfillmentService $fulfill)
    {
        $user = $request->user();
        if (!$user || (int)$order->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Forbidden',
            ], 403);
        }

        $to = $order->email ?? $order->user?->email;
        if (!$to) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Order email not found',
            ], 422);
        }

        // ambil semua deliveries (kalau nanti qty>1, tetap aman)
        $deliveries = Delivery::query()
            ->where('order_id', $order->id)
            ->with(['license.product'])
            ->get();

        if ($deliveries->isEmpty()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'No deliveries',
            ], 404);
        }

        // kalau sudah pernah emailed sukses, skip biar gak spam
        $alreadyEmailed = $deliveries->firstWhere(fn ($d) => !empty($d->emailed_at));
        if ($alreadyEmailed) {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Already emailed',
                    'data' => true,
                ],
                'meta' => (object)[],
                'error' => null,
            ]);
        }

        $itemsEmail = $deliveries
            ->map(fn ($d) => $fulfill->formatLicense($d->license))
            ->values()
            ->all();

        $html = view('emails.digital-items', [
            'order' => $order,
            'items' => $itemsEmail,
        ])->render();

        $brevo = app(BrevoMailService::class);
        $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

        if (!($res['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'data' => [
                    'message' => 'Brevo send failed',
                    'details' => $res,
                ],
                'meta' => (object)[],
                'error' => 'Brevo send failed',
            ], 500);
        }

        // ✅ tandai emailed_at setelah sukses
        Delivery::query()
            ->where('order_id', $order->id)
            ->whereNull('emailed_at')
            ->update(['emailed_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Closed & email sent',
                'data' => true,
            ],
            'meta' => (object)[],
            'error' => null,
        ]);
    }

    /**
     * POST /api/v1/orders/{order}/delivery/resend
     * Kirim ulang email untuk order (single/multi).
     */
    public function resend(Request $request, Order $order, OrderFulfillmentService $fulfill)
    {
        $user = $request->user();
        if (!$user || (int)$order->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Forbidden',
            ], 403);
        }

        $to = $order->email ?? $order->user?->email;
        if (!$to) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'Order email not found',
            ], 422);
        }

        $deliveries = Delivery::query()
            ->where('order_id', $order->id)
            ->with(['license.product'])
            ->get();

        if ($deliveries->isEmpty()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => 'No deliveries',
            ], 404);
        }

        $itemsEmail = $deliveries
            ->map(fn ($d) => $fulfill->formatLicense($d->license))
            ->values()
            ->all();

        $html = view('emails.digital-items', [
            'order' => $order,
            'items' => $itemsEmail,
        ])->render();

        $brevo = app(BrevoMailService::class);
        $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

        if (!($res['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'data' => [
                    'message' => 'Brevo send failed',
                    'details' => $res,
                ],
                'meta' => (object)[],
                'error' => 'Brevo send failed',
            ], 500);
        }

        // ✅ update emailed_at (resend juga dianggap sukses)
        Delivery::query()
            ->where('order_id', $order->id)
            ->update(['emailed_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Resent email',
                'data' => true,
            ],
            'meta' => (object)[],
            'error' => null,
        ]);
    }
}