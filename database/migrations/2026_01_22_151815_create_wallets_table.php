<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // Wallet milik user (umumnya 1 user 1 wallet)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Untuk system wallet: SYSTEM_CASH, SYSTEM_REVENUE, SYSTEM_PAYOUT
            $table->string('code')->nullable()->unique();

            // Saldo cache dalam rupiah (integer)
            $table->bigInteger('balance')->default(0);

            $table->string('currency', 3)->default('IDR');

            $table->enum('status', ['ACTIVE', 'FROZEN'])->default('ACTIVE');

            $table->timestamps();

            // 1 user 1 wallet (kalau kamu mau multi wallet per user, hapus unique ini)
            $table->unique(['user_id', 'currency']);
            $table->index(['code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
