<?php

namespace App\Http\Middleware;

use App\Services\AdminAuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminActionAuditMiddleware
{
    public function __construct(private AdminAuditLogger $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!$this->audit->shouldLogRequest($request)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $responseMeta = [
            'status' => $status >= 200 && $status < 400 ? 'success' : 'failed',
            'response_status_code' => $status,
        ];

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $responseMeta['response_error'] = data_get($data, 'error.message');
            $responseMeta['response_success'] = data_get($data, 'success');
        }

        $this->audit->logRequest($request, $responseMeta);

        return $response;
    }
}