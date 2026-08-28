<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TDD ULTRA — Sprint 2 RED suite (M1-M2: E2+E3 / RQ-020→035)
 * 15 testes escritos ANTES da implementação. Todos devem falhar (RED)
 * até as IAs entregarem Sprint 2 por fila Agent.
 * Rodar: docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit --testsuite Feature --filter TddUltraSprint2
 */
class TddUltraSprint2Test extends TestCase
{
    // --- RQ-020: service_id sem duplicar URL/user/senhas -----------------
    public function test_rq020_service_reference_stores_service_id_not_url(): void
    {
        $repo = __DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php';
        $content = file_exists($repo) ? file_get_contents($repo) : '';
        // Deve persistir service_id FK, não jdbc_url / username / password duplicados
        $hasServiceId = str_contains($content, 'service_id');
        $hasNoUrlDup = !preg_match('/jdbc_url|JDBC_URL|jdbcUrl/i', $content);
        $this->assertTrue($hasServiceId && $hasNoUrlDup, 'TDD RED RQ-020: NamedQuery deve referenciar service_id sem duplicar URL/credentials');
        // Força RED até RQ-020 fechado com prova de rename/disable/delete sem duplicar
        $this->assertTrue(false, 'TDD RED RQ-020: falta prova de rename/disable/delete sem duplicar credencial (ver inventory-api-query-contract.md §2)');
    }

    // --- RQ-021: abstração de capacidades de dialeto ----------------------
    public function test_rq021_dialect_capabilities_queryable(): void
    {
        $candidates = [
            __DIR__ . '/../../extensions/df-named-query/src/Services/DialectCapabilities.php',
            __DIR__ . '/../../extensions/df-named-query/src/Query/DialectCapabilities.php',
        ];
        $found = false;
        foreach ($candidates as $p) { if (file_exists($p)) { $found = true; break; } }
        $this->assertTrue($found, 'TDD RED RQ-021: abstração de capabilities deve existir e ser consultável pela UI/engine');
        $this->assertTrue(false, 'TDD RED RQ-021: falta expor bind/quoting/metadata/timeout/paginacao por conector');
    }

    public function test_rq021_unsupported_feature_blocks_publish(): void
    {
        // publish deve falhar se capability não suportada pelo driver do service_id
        $compiler = __DIR__ . '/../../extensions/df-named-query/src/Query/NamedSqlCompiler.php';
        $content = file_exists($compiler) ? file_get_contents($compiler) : '';
        $hasBlock = str_contains($content, 'capabilit') || str_contains($content, 'supports');
        $this->assertTrue($hasBlock, 'TDD RED RQ-021: publish deve bloquear feature não suportada');
        $this->assertTrue(false, 'TDD RED RQ-021: sem gate publish capability-aware');
    }

    // --- RQ-022: SQL Server ------------------------------------------------
    public function test_rq022_sqlsrv_service_type_registered(): void
    {
        $sp = __DIR__ . '/../../extensions/df-sqlsrv/src/ServiceProvider.php';
        $content = file_exists($sp) ? file_get_contents($sp) : '';
        $this->assertStringContainsString('sqlsrv', strtolower($content), 'TDD RED RQ-022: ServiceProvider sqlsrv');
        // deve derivar metadata de sys.* independente (não emular)
        $schema = __DIR__ . '/../../extensions/df-sqlsrv/src/Database/Schema/SqlServerSchema.php';
        $this->assertFileExists($schema, 'TDD RED RQ-022: SqlServerSchema via sys.*');
        $this->assertTrue(false, 'TDD RED RQ-022: falta provar binds/schemas/identity/rowversion/datetimeoffset/GUID/result sets + encrypt defaults + sem redistribuir ODBC');
    }

    // --- RQ-023: Oracle ----------------------------------------------------
    public function test_rq023_oracle_service_type_registered(): void
    {
        $sp = __DIR__ . '/../../extensions/df-oracle/src/ServiceProvider.php';
        $content = file_exists($sp) ? file_get_contents($sp) : '';
        $this->assertStringContainsString('oracle', strtolower($content), 'TDD RED RQ-023');
        $schema = __DIR__ . '/../../extensions/df-oracle/src/Database/Schema/OracleSchema.php';
        $this->assertFileExists($schema, 'TDD RED RQ-023: OracleSchema via ALL_*/USER_*');
        $this->assertTrue(false, 'TDD RED RQ-023: falta provar service/SID/schema/NUMBER/DATE/LOB/sequence/synonym/ref cursor + Instant Client como dependência externa');
    }

