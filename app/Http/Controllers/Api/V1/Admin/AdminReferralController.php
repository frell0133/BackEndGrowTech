<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminReferralController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // TODO: list referral relations (filter date/user)
        return $this->ok(['todo' => true]);
    }

    public function forceUnlock(Request $request, string $user_id)
    {
        // TODO: super admin only (optional)
        return $this->ok(['user_id' => $user_id, 'todo' => true]);
    }
}
