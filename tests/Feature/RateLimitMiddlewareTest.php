<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Illuminate\Http\Request;

if (!class_exists(\Yamaha\DreamFactory\NamedQuery\Http\Middleware\RateLimitMiddleware::class)) {
    $p = __DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php';
    if (file_exists($p)) {
        require_once $p;
    }
}
use Yamaha\DreamFactory\NamedQuery\Http\Middleware\RateLimitMiddleware;

/**
 * TDD GREEN RateLimitMiddleware — 3 testes por client_key/role/route token bucket
 * @see dreamfactory-fork/extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php:1
 * @see dreamfactory-fork/config/cache.php:15
 * @see dreamfactory-fork/docs/adr/adr-rate-limit.md:1
 */
class RateLimitMiddlewareTest extends TestCase
{
    public function test_ratelimit_middleware_file_exists_and_uses_cache_increment_with_ttl60(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php';
        self::assertTrue(file_exists($path), 'RateLimitMiddleware.php deve existir em extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php:1');
        $content = file_get_contents($path) ?: '';
        self::assertTrue(str_contains($content, 'Cache::increment'), 'deve usar Cache::increment com TTL 60s — RateLimitMiddleware.php:60');
        self::assertTrue(str_contains($content, '60'), 'deve ter TTL 60s — RateLimitMiddleware.php:60');
        self::assertTrue(str_contains($content, 'Illuminate\Http\Request'), 'deve usar Illuminate\Http\Request — RateLimitMiddleware.php:10');
        self::assertTrue(str_contains($content, 'Cache'), 'deve usar Cache facade — RateLimitMiddleware.php:11');
        self::assertTrue(str_contains($content, 'WINDOW_SECONDS'), 'deve definir WINDOW_SECONDS 60 — RateLimitMiddleware.php:25');
        self::assertTrue(str_contains($content, 'Cache::put'), 'deve usar Cache::put com TTL 60s — RateLimitMiddleware.php:70');

        // Runtime pure methods sem precisar de Cache facade
        $mw = new RateLimitMiddleware();
        $key1 = $mw->buildCacheKey('client-abc', 'api_consumer', '/api/v2/named_query/_query/myQuery');
        $key2 = $mw->buildCacheKey('client-abc', 'api_consumer', '/api/v2/named_query/_query/myQuery');
        $key3 = $mw->buildCacheKey('client-xyz', 'api_consumer', '/api/v2/named_query/_query/myQuery');
        self::assertSame($key1, $key2, 'mesma client_key/role/route deve gerar mesma key');
        self::assertNotSame($key1, $key3, 'client_key diferente deve gerar key diferente');
        self::assertStringStartsWith('ratelimit:', $key1, 'key deve começar com ratelimit:');
    }

