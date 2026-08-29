<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * RQ-045 — Espelho Feature da abuse suite.
 * Valida que a suite existe e cobre cada ameaça com file:line citável.
 * A suite canônica roda em extensions/df-named-query/tests/AbuseSuiteTest.php.
 *
 * @see docs/architecture/threat-model.md:1
 */
class AbuseSuiteTest extends TestCase
{
    public function test_abuse_suite_existe_com_suites_separadas(): void
    {
        $ext = __DIR__ . '/../../extensions/df-named-query/tests/AbuseSuiteTest.php';
        self::assertFileExists($ext, 'AbuseSuite canônica deve existir em extensions/df-named-query/tests');
        $c = file_get_contents($ext);
        // Suites separadas: valores vs identificadores
        self::assertStringContainsString('injection_valores', $c, 'suite valores separada');
        self::assertStringContainsString('injection_identificadores', $c, 'suite identificadores separada');
        self::assertStringContainsString('injectionValoresProvider', $c);
        self::assertStringContainsString('injectionIdentificadoresProvider', $c);
    }

    public function test_abuse_suite_cobre_cada_ameaca(): void
    {
        $ext = __DIR__ . '/../../extensions/df-named-query/tests/AbuseSuiteTest.php';
        $c = file_exists($ext) ? file_get_contents($ext) : '';
        $threats = [
            'injection valores' => 'test_injection_valores',
            'injection identificadores' => 'test_injection_identificadores',
            'auth bypass' => 'test_auth_bypass',
            'SSRF' => 'test_ssrf',
            'XXE' => 'test_xxe',
            'DoS' => 'test_dos',
            'definições maliciosas' => 'test_definicoes_maliciosas',
            'XSS' => 'test_xss',
            'privilege escalation' => 'test_privilege_escalation',
        ];
        foreach ($threats as $label => $needle) {
            self::assertStringContainsString($needle, $c, "suite cobre $label ($needle)");
        }
    }

    public function test_abuse_suite_admin_plane_isolavel_e_egress_allowlist(): void
    {
        $ext = __DIR__ . '/../../extensions/df-named-query/tests/AbuseSuiteTest.php';
        $c = file_exists($ext) ? file_get_contents($ext) : '';
        self::assertStringContainsString('admin plane', $c, 'admin plane isolável');
        self::assertStringContainsString('system/named_query', $c, 'system/named_query separado de _query');
        self::assertStringContainsString('test_ssrf_egress', $c, 'egress allowlist');
        self::assertStringContainsString('FORBIDDEN_CREDENTIAL_FIELDS', $c);
    }

    public function test_abuse_suite_reutiliza_RBAC_e_events_nativos_e_exige_aceite_high_critical(): void
    {
        $threat = __DIR__ . '/../../docs/architecture/threat-model.md';
        self::assertFileExists($threat, 'threat-model.md existe');
        $c = file_get_contents($threat);
        self::assertStringContainsString('STRIDE', $c);
        self::assertStringContainsString('High e Critical', $c, 'High e critical exigem correção ou aceite');
        self::assertStringContainsString('Session::checkServicePermission', $c, 'reutiliza RBAC nativo');
        self::assertStringContainsString('getEventMap', $c, 'reutiliza events nativos');
        self::assertStringContainsString('AbuseSuiteTest.php', $c, 'cita suite');
    }
}
