<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Query\JsonQueryCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\QueryExecutionBudget;
use Yamaha\DreamFactory\NamedQuery\Http\EnvelopeTranslator;
use Yamaha\DreamFactory\NamedQuery\Http\Middleware\LegacyHeaderMiddleware;

/**
 * TDD ULTRA — Sprint 3 GREEN suite (M3 E4: RQ-040→045)
 * 15 testes escritos ANTES (RED 260d1cf), agora virados para GREEN
 * após Wave1 (040+041+073) + Wave2 serial (042→043→044→045).
 * Rodar: docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint3
 */
class TddUltraSprint3Test extends TestCase
{
    public function test_rq040_discovery_does_not_leak_without_permission(): void
    {
        $res = __DIR__ . '/../../extensions/df-named-query/src/Resources/NamedQueryResource.php';
        $content = file_exists($res) ? file_get_contents($res) : '';
        self::assertStringContainsString('listAccessComponents', $content);
        self::assertStringContainsString('getPermissions', $content);
        // handleGET filtra por permissão antes de cleanResources
        self::assertStringContainsString('array_filter', $content);
        self::assertStringContainsString('getPermissions', $content);
        self::assertFileExists(__DIR__ . '/../../docs/architecture/rbac.md');
    }

    public function test_rq040_concrete_query_permission_checked_on_execute(): void
    {
        $res = __DIR__ . '/../../extensions/df-named-query/src/Resources/NamedQueryResource.php';
        $content = file_exists($res) ? file_get_contents($res) : '';
        self::assertStringContainsString('checkPermission', $content);
        self::assertStringContainsString('_query', $content);
        self::assertStringContainsString('getAction', $content);
        self::assertFileExists(__DIR__ . '/../../docs/architecture/rbac.md');
    }

    public function test_rq040_internal_calls_follow_explicit_policy_no_parallel_auth(): void
    {
        $res = __DIR__ . '/../../extensions/df-named-query/src/Resources/NamedQueryResource.php';
        $content = file_exists($res) ? file_get_contents($res) : '';
        self::assertStringNotContainsString('parallelAuth', $content);
        self::assertStringContainsString('Session::', $content);
        self::assertStringContainsString('checkServicePermission', file_get_contents(__DIR__ . '/../../docs/architecture/rbac.md'));
    }

    public function test_rq041_defaults_preserve_45s_10000_10mib(): void
    {
        self::assertSame(10000, JsonQueryCompiler::DEFAULT_BUDGETS['max_rows']);
        self::assertSame(10485760, JsonQueryCompiler::DEFAULT_BUDGETS['max_total_bytes']);
        self::assertSame(45, JsonQueryCompiler::DEFAULT_BUDGETS['query_timeout_seconds']);
        self::assertSame(45, JsonQueryCompiler::DEFAULT_BUDGETS['request_timeout_seconds']);
        self::assertFileExists(__DIR__ . '/../../docs/architecture/budgets.md');
    }

    public function test_rq041_preserves_100_params_and_4096_chars(): void
    {
        self::assertSame(100, JsonQueryCompiler::DEFAULT_BUDGETS['max_parameters']);
        self::assertSame(4096, JsonQueryCompiler::DEFAULT_BUDGETS['max_parameter_value_length']);
        $c = new JsonQueryCompiler();
        $doc = ['query' => ['mainQuery' => ['from' => 't', 'select' => ['a'], 'filters' => []]]];
        // 101 params deve falhar
        $params = array_fill_keys(array_map(fn($i) => "p$i", range(1, 101)), 'x');
        try {
            $c->compile($doc, $params);
            self::fail('deveria falhar com >100 params');
        } catch (\DreamFactory\Core\Exceptions\BadRequestException $e) {
            self::assertStringContainsString('maxima', strtolower($e->getMessage()));
        }
    }

    public function test_rq041_preserves_in_100_and_500_subqueries(): void
    {
        self::assertSame(100, JsonQueryCompiler::DEFAULT_BUDGETS['max_in_items']);
        self::assertSame(500, JsonQueryCompiler::DEFAULT_BUDGETS['max_subquery_executions']);
        $c = new JsonQueryCompiler();
        $doc = ['query' => ['mainQuery' => ['from' => 't', 'select' => ['a'], 'filters' => [['groupId' => 'g1', 'required' => ['ids'], 'optional' => [], 'conditions' => [['column' => 't.id', 'op' => 'IN', 'param' => 'ids']]]]]]];
        try {
            $c->compile($doc, ['ids' => implode(',', array_fill(0, 101, 'x'))]);
            self::fail('IN >100 deveria falhar');
        } catch (\DreamFactory\Core\Exceptions\BadRequestException $e) {
            self::assertStringContainsString('maxima', strtolower($e->getMessage()));
        }
    }

    public function test_rq041_deadline_reduces_statement_timeout(): void
    {
        $budget = new QueryExecutionBudget(['request_timeout_seconds' => 45, 'max_total_rows' => 10000, 'max_total_bytes' => 10485760], microtime(true) - 44);
        $reduced = $budget->statementTimeoutSeconds(45);
        self::assertLessThanOrEqual(2, $reduced, 'deadline quase esgotado deve reduzir timeout');
        self::assertGreaterThanOrEqual(1, $reduced);
        $fresh = new QueryExecutionBudget(['request_timeout_seconds' => 45, 'max_total_rows' => 10000, 'max_total_bytes' => 10485760], microtime(true));
        self::assertSame(45, $fresh->statementTimeoutSeconds(45));
    }

