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
            ['key' => 'view_dashboard', 'label' => 'Dashboard', 'group' => 'dashboard'],
            ['key' => 'view_audit_logs', 'label' => 'Audit Logs', 'group' => 'security'],

            // Users/Admin management
            ['key' => 'manage_users', 'label' => 'Kelola User', 'group' => 'users'],
            ['key' => 'manage_admins', 'label' => 'Kelola Admin & Role', 'group' => 'security'],

            // Catalog
            ['key' => 'manage_categories', 'label' => 'Kategori', 'group' => 'catalog'],
            ['key' => 'manage_subcategories', 'label' => 'Sub Kategori', 'group' => 'catalog'],
            ['key' => 'manage_products', 'label' => 'Produk', 'group' => 'catalog'],
            ['key' => 'manage_licenses', 'label' => 'Lisensi/Stock', 'group' => 'catalog'],

            // Orders/Delivery
            ['key' => 'manage_orders', 'label' => 'Order', 'group' => 'orders'],
            ['key' => 'manage_deliveries', 'label' => 'Delivery', 'group' => 'orders'],

            // Finance
            ['key' => 'manage_wallets', 'label' => 'Wallet/Topup', 'group' => 'finance'],
            ['key' => 'manage_withdraws', 'label' => 'Withdraw', 'group' => 'finance'],

            // Marketing
            ['key' => 'manage_referrals', 'label' => 'Referral', 'group' => 'marketing'],
            ['key' => 'manage_discounts', 'label' => 'Discount Campaign', 'group' => 'marketing'],
            ['key' => 'manage_vouchers', 'label' => 'Voucher', 'group' => 'marketing'],

            // Content/Settings
            ['key' => 'manage_site_settings', 'label' => 'Settings Website', 'group' => 'content'],
            ['key' => 'manage_banners', 'label' => 'Banner', 'group' => 'content'],
            ['key' => 'manage_popups', 'label' => 'Popup', 'group' => 'content'],
            ['key' => 'manage_pages', 'label' => 'Pages', 'group' => 'content'],
            ['key' => 'manage_faqs', 'label' => 'FAQ', 'group' => 'content'],

            // Payment gateway
            ['key' => 'manage_payment_gateways', 'label' => 'Payment Gateways', 'group' => 'settings'],

            // Upload sign (supabase)
            ['key' => 'manage_uploads', 'label' => 'Upload (Sign URL)', 'group' => 'settings'],
        ];

        foreach ($perms as $p) {
            AdminPermission::updateOrCreate(['key' => $p['key']], $p);
        }
    }
}