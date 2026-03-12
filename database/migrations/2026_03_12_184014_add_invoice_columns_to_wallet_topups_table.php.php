<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_topups', 'invoice_emailed_at')) {
                $table->timestamp('invoice_emailed_at')->nullable()->after('paid_at');
            }

            if (!Schema::hasColumn('wallet_topups', 'invoice_email_error')) {
                $table->text('invoice_email_error')->nullable()->after('invoice_emailed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_topups', 'invoice_emailed_at')) {
                $table->dropColumn('invoice_emailed_at');
            }

            if (Schema::hasColumn('wallet_topups', 'invoice_email_error')) {
                $table->dropColumn('invoice_email_error');
            }
        });
    }
};