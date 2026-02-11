<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->boolean('track_stock')->default(true)->after('tier_pricing');
      $table->unsignedInteger('stock_min_alert')->default(0)->after('track_stock');
    });
  }

  public function down(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->dropColumn(['track_stock','stock_min_alert']);
    });
  }
};

