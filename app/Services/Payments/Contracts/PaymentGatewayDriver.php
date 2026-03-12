<?php

namespace App\Services\Payments\Contracts;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\WalletTopup;
use Illuminate\Http\Request;

interface PaymentGatewayDriver
{
    public function createOrderPayment(PaymentGateway $gateway, Order $order, array $context = []): array;

    public function createTopupPayment(PaymentGateway $gateway, WalletTopup $topup, array $context = []): array;

    public function parseWebhook(PaymentGateway $gateway, Request $request): array;
}