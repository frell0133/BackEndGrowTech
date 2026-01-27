<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // TODO: filter user/action/entity/date
        return $this->ok(['todo' => true]);
    }

    public function show(string $id)
    {
        return $this->ok(['id' => $id, 'todo' => true]);
    }
}