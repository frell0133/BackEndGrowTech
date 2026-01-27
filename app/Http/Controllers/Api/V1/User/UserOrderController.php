<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserOrderController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        // TODO: create order (draft/pending)
        return $this->ok(['todo' => true]);
    }

    public function index(Request $request)
    {
        // TODO: list order user (filter status/date)
        return $this->ok(['todo' => true]);
    }

    public function show(string $id)
    {
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function cancel(Request $request, string $id)
    {
        // TODO: cancel if not paid
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function createPayment(Request $request, string $id)
    {
        // TODO: create payment session
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }

    public function paymentStatus(string $id)
    {
        // TODO: payment status
        return $this->ok(['order_id' => $id, 'todo' => true]);
    }
}
