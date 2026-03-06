<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\AdminPermission;

class AdminPermissionController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $items = AdminPermission::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return $this->ok($items);
    }
}