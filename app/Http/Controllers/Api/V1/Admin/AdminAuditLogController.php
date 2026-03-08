<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $userId = $request->query('user_id');
        $action = $request->query('action');
        $entity = $request->query('entity');
        $module = $request->query('module');
        $status = $request->query('status');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $logs = AuditLog::query()
            ->with(['user:id,name,full_name,email'])
            ->when($userId, fn ($qq) => $qq->where('user_id', (int) $userId))
            ->when($action, fn ($qq) => $qq->where('action', $action))
            ->when($entity, fn ($qq) => $qq->where('entity', $entity))
            ->when($module, fn ($qq) => $qq->where('meta->module', $module))
            ->when($status, fn ($qq) => $qq->where('meta->status', $status));

        if ($dateFrom) {
            $logs->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $logs->whereDate('created_at', '<=', $dateTo);
        }

        if ($q !== '') {
            $logs->where(function ($qq) use ($q) {
                $qq->where('action', 'ilike', "%{$q}%")
                    ->orWhere('entity', 'ilike', "%{$q}%")
                    ->orWhere('meta->>module', 'ilike', "%{$q}%")
                    ->orWhere('meta->>summary', 'ilike', "%{$q}%")
                    ->orWhereHas('user', function ($u) use ($q) {
                        $u->where('name', 'ilike', "%{$q}%")
                            ->orWhere('full_name', 'ilike', "%{$q}%")
                            ->orWhere('email', 'ilike', "%{$q}%");
                    });
            });
        }

        $paginator = $logs
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (AuditLog $log) => $this->transformListItem($log));

        return $this->ok($paginator);
    }

    public function show(string $id)
    {
        $log = AuditLog::query()
            ->with(['user:id,name,full_name,email'])
            ->find($id);

        if (!$log) {
            return $this->fail('Audit log tidak ditemukan', 404);
        }

        return $this->ok([
            'id' => $log->id,
            'created_at' => $log->created_at,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'full_name' => $log->user->full_name,
                'email' => $log->user->email,
            ] : null,
            'action' => $log->action,
            'entity' => $log->entity,
            'entity_id' => $log->entity_id,
            'meta' => $log->meta ?? [],
        ]);
    }

    private function transformListItem(AuditLog $log): array
    {
        $meta = is_array($log->meta) ? $log->meta : [];

        return [
            'id' => $log->id,
            'created_at' => $log->created_at,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'full_name' => $log->user->full_name,
                'email' => $log->user->email,
            ] : null,
            'action' => $log->action,
            'entity' => $log->entity,
            'entity_id' => $log->entity_id,
            'module' => data_get($meta, 'module'),
            'status' => data_get($meta, 'status', 'success'),
            'summary' => data_get($meta, 'summary'),
            'target' => data_get($meta, 'target'),
        ];
    }
}