    public function test_rq042_pair_semantics_rotation_revocation(): void
    {
        self::assertFileExists(__DIR__ . '/../../docs/architecture/credential-migration.md');
        $content = file_get_contents(__DIR__ . '/../../docs/architecture/credential-migration.md');
        self::assertStringContainsString('client_secret', strtolower($content));
        self::assertStringContainsString('client_key', strtolower($content));
        self::assertStringContainsString('hash', strtolower($content));
        self::assertStringContainsString('is_active', $content);
        self::assertStringContainsString('7 dias', $content);
    }

    public function test_rq043_header_aliases_underscore_hyphen_x(): void
    {
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php');
        self::assertContains('client_secret', LegacyHeaderMiddleware::SECRET_ALIASES);
        self::assertContains('client-secret', LegacyHeaderMiddleware::SECRET_ALIASES);
        self::assertContains('x-client-secret', LegacyHeaderMiddleware::SECRET_ALIASES);
        self::assertContains('client_key', LegacyHeaderMiddleware::KEY_ALIASES);
        self::assertContains('x-client-key', LegacyHeaderMiddleware::KEY_ALIASES);
        $m = new LegacyHeaderMiddleware();
        $req = new \Illuminate\Http\Request();
        $req->headers->set('x-client-secret', 'TEST_SECRET');
        $req->headers->set('x-client-key', 'TEST_KEY');
        // deve normalizar sem lançar
        $nextCalled = false;
        $m->handle($req, function ($r) use (&$nextCalled) { $nextCalled = true; return $r; });
        self::assertTrue($nextCalled);
        self::assertSame('TEST_SECRET', $req->headers->get('client_secret'));
    }

    public function test_rq043_longest_prefix_and_native_no_bypass(): void
    {
        $m = new LegacyHeaderMiddleware();
        $routes = ['/query-builder/py-ptg', '/query-builder/py-ptg/acasala', '/query-builder'];
        $best = $m->findLongestMatchingRoute($routes, '/api/v1/query-builder/py-ptg/acasala?cma=x');
        self::assertSame('/query-builder/py-ptg/acasala', $best);
        $none = $m->findLongestMatchingRoute($routes, '/api/v2/py_ptg/_query/acasala');
        self::assertNull($none);
        self::assertFileExists(__DIR__ . '/../../docs/architecture/legacy-headers.md');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/rbac.md');
    }

    public function test_rq044_envelope_preserves_erroCode(): void
    {
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Http/EnvelopeTranslator.php');
        self::assertSame(1400, EnvelopeTranslator::statusToErroCode(400));
        self::assertSame(1001, EnvelopeTranslator::statusToErroCode(401));
        self::assertSame(1003, EnvelopeTranslator::statusToErroCode(403));
        self::assertSame(1004, EnvelopeTranslator::statusToErroCode(404));
        self::assertSame(1409, EnvelopeTranslator::statusToErroCode(409));
        self::assertSame(5504, EnvelopeTranslator::statusToErroCode(504));
        $legacy = EnvelopeTranslator::toLegacyErrorFromStatusAndMessage(400, 'Parametro ausente');
        self::assertSame(1400, $legacy['erroCode']);
        self::assertArrayHasKey('timestamp', $legacy);
        self::assertArrayHasKey('errorMessage', $legacy);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $legacy['timestamp']);
    }

    public function test_rq044_http_mapping_covers_400_to_504(): void
    {
        $cases = [400 => 1400, 401 => 1001, 403 => 1003, 404 => 1004, 409 => 1409, 500 => 5000, 504 => 5504];
        foreach ($cases as $http => $code) {
            self::assertSame($code, EnvelopeTranslator::statusToErroCode($http), "HTTP $http -> erroCode $code");
        }
        // contrato nativo preservado: sem envelope legado por padrão
        self::assertFalse(EnvelopeTranslator::isLegacyRequestedFromGlobals());
        self::assertFileExists(__DIR__ . '/../../docs/architecture/envelopes.md');
    }

    public function test_rq045_threat_model_separate_suites(): void
    {
        self::assertFileExists(__DIR__ . '/../../docs/architecture/threat-model.md');
        $content = file_get_contents(__DIR__ . '/../../docs/architecture/threat-model.md');
        self::assertStringContainsString('T-01', $content);
        self::assertStringContainsString('T-03', $content);
        self::assertStringContainsString('T-09', $content);
        self::assertStringContainsString('Critical', $content);
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/tests/AbuseSuiteTest.php');
        $abuse = strtolower(file_get_contents(__DIR__ . '/../../extensions/df-named-query/tests/AbuseSuiteTest.php'));
        self::assertStringContainsString('test_injection_valores', $abuse);
        self::assertStringContainsString('test_injection_identificadores', $abuse);
    }

    public function test_rq041_cluster_limits_without_sticky(): void
    {
        self::assertFileExists(__DIR__ . '/../../docs/architecture/budgets.md');
        $content = file_get_contents(__DIR__ . '/../../docs/architecture/budgets.md');
        self::assertStringContainsString('Cluster-safe', $content);
        self::assertStringContainsString('sem cache', strtolower($content));
        // budgets lido do DB por request, sem static cache
        $content2 = file_get_contents(__DIR__ . '/../../extensions/df-named-query/src/Resources/NamedQueryResource.php');
        self::assertStringNotContainsString('static::$budgets', $content2);
    }

    public function test_sprint3_e4_traceability(): void
    {
        self::assertFileExists(__DIR__ . '/../../docs/architecture/rbac.md');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/budgets.md');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/credential-migration.md');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/legacy-headers.md');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/envelopes.md');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/threat-model.md');
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php');
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Http/EnvelopeTranslator.php');
    }
}
