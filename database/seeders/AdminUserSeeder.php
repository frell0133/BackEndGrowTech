<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\AdminRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
                'address' => 'Bandung',
                'password' => Hash::make('Admin12345!'),
                'role' => 'admin',
                'tier' => 'member',
            ]
        );

        $this->ensureGtcReferralCode($owner);

        $ownerRole = AdminRole::where('slug', 'owner')->first();
        if ($ownerRole) {
            $owner->admin_role_id = $ownerRole->id;
            $owner->save();
        }

        // =========================
        // 2) ADMIN UJI COBA
        // =========================
        $trialAdmin = User::updateOrCreate(
            ['email' => 'trial-admin@local.test'],
            [
                'name' => 'trial_admin',
                'full_name' => 'Trial Admin (No Access Yet)',
                'address' => 'Bandung',
                'password' => Hash::make('Admin12345!'),
                'role' => 'admin',
                'tier' => 'member',
            ]
        );

        $this->ensureGtcReferralCode($trialAdmin);

        $trialAdmin->admin_role_id = null;
        $trialAdmin->save();

        // =========================
        // 3) USER DEMO
        // =========================
        $userDemo = User::updateOrCreate(
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

        $this->ensureGtcReferralCode($userDemo);

        $bima = User::updateOrCreate(
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

        $this->ensureGtcReferralCode($bima);
    }

    private function ensureGtcReferralCode(User $user): void
    {
        $current = (string) ($user->referral_code ?? '');

        // kalau sudah format GTC-, biarkan
        if (Str::startsWith($current, 'GTC-')) {
            return;
        }

        // kalau mau hanya isi yang null saja, ganti kondisi di atas/bawah sesuai kebutuhan
        $user->forceFill([
            'referral_code' => User::generateUniqueReferralCode(),
        ])->save();
    }
}