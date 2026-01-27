<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // TODO: filter gateway/status/date
        return $this->ok(['todo' => true]);
    }
}
