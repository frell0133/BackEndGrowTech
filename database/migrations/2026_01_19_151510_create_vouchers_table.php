<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('type'); // fixed, percent
            $table->decimal('value', 14, 2);

            $table->unsignedInteger('quota')->nullable(); // null = unlimited
            $table->decimal('min_purchase', 14, 2)->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->jsonb('rules')->nullable(); // conflict rules, max per user etc
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
