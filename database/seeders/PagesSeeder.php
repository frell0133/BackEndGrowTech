<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Page;

class PagesSeeder extends Seeder
{
    public function run(): void
    {
        Page::updateOrCreate(
            ['slug' => 'ketentuan-layanan'],
            [
                'title' => 'Ketentuan Layanan',
                'content' => '<h1>Ketentuan Layanan</h1><p>Isi ketentuan layanan di sini...</p>',
                'is_published' => true,
            ]
        );

        Page::updateOrCreate(
            ['slug' => 'privasi-kami'],
            [
                'title' => 'Privasi Kami',
                'content' => '<h1>Kebijakan Privasi</h1><p>Isi privasi di sini...</p>',
                'is_published' => true,
            ]
        );
    }
}
