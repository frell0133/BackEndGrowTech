<?php

namespace App\Services;

use App\Models\Order;
use App\Models\License;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\DigitalItemsMail;
use App\Jobs\SendDigitalItemsFallbackEmail;

class OrderFulfillmentService
{
    /**
     * Fulfill order ketika payment sudah PAID.
     * - qty total == 1 => one_time + fallback email (delay 3 menit)
     * - qty total > 1  => email_only langsung (via QUEUE)
     *
     * NOTE: Email selalu QUEUE supaya kalau SMTP error tidak rollback transaksi.
     */
    public function fulfillPaidOrder(Order $order): array
    {
        // idempotent: kalau sudah punya deliveries, jangan ulang
        if ($order->deliveries()->count() > 0) {
            return ['ok' => true, 'message' => 'Already fulfilled'];
        }

        $emailTo = $order->email ?? $order->user?->email;

        $result = DB::transaction(function () use ($order) {

            $items = $order->items()->get();

            // fallback legacy (kalau order lama belum punya items)
            if ($items->isEmpty()) {
                $legacyQty = (int) ($order->qty ?? 1);
                $legacyProductId = (int) ($order->product_id ?? 0);

                if ($legacyProductId <= 0 || $legacyQty <= 0) {
                    return ['ok' => false, 'message' => 'Order items empty'];
                }

                $items = collect([(object)[
                    'product_id' => $legacyProductId,
                    'qty' => $legacyQty,
                ]]);
            }

            $totalQty = (int) $items->sum('qty');
            $mode = ($totalQty === 1) ? 'one_time' : 'email_only';

            $allAllocated = collect();

            foreach ($items as $it) {
                $productId = (int) $it->product_id;
                $qty = (int) ($it->qty ?? 1);

                $licenses = License::query()
                    ->where('product_id', $productId)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->limit($qty)
                    ->get();

                if ($licenses->count() < $qty) {
                    return ['ok' => false, 'message' => 'Stock not enough for product_id=' . $productId];
                }

                // mark used
                $ids = $licenses->pluck('id')->all();
                License::whereIn('id', $ids)->update([
                    'status' => 'used',
                    'used_at' => now(),
                ]);

                foreach ($licenses as $lic) {
                    Delivery::create([
                        'order_id' => $order->id,
                        'license_id' => $lic->id,
                        'delivery_mode' => $mode,
                        'reveal_count' => 0,
                        'revealed_at' => null,
                        'emailed_at' => ($totalQty === 1) ? null : now(),
                    ]);
                }

                $allAllocated = $allAllocated->merge($licenses);
            }

            return [
                'ok' => true,
                'message' => 'Fulfilled',
                'mode' => $mode,
                'totalQty' => $totalQty,
                'licenses' => $allAllocated->values(),
            ];
        });

        // OUTSIDE TRANSACTION: kirim email via QUEUE supaya tidak rollback
        if (!($result['ok'] ?? false)) return $result;

        $totalQty = (int) ($result['totalQty'] ?? 0);

        // qty > 1 => email langsung (queue)
        if ($totalQty > 1) {
            try {
                $itemsEmail = collect($result['licenses'] ?? [])
                    ->map(fn($lic) => $this->formatLicense($lic))
                    ->values()
                    ->all();

                Mail::to($emailTo)->queue(new DigitalItemsMail($order, $itemsEmail));
            } catch (\Throwable $e) {
                // jangan gagal-kan order
                Log::error('EMAIL_QUEUE_FAILED (EMAIL_ONLY)', [
                    'order_id' => $order->id,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        // qty == 1 => schedule fallback email 3 menit
        if ($totalQty === 1) {
            $job = SendDigitalItemsFallbackEmail::dispatch($order->id)
                ->delay(now()->addMinutes(3));

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }
        }

        return $result;
    }

    public function formatLicense(License $license): array
    {
        $data = [];

        if (isset($license->code) && $license->code) {
            $data['code'] = $license->code;
        }
        if (isset($license->payload) && $license->payload) {
            $data['payload'] = $license->payload;
        }

        $data['license_id'] = $license->id;
        return $data;
    }
}