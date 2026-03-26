<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Services\BrevoMailService;
use App\Services\OrderFulfillmentService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserDeliveryController extends Controller
{
    use ApiResponse;

    private function loadOrderForUser(int $id, int $userId): ?Order
    {
        return Order::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->with([
                'user',
                'items',
                'deliveries.license.product',
                'product',
                'payment',
            ])
            ->first();
    }

    private function getTotalQty(Order $order): int
    {
        if ($order->relationLoaded('items') && $order->items && $order->items->count() > 0) {
            return (int) $order->items->sum('qty');
        }

        return (int) ($order->qty ?? 1);
    }

    private function buildDeliveryEmailItems(Order $order, OrderFulfillmentService $fulfill): array
    {
        return $order->deliveries
            ->map(fn ($delivery) => $delivery->license ? $fulfill->formatLicense($delivery->license) : null)
            ->filter(fn ($item) => !empty($item['license_key']) || !empty($item['payload']) || !empty($item['product_name']))
            ->values()
            ->all();
    }

    private function deliveryMailLockKey(int $orderId): string
    {
        return 'delivery:mail:order:' . $orderId;
    }

    public function info(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $order = $this->loadOrderForUser((int) $id, (int) $user->id);
        if (!$order) {
            return $this->fail('Order not found', 404);
        }

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
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $order = $this->loadOrderForUser((int) $id, (int) $user->id);
        if (!$order) {
            return $this->fail('Order not found', 404);
        }

        if (!$this->isPaidOrder($order)) {
            return $this->fail('Order belum dibayar', 422);
        }

        if ($order->deliveries->count() === 0) {
            $result = $fulfill->fulfillPaidOrder($order);
            if (!($result['ok'] ?? false)) {
                return $this->fail($result['message'] ?? 'Cannot fulfill', 422);
            }

            $order = $this->loadOrderForUser((int) $id, (int) $user->id);
            if (!$order) {
                return $this->fail('Order not found', 404);
            }
        }

        $totalQty = $this->getTotalQty($order);
        if ($totalQty !== 1) {
            return $this->fail('Reveal only available for total_qty=1', 422);
        }

        $delivery = $order->deliveries->first();
        if (!$delivery) {
            return $this->fail('Delivery not found', 404);
        }

        if ($delivery->delivery_mode !== 'one_time') {
            return $this->fail('This order is not one-time reveal', 422);
        }

        if ($delivery->revealed_at) {
            return $this->fail('Already revealed', 409);
        }

        $result = DB::transaction(function () use ($delivery, $fulfill) {
            $lockedDelivery = Delivery::query()
                ->where('id', $delivery->id)
                ->lockForUpdate()
                ->with(['license.product'])
                ->first();

            if (!$lockedDelivery) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'Delivery not found',
                ];
            }

            if (!$lockedDelivery->license) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'License not found',
                ];
            }

            if ($lockedDelivery->revealed_at) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'message' => 'Already revealed',
                ];
            }

            $lockedDelivery->revealed_at = now();
            $lockedDelivery->reveal_count = (int) ($lockedDelivery->reveal_count ?? 0) + 1;
            $lockedDelivery->save();

            return [
                'ok' => true,
                'data' => $fulfill->formatLicense($lockedDelivery->license),
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
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $order = $this->loadOrderForUser((int) $id, (int) $user->id);
        if (!$order) {
            return $this->fail('Order not found', 404);
        }

        $lock = Cache::lock($this->deliveryMailLockKey((int) $order->id), 30);
        if (!$lock->get()) {
            return $this->fail('Pengiriman email delivery sedang diproses, coba lagi beberapa detik', 409);
        }

        try {
            $freshOrder = $this->loadOrderForUser((int) $id, (int) $user->id);
            if (!$freshOrder) {
                return $this->fail('Order not found', 404);
            }

            $totalQty = $this->getTotalQty($freshOrder);
            if ($totalQty !== 1) {
                return $this->fail('Close flow only for total_qty=1', 422);
            }

            $delivery = $freshOrder->deliveries->first();
            if (!$delivery) {
                return $this->fail('Delivery not found', 404);
            }

            if ($delivery->delivery_mode !== 'one_time') {
                return $this->fail('Not one-time order', 422);
            }

            if (!$delivery->revealed_at) {
                return $this->fail('Reveal first before closing', 422);
            }

            if ($delivery->emailed_at) {
                return $this->ok(['message' => 'Email already sent']);
            }

            $to = $freshOrder->email ?? $freshOrder->user?->email;
            if (!$to) {
                return $this->fail('Order email not found', 422);
            }

            $items = $this->buildDeliveryEmailItems($freshOrder, $fulfill);
            if (empty($items)) {
                return $this->fail('License not found', 404);
            }

            $html = view('emails.digital-items', [
                'order' => $freshOrder,
                'items' => $items,
            ])->render();

            $result = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);
            if (!($result['ok'] ?? false)) {
                return $this->fail('Brevo send failed', 500);
            }

            $message = DB::transaction(function () use ($delivery) {
                $lockedDelivery = Delivery::query()
                    ->where('id', (int) $delivery->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedDelivery) {
                    return 'Delivery not found';
                }

                if ($lockedDelivery->emailed_at) {
                    return 'Email already sent';
                }

                $lockedDelivery->emailed_at = now();
                $lockedDelivery->save();

                return 'Email sent';
            });

            return $this->ok(['message' => $message]);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * Resend email (qty>1 atau qty==1 yang sudah reveal)
     */
    public function resend(Request $request, $id, OrderFulfillmentService $fulfill, BrevoMailService $brevo)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $order = $this->loadOrderForUser((int) $id, (int) $user->id);
        if (!$order) {
            return $this->fail('Order not found', 404);
        }

        $lock = Cache::lock($this->deliveryMailLockKey((int) $order->id), 30);
        if (!$lock->get()) {
            return $this->fail('Pengiriman email delivery sedang diproses, coba lagi beberapa detik', 409);
        }

        try {
            $freshOrder = $this->loadOrderForUser((int) $id, (int) $user->id);
            if (!$freshOrder) {
                return $this->fail('Order not found', 404);
            }

            if ($freshOrder->deliveries->count() === 0) {
                return $this->fail('No deliveries yet', 422);
            }

            $to = $freshOrder->email ?? $freshOrder->user?->email;
            if (!$to) {
                return $this->fail('Order email not found', 422);
            }

            $items = $this->buildDeliveryEmailItems($freshOrder, $fulfill);
            if (empty($items)) {
                return $this->fail('No deliveries yet', 422);
            }

            $html = view('emails.digital-items', [
                'order' => $freshOrder,
                'items' => $items,
            ])->render();

            $result = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);
            if (!($result['ok'] ?? false)) {
                return $this->fail('Brevo send failed', 500);
            }

            DB::transaction(function () use ($freshOrder) {
                Delivery::query()
                    ->where('order_id', (int) $freshOrder->id)
                    ->lockForUpdate()
                    ->get();

                Delivery::query()
                    ->where('order_id', (int) $freshOrder->id)
                    ->update(['emailed_at' => now()]);
            });

            return $this->ok(['message' => 'Resent']);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }
        }
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
