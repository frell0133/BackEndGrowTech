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

class UserDeliveryController extends Controller
{
    use ApiResponse;

    public function info(Request $request, $id)
    {
        $user = $request->user();

        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->with(['deliveries.license','product'])
            ->first();

        if (!$order) return $this->fail('Order not found', 404);

        $qty = (int) ($order->qty ?? 1);

        // info untuk FE: boleh reveal atau tidak
        $canReveal = ($qty === 1)
            && $order->deliveries->count() === 1
            && $order->deliveries->first()->delivery_mode === 'one_time'
            && $order->deliveries->first()->revealed_at === null;

        return $this->ok([
            'order_id' => $order->id,
            'qty' => $qty,
            'delivery_mode' => $qty === 1 ? 'one_time' : 'email_only',
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

        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->with(['deliveries.license','product'])
            ->first();

        if (!$order) return $this->fail('Order not found', 404);

        // kalau belum ada deliveries, berarti belum fulfilled → coba fulfill (optional)
        if ($order->deliveries->count() === 0) {
            $r = $fulfill->fulfillPaidOrder($order);
            if (!($r['ok'] ?? false)) return $this->fail($r['message'] ?? 'Cannot fulfill', 422);

            $order->refresh()->load(['deliveries.license','product']);
        }

        $qty = (int) ($order->qty ?? 1);
        if ($qty !== 1) return $this->fail('Reveal only available for qty=1', 422);

        $delivery = $order->deliveries->first();
        if (!$delivery) return $this->fail('Delivery not found', 404);

        if ($delivery->delivery_mode !== 'one_time') {
            return $this->fail('This order is not one-time reveal', 422);
        }

        if ($delivery->revealed_at) {
            return $this->fail('Already revealed', 409);
        }

        // Lock supaya tidak bisa reveal 2x dalam race condition
        $payload = DB::transaction(function () use ($delivery) {
            $d = Delivery::where('id', $delivery->id)->lockForUpdate()->first();
            if ($d->revealed_at) {
                return null;
            }

            $d->revealed_at = now();
            $d->reveal_count = (int) ($d->reveal_count ?? 0) + 1;
            $d->save();

            $lic = $d->license;
            // return data yang akan ditampilkan di modal
            $data = [];
            if (isset($lic->code) && $lic->code) $data['code'] = $lic->code;
            if (isset($lic->payload) && $lic->payload) $data['payload'] = $lic->payload;

            return $data;
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
    public function close(Request $request, $id)
    {
        $user = $request->user();

        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->with(['deliveries.license','product'])
            ->first();

        if (!$order) return $this->fail('Order not found', 404);

        $qty = (int) ($order->qty ?? 1);
        if ($qty !== 1) return $this->fail('Close flow only for qty=1', 422);

        $delivery = $order->deliveries->first();
        if (!$delivery) return $this->fail('Delivery not found', 404);

        if ($delivery->delivery_mode !== 'one_time') return $this->fail('Not one-time order', 422);
        if (!$delivery->revealed_at) return $this->fail('Reveal first before closing', 422);
        if ($delivery->emailed_at) return $this->ok(['message' => 'Email already sent']);

        // kirim email + mark emailed_at (lock)
        DB::transaction(function () use ($delivery, $order) {
            $d = Delivery::where('id', $delivery->id)->lockForUpdate()->first();
            if ($d->emailed_at) return;

            $lic = $d->license;
            $items = [[
                'license_id' => $lic->id,
                'code' => $lic->code ?? null,
                'payload' => $lic->payload ?? null,
            ]];

            Mail::to($order->email ?? $order->user?->email)->send(
                new DigitalItemsMail($order, $items)
            );

            $d->emailed_at = now();
            $d->save();
        });

        return $this->ok(['message' => 'Email sent']);
    }

    /**
     * Resend email (qty>1 atau qty==1 yang sudah reveal)
     */
    public function resend(Request $request, $id)
    {
        $user = $request->user();

        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->with(['deliveries.license','product'])
            ->first();

        if (!$order) return $this->fail('Order not found', 404);

        if ($order->deliveries->count() === 0) return $this->fail('No deliveries yet', 422);

        $items = $order->deliveries->map(function ($d) {
            $lic = $d->license;
            return [
                'license_id' => $lic->id,
                'code' => $lic->code ?? null,
                'payload' => $lic->payload ?? null,
            ];
        })->values()->all();

        Mail::to($order->email ?? $order->user?->email)->send(
            new DigitalItemsMail($order, $items)
        );

        // optional: update emailed_at untuk semua deliveries
        Delivery::where('order_id', $order->id)->update(['emailed_at' => now()]);

        return $this->ok(['message' => 'Resent']);
    }
}
