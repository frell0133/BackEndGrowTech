<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'email')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Constraint bawaan dari $table->string('email')->unique().
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_active_unique');

            // Hanya akun aktif yang wajib unik. Akun soft-deleted tidak memblokir reuse email.
            // LOWER(email) menjaga agar Test@x.com dan test@x.com tetap dianggap sama.
            DB::statement('CREATE UNIQUE INDEX users_email_active_unique ON users (LOWER(email)) WHERE deleted_at IS NULL');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_active_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_active_unique ON users (LOWER(email)) WHERE deleted_at IS NULL');

            return;
        }

        if ($driver === 'mysql') {
            // Fallback MySQL 8+: unique partial index tidak tersedia, jadi pakai generated column.
            try {
                DB::statement('ALTER TABLE users DROP INDEX users_email_unique');
            } catch (Throwable $e) {
                // Index mungkin sudah tidak ada; lanjutkan.
            }

            if (!Schema::hasColumn('users', 'active_email')) {
                DB::statement("ALTER TABLE users ADD active_email VARCHAR(190) GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN LOWER(email) ELSE NULL END) STORED");
            }

            try {
                DB::statement('DROP INDEX users_active_email_unique ON users');
            } catch (Throwable $e) {
                // Index mungkin belum ada; lanjutkan.
            }

            DB::statement('CREATE UNIQUE INDEX users_active_email_unique ON users (active_email)');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_active_unique');

            // Rollback ini bisa gagal kalau sudah ada email aktif dan soft-deleted yang sama.
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS users_email_active_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
            return;
        }

        if ($driver === 'mysql') {
            try {
                DB::statement('DROP INDEX users_active_email_unique ON users');
            } catch (Throwable $e) {
            }

            if (Schema::hasColumn('users', 'active_email')) {
                DB::statement('ALTER TABLE users DROP COLUMN active_email');
            }

            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        }
    }
};
