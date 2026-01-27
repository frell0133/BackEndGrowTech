<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();     // terms, privacy, announcement
            $table->string('title');
            $table->longText('content');          // HTML/markdown
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
