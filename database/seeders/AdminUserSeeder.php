<?php

namespace Database\Seeders;

use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        /**
         * =========================================================
         * 0) DEMOTE OWNER LAMA MENJADI USER BIASA
         * =========================================================
         * Akun lama: wizardtwr@gmail.com
         * Sebelumnya mungkin dipakai sebagai owner/admin.
         * Sekarang dijadikan user biasa agar tidak bentrok
         * dengan owner baru.
         */
        $legacyOwner = User::where('email', 'wizardtwr@gmail.com')->first();

        if ($legacyOwner) {
            $legacyOwner->update([
                'name' => 'wizard_user',
                'full_name' => 'Wizard User GrowTech Central',
                'address' => 'Bandung',
                'password' => Hash::make('User12345!'),
                'role' => 'user',
                'admin_role_id' => null,
                'tier' => $legacyOwner->tier ?: 'member',
            ]);

            $this->ensureGtcReferralCode($legacyOwner);
        }

        /**
         * =========================================================
         * 1) OWNER / SUPER ADMIN BARU
         * =========================================================
         */
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
        $this->assignAdminRole($owner, 'owner');

        /**
         * =========================================================
         * 2) ADMIN BIASA
         * =========================================================
         */
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
        $this->assignAdminRole($admin, 'catalog_admin');
    }

    private function assignAdminRole(User $user, string $roleSlug): void
    {
        $role = AdminRole::where('slug', $roleSlug)->first();

        if (! $role) {
            return;
        }

        if ($user->admin_role_id !== $role->id) {
            $user->admin_role_id = $role->id;
            $user->save();
        }
    }

    private function ensureGtcReferralCode(User $user): void
    {
        $currentCode = (string) ($user->referral_code ?? '');

        if (Str::startsWith($currentCode, 'GTC-')) {
            return;
        }

        $user->forceFill([
            'referral_code' => User::generateUniqueReferralCode(),
        ])->save();
    }
}