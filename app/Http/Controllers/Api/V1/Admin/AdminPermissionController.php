<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\AdminPermission;
use Illuminate\Http\Request;

class AdminPermissionController extends Controller
{
    use ApiResponse;

    private const HIDDEN_CUSTOM_PERMISSION_KEYS = [
        'manage_stock_proofs',
        'manage_product_stocks',
        'manage_licenses',
    ];

    public function index(Request $request)
    {
        $includeProtected = filter_var(
            $request->query('include_protected', false),
            FILTER_VALIDATE_BOOL
        );

        $items = AdminPermission::query()
            ->when(!$includeProtected, function ($query) {
                $query
                    ->where('is_protected', false)
                    ->whereNotIn('key', self::HIDDEN_CUSTOM_PERMISSION_KEYS);
            })
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return $this->ok($items);
    }
}