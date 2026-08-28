<?php

namespace Tests\Feature;

use DreamFactory\Core\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Query\JsonQueryCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\ResultNormalizer;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;

/**
 * TDD ULTRA — Sprint 2 GREEN suite (M1-M2: E2+E3 / RQ-020..035)
 * 15 testes escritos ANTES da implementação, agora virados para GREEN
 * após entrega das IAs. Rodar: docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit --testsuite Feature --filter TddUltraSprint2
 */
class TddUltraSprint2Test extends TestCase
{
    public function test_rq020_service_reference_stores_service_id_not_url(): void
    {
        $repo = __DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php';
        $content = file_exists($repo) ? file_get_contents($repo) : '';
        $hasServiceId = str_contains($content, 'service_id');
        $hasNoUrlDup = !preg_match('/jdbc_url|JDBC_URL|jdbcUrl/i', $content);
        $hasForbidden = str_contains($content, 'FORBIDDEN_CREDENTIAL_FIELDS');
        $hasRename = str_contains($content, 'function rename(');
        $hasDisable = str_contains($content, 'function disable(');
        $hasDeleteWithLock = str_contains($content, 'function delete(') && str_contains($content, 'lockForUpdate');
        self::assertTrue($hasServiceId && $hasNoUrlDup && $hasForbidden, 'RQ-020: NamedQuery deve referenciar service_id sem duplicar URL/credentials');
        self::assertTrue($hasRename && $hasDisable && $hasDeleteWithLock, 'RQ-020: rename/disable/delete lifecycle via service_id sem duplicar credencial');
    }

    public function test_rq021_dialect_capabilities_queryable(): void
    {
        $candidates = [
            __DIR__ . '/../../extensions/df-named-query/src/Services/DialectCapabilities.php',
            __DIR__ . '/../../extensions/df-named-query/src/Query/DialectCapabilities.php',
        ];
        $found = false;
        foreach ($candidates as $p) { if (file_exists($p)) { $found = true; break; } }
        self::assertTrue($found, 'RQ-021: abstração de capabilities deve existir');
        $caps = DialectCapabilities::forDriver('pgsql');
        self::assertArrayHasKey('named_binds', $caps);
        self::assertArrayHasKey('quoting', $caps);
        self::assertArrayHasKey('pagination', $caps);
        self::assertTrue(DialectCapabilities::supports('pgsql', DialectCapabilities::FEATURE_PAGINATION));
        $payload = DialectCapabilities::payloadForDriver('informix');
        self::assertSame('informix', $payload['driver']);
        self::assertArrayHasKey('capabilities', $payload);
    }

    public function test_rq021_unsupported_feature_blocks_publish(): void
    {
        $repo = __DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php';
        $content = file_exists($repo) ? file_get_contents($repo) : '';
        self::assertStringContainsString('assertSupportedForServiceType', $content, 'RQ-021: publish gate capability-aware');
        // Informix não suporta JSON — definir que exige JSON deve bloquear
        $this->expectException(BadRequestException::class);
        DialectCapabilities::assertSupported('informix', ['sql' => 'SELECT FOR JSON PATH', 'parameters' => [['name'=>'x']], 'output_schema' => [['type'=>'json']], 'budgets'=>[]]);
    }

    public function test_rq022_sqlsrv_service_type_registered(): void
    {
        $sp = __DIR__ . '/../../extensions/df-sqlsrv/src/ServiceProvider.php';
        $content = file_exists($sp) ? file_get_contents($sp) : '';
        self::assertStringContainsString('sqlsrv', strtolower($content));
        $schema = __DIR__ . '/../../extensions/df-sqlsrv/src/Database/Schema/SqlServerSchema.php';
        self::assertFileExists($schema);
        $sc = file_get_contents($schema);
        self::assertStringContainsString('sys.', strtolower($sc), 'RQ-022: metadata via sys.*');
        $config = file_get_contents(__DIR__ . '/../../extensions/df-sqlsrv/src/Models/SqlServerConfig.php');
        self::assertStringContainsString('Encrypt', $config, 'RQ-022: encrypt defaults seguros');
        self::assertStringContainsString('pdo_sqlsrv', strtolower($config), 'RQ-022: requireDriver pdo_sqlsrv');
    }

    public function test_rq023_oracle_service_type_registered(): void
    {
        $sp = __DIR__ . '/../../extensions/df-oracle/src/ServiceProvider.php';
        $content = file_exists($sp) ? file_get_contents($sp) : '';
        self::assertStringContainsString('oracle', strtolower($content));
        $schema = __DIR__ . '/../../extensions/df-oracle/src/Database/Schema/OracleSchema.php';
        self::assertFileExists($schema);
        $sc = file_get_contents($schema);
        self::assertTrue(str_contains(strtolower($sc), 'all_tables') || str_contains(strtolower($sc), 'all_tab_columns'), 'RQ-023: via ALL_*');
        $config = file_get_contents(__DIR__ . '/../../extensions/df-oracle/src/Models/OracleConfig.php');
        self::assertStringContainsString('oci8', strtolower($config), 'RQ-023: oci8 dependência externa');
    }

