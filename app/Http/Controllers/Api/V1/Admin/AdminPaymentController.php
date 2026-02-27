<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);

        $q = Payment::query()
            ->with([
                'gateway',
                'order:id,user_id,invoice_number,status,amount,created_at',
                'order.user:id,name,email',
            ])
            ->orderByDesc('id');

        // ===== filters (opsional) =====
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($gateway = $request->query('gateway_code')) {
            $q->where('gateway_code', $gateway);
        }

        if ($external = $request->query('external_id')) {
            $q->where('external_id', 'like', "%{$external}%");
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
}