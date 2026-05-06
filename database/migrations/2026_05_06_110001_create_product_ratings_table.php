<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_ratings')) {
            Schema::create('product_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedTinyInteger('rating');
                $table->timestamps();

                $table->unique(['user_id', 'product_id', 'order_id'], 'product_ratings_user_product_order_unique');
                $table->index(['product_id', 'rating'], 'product_ratings_product_rating_index');
            });
        }

        if (Schema::hasTable('favorites')) {
            $rows = DB::table('favorites')
                ->select(['user_id', 'product_id', 'rating', 'created_at', 'updated_at'])
                ->whereNotNull('rating')
                ->get();

            foreach ($rows as $row) {
                DB::table('product_ratings')->updateOrInsert(
                    [
                        'user_id' => $row->user_id,
                        'product_id' => $row->product_id,
                        'order_id' => null,
                    ],
                    [
                        'rating' => $row->rating,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ratings');
    }
};
