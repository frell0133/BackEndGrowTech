<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // targets sudah di-load dari controller (with)
        $targets = $this->targets ?? collect();

        // ambil target subcategory pertama (untuk tampilan FE yang 1 subcategory)
        $subTarget = $targets->firstWhere('target_type', 'subcategory');

        $subcategory = null;
        $category = null;

        // controller akan inject map subcategories (lihat step 3)
        if ($subTarget && isset($this->resolved_subcategory)) {
            $subcategory = $this->resolved_subcategory;
            $category = $subcategory?->category;
        }

        // nominal display
        $nominal = $this->discount_type === 'percent'
            ? ((int) $this->discount_value) . '%'
            : 'Rp ' . number_format((int) $this->discount_value, 0, ',', '.');

        // status label untuk FE: Aktif / Nonaktif
        // FE kamu tampil Aktif/Nonaktif, kita bikin konsisten:
        $isActiveNow = (bool) $this->enabled
            && ($this->starts_at === null || $this->starts_at <= now())
            && ($this->ends_at === null || $this->ends_at >= now());

        return [
            'id' => $this->id,
            'nama_discount' => $this->name,
            'nominal' => $nominal, // "2%" atau "Rp 5.000"
            'discount_type' => $this->discount_type,
            'discount_value' => (int) $this->discount_value,

            // untuk tabel FE
            'kategori_produk' => $category?->name ?? '-',
            'sub_kategori' => $subcategory?->name ?? '-',

            // FE badge
            'status' => $isActiveNow ? 'Aktif' : 'Nonaktif',

            // optional data untuk edit form
            'enabled' => (bool) $this->enabled,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'min_order_amount' => $this->min_order_amount,
            'max_discount_amount' => $this->max_discount_amount,
            'priority' => (int) $this->priority,
            'stack_policy' => $this->stack_policy,

            // raw targets (kalau FE butuh detail)
            'targets' => $targets->map(fn($t) => [
                'type' => $t->target_type,
                'id' => (int) $t->target_id,
            ])->values(),
        ];
    }
}
