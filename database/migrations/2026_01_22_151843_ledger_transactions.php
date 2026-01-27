<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();

            $table->enum('type', ['TOPUP', 'PURCHASE', 'WITHDRAW', 'REFERRAL', 'ADJUST', 'REFUND']);
            $table->enum('status', ['PENDING', 'SUCCESS', 'FAILED'])->default('SUCCESS');

            // Untuk idempotency: mencegah callback payment dobel
            $table->string('idempotency_key')->nullable()->unique();

            // Relasi opsional ke entitas bisnis (order, payment, withdraw_request, dll)
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
