<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {

            if (!Schema::hasColumn('licenses', 'data_other')) {
                $table->string('data_other', 200)->nullable()->after('license_key');
            }

            if (!Schema::hasColumn('licenses', 'note')) {
                $table->text('note')->nullable()->after('data_other');
            }

            if (!Schema::hasColumn('licenses', 'fingerprint')) {
                $table->string('fingerprint', 64)->nullable()->after('note');
            }

            if (!Schema::hasColumn('licenses', 'status')) {
                $table->string('status', 20)
                    ->default('available')
                    ->after('fingerprint');
            }

            if (!Schema::hasColumn('licenses', 'taken_by')) {
                $table->foreignId('taken_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('status');
            }

            if (!Schema::hasColumn('licenses', 'taken_at')) {
                $table->timestamp('taken_at')
                    ->nullable()
                    ->after('taken_by');
            }

            if (!Schema::hasColumn('licenses', 'reserved_order_id')) {
                $table->foreignId('reserved_order_id')
                    ->nullable()
                    ->constrained('orders')
                    ->nullOnDelete()
                    ->after('taken_at');
            }

            if (!Schema::hasColumn('licenses', 'reserved_at')) {
                $table->timestamp('reserved_at')
                    ->nullable()
                    ->after('reserved_order_id');
            }

            if (!Schema::hasColumn('licenses', 'delivered_at')) {
                $table->timestamp('delivered_at')
                    ->nullable()
                    ->after('reserved_at');
            }

        });

        // Tambah unique index fingerprint jika belum ada
        if (!Schema::hasColumn('licenses', 'fingerprint')) {
            return;
        }

        try {
            Schema::table('licenses', function (Blueprint $table) {
                $table->unique(['product_id', 'fingerprint'], 'licenses_product_fp_unique');
            });
        } catch (\Throwable $e) {
            // ignore kalau index sudah ada
        }
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {

            if (Schema::hasColumn('licenses', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }

            if (Schema::hasColumn('licenses', 'reserved_at')) {
                $table->dropColumn('reserved_at');
            }

            if (Schema::hasColumn('licenses', 'reserved_order_id')) {
                $table->dropConstrainedForeignId('reserved_order_id');
            }

            if (Schema::hasColumn('licenses', 'taken_at')) {
                $table->dropColumn('taken_at');
            }

            if (Schema::hasColumn('licenses', 'taken_by')) {
                $table->dropConstrainedForeignId('taken_by');
            }

            if (Schema::hasColumn('licenses', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('licenses', 'fingerprint')) {
                $table->dropColumn('fingerprint');
            }

            if (Schema::hasColumn('licenses', 'note')) {
                $table->dropColumn('note');
            }

            if (Schema::hasColumn('licenses', 'data_other')) {
                $table->dropColumn('data_other');
            }
        });
    }
};
