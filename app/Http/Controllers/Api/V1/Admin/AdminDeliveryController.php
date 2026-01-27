<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminDeliveryController extends Controller
{
    use ApiResponse;

    public function resend(Request $request, string $id)
    {
        // TODO: resend by admin
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }

    public function revoke(Request $request, string $id)
    {
        // TODO: revoke (refund/abuse)
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }
}
