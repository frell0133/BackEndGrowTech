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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('gateway_code'); // midtrans, etc.
            $table->string('external_id')->nullable(); // transaction_id / reference
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('initiated'); // initiated, pending, paid, failed, expired

            $table->jsonb('raw_callback')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['gateway_code', 'status']);
            $table->index(['external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
