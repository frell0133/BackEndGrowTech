<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\DiscountCampaign;
use App\Models\DiscountCampaignTarget;
use App\Models\SubCategory;
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
            ->with(['targets'])
            ->when($q, fn($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        $items = collect($paginator->items());

        $subIds = $items->flatMap(function ($c) {
            return $c->targets
                ->where('target_type', 'subcategory')
                ->pluck('target_id');
        })->unique()->values();

        // ✅ FIX: SubCategory (bukan Subcategory)
        $subMap = SubCategory::query()
            ->with('category:id,name')
            ->whereIn('id', $subIds)
            ->get()
            ->keyBy('id');

        // inject resolved_subcategory agar Resource bisa baca
        $items->each(function ($c) use ($subMap) {
            $subTarget = $c->targets->firstWhere('target_type', 'subcategory');
            $c->resolved_subcategory = $subTarget ? ($subMap[(int)$subTarget->target_id] ?? null) : null;
        });

        $paginator->setCollection($items);

        return $this->ok([
            // ✅ lebih aman: koleksi dari paginator (biar meta resource ga double)
            'data' => DiscountCampaignResource::collection($paginator->getCollection()),
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

        $subTarget = $campaign->targets->firstWhere('target_type', 'subcategory');
        if ($subTarget) {
            // ✅ FIX: SubCategory
            $campaign->resolved_subcategory = SubCategory::with('category:id,name')
                ->find((int) $subTarget->target_id);
        } else {
            $campaign->resolved_subcategory = null;
        }

        return $this->ok(new DiscountCampaignResource($campaign));
    }

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

            'targets' => ['nullable','array'],
            'targets.*.type' => ['required','in:category,subcategory,product'],
            'targets.*.id' => ['required','integer','min:1'],
        ]);

        if (empty($v['slug'])) {
            $v['slug'] = Str::slug($v['name']);
        }

        $targets = $v['targets'] ?? [];
        unset($v['targets']);

        $campaign = DiscountCampaign::create($v);

        foreach ($targets as $t) {
            DiscountCampaignTarget::create([
                'campaign_id' => $campaign->id,
                'target_type' => $t['type'],
                'target_id' => (int) $t['id'],
            ]);
        }

        if ($subcategoryId) {
            DiscountCampaignTarget::firstOrCreate([
                'campaign_id' => $campaign->id,
                'target_type' => 'subcategory',
                'target_id' => (int) $subcategoryId,
            ]);
        }

        $campaign->load('targets');

        $subTarget = $campaign->targets->firstWhere('target_type', 'subcategory');
        $campaign->resolved_subcategory = $subTarget
            ? SubCategory::with('category:id,name')->find((int)$subTarget->target_id) // ✅ FIX
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

            'subcategory_id' => ['sometimes','nullable','integer','min:1'],

            'targets' => ['sometimes','array'],
            'targets.*.type' => ['required','in:category,subcategory,product'],
            'targets.*.id' => ['required','integer','min:1'],
        ]);

        if (array_key_exists('name', $v) && (!array_key_exists('slug', $v) || $v['slug'] === null)) {
            $v['slug'] = Str::slug($v['name']);
        }

        $subcategoryId = $v['subcategory_id'] ?? null;
        $hasSubKey = array_key_exists('subcategory_id', $v);
        unset($v['subcategory_id']);

        $campaign->update($v);

        if ($hasSubKey) {
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

        if (array_key_exists('targets', $v)) {
            DiscountCampaignTarget::where('campaign_id', $campaign->id)->delete();

            foreach ($v['targets'] as $t) {
                DiscountCampaignTarget::create([
                    'campaign_id' => $campaign->id,
                    'target_type' => $t['type'],
                    'target_id' => (int) $t['id'],
                ]);
            }
        }

        $campaign->refresh()->load('targets');

        $subTarget = $campaign->targets->firstWhere('target_type', 'subcategory');
        $campaign->resolved_subcategory = $subTarget
            ? SubCategory::with('category:id,name')->find((int)$subTarget->target_id) // ✅ FIX
            : null;

        return $this->ok(new DiscountCampaignResource($campaign));
    }

    public function destroy(int $id)
    {
        $campaign = DiscountCampaign::findOrFail($id);
        $campaign->delete();
        return $this->ok(['deleted' => true]);
    }

    public function addTargets(Request $request, int $id)
    {
        $campaign = DiscountCampaign::findOrFail($id);

        $v = $request->validate([
            'targets' => ['required','array','min:1'],
            'targets.*.type' => ['required','in:category,subcategory,product'],
            'targets.*.id' => ['required','integer','min:1'],
        ]);

        foreach ($v['targets'] as $t) {
            DiscountCampaignTarget::firstOrCreate([
                'campaign_id' => $campaign->id,
                'target_type' => $t['type'],
                'target_id' => (int) $t['id'],
            ]);
        }

        return $this->ok($campaign->fresh('targets'));
    }

    public function removeTargets(Request $request, int $id)
    {
        $campaign = DiscountCampaign::findOrFail($id);

        $v = $request->validate([
            'targets' => ['required','array','min:1'],
            'targets.*.type' => ['required','in:category,subcategory,product'],
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
