<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_gateways', 'provider')) {
                $table->string('provider', 100)->nullable();
            }
            if (!Schema::hasColumn('payment_gateways', 'driver')) {
                $table->string('driver', 100)->nullable();
            }
            if (!Schema::hasColumn('payment_gateways', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('payment_gateways', 'is_default_order')) {
                $table->boolean('is_default_order')->default(false);
            }
            if (!Schema::hasColumn('payment_gateways', 'is_default_topup')) {
                $table->boolean('is_default_topup')->default(false);
            }
            if (!Schema::hasColumn('payment_gateways', 'supports_order')) {
                $table->boolean('supports_order')->default(true);
            }
            if (!Schema::hasColumn('payment_gateways', 'supports_topup')) {
                $table->boolean('supports_topup')->default(true);
            }
            if (!Schema::hasColumn('payment_gateways', 'sandbox_mode')) {
                $table->boolean('sandbox_mode')->default(true);
            }
            if (!Schema::hasColumn('payment_gateways', 'fee_type')) {
                $table->string('fee_type', 20)->nullable();
            }
            if (!Schema::hasColumn('payment_gateways', 'fee_value')) {
                $table->decimal('fee_value', 14, 2)->default(0);
            }
            if (!Schema::hasColumn('payment_gateways', 'sort_order')) {
                $table->integer('sort_order')->default(0);
            }
            if (!Schema::hasColumn('payment_gateways', 'secret_config')) {
                $table->text('secret_config')->nullable();
            }
        });

        Schema::table('wallet_topups', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_topups', 'gateway_code')) {
                $table->string('gateway_code', 100)->nullable();
            }
            if (!Schema::hasColumn('wallet_topups', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }
        });

        try {
            Schema::table('payment_gateways', function (Blueprint $table) {
                $table->index(['is_active', 'supports_order', 'sort_order'], 'pg_active_order_idx');
                $table->index(['is_active', 'supports_topup', 'sort_order'], 'pg_active_topup_idx');
                $table->index(['provider', 'driver'], 'pg_provider_driver_idx');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('wallet_topups', function (Blueprint $table) {
                $table->index(['gateway_code', 'status'], 'wallet_topups_gateway_status_idx');
            });
        } catch (\Throwable $e) {
        }

        $gateways = DB::table('payment_gateways')
            ->select('id', 'code', 'provider', 'driver')
            ->get();

        foreach ($gateways as $gateway) {
            DB::table('payment_gateways')
                ->where('id', $gateway->id)
                ->update([
                    'provider' => $gateway->provider ?: $gateway->code,
                    'driver' => $gateway->driver ?: $gateway->code,
                ]);
        }

        DB::table('payment_gateways')
            ->where('code', 'midtrans')
            ->update([
                'provider' => 'midtrans',
                'driver' => 'midtrans',
                'supports_order' => true,
                'supports_topup' => true,
                'sandbox_mode' => true,
                'sort_order' => 10,
            ]);

        DB::table('wallet_topups')
            ->whereNull('gateway_code')
            ->update(['gateway_code' => 'midtrans']);
    }

    public function down(): void
    {
        try {
            Schema::table('wallet_topups', function (Blueprint $table) {
                $table->dropIndex('wallet_topups_gateway_status_idx');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('payment_gateways', function (Blueprint $table) {
                $table->dropIndex('pg_active_order_idx');
                $table->dropIndex('pg_active_topup_idx');
                $table->dropIndex('pg_provider_driver_idx');
            });
        } catch (\Throwable $e) {
        }

        Schema::table('wallet_topups', function (Blueprint $table) {
            $drop = [];

            if (Schema::hasColumn('wallet_topups', 'gateway_code')) {
                $drop[] = 'gateway_code';
            }
            if (Schema::hasColumn('wallet_topups', 'paid_at')) {
                $drop[] = 'paid_at';
            }

            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('payment_gateways', function (Blueprint $table) {
            $drop = [];

            foreach ([
                'provider',
                'driver',
                'description',
                'is_default_order',
                'is_default_topup',
                'supports_order',
                'supports_topup',
                'sandbox_mode',
                'fee_type',
                'fee_value',
                'sort_order',
                'secret_config',
            ] as $col) {
                if (Schema::hasColumn('payment_gateways', $col)) {
                    $drop[] = $col;
                }
            }

            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};