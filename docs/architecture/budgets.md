# RQ-041 — Budgets Hierárquicos Cluster-Safe

> **Status:** Implementado — 2026-08-28
> **Fontes:** `extensions/df-named-query/src/Query/JsonQueryCompiler.php:35-44` (`DEFAULT_BUDGETS`), `extensions/df-named-query/src/Query/QueryExecutionBudget.php:1-199`, `extensions/df-named-query/src/Resources/NamedQueryResource.php:204-330` (`execute`/`maxRows`/`collectRows`), `vendor/dreamfactory/df-database/src/Services/BaseDbService.php:99-105` (`getMaxRecordsLimit`), `api-query/src/main/java/com/querybuilder/config/SqlExecutionLimits.java:27-48,94-97` e `QueryExecutionBudget.java:39-75`.

## 1. Defaults preservados

`JsonQueryCompiler::DEFAULT_BUDGETS` preserva `SqlExecutionLimits` Java:

```php
public const DEFAULT_BUDGETS = [
    'max_rows' => 10000,
    'max_parameters' => 100,
    'max_parameter_value_length' => 4096,
    'max_in_items' => 100,
    'max_subquery_executions' => 500,
    'max_total_rows' => 10000,
    'max_total_bytes' => 10485760, // 10 MiB
    'query_timeout_seconds' => 45,
    'request_timeout_seconds' => 45,
    'timeout_seconds' => 45,
];
```

`NamedQueryRepository` valida `budgets.max_rows` positivo (`Repositories/NamedQueryRepository.php:396-401`). `QueryExecutionBudget` exige timeout/maxTotalRows/maxTotalBytes positivos.

## 2. Consumo de budgets

- `JsonQueryCompiler::validateRequestParameters` (`src/Query/JsonQueryCompiler.php:624-632`) — `count($params) > max_parameters` → 400; itera `validateValue`.
- `validateValue` (`634-639`) — `mb_strlen(value) > max_parameter_value_length (4096)` → 400.
- `validateInItemCount` (`648-653`) — `itemCount > max_in_items (100)` → 400.
- `validateSubqueryExecutions` (`655-660`) — `executions > max_subquery_executions (500)` → 400 (`compileWithSubQueries:306-311`).
- `validateBindCount` (`641-646`) — `bindCount > max_parameters (100)` → 400.
- `NamedQueryResource::maxRows` (`src/Resources/NamedQueryResource.php:340-348`) — `min((int) budgets.max_rows, parent->getMaxRecordsLimit((int) max_rows))`; fallback `getMaxRecordsLimit()` quando ausente/inválido.
- `QueryExecutionBudget::acceptRow/verifyFinalBody` — agregado `max_total_rows`/`max_total_bytes` com `estimateJsonBytes`.

## 3. Deadline reduz timeout de statements

`QueryExecutionBudget::statementTimeoutSeconds(maximumSeconds)` (`src/Query/QueryExecutionBudget.php:66-77`) espelha `QueryExecutionBudget.java:45-54`: `min(maximumSeconds, ceil(remainingNanos/1s))`. `applyToConnection` (`172-198`):

- `PDO::ATTR_TIMEOUT = timeout` (quando deadline fornecido)
- `pgsql`: `SET LOCAL statement_timeout = '${ms}ms'`
- `mysql`: `SET SESSION MAX_EXECUTION_TIME=${ms}`

`NamedQueryResource::execute` (`228-236`) instancia `QueryExecutionBudget($budgets, $start)`, chama `checkDeadline()` antes da query e `applyToConnection(..., query_timeout_seconds)`. `collectRows` chama `checkDeadline()` + `acceptRow` por linha e `verifyFinalBody` ao final (`349-365`).

Inspiração: `SqlExecutionLimits.applyTo(PreparedStatement, QueryExecutionBudget)` (`SqlExecutionLimits.java:94-97`) — `statement.setQueryTimeout(budget.statementTimeoutSeconds(queryTimeoutSeconds))`.

## 4. Cluster-safe (sem sticky)

Budgets são **lidos direto do DB por request**, sem cache local retido:

- `NamedQuery::forService(serviceId)->where(name)->where(is_active)->with('publishedRevision')->first()` (`NamedQueryResource.php:211-215`) — `budgets` vem de `named_query_revision.budgets` (JSON) persistido via `NamedQueryRepository.createRevision`.
- Não há `static::$budgetsCache`, `Cache::remember` ou `ConcurrentHashMap` para budgets de execução; cada request resolve `array_merge(DEFAULT_BUDGETS, revision.budgets)` (`230`).
- Invalidação de conexão: `docs/architecture/postgres-qualification.md:4` — `Service::setConfigAttribute` + `ServiceModifiedEvent` + `DatabaseManager::disconnect`; cache distribuído via `config/cache.php` (`database`/`redis`); `BaseDbService::getMaxRecordsLimit` lê `config('database.max_records_returned')` + `service.maxRecords` (§9-10: envelopes/budgets demandam hierarquia `revision.budgets.max_rows` vs `service.maxRecords`/`DB_MAX_RECORDS_RETURNED`).
- Conexão por request: `SqlDb::initializeConnection` + `__destruct disconnect` (`postgres-qualification.md:58-59`); sem sticky session (VIP `172.31.18.240` → dois nodes), `DistributedCache` + system DB são única fonte de verdade.

Contraste cluster-unsafe (não usado): cache local de budgets com TTL ou `static` sem invalidação distribuída quebraria RQ-041 em cluster (nó A com budget antigo após publish no nó B). Aqui, publish via `NamedQueryRepository::publish` (`lockForUpdate` + `published_revision_id`) é imediatamente visível a todos os nós no próximo `SELECT`.

## 5. Envelopes relacionados (§9-10)

§9-10 `inventory-api-query-contract.md:9-10` — budgets limitam `result_count`/`result` e `erroCode` em 400/504; `QueryExecutionBudget` verifica deadline antes/depois e reduz timeout remanescente.
