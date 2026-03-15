<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Order;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\DigitalItemsMail;
use App\Services\OrderFulfillmentService;
use App\Services\BrevoMailService;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

class UserDeliveryController extends Controller
{
    use ApiResponse;

    private function loadOrderForUser(int $id, int $userId): ?Order
    {
        return Order::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->with([
                'items',                 // order_items (kalau ada)
                'deliveries.license.product', // ✅ penting: license + product
                'product',
                'payment',             
            ])
            ->first();
    }

    private function getTotalQty(Order $order): int
    {
        // ✅ Prioritas: order_items
        if ($order->relationLoaded('items') && $order->items && $order->items->count() > 0) {
            return (int) $order->items->sum('qty');
        }

        // fallback legacy
        return (int) ($order->qty ?? 1);
    }

    public function info(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $order = $this->loadOrderForUser((int)$id, (int)$user->id);
        if (!$order) return $this->fail('Order not found', 404);

        $totalQty = $this->getTotalQty($order);
        $firstDelivery = $order->deliveries->first();

        $canReveal = $this->isPaidOrder($order)
            && ($totalQty === 1)
            && $order->deliveries->count() === 1
            && $firstDelivery
            && $firstDelivery->delivery_mode === 'one_time'
            && $firstDelivery->revealed_at === null;

        return $this->ok([
            'order_id' => $order->id,
            'total_qty' => $totalQty,
            'delivery_mode' => $firstDelivery?->delivery_mode ?? ($totalQty === 1 ? 'one_time' : 'email_only'),
            'deliveries_count' => $order->deliveries->count(),
            'can_reveal' => $canReveal,
            'emailed' => $order->deliveries->whereNotNull('emailed_at')->count() > 0,
            'order_status' => (string) ($order->status?->value ?? $order->status),
            'payment_status' => (string) ($order->payment->status?->value ?? $order->payment->status ?? null),
        ]);
    }

    public function reveal(Request $request, $id, OrderFulfillmentService $fulfill)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $order = $this->loadOrderForUser((int)$id, (int)$user->id);
        if (!$order) return $this->fail('Order not found', 404);

        if (!$this->isPaidOrder($order)) {
            return $this->fail('Order belum dibayar', 422);
        }

        if ($order->deliveries->count() === 0) {
            $r = $fulfill->fulfillPaidOrder($order);
            if (!($r['ok'] ?? false)) {
                return $this->fail($r['message'] ?? 'Cannot fulfill', 422);
            }

            $order->refresh();
            $order = $this->loadOrderForUser((int)$id, (int)$user->id);
            if (!$order) return $this->fail('Order not found', 404);
        }

        $totalQty = $this->getTotalQty($order);
        if ($totalQty !== 1) {
            return $this->fail('Reveal only available for total_qty=1', 422);
        }

        $delivery = $order->deliveries->first();
        if (!$delivery) return $this->fail('Delivery not found', 404);

        if ($delivery->delivery_mode !== 'one_time') {
            return $this->fail('This order is not one-time reveal', 422);
        }

        if ($delivery->revealed_at) {
            return $this->fail('Already revealed', 409);
        }

        $result = DB::transaction(function () use ($delivery, $fulfill) {
            $d = Delivery::query()
                ->where('id', $delivery->id)
                ->lockForUpdate()
                ->with(['license.product'])
                ->first();

            if (!$d) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'Delivery not found',
                ];
            }

