<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminPermission;

class AdminPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            // Dashboard & logs
            ['key' => 'view_dashboard', 'label' => 'Dashboard', 'group' => 'dashboard', 'is_protected' => false],
            ['key' => 'view_audit_logs', 'label' => 'Audit Logs', 'group' => 'security', 'is_protected' => false],

            // Users/Admin management
            ['key' => 'manage_users', 'label' => 'Kelola User', 'group' => 'users', 'is_protected' => false],
            ['key' => 'manage_admins', 'label' => 'Kelola Admin & Role', 'group' => 'security', 'is_protected' => true],

            // Catalog
            ['key' => 'manage_categories', 'label' => 'Kategori', 'group' => 'catalog', 'is_protected' => false],
            ['key' => 'manage_subcategories', 'label' => 'Sub Kategori', 'group' => 'catalog', 'is_protected' => false],
            ['key' => 'manage_products', 'label' => 'Produk', 'group' => 'catalog', 'is_protected' => false],
            ['key' => 'manage_licenses', 'label' => 'Lisensi/Stock', 'group' => 'catalog', 'is_protected' => false],

            // Orders/Delivery
            ['key' => 'manage_orders', 'label' => 'Order', 'group' => 'orders', 'is_protected' => false],
            ['key' => 'manage_deliveries', 'label' => 'Delivery', 'group' => 'orders', 'is_protected' => false],

            // Finance
            ['key' => 'manage_wallets', 'label' => 'Wallet/Topup', 'group' => 'finance', 'is_protected' => false],
            ['key' => 'manage_withdraws', 'label' => 'Withdraw', 'group' => 'finance', 'is_protected' => false],

            // Marketing
            ['key' => 'manage_referrals', 'label' => 'Referral', 'group' => 'marketing', 'is_protected' => false],
            ['key' => 'manage_discounts', 'label' => 'Discount Campaign', 'group' => 'marketing', 'is_protected' => false],
            ['key' => 'manage_vouchers', 'label' => 'Voucher', 'group' => 'marketing', 'is_protected' => false],

            // Content/Settings
            ['key' => 'manage_site_settings', 'label' => 'Settings Website', 'group' => 'content', 'is_protected' => false],
            ['key' => 'manage_banners', 'label' => 'Banner', 'group' => 'content', 'is_protected' => false],
            ['key' => 'manage_popups', 'label' => 'Popup', 'group' => 'content', 'is_protected' => false],
            ['key' => 'manage_pages', 'label' => 'Pages', 'group' => 'content', 'is_protected' => false],
            ['key' => 'manage_faqs', 'label' => 'FAQ', 'group' => 'content', 'is_protected' => false],

            // Payment gateway
            ['key' => 'manage_payment_gateways', 'label' => 'Payment Gateways', 'group' => 'settings', 'is_protected' => false],

            // Upload sign
            ['key' => 'manage_uploads', 'label' => 'Upload (Sign URL)', 'group' => 'settings', 'is_protected' => false],
        ];

        foreach ($perms as $p) {
            AdminPermission::updateOrCreate(
                ['key' => $p['key']],
                $p
            );
        }
    }
}