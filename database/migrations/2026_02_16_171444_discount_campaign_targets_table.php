<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discount_campaign_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')
                ->constrained('discount_campaigns')
                ->cascadeOnDelete();

            $table->string('target_type', 30); // subcategory | product
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            $table->unique(['campaign_id', 'target_type', 'target_id'], 'uniq_campaign_target');
            $table->index(['target_type', 'target_id'], 'idx_target_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_campaign_targets');
    }
};
