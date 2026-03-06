<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminRole;
use App\Models\AdminPermission;

class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $owner = AdminRole::updateOrCreate(
            ['slug' => 'owner'],
            [
                'name' => 'Owner (Super Admin)',
                'is_super' => true,
                'is_system' => true,
            ]
        );

        $content = AdminRole::updateOrCreate(
            ['slug' => 'content_admin'],
            [
                'name' => 'Admin Konten',
                'is_super' => false,
                'is_system' => true,
            ]
        );

        $catalog = AdminRole::updateOrCreate(
            ['slug' => 'catalog_admin'],
            [
                'name' => 'Admin Produk',
                'is_super' => false,
                'is_system' => true,
            ]
        );

        $orders = AdminRole::updateOrCreate(
            ['slug' => 'order_admin'],
            [
                'name' => 'Admin Order',
                'is_super' => false,
                'is_system' => true,
            ]
        );

        $finance = AdminRole::updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Admin Finance',
                'is_super' => false,
                'is_system' => true,
            ]
        );

        $marketing = AdminRole::updateOrCreate(
            ['slug' => 'marketing_admin'],
            [
                'name' => 'Admin Marketing',
                'is_super' => false,
                'is_system' => true,
            ]
        );

        $auditor = AdminRole::updateOrCreate(
            ['slug' => 'auditor'],
            [
                'name' => 'Auditor (Read Only)',
                'is_super' => false,
                'is_system' => true,
            ]
        );

        $map = [
            'content_admin' => [
                'manage_site_settings', 'manage_banners', 'manage_popups', 'manage_pages', 'manage_faqs', 'manage_uploads',
            ],
            'catalog_admin' => [
                'manage_categories', 'manage_subcategories', 'manage_products', 'manage_licenses', 'manage_uploads',
            ],
            'order_admin' => [
                'manage_orders', 'manage_deliveries', 'view_dashboard',
            ],
            'finance_admin' => [
                'manage_wallets', 'manage_withdraws', 'view_dashboard',
            ],
            'marketing_admin' => [
                'manage_referrals', 'manage_discounts', 'manage_vouchers', 'view_dashboard',
            ],
            'auditor' => [
                'view_dashboard', 'view_audit_logs',
            ],
        ];

        foreach ($map as $slug => $keys) {
            $role = AdminRole::where('slug', $slug)->first();
            $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permIds);
        }

        // owner is_super = true => allow all
        $owner->permissions()->sync([]);
    }
}