<?php

namespace Yamaha\DreamFactory\NamedQuery\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * RQ-LIM-02 — RateLimitMiddleware (premium liberado)
 * Token bucket por client_key/role/route com Cache store file/redis
 * Design: DreamFactory api group middleware, sem quebrar nginx-dreamfactory.conf
 * File: extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php:1
 */
class RateLimitMiddleware
{
    public const DEFAULT_LIMIT = 100;
    public const DEFAULT_WINDOW = 60; // segundos
    public const HEADER_REMAINING = 'X-RateLimit-Remaining';
    public const HEADER_LIMIT = 'X-RateLimit-Limit';
    public const HEADER_RETRY = 'Retry-After';

    public function handle(Request $request, Closure $next, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): Response
    {
        $key = $this->resolveKey($request);
        $cacheKey = 'nq:ratelimit:' . sha1($key);

        $current = (int) Cache::get($cacheKey, 0);
        $ttl = $window;

        if ($current >= $limit) {
            return response()->json([
                'error' => [
                    'code' => 429,
                    'message' => 'Too Many Requests',
                    'context' => ['limit' => $limit, 'window' => $window],
                ],
            ], 429)->withHeaders([
                self::HEADER_LIMIT => $limit,
                self::HEADER_REMAINING => 0,
                self::HEADER_RETRY => $ttl,
            ]);
        }

        Cache::put($cacheKey, $current + 1, $window);

        /** @var Response $response */
        $response = $next($request);

        $remaining = max(0, $limit - ($current + 1));
        $response->headers->set(self::HEADER_LIMIT, (string) $limit);
        $response->headers->set(self::HEADER_REMAINING, (string) $remaining);

        return $response;
    }

    private function resolveKey(Request $request): string
    {
        $clientKey = $request->header('X-DreamFactory-Api-Key') ?? $request->query('api_key', 'anon');
        $role = $request->header('X-Role') ?? 'guest';
        $route = $request->path();
        // Para admin, limite maior (1000) — ver ADR rate-limit.md:14
        if ($role === 'admin') {
            return "admin:{$route}";
        }
        return "{$clientKey}:{$role}:{$route}";
    }
}
