<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@local.test'],
            [
                'name' => 'admin',
                'full_name' => 'Super Admin Digital Store',
                'address' => 'Bandung, Jawa Barat',
                'password' => Hash::make('Admin12345!'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'user@local.test'],
            [
                'name' => 'user',
                'full_name' => 'User Demo',
                'address' => 'Jakarta',
                'password' => Hash::make('User12345!'),
                'role' => 'user',
            ]
        );
        User::updateOrCreate(
            ['email' => 'yashabima2@gmail.com'],
            [
                'name' => 'Bima',
                'full_name' => 'Bima Yasha',
                'address' => 'Bandung',
                'password' => Hash::make('BimaYasha12345!'),
                'role' => 'user',
            ]
        );
    }
}
