<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->string('status', 20)->default('pending'); // pending|valid|invalid

            $table->unsignedInteger('order_amount')->default(0);
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('commission_amount')->default(0);

            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->index(['referrer_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_transactions');
    }
};
