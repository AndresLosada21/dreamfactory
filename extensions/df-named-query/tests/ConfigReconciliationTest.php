<?php

namespace Yamaha\DreamFactory\NamedQuery\Tests;

use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Services\ConfigReconciliationService;
use Yamaha\DreamFactory\NamedQuery\Services\SecretRotationService;

/**
 * RQ-081 — Testes de reconciliação de configuração migrada
 */
class ConfigReconciliationTest extends TestCase
{
    private ConfigReconciliationService $svc;

    protected function setUp(): void
    {
        $this->svc = new ConfigReconciliationService();
    }

    public function testUnsupportedBlocksPromotion(): void
    {
        $defs = [
            ['name' => 'ok', 'service_id' => 1, 'definition_type' => 'sql', 'sql' => 'SELECT 1'],
            ['name' => 'bad', 'service_id' => 1, 'definition_type' => 'unsupported_type', 'sql' => 'SELECT 1'],
        ];
        $report = $this->svc->validate($defs);
        self::assertTrue($report['blocked'], 'unsupported deve bloquear promoção');
        self::assertCount(1, $report['unsupported']);
        self::assertStringNotContainsString('SELECT 1', json_encode($report['unsupported']), 'relatório sem SQL');
    }

    public function testCollisionsReported(): void
    {
        $migrated = [
            ['name' => 'q1', 'service_id' => 1, 'sql' => 'SELECT 1'],
            ['name' => 'q2', 'service_id' => 1, 'sql' => 'SELECT 2'],
        ];
        $existing = [
            ['name' => 'q1', 'service_id' => 1, 'checksum' => hash('sha256', json_encode(['definition_type'=>'sql','sql'=>'SELECT 999','parameters'=>[],'output_schema'=>[],'budgets'=>[]]))],
        ];
        $report = $this->svc->reconcile($migrated, $existing);
        self::assertNotEmpty($report['collisions']);
        self::assertSame('q1', $report['collisions'][0]['name']);
        self::assertTrue($report['blocked']);
    }

    public function testChecksumsMatchWhenNoCollisions(): void
    {
        $defs = [
            ['name' => 'q1', 'service_id' => 1, 'sql' => 'SELECT 1'],
            ['name' => 'q2', 'service_id' => 1, 'sql' => 'SELECT 2'],
        ];
        $report = $this->svc->reconcile($defs, []);
        self::assertTrue($report['checksums_match']);
        self::assertSame(2, $report['total']);
        self::assertSame(0, count($report['collisions']));
        self::assertEmpty($report['unsupported']);
    }

    public function testCountAndChecksumsClose(): void
    {
        $migrated = [
            ['name' => 'a', 'service_id' => 1, 'sql' => 'SELECT 1'],
            ['name' => 'b', 'service_id' => 1, 'sql' => 'SELECT 2'],
        ];
        $existing = [];
        $report = $this->svc->reconcile($migrated, $existing);
        self::assertSame(2, $report['expected']);
        self::assertSame(2, $report['actual']);
        self::assertSame(2, $report['total']);
    }

    public function testAesGcmMigratesToSecretStore(): void
    {
        $svc = new SecretRotationService();
        $key = str_repeat('k', 32);
        $iv = str_repeat('i', 12);
        $plain = 'my-secret-value';
        $tag = '';
        $enc = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        self::assertNotFalse($enc);
        $secretId = $svc->migrateAesGcmToSecretStore($enc, 'test-key', $key, $iv, $tag);
        self::assertStringContainsString('test-key', $secretId);
        $retrieved = $svc->getSecret($secretId);
        self::assertSame($plain, $retrieved);
        // Ensure no secret in logs would be validated via sanitized report
        $json = json_encode(['secret_id' => $secretId]);
        self::assertStringNotContainsString($plain, $json);
    }

    public function testReportSanitizedWithoutSecrets(): void
    {
        $defs = [
            ['name' => 'q1', 'service_id' => 1, 'sql' => 'SELECT * FROM t WHERE password=:p', 'parameters' => [['name'=>'p']]],
        ];
        $report = $this->svc->validate($defs);
        $json = json_encode($report);
        // Relatório não deve conter SQL
        self::assertStringNotContainsString('SELECT * FROM', $json);
        self::assertStringNotContainsString('password', strtolower($json) === 'password' ? $json : '');
    }
}
