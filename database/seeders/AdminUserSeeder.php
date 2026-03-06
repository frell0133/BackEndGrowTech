<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\AdminRole;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // =========================
        // 1) OWNER / SUPER ADMIN
        // =========================
        $owner = User::updateOrCreate(
            ['email' => 'admin@local.test'],
            [
                'name' => 'owner',
                'full_name' => 'Owner GrowTech Central',
                'address' => 'Bandung, Jawa Barat',
                'password' => Hash::make('Admin12345!'),
                'role' => 'admin',
                'tier' => 'member', // aman, tapi gak ngaruh ke admin panel
            ]
        );

        $ownerRole = AdminRole::where('slug', 'owner')->first();
        if ($ownerRole) {
            $owner->admin_role_id = $ownerRole->id;
            $owner->save();
        }

        // =========================
        // 2) ADMIN UJI COBA (KOSONG - BELUM PUNYA AKSES)
        // =========================
        // admin_role_id sengaja NULL biar belum bisa akses /api/v1/admin/*
        $trialAdmin = User::updateOrCreate(
            ['email' => 'trial-admin@local.test'],
            [
                'name' => 'trial_admin',
                'full_name' => 'Trial Admin (No Access Yet)',
                'address' => 'Bandung',
                'password' => Hash::make('Admin12345!'),
                'role' => 'admin',
                'tier' => 'member',
                // admin_role_id JANGAN DIISI
            ]
        );

        // pastikan benar-benar kosong
        $trialAdmin->admin_role_id = null;
        $trialAdmin->save();

        // =========================
        // 3) USER DEMO
        // =========================
        User::updateOrCreate(
            ['email' => 'user@local.test'],
            [
                'name' => 'user',
                'full_name' => 'User Demo',
                'address' => 'Jakarta',
                'password' => Hash::make('User12345!'),
                'role' => 'user',
                'tier' => 'member',
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
                'tier' => 'member',
            ]
        );
    }
}