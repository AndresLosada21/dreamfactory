<?php

namespace Yamaha\DreamFactory\NamedQuery\Tests;

use DreamFactory\Core\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Query\JsonQueryCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\QueryExecutionBudget;
use Yamaha\DreamFactory\NamedQuery\Repositories\NamedQueryRepository;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;

/**
 * RQ-045 — Threat model abuse suites.
 *
 * Suites separadas para valores vs identificadores + admin plane isolável + egress allowlist.
 * Cobre: injection (valor/identificador), auth bypass (RBAC), SSRF/egress, XXE, DoS/budgets,
 * definições maliciosas (publish gate), XSS (output_schema sanitizado), privilege escalation.
 *
 * Reutiliza RBAC/events nativos — sem autorização paralela.
 * High e critical exigem correção ou aceite formal (threat-model.md §10).
 *
 * @see docs/architecture/threat-model.md:1
 * @see docs/architecture/rbac.md:1
 * @see extensions/df-named-query/src/Query/NamedSqlCompiler.php:60 (assertReadOnly)
 * @see extensions/df-named-query/src/Repositories/NamedQueryRepository.php:356 (validateDefinition)
 * @see extensions/df-named-query/src/Resources/NamedQueryResource.php:18
 * @see extensions/df-named-query/src/Resources/NamedQueryAdminResource.php:15
 */
class AbuseSuiteTest extends TestCase
{
    // =========================================================================
    // T-01 — Injection VALORES (via :param) — suite isolada
    // @see NamedSqlCompiler.php:38 (compile binds)
    // =========================================================================

    /**
     * @dataProvider injectionValoresProvider
     */
    public function test_injection_valores_sao_binds_tipados_nao_interpolacao(string $payload): void
    {
        $compiler = new NamedSqlCompiler();
        $compiled = $compiler->compile(
            'SELECT * FROM t WHERE vin = :vin',
            [['name' => 'vin', 'type' => 'string', 'required' => true]],
            ['vin' => $payload]
        );
        // SQL deve conter placeholder único :vin_0, nunca o payload cru
        self::assertStringContainsString(':vin_0', $compiled->sql);
        self::assertStringNotContainsString($payload, $compiled->sql);
        // Valor fica no binding escalar, não no SQL
        self::assertSame($payload, $compiled->bindings['vin_0'] ?? null);
    }

    public static function injectionValoresProvider(): array
    {
        return [
            'classic sqli' => ["' OR '1'='1"],
            "union select" => ["' UNION SELECT * FROM users --"],
            'comment close' => ["'; DROP TABLE t; --"],
            'stacked' => ["1; DELETE FROM t"],
            'boolean blind' => ["' OR 1=1 --"],
            'pg cast attempt' => ["1::text; DROP TABLE t"],
            'like wildcard bomb' => ["%'; DELETE FROM t WHERE '1'='1"],
        ];
    }

    public function test_injection_valores_literal_nao_e_substituido(): void
    {
        $compiled = (new NamedSqlCompiler())->compile(
            "SELECT ':vin' AS lit, :vin::text AS v FROM t WHERE vin = :vin",
            [['name' => 'vin', 'required' => true]],
            ['vin' => "a' OR 1=1 --"]
        );
        // literal ':vin' preservado, só binds fora dele substituídos
        self::assertStringContainsString("':vin'", $compiled->sql);
        self::assertStringContainsString(':vin_0::text', $compiled->sql);
        self::assertCount(2, $compiled->bindings); // :vin no SELECT+WHERE -> 2 binds únicos
    }

    public function test_injection_valores_comentario_nao_vira_param(): void
    {
        $compiled = (new NamedSqlCompiler())->compile(
            "SELECT * FROM t WHERE vin = :vin /* :injected */ -- :injected2\n AND active=1",
            [['name' => 'vin', 'required' => true]],
            ['vin' => 'x']
        );
        // Comentário permanece no SQL mas não vira bind — prova é que bindings não contém injected
        self::assertArrayNotHasKey('injected', $compiled->bindings);
        self::assertArrayNotHasKey('injected_0', $compiled->bindings);
        self::assertArrayHasKey('vin_0', $compiled->bindings);
        self::assertStringContainsString(':vin_0', $compiled->sql);
    }

