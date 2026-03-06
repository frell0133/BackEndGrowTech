<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\AdminPermission;
use Illuminate\Http\Request;

class AdminPermissionController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $includeProtected = filter_var(
            $request->query('include_protected', false),
            FILTER_VALIDATE_BOOL
        );

        $items = AdminPermission::query()
            ->when(!$includeProtected, fn ($q) => $q->where('is_protected', false))
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return $this->ok($items);
    }
}