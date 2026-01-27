<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminProductController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        // TODO: validate + create product
        return $this->ok(['todo' => true]);
    }

    public function update(Request $request, string $id)
    {
        // TODO: update product
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function destroy(string $id)
    {
        // TODO: soft delete preferred
        return $this->ok(['id' => $id, 'todo' => true]);
    }

    public function publish(Request $request, string $id)
    {
        // TODO: publish/unpublish
        return $this->ok(['id' => $id, 'todo' => true]);
    }
}
