<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Api\V1\User\UserDeliveryController;
use App\Http\Controllers\Api\V1\User\UserOrderController;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderSuccessBootstrapController extends Controller
{
    use ApiResponse;

    public function __invoke(
        Request $request,
        string $id,
        UserDeliveryController $deliveryController,
        UserOrderController $orderController,
    ): JsonResponse {
        $deliveryResponse = $deliveryController->info($request, $id);
        if (!$this->isSuccess($deliveryResponse)) {
            return $deliveryResponse;
        }

        $orderResponse = $orderController->show($request, $id);
        $paymentResponse = $orderController->paymentStatus($request, $id);

        if (!$this->isSuccess($orderResponse)) {
            return $orderResponse;
        }

        if (!$this->isSuccess($paymentResponse)) {
            return $paymentResponse;
        }

        $deliveryPayload = $deliveryResponse->getData(true);
        $orderPayload = $orderResponse->getData(true);
        $paymentPayload = $paymentResponse->getData(true);

        return $this->ok([
            'delivery' => $deliveryPayload['data'] ?? null,
            'order' => $orderPayload['data'] ?? null,
            'payment' => $paymentPayload['data'] ?? null,
        ]);
    }

    private function isSuccess(JsonResponse $response): bool
    {
        $payload = $response->getData(true);
        return (bool) ($payload['success'] ?? false);
    }
}
