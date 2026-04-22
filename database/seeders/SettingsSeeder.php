<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
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

        $systemDefaults = [
            'public_access' => [
                'enabled' => true,
                'message' => 'Halaman publik sedang maintenance.',
            ],
            'user_auth_access' => [
                'enabled' => true,
                'message' => 'Login dan registrasi user sedang maintenance.',
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

        Setting::query()
            ->where('group', 'system')
            ->where('key', 'user_area_access')
            ->delete();
    }
}
