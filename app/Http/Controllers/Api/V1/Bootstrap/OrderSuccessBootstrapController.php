<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Api\V1\User\UserDeliveryController;
use App\Http\Controllers\Api\V1\User\UserOrderController;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\RuntimeCache;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderSuccessBootstrapController extends Controller
{
    use ApiResponse;

    private const BOOTSTRAP_TTL = 10;

    public function __invoke(
        Request $request,
        string $id,
        UserDeliveryController $deliveryController,
        UserOrderController $orderController,
    ): JsonResponse {
        $user = $request->user();
        $orderId = (int) $id;

        $cacheKey = sprintf('bootstrap:order-success:%d:user:%d', $orderId, (int) $user->id);

        $payload = RuntimeCache::remember($cacheKey, self::BOOTSTRAP_TTL, function () use ($request, $id, $deliveryController, $orderController) {
            $deliveryResponse = $deliveryController->info($request, $id, app(OrderFulfillmentService::class));
            if (!$this->isSuccess($deliveryResponse)) {
                return ['response' => $deliveryResponse];
            }

            $orderResponse = $orderController->show($request, $id);
            if (!$this->isSuccess($orderResponse)) {
                return ['response' => $orderResponse];
            }

            $paymentResponse = $orderController->paymentStatus($request, $id);
            if (!$this->isSuccess($paymentResponse)) {
                return ['response' => $paymentResponse];
            }

            $deliveryPayload = $deliveryResponse->getData(true);
            $orderPayload = $orderResponse->getData(true);
            $paymentPayload = $paymentResponse->getData(true);

            return [
                'data' => [
                    'delivery' => $deliveryPayload['data'] ?? null,
                    'order' => $orderPayload['data'] ?? null,
                    'payment' => $paymentPayload['data'] ?? null,
                ],
            ];
        });

        if (isset($payload['response']) && $payload['response'] instanceof JsonResponse) {
            return $payload['response'];
        }

        return $this->ok($payload['data'] ?? null);
    }

    private function isSuccess(JsonResponse $response): bool
    {
        $payload = $response->getData(true);

        return (bool) ($payload['success'] ?? false);
    }
}
