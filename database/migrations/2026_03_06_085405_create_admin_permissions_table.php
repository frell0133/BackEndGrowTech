<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();        // manage_products, manage_orders, ...
            $table->string('label');                // label UI
            $table->string('group')->default('general'); // buat grouping di UI
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_permissions');
    }
};