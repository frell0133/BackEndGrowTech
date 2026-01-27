<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserWithdrawController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        // TODO: request withdraw
        return $this->ok(['todo' => true]);
    }

    public function index(Request $request)
    {
        return $this->ok(['todo' => true]);
    }

    public function show(string $id)
    {
        return $this->ok(['id' => $id, 'todo' => true]);
    }
}