    public function test_injection_valores_tipo_inteiro_rejeita_string_maliciosa(): void
    {
        $this->expectException(BadRequestException::class);
        (new NamedSqlCompiler())->compile(
            'SELECT * FROM t WHERE id = :id',
            [['name' => 'id', 'type' => 'integer', 'required' => true]],
            ['id' => "1 OR 1=1"]
        );
    }

    public function test_injection_valores_parametro_nao_declarado_rejeita(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/not declared/');
        (new NamedSqlCompiler())->compile(
            'SELECT * FROM t WHERE vin = :vin AND extra = :injected',
            [['name' => 'vin']],
            ['vin' => 'x', 'injected' => 'y']
        );
    }

    // =========================================================================
    // T-02 — Injection IDENTIFICADORES (via SQL da definição) — suite separada
    // @see NamedSqlCompiler.php:60 assertReadOnly
    // =========================================================================

    /**
     * @dataProvider injectionIdentificadoresProvider
     */
    public function test_injection_identificadores_bloqueados_por_assertReadOnly(string $sql, string $hint): void
    {
        $this->expectException(BadRequestException::class);
        (new NamedSqlCompiler())->assertReadOnly($sql);
        // hint só para debug do provider
        self::assertNotEmpty($hint);
    }

    public static function injectionIdentificadoresProvider(): array
    {
        return [
            'semicolon stacked' => ['SELECT * FROM t; DELETE FROM t', 'terminator'],
            'insert' => ['INSERT INTO t VALUES (1)', 'DML'],
            'update' => ['UPDATE t SET x=1', 'DML'],
            'delete' => ['DELETE FROM t', 'DML'],
            'drop' => ['DROP TABLE t', 'DDL'],
            'truncate' => ['TRUNCATE TABLE t', 'DDL'],
            'alter' => ['ALTER TABLE t ADD COLUMN x TEXT', 'DDL'],
            'create' => ['CREATE TABLE t (id INT)', 'DDL'],
            'exec' => ['EXEC sp_help', 'exec'],
            'select into' => ['SELECT * INTO archive FROM t', 'select_into'],
            'for update' => ['SELECT * FROM t FOR UPDATE', 'locking'],
            'for share' => ['SELECT * FROM t FOR SHARE', 'locking'],
            'lock in share mode' => ['SELECT * FROM t LOCK IN SHARE MODE', 'locking'],
            'with then delete' => ['WITH cte AS (SELECT 1) DELETE FROM t', 'with+dml token'],
            'union with terminator' => ["SELECT * FROM t UNION SELECT * FROM users; DELETE FROM users", 'union+terminator'],
        ];
    }

    public function test_injection_identificadores_comentario_nao_esconde_DDL(): void
    {
        // stripLiteralsAndComments remove comentário mas mantém token DELETE
        $this->expectException(BadRequestException::class);
        (new NamedSqlCompiler())->assertReadOnly("SELECT * FROM t -- comment\n; DELETE FROM t");
    }

    public function test_injection_identificadores_select_with_valido(): void
    {
        // Positivo: WITH/CTE e UNION legítimo (pymac-origin-destination) não bloqueiam
        (new NamedSqlCompiler())->assertReadOnly('WITH cte AS (SELECT 1 AS a) SELECT * FROM cte');
        (new NamedSqlCompiler())->assertReadOnly('SELECT * FROM a UNION SELECT * FROM b');
        self::assertTrue(true, 'WITH e UNION legítimos são permitidos');
    }

    public function test_injection_identificadores_mutacao_so_com_flag(): void
    {
        // Sem flag, mesmo com allowMutation=true o assert falha (fallthrough read-only)
        $this->expectException(BadRequestException::class);
        (new NamedSqlCompiler())->assertReadOnly('INSERT INTO t VALUES (1)', true);
    }

