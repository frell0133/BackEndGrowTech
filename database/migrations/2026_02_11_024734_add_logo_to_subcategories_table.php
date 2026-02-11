<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            if (!Schema::hasColumn('subcategories', 'image_path')) {
                $table->string('image_path')->nullable()->after('slug');
            }
            if (!Schema::hasColumn('subcategories', 'image_url')) {
                $table->text('image_url')->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            if (Schema::hasColumn('subcategories', 'image_url')) $table->dropColumn('image_url');
            if (Schema::hasColumn('subcategories', 'image_path')) $table->dropColumn('image_path');
        });
    }
};
