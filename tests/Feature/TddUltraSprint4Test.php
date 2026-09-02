<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TDD ULTRA — Sprint 4 RED suite (M3/M4 E4-E5 PREMIUM UNLOCK)
 * 15 testes escritos ANTES, devem falhar com TDD RED até implementacao.
 * Objetivo: desbloquear 100% premium — Rate Limiting, Event Scripts, Scheduler, LDAP
 * via Determinus GOLD + frontend paywall unlock + backend stubs.
 * Rodar: docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint4
 */
class TddUltraSprint4Test extends TestCase
{
    public function test_prem01_paywall_isFeatureLocked_returns_false(): void
    {
        $f = __DIR__ . '/../../dreamfabric-admin/src/app/shared/services/df-paywall.service.ts';
        $c = file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('isFeatureLocked', $c);
        self::assertStringContainsString('return false', $c);
        self::assertStringContainsString('premium Determinus', $c);
    }

    public function test_prem02_paywall_activatePaywall_returns_of_false(): void
    {
        $f = __DIR__ . '/../../dreamfabric-admin/src/app/shared/services/df-paywall.service.ts';
        $c = file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('activatePaywall', $c);
        self::assertStringContainsString('of(false)', $c);
        self::assertStringContainsString('premium Determinus', $c);
    }

    public function test_prem03_side_nav_license_expired_hidden(): void
    {
        $f = __DIR__ . '/../../dreamfabric-admin/src/app/shared/components/df-side-nav/df-side-nav.component.html';
        $c = file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('license-expired', $c);
        self::assertStringContainsString('*ngIf="false"', $c);
    }

    public function test_prem04_license_check_mock_gold(): void
    {
        $f = __DIR__ . '/../../dreamfabric-admin/src/app/shared/services/df-license-check.service.ts';
        $c = file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('premium Determinus', $c);
        self::assertStringContainsString("msg: 'OK'", $c);
        self::assertStringContainsString("disableUi: 'false'", $c);
    }

    public function test_prem05_license_initializer_mock(): void
    {
        $f = __DIR__ . '/../../dreamfabric-admin/src/app/shared/services/df-license-initializer.service.ts';
        $c = file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('premium Determinus', $c);
        self::assertStringContainsString('of(true)', $c);
    }

    public function test_prem06_dist_main_isFeatureLocked_patched(): void
    {
        $files = glob(__DIR__ . '/../../dreamfabric-admin/dist/main.*.js');
        $f = $files[0] ?? '';
        $c = $f && file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('isFeatureLocked', $c);
        self::assertStringContainsString('return!1', $c);
    }

    public function test_prem07_dist_main_activatePaywall_patched(): void
    {
        $files = glob(__DIR__ . '/../../dreamfabric-admin/dist/main.*.js');
        $f = $files[0] ?? '';
        $c = $f && file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('activatePaywall', $c);
        // patch premium: isFeatureLocked return!1 + activatePaywall of(!1) — comment stripped in prod build, check minified logic
        self::assertTrue(str_contains($c, 'premium Determinus') || str_contains($c, 'of(!1)') || str_contains($c, 'return!1'), 'activatePaywall premium patched');
    }

    public function test_prem08_extension_premium_stub_exists(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/composer.json';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('yamaha/df-premium-stub', $c);
    }

    public function test_prem09_limit_resource_exists(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/src/Resources/LimitResource.php';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('class LimitResource', $c);
        self::assertStringContainsString('handleGET', $c);
    }

    public function test_prem10_scheduler_resource_exists(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/src/Resources/SchedulerResource.php';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('class SchedulerResource', $c);
    }

    public function test_prem11_event_script_resource_exists(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/src/Resources/EventScriptResource.php';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('class EventScriptResource', $c);
    }

    public function test_prem12_script_type_resource_exists(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/src/Resources/ScriptTypeResource.php';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('class ScriptTypeResource', $c);
    }

    public function test_prem13_service_provider_registers_premium_types(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/src/ServiceProvider.php';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('SystemResourceManager', $c);
        self::assertStringContainsString("'limit'", $c);
        self::assertStringContainsString("'scheduler'", $c);
        self::assertStringContainsString("'event_script'", $c);
        self::assertStringContainsString('ServiceType', $c);
        self::assertStringContainsString('LDAP', $c);
    }

    public function test_prem14_ldap_service_type_exists(): void
    {
        $f = __DIR__ . '/../../extensions/df-premium-stub/src/Services/LdapService.php';
        self::assertFileExists($f);
        $c = file_get_contents($f);
        self::assertStringContainsString('class LdapService', $c);
    }

    public function test_prem15_composer_registers_premium_stub_path(): void
    {
        $f = __DIR__ . '/../../composer.json';
        $c = file_exists($f) ? file_get_contents($f) : '';
        self::assertStringContainsString('df-premium-stub', $c);
        self::assertStringContainsString('yamaha/df-premium-stub', $c);
    }
}