    // --- RQ-024: Informix --------------------------------------------------
    public function test_rq024_informix_blocked_without_csdk_and_uses_systables(): void
    {
        $conn = __DIR__ . '/../../extensions/df-informix/src/Database/InformixConnector.php';
        $schema = __DIR__ . '/../../extensions/df-informix/src/Database/Schema/InformixSchema.php';
        $this->assertFileExists($conn, 'TDD RED RQ-024: InformixConnector');
        $this->assertFileExists($schema, 'TDD RED RQ-024: InformixSchema via systables');
        $c = file_exists($schema) ? file_get_contents($schema) : '';
        $this->assertStringContainsString('systables', strtolower($c), 'TDD RED RQ-024: deve usar systables/syscolumns');
        $this->assertTrue(false, 'TDD RED RQ-024: falta provar PHP 8.3+Laravel 13, serial/LVARCHAR/TEXT/BYTE/owner/transação, Pymac reais e CSDK não redistribuído');
    }

    // --- RQ-025: PostgreSQL ------------------------------------------------
    public function test_rq025_postgres_shared_connection_lifecycle(): void
    {
        // mudança de credencial deve invalidar em todos os nós, sem sticky session
        $sp = __DIR__ . '/../../extensions/df-named-query/src/ServiceProvider.php';
        $content = file_exists($sp) ? file_get_contents($sp) : '';
        $this->assertStringContainsString('pgsql', strtolower($content), 'TDD RED RQ-025: pgsql wiring');
        $this->assertTrue(false, 'TDD RED RQ-025: falta provar PTG no alvo, invalidação cluster-wide, stateless e pool configurável');
    }

    // --- RQ-030: schema versionado e compilador ----------------------------
    public function test_rq030_tokenizer_ignores_strings_and_comments(): void
    {
        $compiler = __DIR__ . '/../../extensions/df-named-query/src/Query/NamedSqlCompiler.php';
        $content = file_exists($compiler) ? file_get_contents($compiler) : '';
        // Regex deve ter SKIP para '...', "..." , -- , /* */
        $hasSkip = str_contains($content, 'SKIP') && str_contains($content, "'[^']");
        $this->assertTrue($hasSkip, 'TDD RED RQ-030: tokenizer deve ignorar strings e comentários');
        $this->assertTrue(false, 'TDD RED RQ-030: falta provar param repetido + policy de ausentes versionada + sem alterar identificadores');
    }

    public function test_rq030_repeated_parameter_bound_correctly(): void
    {
        // Placeholder repetido :vin deve gerar binds distintos :vin_0 :vin_1
        $this->assertTrue(class_exists(\Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler::class) || true, 'class exists check');
        $this->assertTrue(false, 'TDD RED RQ-030: param repetido deve ligar corretamente sem tocar literais');
    }

    // --- RQ-031: read-only -------------------------------------------------
    public function test_rq031_readonly_defense_in_depth(): void
    {
        $this->assertTrue(false, 'TDD RED RQ-031: parser e driver devem bloquear DML/DDL/multi-statement/escapes SELECT-only, corpus comments/terminadores, mutação futura exige modo explícito, falha no publish');
    }

    // --- RQ-032: DSL JSON --------------------------------------------------
    public function test_rq032_json_dsl_allowlists_and_legacy_import(): void
    {
        $this->assertTrue(false, 'TDD RED RQ-032: JSON schema deve allowlist operators/joins/valueTypes, validar admin, usar prepared, importar legados sem mudança semântica');
    }

    // --- RQ-033: filter groups e subqueries --------------------------------
    public function test_rq033_filter_groups_and_limited_subqueries(): void
    {
        $this->assertTrue(false, 'TDD RED RQ-033: grupo parcial →400, string preserva zeros, IN/subquery consomem budgets, bind/merge keys em fixtures');
    }

    // --- RQ-034: normalização ----------------------------------------------
    public function test_rq034_normalization_by_executor_and_db(): void
    {
        $this->assertTrue(false, 'TDD RED RQ-034: lowercase parsing, labels preservados, aliases pontilhados, golden null/unicode/binary/precisão por executor/banco');
    }

    // --- RQ-035: portar 8 consultas ----------------------------------------
    public function test_rq035_all_eight_queries_ported_and_certified(): void
    {
        $defs = glob(__DIR__ . '/../../extensions/df-named-query/database/definitions/*.json');
        $this->assertGreaterThanOrEqual(7, count($defs), 'TDD RED RQ-035: definitions presentes');
        $this->assertTrue(false, 'TDD RED RQ-035: falta certificar as 8 consultas (inventário QB_* + gq-inspecao I/U) em comparação real');
    }

    // --- E2+E3: espelhamento épico -----------------------------------------
    public function test_sprint2_epics_e2_e3_traceability(): void
    {
        // Project 4 Iteration Sprint 2 deve ter 14 itens com Agent correto
        $this->assertTrue(false, 'TDD RED Sprint2: E2(db-connector) e E3(query-engine) devem estar em Sprint 2 com Agent/Ready/Priority corretos no board');
    }
}
