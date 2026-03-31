<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('discount_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('discount_campaigns', 'tier_rules')) {
                $table->jsonb('tier_rules')->nullable()->after('usage_limit_per_user');
            }
        });
    }

    public function down(): void
    {
        Schema::table('discount_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('discount_campaigns', 'tier_rules')) {
                $table->dropColumn('tier_rules');
            }
        });
    }
};
