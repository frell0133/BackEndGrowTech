<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;

class PaymentGatewayController extends Controller
{
    public function index() { return response()->json(['ok' => true]); }
    public function store() { return response()->json(['ok' => true]); }
    public function show($code) { return response()->json(['ok' => true]); }
    public function update($code) { return response()->json(['ok' => true]); }
    public function destroy($code) { return response()->json(['ok' => true]); }
}
