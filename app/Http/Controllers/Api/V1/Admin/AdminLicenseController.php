<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminLicenseController extends Controller
{
    use ApiResponse;

    // =========================
    // Helpers
    // =========================
    private function parseBulkLines(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        // 1 baris = 1 stock item
        $lines = preg_split("/\r\n|\r|\n/", $raw);

        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Format: LICENSE_KEY:DATA_LAIN:CATATAN
            // explode max 3 biar catatan boleh mengandung ":" juga
            [$k, $d, $n] = array_pad(explode(':', $line, 3), 3, null);

            $licenseKey = trim((string) $k);
            $dataOther  = trim((string) ($d ?? '')) ?: null;
            $note       = trim((string) ($n ?? '')) ?: null;

            if ($licenseKey === '') continue;

            // fingerprint based on normalized full line (biar deteksi duplikat lebih kuat)
            $normalized = preg_replace('/\s+/', ' ', $licenseKey . ':' . ($dataOther ?? '') . ':' . ($note ?? ''));
            $fingerprint = hash('sha256', mb_strtolower($normalized));

            $items[] = [
                'license_key' => $licenseKey,
                'data_other' => $dataOther,
                'note' => $note,
                'fingerprint' => $fingerprint,
                'raw_line' => $line,
            ];
        }

        return $items;
    }

    private function productOr404(int $id): Product
    {
        $p = Product::find($id);
        abort_if(!$p, 404, 'Product not found');
        return $p;
    }

    // =========================
    // 1) LIST LICENSES (per product)
    // GET /api/v1/admin/products/{id}/licenses?status=available&q=...&per_page=50
    // =========================
    public function index(Request $request, int $id)
    {
        $product = $this->productOr404($id);

        $status = $request->query('status');
        $q = $request->query('q');
        $perPage = (int) $request->query('per_page', 50);

        $query = License::query()->where('product_id', $product->id);

        if ($status) $query->where('status', $status);

        if ($q) {
            $query->where(function ($qr) use ($q) {
                $qr->where('license_key', 'ilike', "%{$q}%")
                   ->orWhere('data_other', 'ilike', "%{$q}%")
                   ->orWhere('note', 'ilike', "%{$q}%");
            });
        }

        $data = $query->latest()->paginate($perPage);

        return $this->ok($data);
    }

    // =========================
    // 2) SUMMARY
    // GET /api/v1/admin/products/{id}/licenses/summary
    // =========================
    public function summary(Request $request, int $id)
    {
        $product = $this->productOr404($id);

        $base = License::query()->where('product_id', $product->id);

        $counts = [
            'available' => (clone $base)->where('status', 'available')->count(),
            'taken' => (clone $base)->where('status', 'taken')->count(),
            'reserved' => (clone $base)->where('status', 'reserved')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'disabled' => (clone $base)->where('status', 'disabled')->count(),
            'total' => (clone $base)->count(),
        ];

        return $this->ok([
            'product_id' => $product->id,
            'counts' => $counts,
        ]);
    }

    // =========================
    // 3) STORE SINGLE (manual add 1 item)
    // POST /api/v1/admin/products/{id}/licenses
    // body: { license_key, data_other?, note? }
    // =========================
    public function store(Request $request, int $id)
    {
        $product = $this->productOr404($id);

        $v = $request->validate([
            'license_key' => ['required','string','max:200'],
            'data_other' => ['nullable','string','max:200'],
            'note' => ['nullable','string','max:2000'],
        ]);

        $normalized = preg_replace('/\s+/', ' ', $v['license_key'] . ':' . ($v['data_other'] ?? '') . ':' . ($v['note'] ?? ''));
        $fingerprint = hash('sha256', mb_strtolower($normalized));

        try {
            $license = License::create([
                'product_id' => $product->id,
                'license_key' => $v['license_key'],
                'data_other' => $v['data_other'] ?? null,
                'note' => $v['note'] ?? null,
                'fingerprint' => $fingerprint,
                'status' => 'available',
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Data duplikat terdeteksi (sudah ada).', 409);
        }

        return $this->ok($license);
    }

    // =========================
    // 4) UPLOAD / BULK (textarea)
    // POST /api/v1/admin/products/{id}/licenses/upload
    // body: { bulk_text: "line1\nline2\n", mode: "skip|fail" }
    // =========================
    public function upload(Request $request, int $id)
    {
        $product = $this->productOr404($id);

        $v = $request->validate([
            'bulk_text' => ['required','string'],
            'mode' => ['nullable','in:skip,fail'], // skip duplikat / fail jika ada duplikat
        ]);

        $mode = $v['mode'] ?? 'skip';
        $items = $this->parseBulkLines($v['bulk_text']);

        if (count($items) === 0) {
            return $this->fail('Tidak ada baris valid. Pastikan 1 baris = 1 data.', 422);
        }

        return DB::transaction(function () use ($product, $items, $mode) {
            $inserted = 0;
            $duplicates = 0;
            $duplicateLines = [];

            foreach ($items as $i => $it) {
                try {
                    License::create([
                        'product_id' => $product->id,
                        'license_key' => $it['license_key'],
                        'data_other' => $it['data_other'],
                        'note' => $it['note'],
                        'fingerprint' => $it['fingerprint'],
                        'status' => 'available',
                    ]);
                    $inserted++;
                } catch (\Throwable $e) {
                    $duplicates++;
                    $duplicateLines[] = $i + 1;

                    if ($mode === 'fail') {
                        // rollback transaction
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
    }

    // =========================
    // 5) CHECK DUPLICATES (preview)
    // POST /api/v1/admin/licenses/check-duplicates
    // body: { product_id, bulk_text }
    // =========================
    public function checkDuplicates(Request $request)
    {
        $v = $request->validate([
            'product_id' => ['required','integer'],
            'bulk_text' => ['required','string'],
        ]);

        $product = $this->productOr404((int)$v['product_id']);
        $items = $this->parseBulkLines($v['bulk_text']);

        if (count($items) === 0) {
            return $this->ok([
                'product_id' => $product->id,
                'total_lines' => 0,
                'duplicates' => 0,
                'duplicate_lines' => [],
            ]);
        }

        // cek fingerprint yg sudah ada
        $fps = array_values(array_unique(array_map(fn($x) => $x['fingerprint'], $items)));

        $existing = License::query()
            ->where('product_id', $product->id)
            ->whereIn('fingerprint', $fps)
            ->pluck('fingerprint')
            ->all();

        $existingSet = array_flip($existing);

        $dupLines = [];
        foreach ($items as $idx => $it) {
            if (isset($existingSet[$it['fingerprint']])) {
                $dupLines[] = $idx + 1;
            }
        }

        return $this->ok([
            'product_id' => $product->id,
            'total_lines' => count($items),
            'duplicates' => count($dupLines),
            'duplicate_lines' => $dupLines,
        ]);
    }

    // =========================
    // 6) TAKE STOCK (inti UI kamu)
    // POST /api/v1/admin/products/{id}/take-stock
    // body: { qty: 10 }
    // =========================
    public function takeStock(Request $request, int $id)
    {
        $product = $this->productOr404($id);

        $v = $request->validate([
            'qty' => ['required','integer','min:1','max:500'],
        ]);

        $qty = (int) $v['qty'];
        $actorId = optional($request->user())->id;

        return DB::transaction(function () use ($product, $qty, $actorId) {

            // Lock biar aman dari double take
            $stocks = License::query()
                ->where('product_id', $product->id)
                ->where('status', 'available')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($stocks->count() === 0) {
                return $this->fail('Stock Tidak Tersedia', 409);
            }

            foreach ($stocks as $s) {
                $s->status = 'taken';
                $s->taken_by = $actorId;
                $s->taken_at = now();
                $s->save();
            }

            // generate proof file (txt) — no extra package
            $proofId = 'proof_' . now()->format('Ymd_His') . '_' . Str::random(8);
            $path = "stock_proofs/{$proofId}.txt";

            $contentLines = [];
            $contentLines[] = "PROOF ID: {$proofId}";
            $contentLines[] = "PRODUCT ID: {$product->id}";
            $contentLines[] = "PRODUCT NAME: {$product->name}";
            $contentLines[] = "TAKEN AT: " . now()->toDateTimeString();
            $contentLines[] = "TAKEN BY: " . ($actorId ?? 'system');
            $contentLines[] = "QTY: " . $stocks->count();
            $contentLines[] = "----------------------------------------";
            foreach ($stocks as $s) {
                // 1 baris sesuai input awal
                $line = $s->license_key;
                if ($s->data_other) $line .= ":" . $s->data_other;
                if ($s->note) $line .= ":" . $s->note;
                $contentLines[] = $line;
            }
            $contentLines[] = "----------------------------------------";
            $content = implode("\n", $contentLines);

            Storage::disk('local')->put($path, $content);

            // Kembalikan payload utk FE (modal “Stock Terambil”)
            return $this->ok([
                'product_id' => $product->id,
                'requested_qty' => $qty,
                'taken_qty' => $stocks->count(),
                'message' => $stocks->count() >= $qty ? 'Stock Terambil' : 'Stock Terambil Sebagian',
                'proof' => [
                    'proof_id' => $proofId,
                    'path' => $path,
                ],
                'items' => $stocks->map(fn($s) => [
                    'id' => $s->id,
                    'license_key' => $s->license_key,
                    'data_other' => $s->data_other,
                    'note' => $s->note,
                    'status' => $s->status,
                ])->values(),
            ]);
        });
    }

    // =========================
    // 7) PROOF LIST
    // GET /api/v1/admin/stock/proofs
    // =========================
    public function proofList()
    {
        $files = Storage::disk('local')->files('stock_proofs');

        $items = collect($files)
            ->filter(fn($f) => str_ends_with($f, '.txt'))
            ->map(function ($f) {
                return [
                    'proof_id' => basename($f, '.txt'),
                    'path' => $f,
                    'updated_at' => Storage::disk('local')->lastModified($f),
                    'size' => Storage::disk('local')->size($f),
                ];
            })
            ->sortByDesc('updated_at')
            ->values();

        return $this->ok($items);
    }

    // =========================
    // 8) PROOF DOWNLOAD
    // GET /api/v1/admin/stock/proofs/{proof_id}
    // =========================
    public function proofDownload(string $proof_id)
    {
        $path = "stock_proofs/{$proof_id}.txt";

        if (!Storage::disk('local')->exists($path)) {
            return $this->fail('Proof tidak ditemukan', 404);
        }

        return Storage::disk('local')->download($path, "{$proof_id}.txt");
    }
}
