<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminVoucherController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        return $this->ok(['todo' => true]);
    }

    public function store(Request $request)
    {
        // TODO: create voucher
        return $this->ok(['todo' => true]);
    }

    public function show(string $id)
    {
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function update(Request $request, string $id)
    {
        // TODO: update voucher
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function destroy(string $id)
    {
        // TODO: delete/disable
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function usage(string $id)
    {
        // TODO: usage analytics basic
        return $this->ok(['id' => $id, 'todo' => true]);
    }
}
