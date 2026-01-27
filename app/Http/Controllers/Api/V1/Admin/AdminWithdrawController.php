<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminWithdrawController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // TODO: list withdraw requests + filter status/date/user
        return $this->ok(['todo' => true]);
    }

    public function approve(Request $request, string $id)
    {
        // TODO: approve + create ledger debit
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function reject(Request $request, string $id)
    {
        // TODO: reject
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function markPaid(Request $request, string $id)
    {
        // TODO: mark paid (optional step)
        return $this->ok(['id' => $id, 'todo' => true]);
    }
}
