<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        PaymentGateway::updateOrCreate(
            ['code' => 'midtrans'],
            [
                'name' => 'Midtrans',
                'is_active' => true,
                'config' => [
                    'environment' => 'sandbox',
                    'server_key' => env('MIDTRANS_SERVER_KEY', 'CHANGE_ME'),
                    'client_key' => env('MIDTRANS_CLIENT_KEY', 'CHANGE_ME'),
                    'merchant_id' => env('MIDTRANS_MERCHANT_ID', 'CHANGE_ME'),
                ],
            ]
        );
    }
}
