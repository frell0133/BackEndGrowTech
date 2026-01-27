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
    }
}
