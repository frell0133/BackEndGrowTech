<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

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