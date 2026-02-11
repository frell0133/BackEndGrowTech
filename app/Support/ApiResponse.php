<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Response sukses
     *
     * @param mixed $data   data utama
     * @param array $meta   metadata (pagination, dll)
     * @param int   $status HTTP status code (default 200)
     */
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
            'error' => null,
        ], $status);
    }

    /**
     * Response gagal
     *
     * @param string $message pesan error
     * @param int    $status  HTTP status code (default 400)
     * @param mixed  $details detail tambahan (optional)
     */
    protected function fail(string $message, int $status = 400, mixed $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => (object) [],
            'error' => [
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
