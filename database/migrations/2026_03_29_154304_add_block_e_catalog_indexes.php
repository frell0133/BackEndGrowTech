<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cache')) {
            Schema::table('cache', function (Blueprint $table) {
                $table->index('expiration', 'cache_expiration_index');
            });
        }

        if (Schema::hasTable('cache_locks')) {
            Schema::table('cache_locks', function (Blueprint $table) {
                $table->index('expiration', 'cache_locks_expiration_index');
            });
        }

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->index(['queue', 'reserved_at', 'available_at'], 'jobs_queue_reserved_available_index');
            });
        }

        if (Schema::hasTable('failed_jobs')) {
            Schema::table('failed_jobs', function (Blueprint $table) {
                $table->index('failed_at', 'failed_jobs_failed_at_index');
            });
        }

        if (Schema::hasTable('job_batches')) {
            Schema::table('job_batches', function (Blueprint $table) {
                $table->index('finished_at', 'job_batches_finished_at_index');
                $table->index('cancelled_at', 'job_batches_cancelled_at_index');
            });
        }

        if (Schema::hasTable('auth_challenges')) {
            Schema::table('auth_challenges', function (Blueprint $table) {
                $table->index('expires_at', 'auth_challenges_expires_at_index');
                $table->index('consumed_at', 'auth_challenges_consumed_at_index');
            });
        }

        if (Schema::hasTable('password_reset_tokens')) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->index('created_at', 'password_reset_tokens_created_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->dropIndex('password_reset_tokens_created_at_index');
            });
        }

        if (Schema::hasTable('auth_challenges')) {
            Schema::table('auth_challenges', function (Blueprint $table) {
                $table->dropIndex('auth_challenges_consumed_at_index');
                $table->dropIndex('auth_challenges_expires_at_index');
            });
        }

        if (Schema::hasTable('job_batches')) {
            Schema::table('job_batches', function (Blueprint $table) {
                $table->dropIndex('job_batches_cancelled_at_index');
                $table->dropIndex('job_batches_finished_at_index');
            });
        }

        if (Schema::hasTable('failed_jobs')) {
            Schema::table('failed_jobs', function (Blueprint $table) {
                $table->dropIndex('failed_jobs_failed_at_index');
            });
        }

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropIndex('jobs_queue_reserved_available_index');
            });
        }

        if (Schema::hasTable('cache_locks')) {
            Schema::table('cache_locks', function (Blueprint $table) {
                $table->dropIndex('cache_locks_expiration_index');
            });
        }

        if (Schema::hasTable('cache')) {
            Schema::table('cache', function (Blueprint $table) {
                $table->dropIndex('cache_expiration_index');
            });
        }
    }
};
