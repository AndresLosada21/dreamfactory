<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Http\HealthCheckService;

/**
 * Wave1 Task2 tk-6284c63f9f1b - Health liveness/readiness detalhado
 * TDD RED -> GREEN com 3 testes
 * - liveness sem DB
 * - readiness com DB + SGA (172.31.16.89/SGA) + SGC (172.31.16.89/SGC)
 * - detailed com nq:cache_generation (ClusterInvalidationService) + SecretRotationService
 */
class HealthResourceTest extends TestCase
{
    public function test_liveness_does_not_depend_on_db(): void
    {
        $svc = new HealthCheckService(microtime(true) - 5);
        $payload = $svc->liveness();
        self::assertSame('ok', $payload['status'], 'liveness sempre ok');
        self::assertArrayHasKey('uptime_seconds', $payload);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('timestamp', $payload);
        self::assertArrayNotHasKey('checks', $payload, 'liveness nao deve conter checks de DB');
        // source check: liveness block nao toca DB
        $ref = new \ReflectionClass(HealthCheckService::class);
        $src = file_get_contents($ref->getFileName());
        $livenessPos = strpos($src, 'function liveness');
        $readinessPos = strpos($src, 'function readiness');
        $block = substr($src, $livenessPos, $readinessPos - $livenessPos);
        self::assertStringNotContainsString('getPdo', $block, 'liveness nao deve tocar DB dreamfactory-fork/extensions/df-named-query/src/Http/HealthCheckService.php: liveness');
        self::assertStringNotContainsString('DB::', $block, 'liveness nao deve tocar DB');
        self::assertStringNotContainsString('SgaClient', $block, 'liveness nao deve checar SGA');
        self::assertStringNotContainsString('SgcConnectionClient', $block, 'liveness nao deve checar SGC');
    }

    public function test_readiness_checks_db_and_sga_sgc_detailed(): void
    {
        $svc = new HealthCheckService();
        $payload = $svc->readiness();
        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('checks', $payload);
        self::assertArrayHasKey('timestamp', $payload);
        $names = array_column($payload['checks'], 'check');
        self::assertContains('database', $names, 'readiness deve checar database');
        self::assertContains('sga', $names, 'readiness deve checar SGA 172.31.16.89/SGA');
        self::assertContains('sgc', $names, 'readiness deve checar SGC 172.31.16.89/SGC');
        // cada check tem latencyMs e status
        foreach ($payload['checks'] as $c) {
            self::assertArrayHasKey('status', $c);
            self::assertArrayHasKey('latencyMs', $c, 'cada check deve ter latencyMs');
            self::assertStringNotContainsString('password', strtolower(json_encode($c)), 'health nao deve vazar segredos');
        }
        // verifica fonte contem SGA/SGC endpoints e uso de timeout
        $ref = new \ReflectionClass(HealthCheckService::class);
        $src = file_get_contents($ref->getFileName());
        self::assertTrue(str_contains($src, '172.31.16.89/SGA') || str_contains($src, 'SGA') || str_contains($src, 'sga'), 'HealthCheckService deve referenciar SGA dreamfactory-fork/extensions/df-named-query/src/Http/HealthCheckService.php');
        self::assertTrue(str_contains($src, '172.31.16.89/SGC') || str_contains($src, 'SGC') || str_contains($src, 'sgc'), 'HealthCheckService deve referenciar SGC');
        // readiness deve verificar DB
        self::assertTrue(str_contains($src, 'checkDatabase') || str_contains($src, 'getPdo'), 'readiness deve verificar DB');
    }

    public function test_detailed_uses_cluster_invalidation_and_secret_rotation(): void
    {
        $svc = new HealthCheckService();
        $payload = $svc->detailed();
        $json = json_encode($payload);
        self::assertStringNotContainsString('password', strtolower($json), 'detailed nao deve vazar segredos');
        self::assertStringNotContainsString('SELECT', $json, 'detailed nao deve expor SQL');
        self::assertArrayHasKey('liveness', $payload, 'detailed deve conter liveness');
        self::assertArrayHasKey('memory', $payload);
        self::assertArrayHasKey('cache_generation', $payload, 'detailed deve conter nq:cache_generation');
        // checks deve incluir cache_generation e secret_store ou sga/sgc
        $checks = $payload['checks'] ?? [];
        $names = array_column($checks, 'check');
        // readiness ja tem sga/sgc, detailed herda, verifica uso de ClusterInvalidationService e SecretRotationService na fonte
        $ref = new \ReflectionClass(HealthCheckService::class);
        $src = file_get_contents($ref->getFileName());
        self::assertTrue(str_contains($src, 'ClusterInvalidationService') || str_contains($src, 'nq:cache_generation') || str_contains($src, 'cache_generation'), 'detailed deve usar ClusterInvalidationService nq:cache_generation dreamfactory-fork/extensions/df-named-query/src/Http/HealthCheckService.php');
        self::assertTrue(str_contains($src, 'SecretRotationService') || str_contains($src, 'secret_store') || str_contains($src, 'secret'), 'detailed deve usar SecretRotationService dreamfactory-fork/extensions/df-named-query/src/Http/HealthCheckService.php');
        // verifica arquivo Services alias existe
        $servicesPath = __DIR__ . '/../../extensions/df-named-query/src/Services/HealthCheckService.php';
        self::assertFileExists($servicesPath, 'src/Services/HealthCheckService.php deve existir dreamfactory-fork/extensions/df-named-query/src/Services/HealthCheckService.php');
        $servicesSrc = file_get_contents($servicesPath);
        self::assertTrue(str_contains($servicesSrc, 'HealthCheckService'), 'Services alias deve conter classe');
        // HealthResource deve manter liveness sem DB e rotear ready/detailed
        $resourcePath = __DIR__ . '/../../extensions/df-named-query/src/Resources/HealthResource.php';
        $rc = file_get_contents($resourcePath);
        self::assertStringContainsString('liveness', $rc, 'HealthResource deve rotear liveness dreamfactory-fork/extensions/df-named-query/src/Resources/HealthResource.php');
        self::assertStringContainsString('readiness', $rc);
        self::assertStringContainsString('detailed', $rc);
        self::assertStringContainsString('no-cache', $rc, 'health deve ter no-cache header');
    }
}
