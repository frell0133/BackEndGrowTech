<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminOrderController extends Controller
{
    use ApiResponse;

    private function hasRelation(string $modelClass, string $relation): bool
    {
        try {
            $m = new $modelClass();
            return method_exists($m, $relation);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);

        $q = Order::query()->orderByDesc('id');

        // ===== WITH: user =====
        if ($this->hasRelation(Order::class, 'user')) {
            $q->with(['user:id,name,email']);
        }

        // ===== WITH: items =====
        if ($this->hasRelation(Order::class, 'items')) {
            // aman: ambil kolom paling umum, tanpa maksa kolom yang mungkin tidak ada
            $q->with(['items' => function ($qq) {
                $qq->select('id', 'order_id', 'product_id', 'qty')
                   ->addSelect([
                       // kolom optional, kalau ada di tabel
                       Schema::hasColumn('order_items', 'unit_price') ? 'unit_price' : null,
                       Schema::hasColumn('order_items', 'line_subtotal') ? 'line_subtotal' : null,
                       Schema::hasColumn('order_items', 'product_name') ? 'product_name' : null,
                       Schema::hasColumn('order_items', 'product_slug') ? 'product_slug' : null,
                   ])
                   ->whereNotNull('order_id');
            }]);
        }

        // ===== WITH: payment =====
        if ($this->hasRelation(Order::class, 'payment')) {
            $q->with(['payment' => function ($qq) {
                // pilih yang paling aman
                $qq->select('id', 'order_id')
                   ->addSelect([
                       Schema::hasColumn('payments', 'gateway_code') ? 'gateway_code' : null,
                       Schema::hasColumn('payments', 'external_id') ? 'external_id' : null,
                       Schema::hasColumn('payments', 'amount') ? 'amount' : null,
                       Schema::hasColumn('payments', 'status') ? 'status' : null,
                       Schema::hasColumn('payments', 'created_at') ? 'created_at' : null,
                   ])
                   ->whereNotNull('order_id');
            }]);
        }

        // ===== WITH: deliveries (ini yang paling sering bikin 500) =====
        if ($this->hasRelation(Order::class, 'deliveries')) {
            $q->with(['deliveries' => function ($qq) {
                // ambil kolom aman dulu
                $qq->select('id', 'order_id')
                   ->addSelect([
                       Schema::hasColumn('deliveries', 'delivery_type') ? 'delivery_type' : null,
                       Schema::hasColumn('deliveries', 'type') ? 'type' : null,
                       Schema::hasColumn('deliveries', 'status') ? 'status' : null,
                       Schema::hasColumn('deliveries', 'emailed') ? 'emailed' : null,
                       Schema::hasColumn('deliveries', 'created_at') ? 'created_at' : null,
                   ])
                   ->whereNotNull('order_id');
            }]);
        }

        // ===== filters =====
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($gateway = $request->query('gateway_code')) {
            // beberapa project pakai payment_gateway_code di orders
            if (Schema::hasColumn('orders', 'payment_gateway_code')) {
                $q->where('payment_gateway_code', $gateway);
            } else {
                // kalau kolomnya tidak ada, coba filter via relasi payment
                if ($this->hasRelation(Order::class, 'payment')) {
                    $q->whereHas('payment', fn($qq) => $qq->where('gateway_code', $gateway));
                }
            }
        }

        if ($userId = $request->query('user_id')) {
            $q->where('user_id', (int) $userId);
        }

        if ($invoice = $request->query('invoice_number')) {
            if (Schema::hasColumn('orders', 'invoice_number')) {
                $q->where('invoice_number', 'like', "%{$invoice}%");
            }
        }

        if ($request->query('date_from')) {
            $q->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->query('date_to')) {
            $q->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $data = $q->paginate($perPage);

        return $this->ok($data);
    }

    public function show(string $id)
    {
        $q = Order::query();

        if ($this->hasRelation(Order::class, 'user')) $q->with(['user:id,name,email']);
        if ($this->hasRelation(Order::class, 'items')) $q->with(['items']);
        if ($this->hasRelation(Order::class, 'payment')) $q->with(['payment']);
        if ($this->hasRelation(Order::class, 'deliveries')) $q->with(['deliveries']);

        $order = $q->findOrFail($id);

        return $this->ok($order);
    }
}