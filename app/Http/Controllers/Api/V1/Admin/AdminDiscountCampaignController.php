<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\DiscountCampaign;
use App\Models\DiscountCampaignTarget;
use App\Models\Subcategory;
use App\Http\Resources\DiscountCampaignResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminDiscountCampaignController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/admin/discount-campaigns
     * Untuk FE list "Manajemen Discount"
     */
    public function index(Request $request)
    {
        $q = $request->query('q');

        $paginator = DiscountCampaign::query()
            ->with(['targets']) // ambil target untuk mapping subcategory
            ->when($q, fn($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        // resolve subcategory + category untuk masing-masing campaign (agar FE bisa tampil kategori/subkategori)
        $items = collect($paginator->items());

        $subIds = $items->flatMap(function ($c) {
            return $c->targets
                ->where('target_type', 'subcategory')
                ->pluck('target_id');
        })->unique()->values();

        $subMap = Subcategory::query()
            ->with('category:id,name')
            ->whereIn('id', $subIds)
            ->get()
            ->keyBy('id');

        // inject resolved_subcategory agar Resource bisa baca
        $items->each(function ($c) use ($subMap) {
            $subTarget = $c->targets->firstWhere('target_type', 'subcategory');
            $c->resolved_subcategory = $subTarget ? ($subMap[(int)$subTarget->target_id] ?? null) : null;
        });

        // replace paginator items with modified objects
        $paginator->setCollection($items);

        return $this->ok([
            'data' => DiscountCampaignResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $campaign = DiscountCampaign::with(['targets'])->findOrFail($id);

        // resolve subcategory + category (untuk FE edit)
        $subTarget = $campaign->targets->firstWhere('target_type', 'subcategory');
        if ($subTarget) {
            $campaign->resolved_subcategory = Subcategory::with('category:id,name')
                ->find((int) $subTarget->target_id);
        } else {
            $campaign->resolved_subcategory = null;
        }

        return $this->ok(new DiscountCampaignResource($campaign));
    }

    /**
     * POST /api/v1/admin/discount-campaigns
     * NOTE: Untuk FE kamu, kita tambahin opsi langsung kirim subcategory_id sebagai target utama
     */
    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => ['required','string','max:150'],
            'slug' => ['nullable','string','max:150'],
            'enabled' => ['required','boolean'],

            'starts_at' => ['nullable','date'],
            'ends_at' => ['nullable','date'],

            'discount_type' => ['required','in:percent,fixed'],
            'discount_value' => ['required','integer','min:1'],

            'max_discount_amount' => ['nullable','integer','min:1'],
            'min_order_amount' => ['nullable','integer','min:1'],

            'priority' => ['nullable','integer'],
            'stack_policy' => ['nullable','in:stackable,exclusive'],

            'usage_limit_total' => ['nullable','integer','min:1'],
            'usage_limit_per_user' => ['nullable','integer','min:1'],

            // FE tabel kamu fokus ke subcategory
            'subcategory_id' => ['nullable','integer','min:1'],
        ]);

        if (empty($v['slug'])) {
            $v['slug'] = Str::slug($v['name']);
        }

        $subcategoryId = $v['subcategory_id'] ?? null;
        unset($v['subcategory_id']);

        $campaign = DiscountCampaign::create($v);

        // kalau FE kirim subcategory_id, langsung bikin target-nya
        if ($subcategoryId) {
            DiscountCampaignTarget::firstOrCreate([
                'campaign_id' => $campaign->id,
                'target_type' => 'subcategory',
                'target_id' => (int) $subcategoryId,
            ]);
        }

        $campaign->load('targets');

        // resolve for resource
        $subTarget = $campaign->targets->firstWhere('target_type', 'subcategory');
        $campaign->resolved_subcategory = $subTarget
            ? Subcategory::with('category:id,name')->find((int)$subTarget->target_id)
            : null;

        return $this->ok(new DiscountCampaignResource($campaign));
    }

    public function update(Request $request, int $id)
    {
        $campaign = DiscountCampaign::with('targets')->findOrFail($id);

        $v = $request->validate([
            'name' => ['sometimes','string','max:150'],
            'slug' => ['sometimes','nullable','string','max:150'],
            'enabled' => ['sometimes','boolean'],

            'starts_at' => ['sometimes','nullable','date'],
            'ends_at' => ['sometimes','nullable','date'],

            'discount_type' => ['sometimes','in:percent,fixed'],
            'discount_value' => ['sometimes','integer','min:1'],

            'max_discount_amount' => ['sometimes','nullable','integer','min:1'],
            'min_order_amount' => ['sometimes','nullable','integer','min:1'],

            'priority' => ['sometimes','integer'],
            'stack_policy' => ['sometimes','in:stackable,exclusive'],

            'usage_limit_total' => ['sometimes','nullable','integer','min:1'],
            'usage_limit_per_user' => ['sometimes','nullable','integer','min:1'],

            // update target subcategory utama via field ini (biar FE gampang)
            'subcategory_id' => ['sometimes','nullable','integer','min:1'],
        ]);

        if (array_key_exists('name', $v) && (!array_key_exists('slug', $v) || $v['slug'] === null)) {
            $v['slug'] = Str::slug($v['name']);
        }

        $subcategoryId = $v['subcategory_id'] ?? null;
        $hasSubKey = array_key_exists('subcategory_id', $v);
        unset($v['subcategory_id']);

        $campaign->update($v);

        // kalau FE mengirim subcategory_id, kita set target subcategory menjadi 1 saja
        if ($hasSubKey) {
            // hapus semua subcategory target lama
            DiscountCampaignTarget::query()
                ->where('campaign_id', $campaign->id)
                ->where('target_type', 'subcategory')
                ->delete();

            if ($subcategoryId) {
                DiscountCampaignTarget::create([
                    'campaign_id' => $campaign->id,
                    'target_type' => 'subcategory',
                    'target_id' => (int) $subcategoryId,
                ]);
            }
        }

        $campaign->refresh()->load('targets');

        $subTarget = $campaign->targets->firstWhere('target_type', 'subcategory');
        $campaign->resolved_subcategory = $subTarget
            ? Subcategory::with('category:id,name')->find((int)$subTarget->target_id)
            : null;

        return $this->ok(new DiscountCampaignResource($campaign));
    }

    public function destroy(int $id)
    {
        $campaign = DiscountCampaign::findOrFail($id);
        $campaign->delete();
        return $this->ok(['deleted' => true]);
    }

    /**
     * Endpoint lama addTargets/removeTargets boleh tetap ada
     * untuk kebutuhan advanced (multi target).
     */
    public function addTargets(Request $request, int $id)
    {
        $campaign = DiscountCampaign::findOrFail($id);

        $v = $request->validate([
            'targets' => ['required','array','min:1'],
            'targets.*.type' => ['required','in:subcategory,product'],
            'targets.*.id' => ['required','integer','min:1'],
        ]);

        foreach ($v['targets'] as $t) {
            DiscountCampaignTarget::firstOrCreate([
                'campaign_id' => $campaign->id,
                'target_type' => $t['type'],
                'target_id' => (int) $t['id'],
            ]);
        }

        $campaign->load('targets');

        return $this->ok($campaign);
    }

    public function removeTargets(Request $request, int $id)
    {
        $campaign = DiscountCampaign::findOrFail($id);

        $v = $request->validate([
            'targets' => ['required','array','min:1'],
            'targets.*.type' => ['required','in:subcategory,product'],
            'targets.*.id' => ['required','integer','min:1'],
        ]);

        foreach ($v['targets'] as $t) {
            DiscountCampaignTarget::query()
                ->where('campaign_id', $campaign->id)
                ->where('target_type', $t['type'])
                ->where('target_id', (int) $t['id'])
                ->delete();
        }

        return $this->ok($campaign->fresh('targets'));
    }
}
