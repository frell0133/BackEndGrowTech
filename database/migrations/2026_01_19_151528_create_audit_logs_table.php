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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action'); // create/update/delete/approve/reject/adjust/etc
            $table->string('entity'); // products, orders, gateways, withdrawals, etc
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->jsonb('meta')->nullable(); // before/after, request data, ip, etc
            $table->timestamps();

            $table->index(['entity', 'entity_id']);
            $table->index(['user_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
