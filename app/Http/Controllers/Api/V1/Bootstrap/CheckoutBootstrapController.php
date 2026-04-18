<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Api\V1\Admin\PaymentGatewayController;
use App\Http\Controllers\Api\V1\User\UserCartController;
use App\Http\Controllers\Api\V1\User\UserWalletController;
use App\Http\Controllers\Controller;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutBootstrapController extends Controller
{
    use ApiResponse;

    public function __invoke(
        Request $request,
        UserCartController $cartController,
        UserWalletController $walletController,
        PaymentGatewayController $paymentGatewayController,
        LedgerService $ledgerService,
    ): JsonResponse {
        $user = $request->user();
        $checkoutResponse = $cartController->checkoutPreview($request);
        if (!$this->isSuccess($checkoutResponse)) {
            return $checkoutResponse->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        $walletResponse = $walletController->summary($request, $ledgerService);
        $gatewayResponse = $paymentGatewayController->available(
            $request->duplicate(['scope' => 'order'])
        );

        $checkoutPayload = $checkoutResponse->getData(true);
        $walletPayload = $walletResponse->getData(true);
        $gatewayPayload = $gatewayResponse->getData(true);

        return $this->ok([
            'checkout' => $checkoutPayload['data'] ?? null,
            'wallet' => data_get($walletPayload, 'data.wallet'),
            'last_entries' => data_get($walletPayload, 'data.last_entries', []),
            'payment_gateways' => data_get($gatewayPayload, 'data', []),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function isSuccess(JsonResponse $response): bool
    {
        $payload = $response->getData(true);
        return (bool) ($payload['success'] ?? false);
    }
}
