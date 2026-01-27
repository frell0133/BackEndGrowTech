<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::updateOrCreate(
            ['name' => 'Redfinger Cloud Android'],
            [
                'type' => 'ACCOUNT_CREDENTIAL',
                'description' => 'Cloud Android instance like Redfinger',
                'tier_pricing' => [
                    'member' => 100000,
                    'reseller' => 90000,
                    'vip' => 80000,
                ],
            ]
        );
    }
}
