<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AdminAuditLogger
{
    public function logRequest(Request $request, array $meta = []): ?AuditLog
    {
        if (!$this->shouldLogRequest($request)) {
            return null;
        }

        $actor = $request->user();
        if (!$actor) {
            return null;
        }

        $action = $meta['action'] ?? $this->inferActionFromRoute($request);
        $entity = $meta['entity'] ?? $this->inferEntityFromRoute($request);
        $entityId = $meta['entity_id'] ?? $this->inferEntityIdFromRoute($request);

        $payload = array_merge([
            'scope' => 'admin_request',
            'module' => $meta['module'] ?? $this->inferModuleFromRoute($request),
            'status' => $meta['status'] ?? 'success',
            'summary' => $meta['summary'] ?? $this->buildRequestSummary($request),
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => optional($request->route())->uri(),
                'full_url' => $this->limitString($request->fullUrl(), 1000),
                'payload' => $this->sanitize($request->except([
                    'password',
                    'password_confirmation',
                    'token',
                    'access_token',
                    'refresh_token',
                    'license_key',
                    'license',
                    'secret',
                    'server_key',
                    'private_key',
                    'raw_payload',
                ])),
            ],
            'context' => [
                'ip' => $request->ip(),
                'user_agent' => $this->limitString($request->userAgent(), 500),
            ],
        ], Arr::except($meta, ['action', 'entity', 'entity_id']));

        return AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => is_numeric($entityId) ? (int) $entityId : null,
            'meta' => $this->sanitize($payload),
        ]);
    }

    public function logModelEvent(string $event, Model $model, array $before = [], array $after = [], array $extra = []): ?AuditLog
    {
        if (!$this->shouldLogCurrentRequest()) {
            return null;
        }

        $request = request();
        $actor = $request->user();
        if (!$actor) {
            return null;
        }

        $descriptor = $this->descriptorFor($model);
        if (!$descriptor) {
            return null;
        }

        $action = $extra['action'] ?? ($descriptor['action_prefix'] . '.' . $event);
        $entity = $extra['entity'] ?? $descriptor['entity'];
        $summary = $extra['summary'] ?? $this->summaryForEvent($event, $descriptor['label']);

        $meta = array_merge([
            'scope' => 'admin_model',
            'module' => $descriptor['module'],
            'status' => 'success',
            'summary' => $summary,
            'target' => $this->targetSummary($model, $descriptor),
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
            'changes' => $this->sanitize($this->diffForAudit($before, $after)),
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => optional($request->route())->uri(),
            ],
            'context' => [
                'ip' => $request->ip(),
                'user_agent' => $this->limitString($request->userAgent(), 500),
            ],
        ], Arr::except($extra, ['action', 'entity', 'summary']));

        return AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => (int) $model->getKey(),
            'meta' => $meta,
        ]);
    }

    public function snapshot(Model $model, bool $original = false): array
    {
        $data = $original
            ? $model->getRawOriginal()
            : $model->getAttributes();

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $trim = trim($value);

                if ($trim !== '' && (
                    str_starts_with($trim, '{') ||
                    str_starts_with($trim, '[')
                )) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $data[$key] = $decoded;
                    }
                }
            }
        }

        return $this->sanitize($data);
    }

    public function shouldLogCurrentRequest(): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        /** @var Request|null $request */
        $request = request();
        return $request ? $this->shouldLogRequest($request) : false;
    }

    public function shouldLogRequest(Request $request): bool
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = trim($request->path(), '/');
        if (!Str::startsWith($path, 'api/v1/admin')) {
            return false;
        }

        $user = $request->user();
        return (bool) ($user && method_exists($user, 'isAdmin') && $user->isAdmin());
    }

    private function descriptorFor(Model $model): ?array
    {
        return match ($model::class) {
            \App\Models\Category::class => ['entity' => 'categories', 'module' => 'catalog', 'label' => 'category', 'action_prefix' => 'category', 'identity' => ['id', 'name', 'slug', 'is_active']],
            \App\Models\SubCategory::class => ['entity' => 'subcategories', 'module' => 'catalog', 'label' => 'subcategory', 'action_prefix' => 'subcategory', 'identity' => ['id', 'category_id', 'name', 'slug', 'provider', 'is_active']],
            \App\Models\Product::class => ['entity' => 'products', 'module' => 'catalog', 'label' => 'product', 'action_prefix' => 'product', 'identity' => ['id', 'category_id', 'subcategory_id', 'name', 'slug', 'type', 'is_active', 'is_published']],
            \App\Models\License::class => ['entity' => 'licenses', 'module' => 'catalog', 'label' => 'license', 'action_prefix' => 'license', 'identity' => ['id', 'product_id', 'status', 'taken_by', 'reserved_order_id']],
            \App\Models\Order::class => ['entity' => 'orders', 'module' => 'orders', 'label' => 'order', 'action_prefix' => 'order', 'identity' => ['id', 'invoice_number', 'user_id', 'status', 'amount']],
            \App\Models\Delivery::class => ['entity' => 'deliveries', 'module' => 'orders', 'label' => 'delivery', 'action_prefix' => 'delivery', 'identity' => ['id', 'order_id', 'license_id', 'delivery_mode', 'revealed_at', 'emailed_at']],
            \App\Models\Voucher::class => ['entity' => 'vouchers', 'module' => 'marketing', 'label' => 'voucher', 'action_prefix' => 'voucher', 'identity' => ['id', 'code', 'type', 'value', 'quota', 'is_active']],
            \App\Models\DiscountCampaign::class => ['entity' => 'discount_campaigns', 'module' => 'marketing', 'label' => 'discount campaign', 'action_prefix' => 'discount_campaign', 'identity' => ['id', 'name', 'slug', 'enabled', 'discount_type', 'discount_value', 'priority']],
            \App\Models\DiscountCampaignTarget::class => ['entity' => 'discount_campaign_targets', 'module' => 'marketing', 'label' => 'discount target', 'action_prefix' => 'discount_target', 'identity' => ['id', 'campaign_id', 'target_type', 'target_id']],
            \App\Models\ReferralSetting::class => ['entity' => 'referral_settings', 'module' => 'marketing', 'label' => 'referral setting', 'action_prefix' => 'referral_setting', 'identity' => ['id', 'enabled', 'campaign_name', 'starts_at', 'ends_at']],
            \App\Models\Banner::class => ['entity' => 'banners', 'module' => 'content', 'label' => 'banner', 'action_prefix' => 'banner', 'identity' => ['id', 'image_path', 'sort_order', 'is_active']],
            \App\Models\Popup::class => ['entity' => 'popups', 'module' => 'content', 'label' => 'popup', 'action_prefix' => 'popup', 'identity' => ['id', 'title', 'target', 'is_active']],
            \App\Models\Page::class => ['entity' => 'pages', 'module' => 'content', 'label' => 'page', 'action_prefix' => 'page', 'identity' => ['id', 'slug', 'title', 'is_published']],
            \App\Models\Faq::class => ['entity' => 'faqs', 'module' => 'content', 'label' => 'faq', 'action_prefix' => 'faq', 'identity' => ['id', 'question', 'sort_order', 'is_active']],
            \App\Models\Setting::class => ['entity' => 'site_settings', 'module' => 'content', 'label' => 'site setting', 'action_prefix' => 'site_setting', 'identity' => ['id', 'group', 'key', 'is_public']],
            \App\Models\PaymentGateway::class => ['entity' => 'payment_gateways', 'module' => 'settings', 'label' => 'payment gateway', 'action_prefix' => 'payment_gateway', 'identity' => ['id', 'code', 'name', 'is_active']],
            \App\Models\WalletTopup::class => ['entity' => 'wallet_topups', 'module' => 'finance', 'label' => 'wallet topup', 'action_prefix' => 'wallet_topup', 'identity' => ['id', 'user_id', 'order_id', 'amount', 'status']],
            \App\Models\WithdrawRequest::class => ['entity' => 'withdraw_requests', 'module' => 'finance', 'label' => 'withdraw request', 'action_prefix' => 'withdraw', 'identity' => ['id', 'user_id', 'amount', 'status', 'processed_at']],
            \App\Models\AdminRole::class => ['entity' => 'admin_roles', 'module' => 'rbac', 'label' => 'admin role', 'action_prefix' => 'admin_role', 'identity' => ['id', 'name', 'slug', 'is_super', 'is_system']],
            \App\Models\AdminPermission::class => ['entity' => 'admin_permissions', 'module' => 'rbac', 'label' => 'admin permission', 'action_prefix' => 'admin_permission', 'identity' => ['id', 'key', 'label', 'group', 'is_protected']],
            \App\Models\User::class => ['entity' => 'users', 'module' => 'users', 'label' => 'user', 'action_prefix' => 'user', 'identity' => ['id', 'name', 'full_name', 'email', 'role', 'tier', 'admin_role_id']],
            default => null,
        };
    }

    private function inferActionFromRoute(Request $request): string
    {
        $routeUri = (string) optional($request->route())->uri();
        $routeUri = str_replace('v1/admin/', '', $routeUri);
        $routeUri = trim($routeUri, '/');

        $segments = array_values(array_filter(explode('/', $routeUri), fn ($s) => $s !== ''));
        $segments = array_map(function ($seg) {
            return preg_match('/^\{.*\}$/', $seg) ? null : str_replace('-', '_', $seg);
        }, $segments);
        $segments = array_values(array_filter($segments));

        $method = strtoupper($request->method());

        if (count($segments) >= 2 && !in_array(end($segments), ['sign'], true)) {
            $last = end($segments);
            if (!in_array($last, ['categories', 'subcategories', 'users', 'products', 'licenses', 'orders', 'payment_gateways', 'wallet', 'referrals', 'withdraws', 'vouchers', 'settings', 'banners', 'popups', 'pages', 'faqs', 'discount_campaigns', 'uploads', 'admin_users', 'admin_roles'], true)) {
                $base = prev($segments) ?: $segments[0];
                return Str::singular($base) . '.' . $last;
            }
        }

        $base = $segments[0] ?? 'admin';
        $base = Str::singular($base);

        return match ($method) {
            'POST' => $base . '.create',
            'PUT', 'PATCH' => $base . '.update',
            'DELETE' => $base . '.delete',
            default => $base . '.action',
        };
    }

    private function inferEntityFromRoute(Request $request): string
    {
        $routeUri = (string) optional($request->route())->uri();
        $routeUri = str_replace('v1/admin/', '', $routeUri);
        $segments = array_values(array_filter(explode('/', trim($routeUri, '/'))));
        $base = $segments[0] ?? 'admin';

        return str_replace('-', '_', $base);
    }

    private function inferEntityIdFromRoute(Request $request): int|string|null
    {
        foreach ((array) optional($request->route())->parameters() as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function inferModuleFromRoute(Request $request): string
    {
        $entity = $this->inferEntityFromRoute($request);

        return match ($entity) {
            'categories', 'subcategories', 'products', 'licenses', 'stock' => 'catalog',
            'orders', 'deliveries' => 'orders',
            'wallet', 'withdraws' => 'finance',
            'payment_gateways' => 'settings',
            'referrals', 'referral_settings', 'vouchers', 'discount_campaigns' => 'marketing',
            'settings', 'banners', 'popups', 'pages', 'faqs', 'uploads' => 'content',
            'admin_users', 'admin_roles', 'permissions' => 'rbac',
            default => 'admin',
        };
    }

    private function buildRequestSummary(Request $request): string
    {
        $method = strtoupper($request->method());
        $action = str_replace('.', ' ', $this->inferActionFromRoute($request));
        return trim($method . ' ' . $action);
    }

    private function targetSummary(Model $model, array $descriptor): array
    {
        $data = $model->attributesToArray();
        return $this->sanitize(Arr::only($data, $descriptor['identity'] ?? ['id']));
    }

    private function summaryForEvent(string $event, string $label): string
    {
        return match ($event) {
            'create' => 'Create ' . $label,
            'update' => 'Update ' . $label,
            'delete' => 'Delete ' . $label,
            default => ucfirst($event) . ' ' . $label,
        };
    }

    private function diffForAudit(array $before, array $after): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        $diff = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== $new) {
                $diff[$key] = [
                    'before' => $old,
                    'after' => $new,
                ];
            }
        }

        return $diff;
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
            if ($key && $this->isSensitiveKey($key)) {
                return '[REDACTED]';
            }
            return $this->limitString($value, 4000);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = Str::lower($key);

        $exact = [
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
            'raw_payload',
            'raw_callback',
            'proof_file',
            'snap_token',
        ];

        if (in_array($key, $exact, true)) {
            return true;
        }

        return Str::contains($key, [
            'password',
            'token',
            'secret',
            'signature',
            'credential',
            'license_key',
            'server_key',
            'private_key',
        ]);
    }

    private function limitString(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return Str::limit($value, $limit, '...');
    }
}