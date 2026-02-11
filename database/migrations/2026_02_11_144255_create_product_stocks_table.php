<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('product_stocks', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_id')->constrained()->cascadeOnDelete();

      // data mentah stock (misal: "LICENSE:email:catatan", atau "email|pass|profile", bebas)
      $table->text('stock_data');

      // fingerprint untuk deteksi duplikat per product
      $table->string('fingerprint', 64);

      // status
      $table->string('status', 20)->default('available'); 
      // available | taken | reserved | delivered | sold | disabled

      // ownership opsional
      $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamp('taken_at')->nullable();

      $table->foreignId('reserved_order_id')->nullable()->constrained('orders')->nullOnDelete();
      $table->timestamp('reserved_at')->nullable();

      $table->timestamp('delivered_at')->nullable();

      $table->timestamps();

      // duplikat: fingerprint + product_id harus unik (paling aman)
      $table->unique(['product_id', 'fingerprint']);
      $table->index(['product_id', 'status']);
      $table->index(['reserved_order_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('product_stocks');
  }
};

