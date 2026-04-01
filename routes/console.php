<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ops:prune-runtime {--dry-run : Show counts without deleting rows}', function () {
    $dryRun = (bool) $this->option('dry-run');

    $now = now();
    $nowTs = $now->getTimestamp();
    $sessionCutoff = $now->copy()->subMinutes(((int) config('session.lifetime', 120)) + 60)->getTimestamp();
    $expiredChallengeCutoff = $now->copy()->subHours(6);
    $consumedChallengeCutoff = $now->copy()->subDay();
    $resetTokenCutoff = $now->copy()->subDay();

    $results = [];

    $prune = function (string $label, callable $resolver) use (&$results, $dryRun): void {
        try {
            $affected = (int) $resolver($dryRun);
            $results[$label] = $affected;
        } catch (\Throwable $e) {
            $results[$label] = 'error: '.$e->getMessage();

            Log::warning('ops:prune-runtime failed on segment', [
                'segment' => $label,
                'error' => $e->getMessage(),
            ]);
        }
    };

    if (Schema::hasTable('cache')) {
        $prune('cache_expired', fn (bool $dry) => $dry
            ? DB::table('cache')->where('expiration', '<=', $nowTs)->count()
            : DB::table('cache')->where('expiration', '<=', $nowTs)->delete());
    }

    if (Schema::hasTable('cache_locks')) {
        $prune('cache_locks_expired', fn (bool $dry) => $dry
            ? DB::table('cache_locks')->where('expiration', '<=', $nowTs)->count()
            : DB::table('cache_locks')->where('expiration', '<=', $nowTs)->delete());
    }

    $sessionTable = config('session.table', 'sessions');
    if (Schema::hasTable($sessionTable)) {
        $prune('sessions_stale', fn (bool $dry) => $dry
            ? DB::table($sessionTable)->where('last_activity', '<=', $sessionCutoff)->count()
            : DB::table($sessionTable)->where('last_activity', '<=', $sessionCutoff)->delete());
    }

    if (Schema::hasTable('auth_challenges')) {
        $prune('auth_challenges_expired', fn (bool $dry) => $dry
            ? DB::table('auth_challenges')
                ->where('expires_at', '<=', $expiredChallengeCutoff)
                ->count()
            : DB::table('auth_challenges')
                ->where('expires_at', '<=', $expiredChallengeCutoff)
                ->delete());

        $prune('auth_challenges_consumed', fn (bool $dry) => $dry
            ? DB::table('auth_challenges')
                ->whereNotNull('consumed_at')
                ->where('consumed_at', '<=', $consumedChallengeCutoff)
                ->count()
            : DB::table('auth_challenges')
                ->whereNotNull('consumed_at')
                ->where('consumed_at', '<=', $consumedChallengeCutoff)
                ->delete());
    }

    if (Schema::hasTable('password_reset_tokens')) {
        $prune('password_reset_tokens_expired', fn (bool $dry) => $dry
            ? DB::table('password_reset_tokens')
                ->whereNotNull('created_at')
                ->where('created_at', '<=', $resetTokenCutoff)
                ->count()
            : DB::table('password_reset_tokens')
                ->whereNotNull('created_at')
                ->where('created_at', '<=', $resetTokenCutoff)
                ->delete());
    }

    $results['dry_run'] = $dryRun;
    $results['run_at'] = $now->toDateTimeString();

    if (! $dryRun) {
        Log::info('ops:prune-runtime executed', $results);
    }

    $this->table(
        ['segment', 'result'],
        collect($results)->map(fn ($value, $key) => [
            'segment' => $key,
            'result' => (string) $value,
        ])->values()->all()
    );
})->purpose('Prune runtime tables for database queue/cache/session/auth operations');

Schedule::command('ops:prune-runtime')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:10')
    ->withoutOverlapping();

Schedule::command('queue:prune-batches --hours=168 --unfinished=168 --cancelled=168')
    ->dailyAt('02:20')
    ->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:30')
    ->withoutOverlapping();

Artisan::command('orders:auto-cancel-created', function () {
    $cutoff = now()->subHour();
    $updatedOrders = 0;
    $updatedPayments = 0;

    Order::query()
        ->where('status', OrderStatus::CREATED->value)
        ->where('created_at', '<=', $cutoff)
        ->select('id')
        ->orderBy('id')
        ->chunkById(100, function ($rows) use (&$updatedOrders, &$updatedPayments) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$updatedOrders, &$updatedPayments) {
                    $lockedOrder = Order::query()
                        ->with('payment')
                        ->lockForUpdate()
                        ->find($row->id);

                    if (! $lockedOrder) {
                        return;
                    }

                    $currentOrderStatus = (string) ($lockedOrder->status?->value ?? $lockedOrder->status);

                    if ($currentOrderStatus !== OrderStatus::CREATED->value) {
                        return;
                    }

                    $lockedOrder->status = OrderStatus::CANCELLED->value;
                    $lockedOrder->save();
                    $updatedOrders++;

                    if ($lockedOrder->payment) {
                        $currentPaymentStatus = (string) ($lockedOrder->payment->status?->value ?? $lockedOrder->payment->status);

                        if (in_array($currentPaymentStatus, [
                            PaymentStatus::INITIATED->value,
                            PaymentStatus::PENDING->value,
                        ], true)) {
                            $lockedOrder->payment->status = PaymentStatus::EXPIRED->value;
                            $lockedOrder->payment->save();
                            $updatedPayments++;
                        }
                    }
                });
            }
        });

    Log::info('orders:auto-cancel-created executed', [
        'updated_orders' => $updatedOrders,
        'updated_payments' => $updatedPayments,
        'cutoff' => $cutoff->toDateTimeString(),
    ]);

    $this->info("Updated orders: {$updatedOrders}, payments: {$updatedPayments}");
})->purpose('Auto cancel created orders older than 1 hour');

Schedule::command('orders:auto-cancel-created')
    ->everyFiveMinutes()
    ->withoutOverlapping();
