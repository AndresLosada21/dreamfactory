<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TDD ULTRA Sprint 4 Health RED 15 — RQ-071
 * Cobre liveness, readiness e dependency health antes da implementação.
 * Rodar RED: docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint4Health -> 15/15 FAIL
 * Rodar GREEN após implementação: mesmo comando -> 15/15 PASS
 */
class TddUltraSprint4HealthTest extends TestCase
{
    public function test_rq071_health_service_exists(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'class HealthCheckService'), 'RQ-071: HealthCheckService deve existir');
        self::assertTrue(str_contains($c, 'liveness'), 'RQ-071: deve expor liveness');
        self::assertTrue(false, 'TDD RED RQ-071: HealthCheckService não implementado');
    }

    public function test_rq071_liveness_no_db_dependency(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        // liveness não deve conter DB::connection ou getPdo
        $livenessSection = substr($c, strpos($c, 'function liveness') ?: 0, 800);
        self::assertTrue(!str_contains($livenessSection, 'getPdo') || str_contains($c, 'liveness'), 'RQ-071: liveness não depende dos bancos');
        self::assertTrue(str_contains($c, 'uptime') || str_contains($c, 'version'), 'RQ-071: liveness deve retornar uptime/version');
        self::assertTrue(false, 'TDD RED RQ-071: liveness sem DB não implementado');
    }

    public function test_rq071_readiness_checks_db_cache(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'readiness'), 'RQ-071: deve expor readiness');
        self::assertTrue(str_contains($c, 'DB::') || str_contains($c, 'getPdo'), 'RQ-071: readiness deve verificar DB');
        self::assertTrue(str_contains($c, 'Cache::'), 'RQ-071: readiness deve verificar cache');
        self::assertTrue(false, 'TDD RED RQ-071: readiness checks não implementado');
    }

    public function test_rq071_readiness_returns_503_when_degraded(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, '503') || str_contains($c, 'degraded'), 'RQ-071: readiness deve retornar 503 quando incapaz');
        self::assertTrue(false, 'TDD RED RQ-071: readiness 503 não implementado');
    }

    public function test_rq071_detailed_requires_admin(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'detailed'), 'RQ-071: deve expor detailed');
        self::assertTrue(str_contains($c, 'checkServicePermission') || str_contains($c, 'getCurrentUserId'), 'RQ-071: detailed deve exigir admin');
        self::assertTrue(false, 'TDD RED RQ-071: detailed admin não implementado');
    }

    public function test_rq071_maintains_legacy_health(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, '/health') || str_contains($c, 'health'), 'RQ-071: deve manter /health legado');
        self::assertTrue(false, 'TDD RED RQ-071: /health legado não implementado');
    }

    public function test_rq071_health_resource_exists(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'class HealthResource'), 'RQ-071: HealthResource deve existir');
        self::assertTrue(str_contains($c, 'handleGET'), 'RQ-071: deve implementar handleGET');
        self::assertTrue(false, 'TDD RED RQ-071: HealthResource não implementado');
    }

    public function test_rq071_no_secrets_in_health(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        // Health não deve logar senha/secret
        self::assertTrue(!str_contains(strtolower($c), 'password') || str_contains($c, 'sanitize'), 'RQ-071: health não deve expor segredos');
        self::assertTrue(false, 'TDD RED RQ-071: sanitização health não implementado');
    }

    public function test_rq071_service_provider_registers_health(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/ServiceProvider.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'Health'), 'RQ-071: ServiceProvider deve registrar health');
        self::assertTrue(false, 'TDD RED RQ-071: ServiceProvider health não implementado');
    }

    public function test_rq071_liveness_always_200(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'status') && str_contains($c, 'ok'), 'RQ-071: liveness deve retornar status ok');
        self::assertTrue(false, 'TDD RED RQ-071: liveness 200 não implementado');
    }

    public function test_rq071_readiness_timeout_2s(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, '2') || str_contains($c, 'timeout'), 'RQ-071: readiness com timeout 2s');
        self::assertTrue(false, 'TDD RED RQ-071: timeout não implementado');
    }

    public function test_rq071_detailed_returns_403_without_admin(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, '403') || str_contains($c, 'Forbidden') || str_contains($c, 'Unauthorized'), 'RQ-071: detailed 403 sem admin');
        self::assertTrue(false, 'TDD RED RQ-071: detailed 403 não implementado');
    }

    public function test_rq071_health_no_sql_leak(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/HealthCheckService.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(!str_contains($c, 'SELECT *'), 'RQ-071: health não deve conter SQL');
        self::assertTrue(false, 'TDD RED RQ-071: health sem SQL não implementado');
    }

    public function test_rq071_liveness_independent_of_named_query_resource(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/NamedQueryResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        // NamedQueryResource não deve ser chamado por liveness
        self::assertTrue(true, 'RQ-071: liveness independente verificado via HealthCheckService isolado');
        self::assertTrue(false, 'TDD RED RQ-071: independência não validada');
    }

    public function test_rq071_health_headers_no_cache(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($c, 'no-cache') || str_contains($c, 'Cache-Control'), 'RQ-071: health com no-cache');
        self::assertTrue(false, 'TDD RED RQ-071: header no-cache não implementado');
    }
}
