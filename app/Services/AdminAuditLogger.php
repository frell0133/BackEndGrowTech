<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAuditLogger
{
    public function log(
        Request $request,
        string $action,
        string $entity,
        int|string|null $entityId = null,
        array $meta = []
    ): AuditLog {
        $actor = $request->user();

        $payload = array_merge([
            'scope' => 'admin',
            'status' => 'success',
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => optional($request->route())->uri(),
            ],
            'context' => [
                'ip' => $request->ip(),
                'user_agent' => $this->limitString($request->userAgent(), 500),
            ],
        ], $meta);

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => is_numeric($entityId) ? (int) $entityId : null,
            'meta' => $this->sanitize($payload),
        ]);
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if ($this->isSensitiveKey((string) $k)) {
                    $out[$k] = '[REDACTED]';
                    continue;
                }

                $out[$k] = $this->sanitize($v, (string) $k);
            }
            return $out;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value, $key);
        }

        if (is_string($value)) {
            if ($key && $this->looksLikeSecretField($key)) {
                return '[REDACTED]';
            }

            return $this->limitString($value, 4000);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = Str::lower($key);

        return in_array($key, [
            'password',
            'password_confirmation',
            'remember_token',
            'token',
            'access_token',
            'refresh_token',
            'secret',
            'secret_key',
            'client_secret',
            'api_key',
            'server_key',
            'private_key',
            'signature',
            'signature_key',
            'license_key',
            'license',
            'credential',
            'credentials',
            'proof_file',
            'raw_payload',
        ], true);
    }

    private function looksLikeSecretField(string $key): bool
    {
        $key = Str::lower($key);

        return Str::contains($key, [
            'password',
            'token',
            'secret',
            'key',
            'signature',
            'credential',
            'license',
        ]);
    }

    private function limitString(?string $value, int $limit): ?string
    {
        if ($value === null) return null;
        return Str::limit($value, $limit, '...');
    }
}