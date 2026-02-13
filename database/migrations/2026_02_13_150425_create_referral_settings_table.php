<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('enabled')->default(true);

            // DISKON buat pemakai kode
            $table->string('discount_type', 10)->default('percent'); // percent|fixed
            $table->unsignedInteger('discount_value')->default(10);
            $table->unsignedInteger('discount_max_amount')->default(50000);
            $table->unsignedInteger('min_order_amount')->default(0);

            // KOMISI buat pemilik kode
            $table->string('commission_type', 10)->default('percent'); // percent|fixed
            $table->unsignedInteger('commission_value')->default(5);

            // LIMIT
            $table->unsignedInteger('max_uses_per_referrer')->default(999999);
            $table->unsignedInteger('max_uses_per_user')->default(1);

            // WITHDRAW
            $table->unsignedInteger('min_withdrawal')->default(100000);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_settings');
    }
};
