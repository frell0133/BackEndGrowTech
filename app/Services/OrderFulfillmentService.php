<?php

namespace App\Services;

use App\Models\Order;
use App\Models\License;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendDigitalItemsFallbackEmail;
use App\Services\BrevoMailService;
use Illuminate\Support\Facades\Log;

class OrderFulfillmentService
{
    /**
     * Fulfill order ketika status payment sudah PAID.
     * Rules:
     * - qty total == 1 => one_time (reveal sekali) + fallback email otomatis (delay 3 menit)
     * - qty total > 1  => email_only langsung kirim email (via Brevo API)
     */
    public function fulfillPaidOrder(Order $order): array
    {
        Log::info('FULFILL START', [
            'order_id' => $order->id,
            'invoice_number' => $order->invoice_number ?? null,
        ]);

        if ($order->deliveries()->count() > 0) {
            Log::info('FULFILL SKIP ALREADY_DELIVERED', ['order_id' => $order->id]);
            return ['ok' => true, 'message' => 'Already fulfilled'];
        }

        $result = DB::transaction(function () use ($order) {
            $items = $order->items()->get();

            Log::info('FULFILL ITEMS CHECK', [
                'order_id' => $order->id,
                'items_count' => $items->count(),
                'legacy_product_id' => $order->product_id ?? null,
                'legacy_qty' => $order->qty ?? null,
            ]);

            if ($items->isEmpty()) {
                $legacyQty = (int) ($order->qty ?? 1);
                $legacyProductId = (int) ($order->product_id ?? 0);

                if ($legacyProductId <= 0 || $legacyQty <= 0) {
                    Log::error('FULFILL FAIL ORDER_ITEMS_EMPTY', [
                        'order_id' => $order->id,
                        'legacy_product_id' => $legacyProductId,
                        'legacy_qty' => $legacyQty,
                    ]);

                    return ['ok' => false, 'message' => 'Order items empty'];
                }

                $items = collect([(object)[
                    'product_id' => $legacyProductId,
                    'qty' => $legacyQty,
                ]]);
            }

            $totalQty = (int) $items->sum('qty');
            $mode = ($totalQty === 1) ? 'one_time' : 'email_only';

            foreach ($items as $it) {
                $productId = (int) $it->product_id;
                $qty = (int) ($it->qty ?? 1);

                $licenses = License::query()
                    ->where('product_id', $productId)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->limit($qty)
                    ->get();

                Log::info('FULFILL STOCK CHECK', [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'need_qty' => $qty,
                    'available_found' => $licenses->count(),
                    'license_ids' => $licenses->pluck('id')->all(),
                ]);

                if ($licenses->count() < $qty) {
                    Log::error('FULFILL FAIL STOCK_NOT_ENOUGH', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'need_qty' => $qty,
                        'available_found' => $licenses->count(),
                    ]);

                    return ['ok' => false, 'message' => 'Stock not enough for product_id=' . $productId];
                }

                License::query()
                    ->whereIn('id', $licenses->pluck('id')->all())
                    ->update([
                        'status' => 'sold',
                        'order_id' => $order->id,
                        'sold_at' => now(),
                    ]);

                foreach ($licenses as $lic) {
                    Delivery::create([
                        'order_id' => $order->id,
                        'license_id' => $lic->id,
                        'delivery_mode' => $mode,
                        'reveal_count' => 0,
                        'revealed_at' => null,
                        'emailed_at' => null,
                    ]);
                }
            }

            Log::info('FULFILL SUCCESS ALLOCATION_DONE', [
                'order_id' => $order->id,
                'total_qty' => $totalQty,
                'mode' => $mode,
            ]);

            return ['ok' => true, 'message' => 'Fulfilled', 'mode' => $mode, 'totalQty' => $totalQty];
        });

        // Email untuk qty > 1
        if (($result['ok'] ?? false) && (($result['totalQty'] ?? 0) > 1)) {
            $to = $order->email ?? $order->user?->email;

            Log::info('FULFILL EMAIL_ONLY SEND_ATTEMPT', [
                'order_id' => $order->id,
                'to' => $to,
            ]);

            if ($to) {
                $deliveries = Delivery::query()
                    ->where('order_id', $order->id)
                    ->with('license.product')
                    ->get();

                $itemsEmail = $deliveries
                    ->map(fn($d) => $this->formatLicense($d->license))
                    ->values()
                    ->all();

                $html = view('emails.digital-items', [
                    'order' => $order,
                    'items' => $itemsEmail,
                ])->render();

                $brevo = app(BrevoMailService::class);
                $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

                if ($res['ok'] ?? false) {
                    Delivery::query()
                        ->where('order_id', $order->id)
                        ->where('delivery_mode', 'email_only')
                        ->whereNull('emailed_at')
                        ->update(['emailed_at' => now()]);

                    Log::info('FULFILL EMAIL_ONLY SEND_SUCCESS', ['order_id' => $order->id]);
                } else {
                    Log::error('FULFILL EMAIL_ONLY SEND_FAIL', [
                        'order_id' => $order->id,
                        'brevo_response' => $res,
                    ]);

                    return [
                        'ok' => false,
                        'message' => 'Brevo send failed',
                        'details' => $res,
                    ];
                }
            } else {
                Log::error('FULFILL EMAIL_ONLY NO_RECIPIENT', ['order_id' => $order->id]);
            }
        }

        if (($result['ok'] ?? false) && (($result['totalQty'] ?? 0) === 1)) {
            $job = SendDigitalItemsFallbackEmail::dispatch($order->id)->delay(now()->addMinutes(3));
            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }
        }

        return $result;
    }

    public function formatLicense(License $license): array
    {
        $data = [];

        // ✅ Nama product (biar jelas key ini product apa)
        $data['product_name'] = $license->product?->name ?? null;

        // ✅ Ini yang tampil di admin: license_key
        $data['license_key'] = $license->license_key ?? null;

        // ✅ Extra info kalau ada
        $payload = [];

        // metadata (jsonb)
        if (!empty($license->metadata)) {
            $meta = $license->metadata;

            // kalau metadata masih string json, decode
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                if (json_last_error() === JSON_ERROR_NONE) $meta = $decoded;
            }

            $payload['metadata'] = $meta;
        }

        if (!empty($license->data_other)) $payload['data_other'] = $license->data_other;
        if (!empty($license->note))      $payload['note'] = $license->note;

        if (!empty($payload)) $data['payload'] = $payload;

        return $data;
    }

}