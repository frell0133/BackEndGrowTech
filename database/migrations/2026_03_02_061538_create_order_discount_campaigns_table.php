<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_discount_campaigns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('campaign_id')
                ->constrained('discount_campaigns')
                ->cascadeOnDelete();

            // berapa diskon yang disumbang campaign ini utk order tsb
            $table->decimal('discount_amount', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['order_id', 'campaign_id'], 'uniq_order_campaign');
            $table->index(['campaign_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discount_campaigns');
    }
};