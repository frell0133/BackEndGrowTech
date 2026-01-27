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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->string('invoice_number')->unique(); // ex: INV-2026-000001
            $table->string('status')->default('created'); // created, pending, paid, fulfilled, failed, expired, refunded

            $table->unsignedInteger('qty')->default(1);
            $table->decimal('amount', 14, 2);        // final amount after discount
            $table->decimal('subtotal', 14, 2);      // before discount
            $table->decimal('discount_total', 14, 2)->default(0);

            $table->string('payment_gateway_code')->nullable(); // chosen gateway
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['product_id']);
            $table->index(['payment_gateway_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
