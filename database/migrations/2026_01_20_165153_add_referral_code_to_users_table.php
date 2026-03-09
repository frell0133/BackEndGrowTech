<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 12)->nullable()->unique()->after('role');
            }
        });

        $userIds = DB::table('users')
            ->whereNull('referral_code')
            ->pluck('id');

        foreach ($userIds as $id) {
            DB::table('users')
                ->where('id', $id)
                ->update([
                    'referral_code' => $this->generateUniqueReferralCode(),
                ]);
        }
    }

    private function generateUniqueReferralCode(): string
    {
        $prefix = 'GTC-';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $suffix = '';

            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $code = $prefix . $suffix;
        } while (DB::table('users')->where('referral_code', $code)->exists());

        return $code;
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referral_code')) {
                $table->dropUnique(['referral_code']);
                $table->dropColumn('referral_code');
            }
        });
    }
};