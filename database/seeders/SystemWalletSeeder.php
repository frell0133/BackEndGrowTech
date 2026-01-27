<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Wallet;

class SystemWalletSeeder extends Seeder
{
    public function run(): void
    {
        $systemWallets = [
            ['code' => 'SYSTEM_CASH', 'balance' => 0],
            ['code' => 'SYSTEM_REVENUE', 'balance' => 0],
            ['code' => 'SYSTEM_PAYOUT', 'balance' => 0],
        ];

        foreach ($systemWallets as $sw) {
            Wallet::firstOrCreate(
                ['code' => $sw['code']],
                [
                    'user_id' => null,
                    'balance' => $sw['balance'],
                    'currency' => 'IDR',
                    'status' => 'ACTIVE',
                ]
            );
        }
    }
}
