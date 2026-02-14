<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_settings', function (Blueprint $table) {
            $table->string('campaign_name')->nullable()->after('enabled');

            // Campaign window (expiration)
            $table->timestamp('starts_at')->nullable()->after('campaign_name');
            $table->timestamp('ends_at')->nullable()->after('starts_at');

            // Limit komisi total per referrer (0 = unlimited)
            $table->unsignedInteger('max_commission_total_per_referrer')
                ->default(0)
                ->after('commission_value');
        });
    }

    public function down(): void
    {
        Schema::table('referral_settings', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_name',
                'starts_at',
                'ends_at',
                'max_commission_total_per_referrer',
            ]);
        });
    }
};
