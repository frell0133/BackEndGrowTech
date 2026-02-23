<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) return;

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'invoice_emailed_at')) {
                $table->timestamp('invoice_emailed_at')->nullable()->after('updated_at');
            }

            if (!Schema::hasColumn('orders', 'invoice_email_error')) {
                $table->text('invoice_email_error')->nullable()->after('invoice_emailed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) return;

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'invoice_email_error')) {
                $table->dropColumn('invoice_email_error');
            }
            if (Schema::hasColumn('orders', 'invoice_emailed_at')) {
                $table->dropColumn('invoice_emailed_at');
            }
        });
    }
};