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
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->string('license_key')->nullable(); // can be null for FILE-only
            $table->jsonb('metadata')->nullable();      // {username, password, region, etc}
            $table->string('file_path')->nullable();    // storage path for license file

            $table->string('status')->default('available'); // available, used, revoked
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            // prevent duplicates (adjust if you want per-product uniqueness)
            $table->unique(['product_id', 'license_key']);
            $table->index(['product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
