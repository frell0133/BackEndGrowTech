<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Api\V1\Admin\PaymentGatewayController;
use App\Http\Controllers\Api\V1\User\UserCartController;
use App\Http\Controllers\Api\V1\User\UserWalletController;
use App\Http\Controllers\Controller;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use App\Support\RuntimeCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutBootstrapController extends Controller
{
    use ApiResponse;

    private const BOOTSTRAP_TTL = 10;

    public function __invoke(
        Request $request,
        UserCartController $cartController,
        UserWalletController $walletController,
        PaymentGatewayController $paymentGatewayController,
        LedgerService $ledgerService,
    ): JsonResponse {
        $user = $request->user();
        $cacheKey = sprintf('bootstrap:checkout:user:%d', (int) $user->id);

        $payload = RuntimeCache::remember($cacheKey, self::BOOTSTRAP_TTL, function () use ($request, $cartController, $walletController, $paymentGatewayController, $ledgerService) {
            $checkoutResponse = $cartController->checkoutPreview($request);
            if (!$this->isSuccess($checkoutResponse)) {
                return ['response' => $checkoutResponse];
            }

            $walletResponse = $walletController->summary($request, $ledgerService);
            $gatewayResponse = $paymentGatewayController->available(
                $request->duplicate(['scope' => 'order'])
            );

            $checkoutPayload = $checkoutResponse->getData(true);
            $walletPayload = $walletResponse->getData(true);
            $gatewayPayload = $gatewayResponse->getData(true);

            return [
                'data' => [
                    'checkout' => $checkoutPayload['data'] ?? null,
                    'wallet' => data_get($walletPayload, 'data.wallet'),
                    'last_entries' => data_get($walletPayload, 'data.last_entries', []),
                    'payment_gateways' => data_get($gatewayPayload, 'data', []),
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
