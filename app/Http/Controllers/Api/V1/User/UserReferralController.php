<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserReferralController extends Controller
{
    use ApiResponse;

    public function dashboard(Request $request)
    {
        // TODO: referral dashboard
        return $this->ok(['todo' => true]);
    }

    public function attach(Request $request)
    {
        // TODO: attach referral code once
        return $this->ok(['todo' => true]);
    }
}