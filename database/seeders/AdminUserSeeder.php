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
        // 0) UBAH wizard lama jadi USER BIASA
        // =========================
        $oldOwner = User::where('email', 'wizardtwr@gmail.com')->first();

        if ($oldOwner) {
            $oldOwner->update([
                'name' => 'wizard_user',
                'full_name' => 'Wizard User GrowTech Central',
                'address' => 'Bandung',
                'password' => Hash::make('User12345!'),
                'role' => 'user',
                'admin_role_id' => null,
                'tier' => $oldOwner->tier ?: 'member',
            ]);

            $this->ensureGtcReferralCode($oldOwner);
        }

        // =========================
        // 1) OWNER / SUPER ADMIN BARU
        // =========================
        $owner = User::updateOrCreate(
            ['email' => 'emailpercobaan115@gmail.com'],
            [
                'name' => 'owner',
                'full_name' => 'Owner GrowTech Central',
                'address' => 'Bandung',
                'password' => Hash::make('bismillahsukses'),
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

        // opsi role yang tersedia:
        // owner, content_admin, catalog_admin,
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