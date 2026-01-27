<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // TODO: filter status/gateway/product_id/user_id/date_range
        return $this->ok(['todo' => true]);
    }

    public function show(string $id)
    {
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function markFailed(Request $request, string $id)
    {
        // TODO: force fail (opsional)
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function refund(Request $request, string $id)
    {
        // TODO: refund flow (ledger + revoke delivery if needed)
        return $this->ok(['id' => $id, 'todo' => true]);
    }
}
