<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ledger_transaction_id')
                ->constrained('ledger_transactions')
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->constrained('wallets')
                ->cascadeOnDelete();

            // DEBIT = saldo berkurang, CREDIT = saldo bertambah (aturan kita)
            $table->enum('direction', ['DEBIT', 'CREDIT']);

            // nilai selalu positif (rupiah)
            $table->bigInteger('amount');

            // audit trail (biar gampang debug)
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');

            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['ledger_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