            if (!$d->license) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'License not found',
                ];
            }

            if ($d->revealed_at) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'message' => 'Already revealed',
                ];
            }

            $d->revealed_at = now();
            $d->reveal_count = (int) ($d->reveal_count ?? 0) + 1;
            $d->save();

            return [
                'ok' => true,
                'data' => $fulfill->formatLicense($d->license),
            ];
        });

        if (!($result['ok'] ?? false)) {
            return $this->fail(
                $result['message'] ?? 'Reveal failed',
                (int) ($result['status'] ?? 422)
            );
        }

        $payload = $result['data'] ?? [];

        return $this->ok([
            'message' => 'Revealed (one-time)',
            'product_name' => $payload['product_name'] ?? null,
            'license_key' => $payload['license_key'] ?? null,
            'payload' => $payload['payload'] ?? null,
        ]);
    }

    /**
     * Close modal => kirim email (qty==1 only, setelah reveal)
     */
    public function close(Request $request, $id, OrderFulfillmentService $fulfill, BrevoMailService $brevo)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $order = $this->loadOrderForUser((int)$id, (int)$user->id);
        if (!$order) return $this->fail('Order not found', 404);

        $totalQty = $this->getTotalQty($order);
        if ($totalQty !== 1) return $this->fail('Close flow only for total_qty=1', 422);

        $delivery = $order->deliveries->first();
        if (!$delivery) return $this->fail('Delivery not found', 404);

        if ($delivery->delivery_mode !== 'one_time') return $this->fail('Not one-time order', 422);
        if (!$delivery->revealed_at) return $this->fail('Reveal first before closing', 422);
        if ($delivery->emailed_at) return $this->ok(['message' => 'Email already sent']);

        $to = $order->email ?? $order->user?->email;
        if (!$to) return $this->fail('Order email not found', 422);

        $result = DB::transaction(function () use ($delivery, $order, $to, $fulfill, $brevo) {
            $d = Delivery::query()
                ->where('id', $delivery->id)
                ->lockForUpdate()
                ->with(['license.product'])
                ->first();

            if (!$d || !$d->license) {
                return ['ok' => false, 'message' => 'License not found'];
            }

            if ($d->emailed_at) {
                return ['ok' => true, 'message' => 'Email already sent'];
            }

            $items = [$fulfill->formatLicense($d->license)];

            $html = view('emails.digital-items', [
                'order' => $order,
                'items' => $items,
            ])->render();

            $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

            if (!($res['ok'] ?? false)) {
                return ['ok' => false, 'message' => 'Brevo send failed', 'details' => $res];
            }

            $d->emailed_at = now();
            $d->save();

            return ['ok' => true, 'message' => 'Email sent'];
        });

        if (!($result['ok'] ?? false)) {
            // jangan bocorin detail besar, tapi cukup
            return $this->fail($result['message'] ?? 'Failed', 500);
        }

        return $this->ok(['message' => $result['message']]);
    }

    /**
     * Resend email (qty>1 atau qty==1 yang sudah reveal)
     */
    public function resend(Request $request, $id, OrderFulfillmentService $fulfill, BrevoMailService $brevo)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $order = $this->loadOrderForUser((int)$id, (int)$user->id);
        if (!$order) return $this->fail('Order not found', 404);

        if ($order->deliveries->count() === 0) return $this->fail('No deliveries yet', 422);

        $to = $order->email ?? $order->user?->email;
        if (!$to) return $this->fail('Order email not found', 422);

        // ambil deliveries fresh
        $deliveries = Delivery::query()
            ->where('order_id', $order->id)
            ->with(['license.product'])
            ->get();

        if ($deliveries->isEmpty()) return $this->fail('No deliveries yet', 422);

        $items = $deliveries->map(fn($d) => $d->license ? $fulfill->formatLicense($d->license) : null)
            ->filter()
            ->values()
            ->all();

        $html = view('emails.digital-items', [
            'order' => $order,
            'items' => $items,
        ])->render();

        $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

        if (!($res['ok'] ?? false)) {
            return $this->fail('Brevo send failed', 500);
        }

        Delivery::where('order_id', $order->id)->update(['emailed_at' => now()]);

        return $this->ok(['message' => 'Resent']);
    }

    private function isPaidOrder(Order $order): bool
    {
        $orderStatus = (string) ($order->status?->value ?? $order->status);
        $paymentStatus = (string) ($order->payment->status?->value ?? $order->payment->status ?? null);

        return in_array($orderStatus, [
            OrderStatus::PAID->value,
            OrderStatus::FULFILLED->value,
        ], true) || in_array($paymentStatus, [
            PaymentStatus::PAID->value,
        ], true);
    }
}