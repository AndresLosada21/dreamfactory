<?php

namespace Yamaha\DreamFactory\NamedQuery\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RQ-072 — Propaga request_id em logs e traces via X-Request-ID
 */
class RequestTracingMiddleware
{
    public function handle($request, Closure $next)
    {
        $requestId = $this->resolveRequestId($request);

        // Set context for logs
        try {
            Log::withContext(['request_id' => $requestId]);
        } catch (\Throwable $ignored) {}

        // Ensure request carries request_id for downstream
        try {
            $request->headers->set('X-Request-ID', $requestId);
        } catch (\Throwable $ignored) {}

        if (isset($_SERVER)) {
            $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        }

        $response = $next($request);

        // Propagate to response
        try {
            if (method_exists($response, 'header')) {
                $response->header('X-Request-ID', $requestId);
            } elseif (method_exists($response, 'headers') && $response->headers) {
                $response->headers->set('X-Request-ID', $requestId);
            }
        } catch (\Throwable $ignored) {}

        return $response;
    }

    private function resolveRequestId($request): string
    {
        try {
            if ($request) {
                foreach (['X-Request-ID', 'X-REQUEST-ID', 'x-request-id'] as $header) {
                    $id = $request->header($header);
                    if (!empty($id)) return (string) $id;
                }
                $id = $request->headers->get('X-Request-ID');
                if (!empty($id)) return (string) $id;
            }
        } catch (\Throwable $ignored) {}

        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string) $_SERVER['HTTP_X_REQUEST_ID'];
        }

        try {
            return (string) Str::uuid();
        } catch (\Throwable $e) {
            return uniqid('req_', true);
        }
    }
}
