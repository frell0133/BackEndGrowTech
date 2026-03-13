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

        AdminRole::updateOrCreate(['slug' => 'content_admin'], ['name' => 'Admin Konten', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'catalog_admin'], ['name' => 'Admin Produk', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'order_admin'], ['name' => 'Admin Order', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'finance_admin'], ['name' => 'Admin Finance', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'marketing_admin'], ['name' => 'Admin Marketing', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'settings_admin'], ['name' => 'Admin Settings', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'ops_admin'], ['name' => 'Admin Operasional', 'is_super' => false, 'is_system' => true]);
        AdminRole::updateOrCreate(['slug' => 'auditor'], ['name' => 'Auditor (Read Only)', 'is_super' => false, 'is_system' => true]);

        $map = [
            'content_admin' => [
                'manage_site_settings', 'manage_banners', 'manage_popups', 'manage_pages', 'manage_faqs', 'manage_uploads',
            ],
            'catalog_admin' => [
                'manage_categories', 'manage_subcategories', 'manage_products', 'manage_licenses', 'manage_product_stocks', 'manage_stock_proofs', 'manage_uploads', 'view_dashboard',
            ],
            'order_admin' => [
                'manage_orders', 'manage_deliveries', 'view_dashboard',
            ],
            'finance_admin' => [
                'view_dashboard', 'view_payments', 'manage_wallets', 'manage_withdraws',
            ],
            'marketing_admin' => [
                'manage_referrals', 'manage_discounts', 'manage_vouchers', 'view_dashboard',
            ],
            'settings_admin' => [
                'view_dashboard', 'manage_site_settings', 'manage_system_access', 'manage_payment_gateways', 'manage_uploads',
            ],
            'ops_admin' => [
                'view_dashboard', 'manage_orders', 'manage_deliveries', 'manage_licenses', 'manage_product_stocks', 'manage_stock_proofs',
            ],
            'auditor' => [
                'view_dashboard', 'view_audit_logs', 'view_payments',
            ],
        ];

        foreach ($map as $slug => $keys) {
            $role = AdminRole::where('slug', $slug)->first();
            $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permIds);
        }

        $owner->permissions()->sync([]);
    }
}
