<?php

namespace App\Services;

use App\Models\AdminRole;
use App\Models\User;

class AdminRoleLifecycleService
{
    public function customSlugForUser(User|int $user): string
    {
        $userId = $user instanceof User ? $user->id : $user;

        return 'custom_user_' . (int) $userId;
    }

    public function isCustomRoleForUser(?AdminRole $role, User|int $user): bool
    {
        if (!$role) {
            return false;
        }

        return (string) $role->slug === $this->customSlugForUser($user);
    }

    public function isAnyUserCustomRole(?AdminRole $role): bool
    {
        return $role && str_starts_with((string) $role->slug, 'custom_user_');
    }

    /**
     * Membuat/mengembalikan role custom kosong milik user.
     * Dipakai ketika user dibuat/diubah menjadi admin tanpa preset role,
     * sehingga default permission-nya benar-benar kosong/tidak ada checklist.
     */
    public function ensureEmptyCustomRoleForUser(User $user): AdminRole
    {
        $role = AdminRole::updateOrCreate(
            ['slug' => $this->customSlugForUser($user)],
            [
                'name' => $this->customRoleName($user),
                'is_super' => false,
                'is_system' => false,
            ]
        );

        $role->permissions()->sync([]);

        if ((int) ($user->admin_role_id ?? 0) !== (int) $role->id) {
            $user->admin_role_id = $role->id;
            $user->save();
        }

        return $role->fresh(['permissions']);
    }

    public function refreshCustomRoleNameForUser(User $user): void
    {
        $role = AdminRole::where('slug', $this->customSlugForUser($user))->first();

        if (!$role) {
            return;
        }

        $role->name = $this->customRoleName($user);
        $role->is_super = false;
        $role->is_system = false;
        $role->save();
    }

    /**
     * Menghapus role custom yang memang dimiliki user ini.
     * Semua relasi user yang masih menunjuk role custom tersebut dibuat null dulu
     * supaya aman untuk soft delete user maupun pergantian role.
     */
    public function deleteCustomRoleForUser(User|int $user): bool
    {
        $role = AdminRole::where('slug', $this->customSlugForUser($user))->first();

        if (!$role) {
            return false;
        }

        User::withTrashed()
            ->where('admin_role_id', $role->id)
            ->update(['admin_role_id' => null]);

        $role->permissions()->sync([]);
        $role->delete();

        return true;
    }

    /**
     * Menghapus token aktif dan trusted device agar admin terdampak logout.
     * Saat login berikutnya trusted device sudah dicabut, jadi flow akan meminta OTP lagi.
     */
    public function invalidateUserSessions(User $user): void
    {
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        app(TrustedDeviceService::class)->revokeAllForUser($user);
    }

    private function customRoleName(User $user): string
    {
        $identity = $user->email ?: ($user->full_name ?: ($user->name ?: ('User #' . $user->id)));

        return "Custom Role for {$identity}";
    }
}
