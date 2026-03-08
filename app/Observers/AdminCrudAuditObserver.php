<?php

namespace App\Observers;

use App\Services\AdminAuditLogger;
use Illuminate\Database\Eloquent\Model;

class AdminCrudAuditObserver
{
    /**
     * @var array<string, array>
     */
    private static array $beforeSnapshots = [];

    public function created(Model $model): void
    {
        $audit = app(AdminAuditLogger::class);
        $audit->logModelEvent('create', $model, [], $audit->snapshot($model));
    }

    public function updating(Model $model): void
    {
        if (!$this->shouldLog()) {
            return;
        }

        self::$beforeSnapshots[$this->key($model)] = app(AdminAuditLogger::class)->snapshot($model, true);
    }

    public function updated(Model $model): void
    {
        $audit = app(AdminAuditLogger::class);
        $before = self::$beforeSnapshots[$this->key($model)] ?? $audit->snapshot($model, true);
        $after = $audit->snapshot($model);

        if ($before === $after && empty($model->getChanges())) {
            return;
        }

        $audit->logModelEvent('update', $model, $before, $after);
        unset(self::$beforeSnapshots[$this->key($model)]);
    }

    public function deleting(Model $model): void
    {
        if (!$this->shouldLog()) {
            return;
        }

        self::$beforeSnapshots[$this->key($model)] = app(AdminAuditLogger::class)->snapshot($model);
    }

    public function deleted(Model $model): void
    {
        $audit = app(AdminAuditLogger::class);
        $before = self::$beforeSnapshots[$this->key($model)] ?? $audit->snapshot($model, true);
        $audit->logModelEvent('delete', $model, $before, []);
        unset(self::$beforeSnapshots[$this->key($model)]);
    }

    private function shouldLog(): bool
    {
        return app(AdminAuditLogger::class)->shouldLogCurrentRequest();
    }

    private function key(Model $model): string
    {
        return $model::class . '#' . spl_object_id($model);
    }
}