<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $midtransServerKey = (string) env('MIDTRANS_SERVER_KEY', '');
        $midtransClientKey = (string) env('MIDTRANS_CLIENT_KEY', '');
        $midtransMerchantId = (string) env('MIDTRANS_MERCHANT_ID', '');
        $midtransReady = $midtransServerKey !== '';

        PaymentGateway::updateOrCreate(
            ['code' => 'midtrans'],
            [
                'name' => 'Midtrans',
                'provider' => 'midtrans',
                'driver' => 'midtrans',
                'description' => 'Default Midtrans gateway',
                'is_active' => $midtransReady,
                'is_default_order' => $midtransReady,
                'is_default_topup' => $midtransReady,
                'supports_order' => true,
                'supports_topup' => true,
                'sandbox_mode' => !((bool) env('MIDTRANS_IS_PRODUCTION', false)),
                'fee_type' => 'percent',
                'fee_value' => 0,
                'sort_order' => 10,
                'config' => array_filter([
                    'snap_url' => env('MIDTRANS_SNAP_URL'),
                ], fn ($v) => $v !== null && $v !== ''),
                'secret_config' => array_filter([
                    'server_key' => $midtransServerKey,
                    'client_key' => $midtransClientKey,
                    'merchant_id' => $midtransMerchantId,
                ], fn ($v) => $v !== null && $v !== ''),
            ]
        );

        $duitkuMerchantCode = (string) env('DUITKU_MERCHANT_CODE', '');
        $duitkuApiKey = (string) env('DUITKU_API_KEY', '');
        $duitkuReady = $duitkuMerchantCode !== '' && $duitkuApiKey !== '';

        PaymentGateway::updateOrCreate(
            ['code' => 'duitku'],
            [
                'name' => 'Duitku',
                'provider' => 'duitku',
                'driver' => 'duitku',
                'description' => 'Default Duitku gateway',
                'is_active' => false,
                'is_default_order' => false,
                'is_default_topup' => false,
                'supports_order' => true,
                'supports_topup' => true,
                'sandbox_mode' => !((bool) env('DUITKU_IS_PRODUCTION', false)),
                'fee_type' => 'percent',
                'fee_value' => 0,
                'sort_order' => 20,
                'config' => array_filter([
                    'payment_method' => env('DUITKU_PAYMENT_METHOD'),
                    'expiry_period' => (int) env('DUITKU_EXPIRY_PERIOD', 60),
                ], fn ($v) => $v !== null && $v !== ''),
                'secret_config' => array_filter([
                    'merchant_code' => $duitkuMerchantCode,
                    'api_key' => $duitkuApiKey,
                ], fn ($v) => $v !== null && $v !== ''),
            ]
        );
    }
}
