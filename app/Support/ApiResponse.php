<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * ok($data)
     * ok($data, ['page'=>1])
     * ok($data, 201)                 // ✅ kompatibel lama
     * ok($data, ['meta'=>1], 201)
     */
    protected function ok(mixed $data = null, array|int $meta = [], ?int $status = null): JsonResponse
    {
        // Kalau param ke-2 int, anggap itu status code (kompatibel lama)
        if (is_int($meta)) {
            $status = $meta;
            $meta = [];
        }

        $status = $status ?? 200;

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
            'error' => null,
        ], $status);
    }

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
