<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminPermissionSeeder::class,
            AdminRoleSeeder::class,
            AdminUserSeeder::class,
            PaymentGatewaySeeder::class,
            ProductSeeder::class,
            SystemWalletSeeder::class,
            PagesSeeder::class,
            SettingsSeeder::class,
        ]);
    }
}
