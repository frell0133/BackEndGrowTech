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
                'product',               // legacy (kalau masih dipakai)
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

        // info untuk FE: boleh reveal atau tidak
        $canReveal = ($totalQty === 1)
            && $order->deliveries->count() === 1
            && $firstDelivery
            && $firstDelivery->delivery_mode === 'one_time'
            && $firstDelivery->revealed_at === null;

        return $this->ok([
            'order_id' => $order->id,
            'total_qty' => $totalQty,
            'delivery_mode' => $totalQty === 1 ? 'one_time' : 'email_only',
            'deliveries_count' => $order->deliveries->count(),
            'can_reveal' => $canReveal,
            'emailed' => $order->deliveries->whereNotNull('emailed_at')->count() > 0,
        ]);
    }

    /**
     * Reveal one-time (qty==1 only)
     */
    public function reveal(Request $request, $id, OrderFulfillmentService $fulfill)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $order = $this->loadOrderForUser((int)$id, (int)$user->id);
        if (!$order) return $this->fail('Order not found', 404);

        // kalau belum ada deliveries, berarti belum fulfilled → coba fulfill (optional)
        if ($order->deliveries->count() === 0) {
            $r = $fulfill->fulfillPaidOrder($order);
            if (!($r['ok'] ?? false)) return $this->fail($r['message'] ?? 'Cannot fulfill', 422);

            $order->refresh();
            $order = $this->loadOrderForUser((int)$id, (int)$user->id);
            if (!$order) return $this->fail('Order not found', 404);
        }

        $totalQty = $this->getTotalQty($order);
        if ($totalQty !== 1) return $this->fail('Reveal only available for total_qty=1', 422);

        $delivery = $order->deliveries->first();
        if (!$delivery) return $this->fail('Delivery not found', 404);

        if ($delivery->delivery_mode !== 'one_time') {
            return $this->fail('This order is not one-time reveal', 422);
        }

        if ($delivery->revealed_at) {
            return $this->fail('Already revealed', 409);
        }

        // Lock supaya tidak bisa reveal 2x dalam race condition
        $payload = DB::transaction(function () use ($delivery, $fulfill) {
            $d = Delivery::query()
                ->where('id', $delivery->id)
                ->lockForUpdate()
                ->with(['license.product'])
                ->first();

            if (!$d || !$d->license) return null;

            if ($d->revealed_at) {
                return null;
            }

            $d->revealed_at = now();
            $d->reveal_count = (int) ($d->reveal_count ?? 0) + 1;
            $d->save();

            // ✅ return data yang akan ditampilkan di modal (license_key)
            return $fulfill->formatLicense($d->license);
        });

        if ($payload === null) return $this->fail('Already revealed', 409);

        return $this->ok([
            'message' => 'Revealed (one-time)',
            'data' => $payload,
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
}