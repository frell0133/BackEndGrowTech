<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserVoucherController extends Controller
{
    use ApiResponse;

    public function validateCode(Request $request)
    {
        // TODO: validate voucher di checkout
        return $this->ok(['todo' => true]);
    }
}
