<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // sesuai format: LICENSE_KEY:DATA_LAIN:CATATAN
            $table->string('license_key', 200);
            $table->string('data_other', 200)->nullable();
            $table->text('note')->nullable();

            // fingerprint untuk deteksi duplikat per product
            $table->string('fingerprint', 64);

            // available | taken | reserved | delivered | disabled
            $table->string('status', 20)->default('available');

            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at')->nullable();

            // untuk auto-assign ke order (kalau kamu mau)
            $table->foreignId('reserved_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'fingerprint']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
