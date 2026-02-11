<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('product_stock_logs', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_stock_id')->constrained('product_stocks')->cascadeOnDelete();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

      $table->string('action', 30); 
      // import | take | release | reserve | deliver | disable | enable | delete

      $table->json('meta')->nullable();
      $table->timestamps();

      $table->index(['action']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('product_stock_logs');
  }
};
