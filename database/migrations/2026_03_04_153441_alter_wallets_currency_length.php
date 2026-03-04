<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // PostgreSQL (Railway): varchar(3) -> varchar(32)
        DB::statement("ALTER TABLE wallets ALTER COLUMN currency TYPE VARCHAR(32)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE wallets ALTER COLUMN currency TYPE VARCHAR(3)");
    }
};