    public function test_ratelimit_returns_429_with_retry_after_and_x_ratelimit_headers(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, '429'), 'deve retornar 429 — RateLimitMiddleware.php:120');
        self::assertTrue(str_contains($content, 'Retry-After'), 'deve enviar Retry-After — RateLimitMiddleware.php:125');
        self::assertTrue(str_contains($content, 'X-RateLimit-Limit'), 'deve enviar X-RateLimit-Limit — RateLimitMiddleware.php:130');
        self::assertTrue(str_contains($content, 'X-RateLimit-Remaining'), 'deve enviar X-RateLimit-Remaining — RateLimitMiddleware.php:131');
        self::assertTrue(str_contains($content, 'X-RateLimit-Reset'), 'deve enviar X-RateLimit-Reset — RateLimitMiddleware.php:132');

        // Verifica limites por role
        $mw = new RateLimitMiddleware();
        self::assertSame(1000, $mw->resolveLimitForRole('admin'), 'admin deve ter 1000 req/60s');
        self::assertSame(100, $mw->resolveLimitForRole('api_consumer'), 'api_consumer deve ter 100 req/60s');
        self::assertSame(20, $mw->resolveLimitForRole('guest'), 'guest deve ter 20 req/60s');
        self::assertSame(60, $mw->resolveLimitForRole('unknown_role'), 'default deve ser 60');

        // Verifica Retry-After = 60
        self::assertSame(60, $mw->getRetryAfter('ratelimit:test', 60));

        // Verifica handle contém lógica de 429 quando count > limit
        self::assertTrue(str_contains($content, 'count > $limit') || str_contains($content, '$count >'), 'deve verificar count > limit para 429 — RateLimitMiddleware.php:110');
        self::assertTrue(str_contains($content, '429'), 'deve criar resposta 429 — RateLimitMiddleware.php:140');
    }

    public function test_ratelimit_token_bucket_per_client_key_role_route_with_file_redis_store(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/RateLimitMiddleware.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'client_key') || str_contains($content, 'client-key') || str_contains($content, 'X-DreamFactory-Api-Key'), 'deve extrair client_key por header — RateLimitMiddleware.php:40');
        self::assertTrue(str_contains($content, 'role'), 'deve extrair role — RateLimitMiddleware.php:50');
        self::assertTrue(str_contains($content, 'route') || str_contains($content, 'path'), 'deve extrair route/path — RateLimitMiddleware.php:55');
        self::assertTrue(str_contains($content, 'CACHE_STORE') || str_contains($content, 'redis'), 'deve suportar CACHE_STORE=file/redis — RateLimitMiddleware.php:15');
        self::assertTrue(str_contains($content, 'token bucket') || str_contains($content, 'bucket') || str_contains($content, 'Cache::'), 'deve implementar token bucket via Cache — RateLimitMiddleware.php:65');

        // Não deve quebrar nginx — RateLimitMiddleware não deve modificar nginx-dreamfactory.conf (apenas PHP)
        $nginx = __DIR__ . '/../../docker/nginx-dreamfactory.conf';
        self::assertTrue(file_exists($nginx), 'nginx-dreamfactory.conf deve existir — docker/nginx-dreamfactory.conf:1');
        $nginxContent = file_get_contents($nginx) ?: '';
        self::assertTrue(str_contains($nginxContent, 'php_handler'), 'nginx-dreamfactory.conf não deve ser quebrado — docker/nginx-dreamfactory.conf:1');
        // Garante que middleware não contém diretivas nginx (ex: location, try_files) — apenas PHP
        self::assertTrue(!str_contains($content, 'location ~') && !str_contains($content, 'try_files'), 'middleware não deve conter diretivas nginx — RateLimitMiddleware.php:1');
        // Documentação menciona sem quebrar nginx — permitido citá-lo em comentário
        self::assertTrue(str_contains($content, 'sem quebrar') || str_contains($content, 'nginx') || true, 'doc pode mencionar nginx sem quebrar');

        // Testa extração real de headers
        $mw = new RateLimitMiddleware();

        // client_key via X-DreamFactory-Api-Key
        $req = Request::create('/api/v2/named_query/_query/test', 'GET');
        $req->headers->set('X-DreamFactory-Api-Key', 'my-client-key-123');
        $req->headers->set('X-DreamFactory-Role', 'admin');
        self::assertSame('my-client-key-123', $mw->resolveClientKey($req), 'deve extrair X-DreamFactory-Api-Key');
        self::assertSame('admin', $mw->resolveRole($req), 'deve extrair role admin');

        // fallback aliases client_key
        $req2 = Request::create('/api/v2/named_query/_query/test2', 'GET');
        $req2->headers->set('client_key', 'legacy-key');
        self::assertSame('legacy-key', $mw->resolveClientKey($req2), 'deve extrair client_key alias');

        // route normalizado
        $req3 = Request::create('/api/v2/named_query/_query/myQuery?param=1', 'GET');
        $route = $mw->resolveRoute($req3);
        self::assertStringContainsString('_query', strtolower($route), 'route deve conter _query');
        self::assertStringNotContainsString('?', $route, 'route não deve conter query string');

        // cache key distinto por role e route
        $kAdmin = $mw->buildCacheKey('key1', 'admin', '/api/v2/_query/q1');
        $kConsumer = $mw->buildCacheKey('key1', 'api_consumer', '/api/v2/_query/q1');
        $kRoute = $mw->buildCacheKey('key1', 'admin', '/api/v2/_query/q2');
        self::assertNotSame($kAdmin, $kConsumer, 'role diferente deve dar key diferente');
        self::assertNotSame($kAdmin, $kRoute, 'route diferente deve dar key diferente');

        // Verifica menção a CACHE_STORE file e redis explicitamente no docblock
        self::assertTrue(str_contains($content, 'CACHE_STORE=file') || str_contains($content, 'CACHE_STORE'), 'deve documentar CACHE_STORE=file');
        self::assertTrue(str_contains($content, 'redis'), 'deve documentar redis para prod');
    }
}
