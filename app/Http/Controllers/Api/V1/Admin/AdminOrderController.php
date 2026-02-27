<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);

        $q = Order::query()
            ->with([
                'user:id,name,email',
                'items:id,order_id,product_id,qty,unit_price,line_subtotal,product_name,product_slug',
                'payment:id,order_id,gateway_code,external_id,amount,status,created_at',
                'deliveries:id,order_id,type,status,emailed,created_at',
            ])
            ->orderByDesc('id');

        // ===== filters (opsional) =====
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($gateway = $request->query('gateway_code')) {
            $q->where('payment_gateway_code', $gateway);
        }

        if ($userId = $request->query('user_id')) {
            $q->where('user_id', (int) $userId);
        }

        if ($invoice = $request->query('invoice_number')) {
            $q->where('invoice_number', 'like', "%{$invoice}%");
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
        $order = Order::query()
            ->with([
                'user:id,name,email',
                'items',
                'payment',
                'deliveries',
            ])
            ->findOrFail($id);

        return $this->ok($order);
    }
}