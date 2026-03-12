<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Contact - WhatsApp
        Setting::updateOrCreate(
            ['group' => 'contact', 'key' => 'whatsapp'],
            [
                'value' => [
                    'number' => '+62 821-2609-9056',
                    'label' => 'CS GrowTech',
                    'hours' => '09:00 - 17:00 WIB',
                ],
                'is_public' => true,
            ]
        );

        // Contact - Email
        Setting::updateOrCreate(
            ['group' => 'contact', 'key' => 'email'],
            [
                'value' => [
                    'address' => 'cs@growtech.id',
                    'label' => 'Customer Support',
                ],
                'is_public' => true,
            ]
        );

        // =========================
        // SYSTEM ACCESS / MAINTENANCE
        // =========================
        $systemDefaults = [
            'public_access' => [
                'enabled' => true,
                'message' => 'Halaman publik sedang maintenance.',
            ],
            'user_auth_access' => [
                'enabled' => true,
                'message' => 'Login dan registrasi user sedang maintenance.',
            ],
            'user_area_access' => [
                'enabled' => true,
                'message' => 'Area user sedang maintenance.',
            ],
            'catalog_access' => [
                'enabled' => true,
                'message' => 'Katalog sedang maintenance.',
            ],
            'checkout_access' => [
                'enabled' => true,
                'message' => 'Checkout sedang maintenance.',
            ],
            'topup_access' => [
                'enabled' => true,
                'message' => 'Top up wallet sedang maintenance.',
            ],
        ];

        foreach ($systemDefaults as $key => $value) {
            Setting::updateOrCreate(
                ['group' => 'system', 'key' => $key],
                [
                    'value' => $value,
                    'is_public' => false,
                ]
            );
        }
    }
}