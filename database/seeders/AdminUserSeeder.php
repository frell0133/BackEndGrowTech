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
            ['email' => 'wizardtwr@gmail.com'],
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
        // 2) ADMIN BIASA
        // =========================
        $admin = User::updateOrCreate(
            ['email' => 'yashabima2@gmail.com'],
            [
                'name' => 'bima_admin',
                'full_name' => 'Bima Yasha',
                'address' => 'Bandung',
                'password' => Hash::make('BimaYasha12345!'),
                'role' => 'admin',
                'tier' => 'member',
            ]
        );

        $this->ensureGtcReferralCode($admin);

        // pilih role admin yang valid
        // opsi yang tersedia di repo: owner, content_admin, catalog_admin,
        // order_admin, finance_admin, marketing_admin, auditor
        $adminRole = AdminRole::where('slug', 'catalog_admin')->first();

        if ($adminRole) {
            $admin->admin_role_id = $adminRole->id;
            $admin->save();
        }
    }

    private function ensureGtcReferralCode(User $user): void
    {
        $current = (string) ($user->referral_code ?? '');

        if (Str::startsWith($current, 'GTC-')) {
            return;
        }

        $user->forceFill([
            'referral_code' => User::generateUniqueReferralCode(),
        ])->save();
    }
}