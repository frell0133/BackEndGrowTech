<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('challenge_id', 100)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30); // register | login | social
            $table->string('channel', 20)->default('email');
            $table->string('email', 190);
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('resend_available_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->boolean('remember')->default(false);
            $table->string('provider', 30)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
            $table->index(['email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_challenges');
    }
};