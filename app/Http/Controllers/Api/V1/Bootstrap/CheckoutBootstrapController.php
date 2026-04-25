<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Api\V1\Admin\PaymentGatewayController;
use App\Http\Controllers\Api\V1\User\UserCartController;
use App\Http\Controllers\Api\V1\User\UserWalletController;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Services\LedgerService;
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

        $checkoutPayload = $this->resolveCheckoutPayload($request, $cartController, (int) $user->id);
        if (!$checkoutPayload['success']) {
            return response()->json($checkoutPayload['payload'], $checkoutPayload['status'])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        $walletResponse = $walletController->summary($request, $ledgerService);
        $gatewayResponse = $paymentGatewayController->available(
            $request->duplicate(['scope' => 'order'])
        );

        $walletPayload = $walletResponse->getData(true);
        $gatewayPayload = $gatewayResponse->getData(true);

        return $this->ok([
            'checkout' => $checkoutPayload['data'],
            'wallet' => data_get($walletPayload, 'data.wallet'),
            'last_entries' => data_get($walletPayload, 'data.last_entries', []),
            'payment_gateways' => data_get($gatewayPayload, 'data', []),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function resolveCheckoutPayload(Request $request, UserCartController $cartController, int $userId): array
    {
        $orderId = (int) $request->query('order_id', 0);
        if ($orderId > 0) {
            $order = Order::query()
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->with([
                    'items.product.category:id,name,slug',
                    'items.product.subcategory:id,category_id,name,slug,provider,image_url,image_path',
                    'product.category:id,name,slug',
                    'product.subcategory:id,category_id,name,slug,provider,image_url,image_path',
                ])
                ->first();

            if ($order) {
                $items = $order->items;
                if ($items->isEmpty() && $order->product) {
                    $items = collect([[
                        'id' => null,
                        'product_id' => (int) $order->product_id,
                        'qty' => (int) ($order->qty ?? 1),
                        'unit_price' => (float) ((int) ($order->qty ?? 0) > 0
                            ? ((float) ($order->subtotal ?? $order->amount ?? 0) / max(1, (int) $order->qty))
                            : (float) ($order->amount ?? 0)),
                        'line_subtotal' => (float) ($order->subtotal ?? $order->amount ?? 0),
                        'product' => $order->product,
                    ]]);
                }

                return [
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'mode' => 'order',
                        'order' => $order,
                        'items' => $items,
                        'summary' => [
                            'subtotal' => (float) ($order->subtotal ?? 0),
                            'discount_total' => (float) ($order->discount_total ?? 0),
                            'tax_percent' => (int) ($order->tax_percent ?? 0),
                            'tax_amount' => (float) ($order->tax_amount ?? 0),
                            'total' => (float) ($order->amount ?? 0),
                            'gateway_fee_percent' => (float) ($order->gateway_fee_percent ?? 0),
                            'gateway_fee_amount' => (float) ($order->gateway_fee_amount ?? 0),
                            'total_payable_gateway' => (float) ((float) ($order->amount ?? 0) + (float) ($order->gateway_fee_amount ?? 0)),
                        ],
                    ],
                    'payload' => null,
                ];
            }
        }

        $checkoutResponse = $cartController->checkoutPreview($request);
        $payload = $checkoutResponse->getData(true);

        return [
            'success' => (bool) ($payload['success'] ?? false),
            'status' => $checkoutResponse->getStatusCode(),
            'data' => $payload['data'] ?? null,
            'payload' => $payload,
        ];
    }
}
