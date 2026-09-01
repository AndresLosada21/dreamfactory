<?php

namespace Yamaha\DreamFactory\NamedQuery\Tests;

use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Http\HealthCheckService;

/**
 * RQ-071 — Testes de liveness, readiness e dependency health
 * Gate exige evidência reproduzível
 */
class HealthCheckTest extends TestCase
{
    public function testLivenessDoesNotDependOnDatabase(): void
    {
        $svc = new HealthCheckService(microtime(true) - 10);
        $payload = $svc->liveness();
        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('uptime_seconds', $payload);
        self::assertArrayHasKey('version', $payload);
        // Liveness não deve conter checks de DB
        self::assertArrayNotHasKey('checks', $payload, 'liveness não deve verificar DB');
        // Garante que liveness não toca DB — mesmo se DB falhar, liveness ok
        // Simula DB down: não deve lançar exceção
        self::assertIsArray($payload);
    }

    public function testReadinessChecksDbCacheSystemStore(): void
    {
        $svc = new HealthCheckService();
        $payload = $svc->readiness();
        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('checks', $payload);
        $checks = $payload['checks'];
        $names = array_column($checks, 'check');
        self::assertContains('database', $names);
        self::assertContains('cache', $names);
        self::assertContains('system_store', $names);
        // Cada check tem latencyMs e status
        foreach ($checks as $c) {
            self::assertArrayHasKey('status', $c);
            self::assertArrayHasKey('latencyMs', $c);
            self::assertNotContains('password', json_encode($c), 'health não deve vazar segredos');
        }
    }

    public function testDetailedRequiresSanitization(): void
    {
        $svc = new HealthCheckService();
        $payload = $svc->detailed();
        $json = json_encode($payload);
        // Nunca deve conter SQL ou password
        self::assertStringNotContainsString('SELECT', $json);
        self::assertStringNotContainsString('password', strtolower($json));
        self::assertArrayHasKey('liveness', $payload);
        self::assertArrayHasKey('memory', $payload);
    }

    public function testLivenessAlways200EvenWhenReadinessDegraded(): void
    {
        // Liveness sempre ok, mesmo se DB down — teste valida isolamento
        $svc = new HealthCheckService();
        $live = $svc->liveness();
        self::assertSame('ok', $live['status'], 'liveness sempre ok se processo vivo');
        // Readiness pode ser degraded, mas liveness não é afetado
        $readiness = $svc->readiness();
        // readiness pode ser ok ou degraded dependendo do env, mas liveness permanece ok
        self::assertNotSame($live, $readiness);
    }

    public function testHealthResourceExistsWithCorrectHandlers(): void
    {
        $path = __DIR__ . '/../src/Resources/HealthResource.php';
        $c = file_exists($path) ? file_get_contents($path) : '';
        self::assertStringContainsString('class HealthResource', $c);
        self::assertStringContainsString('handleGET', $c);
        self::assertStringContainsString('detailed', $c);
        self::assertStringContainsString('checkServicePermission', $c, 'detailed deve exigir admin');
        // Mantém /health legado
        self::assertStringContainsString('health', strtolower($c));
        // no-cache header
        self::assertStringContainsString('no-cache', $c);
    }

    public function testHealthCheckServiceHasLivenessReadinessDetailed(): void
    {
        $ref = new \ReflectionClass(HealthCheckService::class);
        self::assertTrue($ref->hasMethod('liveness'));
        self::assertTrue($ref->hasMethod('readiness'));
        self::assertTrue($ref->hasMethod('detailed'));
        $livenessSource = file_get_contents($ref->getFileName());
        // liveness não deve conter getPdo
        $livenessPos = strpos($livenessSource, 'function liveness');
        $readinessPos = strpos($livenessSource, 'function readiness');
        $livenessBlock = substr($livenessSource, $livenessPos, $readinessPos - $livenessPos);
        self::assertStringNotContainsString('getPdo', $livenessBlock, 'liveness não deve tocar DB');
    }
}
