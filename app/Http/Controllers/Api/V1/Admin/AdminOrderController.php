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

    private function hasRelation(string $relation): bool
    {
        return method_exists(new Order(), $relation);
    }

    private function cols(string $table, array $wanted): array
    {
        $out = [];
        foreach ($wanted as $c) {
            if (Schema::hasColumn($table, $c)) $out[] = $c;
        }
        return $out;
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);

        $q = Order::query()->orderByDesc('id');

        // user
        if ($this->hasRelation('user')) {
            $q->with(['user:id,name,email']);
        }

        // items
        if ($this->hasRelation('items')) {
            $itemCols = array_merge(
                ['id', 'order_id', 'product_id', 'qty'],
                $this->cols('order_items', ['unit_price', 'line_subtotal', 'product_name', 'product_slug', 'price', 'subtotal'])
            );

            $q->with(['items' => function ($qq) use ($itemCols) {
                $qq->select(array_values(array_unique($itemCols)));
            }]);
        }

        // payment
        if ($this->hasRelation('payment')) {
            $payCols = array_merge(
                ['id', 'order_id'],
                $this->cols('payments', ['gateway_code', 'external_id', 'amount', 'status', 'created_at'])
            );

            $q->with(['payment' => function ($qq) use ($payCols) {
                $qq->select(array_values(array_unique($payCols)));
            }]);
        }

        // deliveries
        if ($this->hasRelation('deliveries')) {
            $delCols = array_merge(
                ['id', 'order_id'],
                $this->cols('deliveries', ['type', 'delivery_type', 'status', 'emailed', 'created_at'])
            );

            $q->with(['deliveries' => function ($qq) use ($delCols) {
                $qq->select(array_values(array_unique($delCols)));
            }]);
        }

        // filters
        if ($status = $request->query('status')) {
            if (Schema::hasColumn('orders', 'status')) $q->where('status', $status);
        }

        if ($userId = $request->query('user_id')) {
            if (Schema::hasColumn('orders', 'user_id')) $q->where('user_id', (int) $userId);
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

        // ✅ ringan, minim timeout
        return $this->ok($q->simplePaginate($perPage));
    }

    public function show(string $id)
    {
        $q = Order::query();

        if ($this->hasRelation('user')) $q->with(['user:id,name,email']);
        if ($this->hasRelation('items')) $q->with(['items']);
        if ($this->hasRelation('payment')) $q->with(['payment']);
        if ($this->hasRelation('deliveries')) $q->with(['deliveries']);

        return $this->ok($q->findOrFail($id));
    }

    // NOTE: routes kamu ada markFailed & refund.
    // Kalau kamu mau, aku bisa tambahin methodnya juga biar endpoint itu tidak error.
}