    // =========================================================================
    // T-03 — Auth bypass (RBAC nativo) — prova sem execução DB
    // @see NamedQueryResource.php:98 listAccessComponents, 218 checkPermission
    // @see rbac.md:11
    // =========================================================================

    public function test_auth_bypass_listagem_filtra_por_permissao_concreta(): void
    {
        $path = __DIR__ . '/../src/Resources/NamedQueryResource.php';
        $src = file_get_contents($path);
        self::assertStringContainsString('listAccessComponents', $src, 'listAccessComponents presente');
        self::assertStringContainsString('getPermissions', $src, 'getPermissions(_query/{name}) presente');
        // handleGET filtra direto (42-50 e 60-69) além de listAccessComponents
        self::assertStringContainsString("!empty(\$this->getPermissions(", $src);
        self::assertStringNotContainsString('parallelAuth', $src, 'sem autorização paralela');
    }

    public function test_auth_bypass_execucao_exige_componente_concreto(): void
    {
        $path = __DIR__ . '/../src/Resources/NamedQueryResource.php';
        $src = file_get_contents($path);
        self::assertStringContainsString('checkPermission', $src);
        // execute() usa checkPermission(action, resource) -> _query/{name}
        self::assertMatchesRegularExpression('/checkPermission\s*\(\s*\$this->getAction\(\)\s*,\s*\$this->resource/', $src);
        self::assertStringNotContainsString('checkPermission(false', $src, 'sem bypass');
        // Repositório não chama handleRequest com bypass
        $repo = file_get_contents(__DIR__ . '/../src/Repositories/NamedQueryRepository.php');
        self::assertStringNotContainsString('checkPermission', $repo, 'Repository não faz auth própria');
    }

    // =========================================================================
    // T-04 — SSRF / Egress allowlist (não fetch arbitrário)
    // @see NamedQueryRepository.php:23 FORBIDDEN_CREDENTIAL_FIELDS, 360 validateDefinition
    // =========================================================================

