<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Midtrans order_id (unik)
            $table->string('order_id')->unique();

            $table->bigInteger('amount'); // integer rupiah
            $table->string('currency')->default('IDR');

            // initiated, pending, paid, failed, expired
            $table->string('status')->default('initiated');

            // snap data (untuk FE)
            $table->string('snap_token')->nullable();
            $table->text('redirect_url')->nullable();

            // midtrans callback
            $table->string('external_id')->nullable(); // transaction_id
            $table->jsonb('raw_callback')->nullable();

            // idempotent ledger posting
            $table->timestamp('posted_to_ledger_at')->nullable();

            $table->timestamps();

            $table->index(['user_id','status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
    }
};
