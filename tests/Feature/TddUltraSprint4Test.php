<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TDD ULTRA Sprint 4 RED 15 antes de Wave4 conforme AGENTS.md:4
 * Wave4 paralela READY: 95(admin-ui OpenAPI) + 100(spike ADR SGC) — arquivos disjuntos, paralelo seguro
 * Wave5 BLOCKED: 96-99 dependem 95, 101-103 dependem 100 — só após Wave4 GREEN
 * Rodar RED: docker run --rm --network qb-net -v "$PWD:/app" -w /app qb-validate-php:8.3 vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint4 -> 15/15 FAIL
 * Rodar GREEN após EW-03: mesmo comando -> 15/15 PASS + --testsuite "Yamaha Extensions" 65/65
 * Fallback: docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint4
 */
class TddUltraSprint4Test extends TestCase
{
    public function test_rq050_openapi_dynamic_per_service(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/OpenApi/QueryRouteOpenApiService.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'getRoutes'), 'RQ-050: QueryRouteOpenApiService deve expor getRoutes dinâmico por service');
        self::assertTrue(str_contains($content, 'buildPathItem'), 'RQ-050: deve construir PathItem por NamedQuery');
        self::assertTrue(str_contains($content, 'api/v1'), 'RQ-050: rota deve mapear /api/v1/_query/{name}');
        self::assertTrue(false, 'TDD RED RQ-050: falta QueryRouteOpenApiService getRoutes/buildPathItem api/v1');
    }

    public function test_rq050_openapi_parameters_from_definitions(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/OpenApi/QueryRouteOpenApiService.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'buildParameters'), 'RQ-050: deve gerar parameters a partir de definitions');
        self::assertTrue(str_contains($content, 'parameters'), 'RQ-050: deve ler NamedQueryRevision parameters');
        self::assertTrue(str_contains($content, 'NamedQueryRevision') || str_contains($content, 'FilterGroup'), 'RQ-050: deve mapear FilterGroup/parameters de NamedQueryRevision');
        self::assertTrue(false, 'TDD RED RQ-050: falta buildParameters lendo FilterGroup/parameters de NamedQueryRevision');
    }

    public function test_rq050_openapi_security_schemes(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/OpenApi/OpenApiConfig.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'securitySchemes'), 'RQ-050: OpenApiConfig deve expor securitySchemes');
        self::assertTrue(str_contains($content, 'clientSecret') || str_contains($content, 'client_secret'), 'RQ-050: deve declarar clientSecret scheme');
        self::assertTrue(str_contains($content, 'clientKey') || str_contains($content, 'client_key'), 'RQ-050: deve declarar clientKey scheme');
        self::assertTrue(false, 'TDD RED RQ-050: falta OpenApiConfig securitySchemes clientSecret/clientKey');
    }

    public function test_rq051_admin_list_query_catalog(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Resources/NamedQueryAdminResource.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'handleGET'), 'RQ-051: NamedQueryAdminResource deve implementar handleGET');
        self::assertTrue(str_contains($content, 'service_id'), 'RQ-051: listagem deve expor service_id');
        self::assertTrue(str_contains($content, 'is_active'), 'RQ-051: listagem deve expor is_active + name/description');
        self::assertTrue(str_contains($content, 'description'), 'RQ-051: listagem deve expor description');
        self::assertTrue(false, 'TDD RED RQ-051: falta NamedQueryAdminResource handleGET catalog service_id/name/description/is_active');
    }

    public function test_rq052_publish_revision_lifecycle(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'function publish'), 'RQ-052: NamedQueryRepository deve expor publish()');
        self::assertTrue(str_contains($content, 'lockForUpdate'), 'RQ-052: publish deve usar lockForUpdate para concorrência');
        self::assertTrue(str_contains($content, 'assertReadOnly'), 'RQ-052: publish deve revalidar assertReadOnly');
        self::assertTrue(str_contains($content, 'assertSupportedForServiceType'), 'RQ-052: publish deve checar assertSupportedForServiceType');
        self::assertTrue(false, 'TDD RED RQ-052: falta publish() lockForUpdate + assertReadOnly + assertSupportedForServiceType');
    }

    public function test_rq052_budgets_enforced_on_publish(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        $budgetsPath = __DIR__ . '/../../docs/architecture/budgets.md';
        $budgetsContent = file_exists($budgetsPath) ? file_get_contents($budgetsPath) : '';
        self::assertTrue(str_contains($content, 'validateDefinition'), 'RQ-052: publish deve validar via validateDefinition');
        self::assertTrue(str_contains($content, 'budgets'), 'RQ-052: validateDefinition deve enforçar budgets hierárquicos');
        self::assertTrue(str_contains($budgetsContent, 'DEFAULT_BUDGETS'), 'RQ-052: budgets.md deve documentar DEFAULT_BUDGETS');
        self::assertTrue(false, 'TDD RED RQ-052: falta validateDefinition budgets + budgets.md DEFAULT_BUDGETS');
    }

    public function test_rq053_preview_diagnosticos(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/EnvelopeTranslator.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'statusToErroCode') || str_contains($content, 'toLegacyError'), 'RQ-053: EnvelopeTranslator deve expor statusToErroCode/toLegacyError');
        self::assertTrue(str_contains($content, '1400'), 'RQ-053: deve mapear 1400 (400)');
        self::assertTrue(str_contains($content, '5504'), 'RQ-053: deve mapear 5504 (504)');
        self::assertTrue(str_contains($content, 'preview') || str_contains($content, 'dry-run') || str_contains($content, 'dry_run'), 'RQ-053: preview dry-run deve usar envelope de erro');
        self::assertTrue(false, 'TDD RED RQ-053: falta EnvelopeTranslator toLegacyError/statusToErroCode 1400/5504 + preview dry-run');
    }

    public function test_rq054_import_export_invalidacao(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'ALIASES') || str_contains($content, 'SECRET_ALIASES'), 'RQ-054: LegacyHeaderMiddleware deve expor ALIASES');
        self::assertTrue(str_contains($content, 'x-client-'), 'RQ-054: deve mapear x-client-* headers');
        self::assertTrue(str_contains($content, 'ServiceModifiedEvent') || str_contains($content, 'Cache::tags') || str_contains(file_exists(__DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php') ? file_get_contents(__DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php') : '', 'Cache::tags'), 'RQ-054: invalidação deve usar ServiceModifiedEvent/Cache::tags');
        self::assertTrue(false, 'TDD RED RQ-054: falta LegacyHeaderMiddleware ALIASES x-client-* + ServiceModifiedEvent Cache::tags');
    }

    public function test_rq060_adr_sgc_exists(): void
    {
        $path = __DIR__ . '/../../docs/architecture/adr-sgc.md';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(file_exists($path), 'RQ-060: docs/architecture/adr-sgc.md:1 deve existir');
        self::assertTrue(str_contains($content, 'SGC') || str_contains($content, 'sgc'), 'RQ-060: ADR deve congelar lifecycle SGC');
        self::assertTrue(str_contains(strtolower($content), 'freeze') || str_contains($content, 'lifecycle'), 'RQ-060: ADR deve documentar freeze lifecycle');
        self::assertTrue(false, 'TDD RED RQ-060: falta adr-sgc.md freeze lifecycle SGC');
    }

    public function test_rq060_adr_decides_serviceconfig_vs_secretstore(): void
    {
        $path = __DIR__ . '/../../docs/architecture/adr-sgc.md';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'ServiceConfig'), 'RQ-060: ADR deve decidir ServiceConfig');
        self::assertTrue(str_contains($content, 'SecretStore'), 'RQ-060: ADR deve decidir SecretStore');
        self::assertTrue(str_contains($content, 'sgc-connection-id') || str_contains($content, 'sgc_connection'), 'RQ-060: ADR deve definir sgc-connection-id');
        self::assertTrue(false, 'TDD RED RQ-060: falta ADR ServiceConfig vs SecretStore + sgc-connection-id');
    }

    public function test_rq061_sgc_resolver_fallback(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Services/SgcConnectionClient.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        self::assertTrue(str_contains($content, 'getConexaoById'), 'RQ-061: SgcConnectionClient deve expor getConexaoById');
        self::assertTrue(str_contains($content, 'SOAP') || str_contains(strtolower($content), 'soap'), 'RQ-061: deve usar SOAP');
        self::assertTrue(str_contains($content, 'validateConfiguration'), 'RQ-061: deve validar validateConfiguration');
        self::assertTrue(str_contains($content, '1MB') || str_contains($content, '1048576') || str_contains($content, 'BODY'), 'RQ-061: deve enforçar BODY limit 1MB');
        self::assertTrue(false, 'TDD RED RQ-061: falta SgcConnectionClient getConexaoById SOAP + validateConfiguration + BODY 1MB');
    }

    public function test_rq062_dataset_service_resolution(): void
    {
        $path = __DIR__ . '/../../extensions/df-named-query/src/Services/HasNamedQueryResource.php';
        $content = file_exists($path) ? file_get_contents($path) : '';
        $pgPath = __DIR__ . '/../../extensions/df-named-query/src/Services/QueryPostgreSql.php';
        $pgContent = file_exists($pgPath) ? file_get_contents($pgPath) : '';
        self::assertTrue(str_contains($content, 'service_id') || str_contains($pgContent, 'service_id'), 'RQ-062: dataset deve resolver via service_id FK');
        self::assertTrue(str_contains($content, 'HasNamedQueryResource') || str_contains($pgContent, 'QueryPostgreSql'), 'RQ-062: HasNamedQueryResource/QueryPostgreSql deve existir');
        self::assertTrue(!str_contains($content, 'jdbc_url') && !str_contains($pgContent, 'jdbc_url'), 'RQ-062: não deve duplicar URL/jdbc_url');
        self::assertTrue(false, 'TDD RED RQ-062: falta HasNamedQueryResource/QueryPostgreSql service_id FK sem duplicar URL');
    }

    public function test_rq063_circuit_breaker(): void
    {
        $path = __DIR__ . '/../../docs/architecture/credential-migration.md';
        $content = file_exists($path) ? file_get_contents($path) : '';
        $repoPath = __DIR__ . '/../../extensions/df-named-query/src/Repositories/NamedQueryRepository.php';
        $repoContent = file_exists($repoPath) ? file_get_contents($repoPath) : '';
        self::assertTrue(str_contains($repoContent, 'FORBIDDEN_CREDENTIAL_FIELDS'), 'RQ-063: NamedQueryRepository deve expor FORBIDDEN_CREDENTIAL_FIELDS');
        self::assertTrue(str_contains(strtolower($content), 'circuit') || str_contains(strtolower($repoContent), 'circuit'), 'RQ-063: deve documentar circuit breaker');
        self::assertTrue(str_contains(strtolower($content), 'open') || str_contains(strtolower($repoContent), 'open'), 'RQ-063: deve tratar estado open');
        self::assertTrue(false, 'TDD RED RQ-063: falta credential-migration + FORBIDDEN_CREDENTIAL_FIELDS + circuit breaker/open');
    }

    public function test_sprint4_e5_traceability(): void
    {
        $invPath = __DIR__ . '/../../docs/architecture/inventory-api-query-contract.md';
        $invContent = file_exists($invPath) ? file_get_contents($invPath) : '';
        $defs = glob(__DIR__ . '/../../extensions/df-named-query/database/definitions/*.json');
        $rbacPath = __DIR__ . '/../../docs/architecture/rbac.md';
        $budgetsPath = __DIR__ . '/../../docs/architecture/budgets.md';
        self::assertTrue(file_exists($invPath), 'Sprint4 E5: inventory-api-query-contract.md deve existir');
        self::assertTrue(is_array($defs) && count($defs) >= 7, 'Sprint4 E5: 7 definitions *.json devem existir');
        self::assertTrue(file_exists($rbacPath) && file_exists($budgetsPath), 'Sprint4 E5: rbac.md + budgets.md devem existir');
        self::assertTrue(str_contains($invContent, 'api-query') || str_contains($invContent, 'contract'), 'Sprint4 E5: inventory deve rastrear contrato');
        self::assertTrue(false, 'TDD RED Sprint4 E5: falta traceability inventory + 7 definitions + rbac + budgets');
    }

    public function test_sprint4_wave4_guardrail_qb_net(): void
    {
        $ciPath = __DIR__ . '/../../docs/ci-matrix.md';
        $ciContent = file_exists($ciPath) ? file_get_contents($ciPath) : '';
        $spPath = __DIR__ . '/../../extensions/df-named-query/src/ServiceProvider.php';
        $spContent = file_exists($spPath) ? file_get_contents($spPath) : '';
        self::assertTrue(str_contains($ciContent, 'qb-net'), 'Wave4 guardrail: ci-matrix.md deve documentar qb-net');
        self::assertTrue(str_contains($ciContent, 'qb-pg') && str_contains($ciContent, 'qb-mssql'), 'Wave4 guardrail: ci-matrix deve cobrir qb-pg/qb-mssql');
        self::assertTrue(str_contains(strtolower($spContent), 'pgsql_query'), 'Wave4 guardrail: ServiceProvider deve registrar pgsql_query');
        self::assertTrue(!str_contains($spContent, 'sticky') || str_contains(strtolower($ciContent), 'sem sticky') || str_contains($ciContent, 'sticky'), 'Wave4 guardrail: pgsql_query sem sticky');
        self::assertTrue(false, 'TDD RED Wave4 guardrail: falta qb-net qb-pg/qb-mssql + ServiceProvider pgsql_query sem sticky');
    }
}
