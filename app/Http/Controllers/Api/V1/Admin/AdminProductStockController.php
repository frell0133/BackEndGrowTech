<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockLog;
use App\Services\StockParser;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProductStockController extends Controller
{
  use ApiResponse;

  private function bumpCatalogCaches(): void
  {
    PublicCache::bumpCatalog();
    PublicCache::bumpDashboard();
  }

  /**
   * GET /api/v1/admin/products/{product}/stocks?status=available&per_page=50
   */
  public function index(Request $request, Product $product)
  {
    $status = $request->query('status');
    $perPage = (int) $request->query('per_page', 50);

    $q = $product->stocks()
      ->when($status, fn($qr) => $qr->where('status', $status))
      ->latest()
      ->paginate($perPage);

    return $this->ok($q);
  }

  /**
   * POST /api/v1/admin/products/{product}/stocks/import
   * body: { bulk_text: "line1\nline2\n..." }
   */
  public function import(Request $request, Product $product, StockParser $parser)
  {
    $data = $request->validate([
      'bulk_text' => ['required','string'],
      'mode' => ['nullable','in:skip,fail'], // skip duplikat / fail jika ada duplikat
    ]);

    $mode = $data['mode'] ?? 'skip';
    $items = $parser->parseLines($data['bulk_text']);

    if (count($items) === 0) {
      return $this->fail('Tidak ada baris stock yang valid.', 422);
    }

    $actorId = optional($request->user())->id;

    $response = DB::transaction(function () use ($product, $items, $mode, $actorId) {
      $inserted = 0;
      $duplicates = 0;
      $duplicateLines = [];

      foreach ($items as $i => $it) {
        try {
          $stock = ProductStock::create([
            'product_id' => $product->id,
            'stock_data' => $it['stock_data'],
            'fingerprint' => $it['fingerprint'],
            'status' => 'available',
          ]);

          ProductStockLog::create([
            'product_stock_id' => $stock->id,
            'actor_id' => $actorId,
            'action' => 'import',
            'meta' => ['line' => $i + 1],
          ]);

          $inserted++;
        } catch (\Throwable $e) {
          // duplicate unique(product_id,fingerprint) akan masuk sini
          $duplicates++;
          $duplicateLines[] = $i + 1;

          if ($mode === 'fail') {
            throw $e;
          }
        }
      }

      return $this->ok([
        'product_id' => $product->id,
        'inserted' => $inserted,
        'duplicates' => $duplicates,
        'duplicate_lines' => $duplicateLines,
      ]);
    });

    $this->bumpCatalogCaches();

    return $response;
  }

  /**
   * POST /api/v1/admin/products/{product}/stocks/take
   * body: { qty: 10 }
   * Ambil stock available -> taken (lock aman)
   */
  public function take(Request $request, Product $product)
  {
    $data = $request->validate([
      'qty' => ['required','integer','min:1','max:200'],
    ]);

    $qty = (int) $data['qty'];
    $actorId = optional($request->user())->id;

    $response = DB::transaction(function () use ($product, $qty, $actorId) {
      // lock rows available
      $stocks = ProductStock::where('product_id', $product->id)
        ->where('status', 'available')
        ->orderBy('id')
        ->lockForUpdate()
        ->limit($qty)
        ->get();

      if ($stocks->count() === 0) {
        return $this->fail('Stock tidak tersedia.', 409);
      }

      // jika kurang dari qty, tetap ambil yang ada (atau kamu bisa fail)
      foreach ($stocks as $s) {
        $s->status = 'taken';
        $s->taken_by = $actorId;
        $s->taken_at = now();
        $s->save();

        ProductStockLog::create([
          'product_stock_id' => $s->id,
          'actor_id' => $actorId,
          'action' => 'take',
          'meta' => ['product_id' => $product->id],
        ]);
      }

      return $this->ok([
        'taken' => $stocks->count(),
        'requested' => $qty,
        'items' => $stocks->map(fn($s) => [
          'id' => $s->id,
          'stock_data' => $s->stock_data,
          'status' => $s->status,
        ]),
      ]);
    });

    $this->bumpCatalogCaches();

    return $response;
  }

  /**
   * POST /api/v1/admin/stocks/{stock}/release
   * Balikin taken/reserved -> available
   */
  public function release(Request $request, ProductStock $stock)
  {
    $actorId = optional($request->user())->id;

    $response = DB::transaction(function () use ($stock, $actorId) {
      if (!in_array($stock->status, ['taken','reserved'])) {
        return $this->fail('Stock tidak bisa direlease dari status ini.', 422);
      }

      $stock->status = 'available';
      $stock->taken_by = null;
      $stock->taken_at = null;
      $stock->reserved_order_id = null;
      $stock->reserved_at = null;
      $stock->save();

      ProductStockLog::create([
        'product_stock_id' => $stock->id,
        'actor_id' => $actorId,
        'action' => 'release',
        'meta' => null,
      ]);

      return $this->ok($stock);
    });

    $this->bumpCatalogCaches();

    return $response;
  }

  /**
   * POST /api/v1/admin/stocks/{stock}/disable
   */
  public function disable(Request $request, ProductStock $stock)
  {
    $actorId = optional($request->user())->id;

    if ($stock->status === 'disabled') return $this->ok($stock);

    $stock->status = 'disabled';
    $stock->save();

    ProductStockLog::create([
      'product_stock_id' => $stock->id,
      'actor_id' => $actorId,
      'action' => 'disable',
      'meta' => null,
    ]);

    $this->bumpCatalogCaches();

    return $this->ok($stock);
  }
}
