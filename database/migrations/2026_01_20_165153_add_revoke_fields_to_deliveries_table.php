<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('deliveries', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->index();
            }

            if (!Schema::hasColumn('deliveries', 'revoked_reason')) {
                $table->text('revoked_reason')->nullable();
            }

            if (!Schema::hasColumn('deliveries', 'revoked_by')) {
                $table->foreignId('revoked_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('deliveries', 'revoked_by')) {
                $table->dropConstrainedForeignId('revoked_by');
            }
            if (Schema::hasColumn('deliveries', 'revoked_at')) {
                $table->dropColumn('revoked_at');
            }
            if (Schema::hasColumn('deliveries', 'revoked_reason')) {
                $table->dropColumn('revoked_reason');
            }
        });
    }
};