    public function test_rq024_informix_blocked_without_csdk_and_uses_systables(): void
    {
        $conn = __DIR__ . '/../../extensions/df-informix/src/Database/InformixConnector.php';
        $schema = __DIR__ . '/../../extensions/df-informix/src/Database/Schema/InformixSchema.php';
        self::assertFileExists($conn);
        self::assertFileExists($schema);
        $c = file_get_contents($schema);
        self::assertStringContainsString('systables', strtolower($c));
        $cc = file_get_contents($conn);
        self::assertStringContainsString('pdo_informix', strtolower($cc), 'RQ-024: falha explícita sem pdo_informix');
        self::assertStringContainsString('extension_loaded', $cc);
    }

    public function test_rq025_postgres_shared_connection_lifecycle(): void
    {
        $sp = __DIR__ . '/../../extensions/df-named-query/src/ServiceProvider.php';
        $content = file_exists($sp) ? file_get_contents($sp) : '';
        self::assertStringContainsString('pgsql', strtolower($content));
        self::assertFileExists(__DIR__ . '/../../docs/architecture/postgres-qualification.md', 'RQ-025: postgres-qualification.md');
        $q = file_get_contents(__DIR__ . '/../../docs/architecture/postgres-qualification.md');
        self::assertStringContainsString('invalida', strtolower($q), 'RQ-025: invalidação cluster-wide documentada');
    }

    public function test_rq030_tokenizer_ignores_strings_and_comments(): void
    {
        $compiler = __DIR__ . '/../../extensions/df-named-query/src/Query/NamedSqlCompiler.php';
        $content = file_exists($compiler) ? file_get_contents($compiler) : '';
        $hasSkip = str_contains($content, 'SKIP') && str_contains($content, '(*SKIP)(*F)');
        self::assertTrue($hasSkip, 'RQ-030: tokenizer deve ignorar strings e comentários');
        // Prova funcional: literal ':vin' não deve virar bind
        $compiled = (new NamedSqlCompiler())->compile(
            "SELECT ':vin' AS literal_value FROM vehicles WHERE vin = :vin",
            [['name' => 'vin', 'required' => true]],
            ['vin' => '00123']
        );
        self::assertSame(1, count($compiled->bindings), 'RQ-030: literal ignorado');
        self::assertStringNotContainsString("':vin_0'", $compiled->sql);
    }

    public function test_rq030_repeated_parameter_bound_correctly(): void
    {
        $compiled = (new NamedSqlCompiler())->compile(
            "SELECT ':vin' AS literal_value FROM vehicles WHERE vin = :vin OR previous_vin = :vin",
            [['name' => 'vin', 'required' => true]],
            ['vin' => '00123']
        );
        self::assertSame(2, count($compiled->bindings));
        self::assertArrayHasKey('vin_0', $compiled->bindings);
        self::assertArrayHasKey('vin_1', $compiled->bindings);
        self::assertSame('00123', $compiled->bindings['vin_0']);
        self::assertSame('00123', $compiled->bindings['vin_1']);
        self::assertStringContainsString(':vin_0', $compiled->sql);
        self::assertStringContainsString(':vin_1', $compiled->sql);
    }

    public function test_rq031_readonly_defense_in_depth(): void
    {
        $compiler = new NamedSqlCompiler();
        $this->expectException(BadRequestException::class);
        $compiler->compile('DELETE FROM vehicles WHERE vin = :vin', [['name'=>'vin','required'=>true]], ['vin'=>'00123']);
    }

    public function test_rq032_json_dsl_allowlists_and_legacy_import(): void
    {
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Query/JsonQueryCompiler.php');
        self::assertContains('=', JsonQueryCompiler::ALLOWED_OPERATORS);
        self::assertContains('INNER', JsonQueryCompiler::ALLOWED_JOIN_TYPES);
        self::assertContains('string', JsonQueryCompiler::ALLOWED_VALUE_TYPES);
        $compiler = new JsonQueryCompiler();
        // Import legado sem mudança semântica (acasala.json) — path em host e fallback sintético quando api-query não está no volume do container
        $candidates = [
            __DIR__ . '/../../../api-query/config/query/acasala.json',
            __DIR__ . '/../../api-query/config/query/acasala.json',
        ];
        $legacy = null;
        foreach ($candidates as $p) {
            if (file_exists($p)) {
                $legacy = json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
                break;
            }
        }
        if ($legacy === null) {
            // Fallback sintético idêntico a acasala.json (sem depender do volume /app/../api-query não montado no container)
            $legacy = ['query' => ['name' => 'acasala', 'mainQuery' => ['from' => 'vic_s_int_bprd_ctl vsibc', 'joins' => [['type' => 'INNER', 'table' => 'vic_s_int_eprd_ctl vsiec', 'on' => "vsiec.plan_stamp_image = substr(vsibc.eg_stamp_key,1,5)||'-'||substr(vsibc.eg_stamp_key,7,6)"]], 'select' => ['vsibc.effect_stamp_image vin', 'vsibc.stamp_key cma'], 'filters' => [['groupId' => 'cma', 'required' => ['cma'], 'optional' => [], 'conditions' => [['column' => 'vsibc.stamp_key', 'op' => '=', 'param' => 'cma']]]]]]];
        }
        // legacy já vem como ['query'=>...] se do disco, ou synthetic já no formato correto
        $doc = isset($legacy['query']) ? $legacy : ['query' => $legacy];
        $normalized = $compiler->importLegacy($doc);
        self::assertArrayHasKey('query', $normalized);
        $compiled = $compiler->compile($normalized, ['cma' => 'TEST123']);
        self::assertNotEmpty($compiled->sql);
        self::assertStringContainsString('SELECT', $compiled->sql);
    }