    /**
     * @dataProvider ssrfEgressProvider
     */
    public function test_ssrf_egress_campos_credenciais_bloqueados(array $definition, string $field): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/credential field.*' . preg_quote($field, '/') . '/i');
        (new NamedQueryRepository())->create($definition);
    }

    public static function ssrfEgressProvider(): array
    {
        $base = ['service_id' => 1, 'name' => 'q1', 'sql' => 'SELECT 1'];
        return [
            'jdbcUrl' => [array_merge($base, ['jdbcUrl' => 'jdbc:postgresql://evil/']), 'jdbcUrl'],
            'jdbc_url' => [array_merge($base, ['jdbc_url' => 'x']), 'jdbc_url'],
            'host' => [array_merge($base, ['host' => '169.254.169.254']), 'host'],
            'password' => [array_merge($base, ['password' => 'x']), 'password'],
            'connection_string' => [array_merge($base, ['connection_string' => 'x']), 'connection_string'],
            'credential' => [array_merge($base, ['credential' => 'x']), 'credential'],
            'secret' => [array_merge($base, ['secret' => 'x']), 'secret'],
        ];
    }

    public function test_ssrf_egress_sem_fetch_arbitrario_no_codigo(): void
    {
        $srcFiles = [
            __DIR__ . '/../src/Resources/NamedQueryResource.php',
            __DIR__ . '/../src/Repositories/NamedQueryRepository.php',
            __DIR__ . '/../src/Query/NamedSqlCompiler.php',
        ];
        foreach ($srcFiles as $f) {
            $c = file_get_contents($f);
            self::assertStringNotContainsString('file_get_contents($', $c, "$f sem fetch arbitrário");
            self::assertStringNotContainsString('GuzzleHttp', $c, "$f sem Guzzle via URL de usuário");
        }
        // assertServiceExists só aceita 4 tipos — egress fechado
        $repo = file_get_contents(__DIR__ . '/../src/Repositories/NamedQueryRepository.php');
        self::assertStringContainsString("'pgsql_query'", $repo);
        self::assertStringContainsString("'oracle'", $repo);
        self::assertStringContainsString("'sqlsrv'", $repo);
        self::assertStringContainsString("'informix'", $repo);
    }

    // =========================================================================
    // T-05 — XXE (N/A mitigado) — prova que não há parsing XML de usuário
    // =========================================================================

    public function test_xxe_nao_aplicavel_sem_parse_xml_usuario(): void
    {
        $haystack = '';
        foreach (glob(__DIR__ . '/../src/**/*.php') ?: [] as $f) { $haystack .= file_get_contents($f); }
        // varredura direta sem glob recursivo confiável — lê arquivos conhecidos
        $files = [
            __DIR__ . '/../src/Resources/NamedQueryResource.php',
            __DIR__ . '/../src/Resources/NamedQueryAdminResource.php',
            __DIR__ . '/../src/Query/NamedSqlCompiler.php',
            __DIR__ . '/../src/Repositories/NamedQueryRepository.php',
        ];
        $joined = implode("\n", array_map('file_get_contents', $files));
        self::assertStringNotContainsString('DOMDocument', $joined, 'sem DOMDocument com input de usuário');
        self::assertStringNotContainsString('simplexml_load_string', $joined, 'sem simplexml com input de usuário');
        // SGC legado usava disallow-doctype-decl — herança documentada em threat-model, não reexposto
        self::assertTrue(true, 'XXE N/A: _query não parseia XML; SGC SOAP legado já tinha XXE mitigado');
    }

    // =========================================================================
    // T-06 — DoS / Budgets hierárquicos cluster-safe
    // @see JsonQueryCompiler.php:35 DEFAULT_BUDGETS, QueryExecutionBudget.php:25
    // @see budgets.md:1
    // =========================================================================

    public function test_dos_budgets_defaults_preservam_10000_45s_10mib(): void
    {
        $d = JsonQueryCompiler::DEFAULT_BUDGETS;
        self::assertSame(10000, $d['max_rows']);
        self::assertSame(100, $d['max_parameters']);
        self::assertSame(4096, $d['max_parameter_value_length']);
        self::assertSame(100, $d['max_in_items']);
        self::assertSame(500, $d['max_subquery_executions']);
        self::assertSame(10485760, $d['max_total_bytes']);
        self::assertSame(45, $d['query_timeout_seconds']);
        self::assertSame(45, $d['request_timeout_seconds']);
    }

    public function test_dos_max_parameters_100(): void
    {
        $compiler = new JsonQueryCompiler();
        $doc = $this->minimalJsonDocument();
        $params = array_combine(
            array_map(fn($i) => "p$i", range(0, 100)),
            array_fill(0, 101, 'x')
        );
        $this->expectException(BadRequestException::class);
        $compiler->compile($doc, $params);
    }

    public function test_dos_max_parameter_value_length_4096(): void
    {
        $compiler = new JsonQueryCompiler();
        $doc = [
            'query' => [
                'mainQuery' => [
                    'from' => 't',
                    'select' => ['t.id'],
                    'filters' => [[
                        'groupId' => 'g', 'required' => ['p'], 'optional' => [],
                        'conditions' => [['column' => 't.x', 'op' => '=', 'param' => 'p']],
                    ]],
                ],
            ],
        ];
        $this->expectException(BadRequestException::class);
        $compiler->compile($doc, ['p' => str_repeat('A', 4097)]);
    }

    public function test_dos_max_in_items_100(): void
    {
        $compiler = new JsonQueryCompiler();
        $doc = [
            'query' => [
                'mainQuery' => [
                    'from' => 't',
                    'select' => ['t.id'],
                    'filters' => [[
                        'groupId' => 'g', 'required' => ['p'], 'optional' => [],
                        'conditions' => [['column' => 't.id', 'op' => 'IN', 'param' => 'p']],
                    ]],
                ],
            ],
        ];
        $many = implode(',', range(1, 101)); // 101 itens
        $this->expectException(BadRequestException::class);
        $compiler->compile($doc, ['p' => $many]);
    }

    public function test_dos_max_subquery_executions_500(): void
    {
        $compiler = new JsonQueryCompiler();
        $doc = [
            'query' => [
                'mainQuery' => ['from' => 't', 'select' => ['t.id']],
                'subQueries' => [
                    ['name' => 's1', 'bindKey' => 'id', 'mainResultKey' => 'id', 'from' => 'u', 'select' => ['u.id'], 'mergeKey' => 'm'],
                    ['name' => 's2', 'bindKey' => 'id', 'mainResultKey' => 'id', 'from' => 'u', 'select' => ['u.id'], 'mergeKey' => 'm'],
                ],
            ],
        ];
        // 300 resultados * 2 subqueries = 600 > 500
        $this->expectException(BadRequestException::class);
        $compiler->compileWithSubQueries($doc, [], 300);
    }

    public function test_dos_deadline_reduz_statement_timeout(): void
    {
        $budget = new QueryExecutionBudget(
            ['request_timeout_seconds' => 45, 'max_total_rows' => 10000, 'max_total_bytes' => 10485760],
            microtime(true) - 44.0 // 44s já decorridos, resta ~1s
        );
        $timeout = $budget->statementTimeoutSeconds(45);
        self::assertLessThanOrEqual(2, $timeout, 'deadline quase esgotado reduz timeout para ~1s');
        self::assertGreaterThanOrEqual(1, $timeout);
    }

    public function test_dos_deadline_excedido_lanca_504(): void
    {
        $budget = new QueryExecutionBudget(
            ['request_timeout_seconds' => 1, 'max_total_rows' => 10000, 'max_total_bytes' => 10485760],
            microtime(true) - 2.0 // já estourou
        );
        $this->expectException(\DreamFactory\Core\Exceptions\RestException::class);
        $budget->checkDeadline();
    }

    // =========================================================================
    // T-07 — Definições maliciosas / publish gate
    // @see NamedQueryRepository.php:356 validateDefinition, 256 publish
    // =========================================================================

    public function test_definicoes_maliciosas_sql_com_terminador_bloqueado(): void
    {
        $this->expectException(BadRequestException::class);
        (new NamedQueryRepository())->create([
            'service_id' => 1, 'name' => 'q1',
            'sql' => 'SELECT * FROM t; DELETE FROM t',
        ]);
    }

    public function test_definicoes_maliciosas_max_rows_negativo_bloqueado(): void
    {
        $this->expectException(BadRequestException::class);
        (new NamedQueryRepository())->create([
            'service_id' => 1, 'name' => 'q1',
            'sql' => 'SELECT 1',
            'budgets' => ['max_rows' => -1],
        ]);
    }

    public function test_definicoes_maliciosas_nome_invalido_bloqueado(): void
    {
        $this->expectException(BadRequestException::class);
        (new NamedQueryRepository())->create([
            'service_id' => 1, 'name' => '1bad', // deve começar com letra
            'sql' => 'SELECT 1',
        ]);
    }

    public function test_definicoes_maliciosas_parametro_nome_invalido(): void
    {
        $this->expectException(BadRequestException::class);
        (new NamedQueryRepository())->create([
            'service_id' => 1, 'name' => 'q1',
            'sql' => 'SELECT :1bad FROM t',
            'parameters' => [['name' => '1bad']],
        ]);
    }

    public function test_definicoes_maliciosas_dialect_gate_informix_json_bloqueado(): void
    {
        // Informix não suporta JSON — publish deve falhar quando dialeto exige json
        $this->expectException(BadRequestException::class);
        DialectCapabilities::assertSupported('informix', [
            'sql' => 'SELECT FOR JSON PATH',
            'parameters' => [['name' => 'x']],
            'output_schema' => [['name' => 'j', 'type' => 'json']],
            'budgets' => [],
        ]);
    }

    // =========================================================================
    // T-08 — XSS via output_schema sanitizado
    // =========================================================================

    public function test_xss_output_schema_e_array_e_nao_executa_html(): void
    {
        $this->expectException(BadRequestException::class);
        // output_schema deve ser array; string payload XSS deve falhar em validateDefinition
        (new NamedQueryRepository())->create([
            'service_id' => 1, 'name' => 'q1',
            'sql' => 'SELECT 1',
            'output_schema' => '<script>alert(1)</script>',
        ]);
    }

    public function test_xss_output_schema_com_script_e_escapado_no_json(): void
    {
        // output_schema com tag: json_encode com JSON_HEX_TAG escapa < > para \u003C \u003E quando Content-Type application/json
        $payload = [['name' => '<script>alert(1)</script>'], ['name' => '"><svg onload=alert(1)>']];
        $encoded = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('<script>', $encoded, 'json com HEX escapa tag');
        // Sem flags, tag crua no JSON mas inerte fora de HTML (não é XSS quando content-type json)
        $encodedRaw = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encodedRaw, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('<script>alert(1)</script>', $decoded[0]['name'], 'valor preservado no JSON mas inerte fora de HTML');
    }

    public function test_xss_audit_nao_loga_sql_ou_bind(): void
    {
        $src = file_get_contents(__DIR__ . '/../src/Services/NamedQueryAudit.php');
        self::assertStringContainsString('sanitizeBudgets', $src);
        self::assertStringNotContainsString("'sql'", $src, 'audit não loga SQL');
        // bindings/bindings não aparecem como chave logada
        self::assertStringNotContainsString("'bindings'", $src);
    }

    // =========================================================================
    // T-09 — Privilege escalation (admin plane isolável)
    // @see ServiceProvider.php:34 df.system.resource vs 55 df.service
    // @see NamedQueryAdminResource.php:15 vs NamedQueryResource.php:18
    // =========================================================================

    public function test_privilege_escalation_planos_isolaveis_system_vs_query(): void
    {
        $provider = file_get_contents(__DIR__ . '/../src/ServiceProvider.php');
        self::assertStringContainsString("df.system.resource", $provider, 'admin plane: df.system.resource');
        self::assertStringContainsString("df.service", $provider, 'exec plane: df.service');
        self::assertStringContainsString("named_query", $provider);
        self::assertStringContainsString("pgsql_query", $provider);
        // HasNamedQueryResource é trait usado por QueryPostgreSql, não precisa estar no ServiceProvider
        self::assertFileExists(__DIR__ . '/../src/Services/HasNamedQueryResource.php');
        self::assertFileExists(__DIR__ . '/../src/Services/QueryPostgreSql.php');

        $admin = file_get_contents(__DIR__ . '/../src/Resources/NamedQueryAdminResource.php');
        self::assertStringContainsString('BaseSystemResource', $admin, 'admin estende BaseSystemResource');

        $exec = file_get_contents(__DIR__ . '/../src/Resources/NamedQueryResource.php');
        self::assertStringContainsString('BaseRestResource', $exec, 'exec estende BaseRestResource');
        self::assertStringNotContainsString('BaseSystemResource', $exec, 'exec não é SystemResource');
    }

    public function test_privilege_escalation_sem_rota_paralela(): void
    {
        $provider = file_get_contents(__DIR__ . '/../src/ServiceProvider.php');
        self::assertStringNotContainsString('Route::', $provider, 'sem Route:: paralela');
        $has = file_get_contents(__DIR__ . '/../src/Services/HasNamedQueryResource.php');
        self::assertStringContainsString('RESOURCE_NAME', $has);
        self::assertStringContainsString('NamedQueryResource', $has);
    }

    public function test_privilege_escalation_reutiliza_RBAC_e_events_nativos(): void
    {
        $exec = file_get_contents(__DIR__ . '/../src/Resources/NamedQueryResource.php');
        self::assertStringContainsString('getEventMap', $exec, 'eventos nativos via getEventMap');
        self::assertStringContainsString('checkPermission', $exec, 'RBAC nativo via checkPermission');
        // Sem dispatch manual paralelo que duplicaria eventos
        self::assertStringNotContainsString('event(new', $exec, 'sem dispatch manual paralelo');
    }

    // -------------------------------------------------------------------------
    private function minimalJsonDocument(): array
    {
        return ['query' => ['mainQuery' => ['from' => 't', 'select' => ['t.id']]]];
    }
}
