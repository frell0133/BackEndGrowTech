<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductStock;
use App\Models\ProductStockLog;
use Illuminate\Support\Facades\DB;
use App\Support\PublicCache;

class StockAllocator
{
  /**
   * Reserve stock untuk order: available -> reserved
   * qty ambil dari order items (asumsi 1 product per order atau ada order_items).
   *
   * Untuk versi simpel: anggap order punya product_id & qty.
   * Kalau kamu pakai order_items, tinggal loop items.
   */
  public function reserveForOrder(Order $order, int $productId, int $qty, ?int $actorId = null): array
  {
    $result = DB::transaction(function () use ($order, $productId, $qty, $actorId) {

      $stocks = ProductStock::where('product_id', $productId)
        ->where('status', 'available')
        ->orderBy('id')
        ->lockForUpdate()
        ->limit($qty)
        ->get();

      if ($stocks->count() < $qty) {
        // reserve parsial? default: fail
        return [
          'ok' => false,
          'reserved' => 0,
          'needed' => $qty,
          'available' => $stocks->count(),
        ];
      }

      foreach ($stocks as $s) {
        $s->status = 'reserved';
        $s->reserved_order_id = $order->id;
        $s->reserved_at = now();
        $s->save();

        ProductStockLog::create([
          'product_stock_id' => $s->id,
          'actor_id' => $actorId,
          'action' => 'reserve',
          'meta' => ['order_id' => $order->id],
        ]);
      }

      return [
        'ok' => true,
        'reserved' => $stocks->count(),
        'items' => $stocks->pluck('id')->all(),
      ];
    });
  }

  /**
   * Deliver stock: reserved -> delivered (return stock_data)
   */
  public function deliver(Order $order, ?int $actorId = null): array
  {
    $result = DB::transaction(function () use ($order, $actorId) {
      $stocks = ProductStock::where('reserved_order_id', $order->id)
        ->where('status', 'reserved')
        ->orderBy('id')
        ->lockForUpdate()
        ->get();

      foreach ($stocks as $s) {
        $s->status = 'delivered';
        $s->delivered_at = now();
        $s->save();

        ProductStockLog::create([
          'product_stock_id' => $s->id,
          'actor_id' => $actorId,
          'action' => 'deliver',
          'meta' => ['order_id' => $order->id],
        ]);
      }

      return [
        'count' => $stocks->count(),
        'items' => $stocks->map(fn($s) => [
          'id' => $s->id,
          'stock_data' => $s->stock_data,
        ])->values(),
      ];
    });
  }
}
