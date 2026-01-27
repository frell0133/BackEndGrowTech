<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserDeliveryController extends Controller
{
    use ApiResponse;

    public function info(string $id)
    {
        // TODO: info delivery (available? revealed?)
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }

    public function reveal(Request $request, string $id)
    {
        // TODO: one-time reveal
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }

    public function resend(Request $request, string $id)
    {
        // TODO: resend email (rate limit)
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }
}