    public function test_rq033_filter_groups_and_limited_subqueries(): void
    {
        $compiler = new JsonQueryCompiler();
        $doc = [
            'query' => [
                'mainQuery' => [
                    'from' => 'vehicles v',
                    'select' => ['v.vin'],
                    'filters' => [[
                        'groupId' => 'g1',
                        'required' => ['vin'],
                        'optional' => [],
                        'conditions' => [['column' => 'v.vin', 'op' => '=', 'param' => 'vin', 'valueType' => 'string']],
                    ]],
                ],
            ],
        ];
        // Grupo parcial (required sem param) deve gerar 400
        try {
            $compiler->compile($doc, []);
            self::fail('RQ-033: grupo parcial deveria gerar 400');
        } catch (BadRequestException $e) {
            self::assertStringContainsString('Parametro', $e->getMessage());
        }
        // String com zeros preservados
        $compiled = $compiler->compile($doc, ['vin' => '00123']);
        self::assertSame('00123', $compiled->bindings[array_key_first($compiled->bindings)]);
        // IN consome budgets
        $doc2 = [
            'query' => [
                'mainQuery' => [
                    'from' => 'vehicles v',
                    'select' => ['v.vin'],
                    'filters' => [[
                        'groupId' => 'g1',
                        'required' => ['ids'],
                        'optional' => [],
                        'conditions' => [['column' => 'v.id', 'op' => 'IN', 'param' => 'ids', 'valueType' => 'string']],
                    ]],
                ],
            ],
        ];
        $compiled2 = $compiler->compile($doc2, ['ids' => 'a,b,c']);
        self::assertSame(3, count($compiled2->bindings));
    }

    public function test_rq034_normalization_by_executor_and_db(): void
    {
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Query/ResultNormalizer.php');
        $normalizer = new ResultNormalizer();
        // lowercase + preserva pontilhados
        self::assertSame('inspecao.pecas', $normalizer->normalizeLabel('[inspecao.pecas]'));
        self::assertSame('motor.numero', $normalizer->normalizeLabel('MOTOR.NUMERO'));
        self::assertSame('nr_carcaca', $normalizer->normalizeLabel('substr(x,1,4) nr_carcaca'));
        $row = $normalizer->normalizeRow(['INSPECAO.PECAS' => '{"a":1}', 'VIN' => '00123'], 'pdo', 'pgsql');
        self::assertArrayHasKey('inspecao.pecas', $row);
        self::assertIsArray($row['inspecao.pecas'], 'RQ-034: JSON embutido decodificado');
        // null permanece null, unicode preservado
        $row2 = $normalizer->normalizeRow(['name' => null, 'city' => 'São Paulo'], 'pdo', 'pgsql');
        self::assertNull($row2['name']);
        self::assertSame('São Paulo', $row2['city']);
    }

    public function test_rq035_all_eight_queries_ported_and_certified(): void
    {
        $defs = glob(__DIR__ . '/../../extensions/df-named-query/database/definitions/*.json');
        self::assertGreaterThanOrEqual(7, count($defs));
        $expected = ['py-ptg.json','gq-mi-wms.json','gq-mi-pymac.json','gq-eficaz.json','py-local.json'];
        foreach ($expected as $f) {
            self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/database/definitions/' . $f, "RQ-035: $f portada");
        }
        $compiler = new NamedSqlCompiler();
        $total = 0;
        foreach ($defs as $file) {
            $definition = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($definition['queries'] as $query) {
                $values = [];
                foreach ($query['parameters'] as $p) {
                    $values[$p['name']] = match ($p['type'] ?? 'string') { 'integer'=>1, 'number'=>1.5, 'boolean'=>true, default=>'test'};
                }
                $compiled = $compiler->compile($query['sql'], $query['parameters'], $values);
                self::assertNotEmpty($compiled->sql);
                $total++;
            }
        }
        self::assertSame(10, $total, 'RQ-035: 10 queries no catálogo (8 do contrato + 2 GMUD)');
    }

    public function test_sprint2_epics_e2_e3_traceability(): void
    {
        $defs = glob(__DIR__ . '/../../extensions/df-named-query/database/definitions/*.json');
        self::assertCount(7, $defs, 'Sprint2: 7 definitions files');
        self::assertFileExists(__DIR__ . '/../../docs/architecture/postgres-qualification.md');
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Services/DialectCapabilities.php');
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Query/JsonQueryCompiler.php');
        self::assertFileExists(__DIR__ . '/../../extensions/df-named-query/src/Query/ResultNormalizer.php');
    }
}
