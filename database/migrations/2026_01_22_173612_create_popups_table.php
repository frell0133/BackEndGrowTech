<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('popups', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');             
            $table->string('cta_text')->nullable();
            $table->string('cta_url')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('target')->default('all'); 
            $table->timestamps();

            $table->index(['is_active', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popups');
    }
};
