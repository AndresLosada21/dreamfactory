# RQ-045 — Threat Model STRIDE e Suite de Abuso — Named Queries (`_query`)

> **Status:** Implementado — 2026-08-28
> **Escopo:** `extensions/df-named-query` — recurso filho `_query` (`pgsql_query`/`oracle`/`sqlsrv`/`informix`) + admin `system/named_query`.
> **Fontes canônicas:** `docs/architecture/inventory-api-query-contract.md:1` (freeze 8 queries), `docs/architecture/rbac.md:1` (RBAC nativo), `extensions/df-named-query/src/Query/NamedSqlCompiler.php:60` (`assertReadOnly`), `extensions/df-named-query/src/Repositories/NamedQueryRepository.php:356` (`validateDefinition`), `extensions/df-named-query/src/Resources/NamedQueryResource.php:18` + `extensions/df-named-query/src/Resources/NamedQueryAdminResource.php:15` (planos isoláveis), `docs/ci-matrix.md:1` (campo real), `extensions/df-named-query/tests/AbuseSuiteTest.php:1`.
> **Exige:** High e Critical só com correção ou aceite formal assinado antes de cutover (ver §10).

---

## 1. Objetivo e fronteira

Este documento fecha RQ-045: modelar ameaças STRIDE para **Named Queries** e provar mitigação com **suites de abuso separadas para valores vs identificadores**, **admin plane isolável**, **egress por allowlist** e **RBAC/events nativos reutilizados** — sem autorização paralela.

**Em escopo:** `service_id FK` → `named_query` / `named_query_revision` → `published_revision_id` → `GET|POST /api/v2/{service}/_query/{name}` com binds tipados e budgets hierárquicos cluster-safe.

**Fora de escopo (deprecado/adaptador):** `api-query/config/query/*.json` legado JSON DSL e `api-query/config/data-set/sgc-connection-id` + `SgcConnectionClient.java:25` — mantidos só como adaptador interno pós-RBAC (`inventory-api-query-contract.md:410` decisão Deprecate). SGC SOAP não é reintroduzido em `_query`.

---

## 2. Diagrama de fluxo de dados (DFD)

```
[Ator admin] --(A)--> [system/named_query] --(B)--> [NamedQueryRepository.validateDefinition]
                         | publish gate                   |  + NamedSqlCompiler.assertReadOnly
                         v                                v
               [named_query_revision.sql / parameters / output_schema / budgets]
                         |
                         | C = published_revision_id (is_active=true)
                         v
[Ator execução] --(D)--> [/_query/{name}] --(E)--> [NamedQueryResource.execute]
                         | RBAC nativo Session::*       |  + compile(:param -> :param_N) + coerceValue
                         | listAccessComponents         |  + QueryExecutionBudget + maxRows
                         v                                v
                                                 [DB service_id FK (pgsql_query|oracle|sqlsrv|informix)]
                         |
                         +--> [NamedQueryAudit sanitizado sem SQL/bind/secret]
                         +--> [Eventos nativos pre/post/final via getEventMap]
```

- **A:** `POST /api/v2/system/named_query` (cria draft) e `PATCH /api/v2/system/named_query/{id}` (`revise`/`disable`/`publish`) — `NamedQueryAdminResource.php:15`.
- **D:** `GET|POST /api/v2/{service}/_query/{name}` — `NamedQueryResource.php:18`.
- **Egress:** apenas via `service_id FK`; sem `fetch` arbitrário do usuário (ver §6).

---

## 3. STRIDE por ameaça — resumo executivo

| # | Ameaça (STRIDE) | Vetor em `_query` | Severidade | Mitigação canônica | Teste (AbuseSuite) | Estado |
|---|---|---|---|---|---|---|
| T-01 | **S**poofing / **T**ampering — **Injection (valores)** via `:param` | `vin=' OR 1=1 --` em `GET /_query/{name}?vin=...` | **Critical** | Binds únicos `:param_N` + `replaceParameters` com `(*SKIP)(*F)` em literais/comentários + `coerceValue` tipada | `AbuseSuiteTest.php: injectionValores*` | Mitigado |
| T-02 | **T**ampering — **Injection (identificadores)** via SQL/alias | `SELECT *; DELETE ...` ou `FOR UPDATE`, `SELECT INTO`, DDL em definição | **Critical** | `assertReadOnly` (SELECT/WITH inicial, denylist 30+ keywords, `;`, `FOR UPDATE/SHARE`, `LOCK IN SHARE MODE`, `SELECT INTO`) + publish gate revalida | `AbuseSuiteTest.php: injectionIdentificadores*` | Mitigado |
| T-03 | **S**poofing — **Auth bypass / IDOR** por `_query/{name}` | `GET /_query/outraQuery` sem verb_mask | **Critical** | `Session::checkServicePermission`/`getServicePermissions` por componente concreto `_query/{name}`; `listAccessComponents` filtra; sem `parallelAuth` | `AbuseSuiteTest.php: authBypass*` | Mitigado |
| T-04 | **I**nformation Disclosure / **E**levation — **SSRF / Egress aberto** | `jdbcUrl=http://169.254.169.254/` ou `sgc endpoint` arbitrário via definição | **High** | Allowlist de `service.type ∈ {pgsql_query,oracle,sqlsrv,informix}` + `validateDefinition` bloqueia `jdbc*/url/password/.../credential` + egress só via `service_id FK` | `AbuseSuiteTest.php: ssrfEgress*` | Mitigado |
| T-05 | **T**ampering — **XXE** em XML | `<!DOCTYPE ... ENTITY>` via payload JSON/XML | **Low** | Não aplicável: `_query` não parseia XML de usuário; `SgcConnectionClient` legado usava `FEATURE_SECURE_PROCESSING + disallow-doctype-decl` (herdado como defesa, não reexposto) | `AbuseSuiteTest.php: xxe*` | N/A mitigado |
| T-06 | **D**enial of Service — **DoS** por volume/tempo | `max_rows=999999`, `IN` com 10k itens, body 100 MiB, deadline 45s | **High** | Budgets hierárquicos cluster-safe (`DEFAULT_BUDGETS` 10k/45s/10MiB) + `maxRows=min(budgets.max_rows, getMaxRecordsLimit)` + `QueryExecutionBudget` (deadline→statement_timeout) | `AbuseSuiteTest.php: dos*` | Mitigado |
| T-07 | **T**ampering — **Definições maliciosas / publish gate** | Draft com `INSERT/DELETE/;` ou `budgets.max_rows=-1` tenta publicar | **High** | `validateDefinition` (struct+allowlist campos) + `assertReadOnly` em `create/revise` + revalida em `publish` + `assertSupportedForServiceType` (dialeto) + `lock_version` otimista | `AbuseSuiteTest.php: definicoesMaliciosas*` | Mitigado |
| T-08 | **I**nformation Disclosure / **T**ampering — **XSS via output_schema** | `output_schema=[{"name":"<script>"}]` reflete no JSON | **Medium** | `output_schema` validado como `array` de objetos; `ResourcesWrapper::cleanResources` + `json_encode` escapa; Angular DF admin usa sanitização; audit nunca loga SQL/bind | `AbuseSuiteTest.php: xss*` | Mitigado |
| T-09 | **E**levation — **Privilege escalation admin vs execução** | Token de execução chama `system/named_query` ou publica revisão | **Critical** | Planos isoláveis: admin `SystemResourceManager` (`system/named_query`) vs execução `ServiceManager` (`_query`) — RBAC distinto (`ServiceProvider.php:34,55`); sem rota paralela | `AbuseSuiteTest.php: privilegeEscalation*` | Mitigado |

> **Regra de cutover:** linhas com **Critical/High pendentes** (coluna Estado ≠ Mitigado) exigem correção **ou aceite formal assinado** (§10) antes do cutover. Não há bypass por `parallelAuth` ou `checkPermission=false`.

---

## 4. Detalhamento por categoria

### 4.1 Injection — valores vs identificadores (suites separadas)

**Por que separar:** valores vêm do **usuário em runtime** (`?vin=...` → bind tipado); identificadores vêm da **definição versionada** (SQL e output_schema) escrita por admin e validada no publish gate. Controles e testes são distintos — não podem compartilhar suite.

#### 4.1.1 Suite `Valores` — `AbuseSuiteTest::test_injection_*_valores`

- **Mitigação:**
  - `NamedSqlCompiler.php:38-54` — `compile(sql, declarations, values)` cria bindings únicos `:name_N` via `replaceParameters` com regex `(*SKIP)(*F)` que **ignora** `:param` dentro de `'literais'`, `"identificadores"`, `-- comentários` e `/* blocos */` (`NamedSqlCompiler.php:143-149`).
  - `coerceValue:127-141` — `integer`/`number`/`boolean`/`string(scalar)` com `BadRequestException` em tipo inválido; `null` preservado.
  - `JsonQueryCompiler.php:381-414` (`buildWhereClause`) para DSL legado: `IN/NOT IN` com `validateInItemCount`, `LIKE` sem injeção de `%` automático.
- **Severidade:** Critical (SQLi clássico).
- **Teste prova:**
  ```php
  // literal não é substituído
  (new NamedSqlCompiler)->compile("SELECT ':vin' FROM t WHERE vin=:vin", [['name'=>'vin']], ['vin'=>"' OR 1=1 --"]);
  // => SELECT ':vin' FROM t WHERE vin=:vin_0  (bind isolado)
  // cast ::text não vira parâmetro
  (new NamedSqlCompiler)->compile("SELECT :vin::text", [['name'=>'vin']], ['vin'=>'x']);
  // => SELECT :vin_0::text
  ```
  Abuso extra: `vin = "1' OR '1'='1"` vira string escalar ligada, nunca interpolada.

#### 4.1.2 Suite `Identificadores` — `AbuseSuiteTest::test_injection_*_identificadores`

- **Mitigação:**
  - `NamedSqlCompiler.php:60-104` — `assertReadOnly($sql, $allowMutation=false)`:
    - Token 0 ∈ `{SELECT,WITH}` (`:71-73`).
    - Denylist 30+ keywords: `ALTER,DELETE,DROP,INSERT,UPDATE,TRUNCATE,...` (`:76-81`).
    - `;` terminador bloqueado (`:90-91`), `FOR UPDATE/SHARE` (`:93-94`), `LOCK IN SHARE MODE` (`:96-97`), `SELECT ... INTO` (`:99-101`).
    - `isExplicitMutationAllowed:106-125` exige `NAMED_QUERY_ALLOW_MUTATION` ou `config('named-query.allow_mutation')===true` — sem flag, falha (fallthrough para read-only).
  - `NamedQueryRepository.php:277-285` — `publish()` revalida `assertReadOnly` mesmo se draft passou em `validateDefinition:390`.
  - `JsonQueryCompiler.php:116-206` — `validateJoin`/`validateCondition` com allowlists `ALLOWED_JOIN_TYPES`/`ALLOWED_OPERATORS`/`ALLOWED_VALUE_TYPES` e `assertNoTerminator` (`;` em `join.table/on`, `condition.column`).
- **Severidade:** Critical.
- **Teste prova:** `testItRejectsMutatingAndMultipleStatements` (`NamedSqlCompilerTest.php:26`), `SELECT INTO` (`:46`), e no AbuseSuite: vetores `"; DELETE"`, `"FOR UPDATE"`, `"INTO archive"`.

### 4.2 Auth bypass (RBAC)

- **Mitigação reutilizada (nativa, sem paralela):**
  - Descoberta: `NamedQueryResource.php:98-124` `listAccessComponents()` + `handleGET:38-73` filtram `NamedQuery::forService()->where is_active && published_revision_id` por `!empty(getPermissions(name))` (`:107,52,71`). `BaseRestResource.php:123` → `Session::getServicePermissions(service, _query/{name})` (`Session.php:73-170` cadeia exact→wildcard→service→all).
  - Execução: `NamedQueryResource.php:218-220` `checkPermission(getAction(), resource)` antes de qualquer `first()` ou `compile`. `BaseRestResource.php:104-116` monta `path=_query/{name}` → `Session::checkServicePermission(operation, service, component, requestor)` (`Session.php:35-64` `verb & mask ==0 → ForbiddenException`).
  - Sem bypass: `NamedQueryRepository`/`NamedQueryAudit` não chamam `ServiceManager::handleRequest(..., checkPermission=false)`; `grep parallelAuth` zero no pacote (`rbac.md:29`); `HasNamedQueryResource.php:9` só registra handler.
- **Severidade:** Critical.
- **Teste:** `AbuseSuiteTest` verifica presença de `listAccessComponents+getPermissions`, `checkPermission+_query/{name}` e ausência de `parallelAuth`/`checkPermission=false`.

### 4.3 SSRF / Egress allowlist (não fetch arbitrário)

- **Mitigação:**
  - `NamedQueryRepository.php:23-30` `FORBIDDEN_CREDENTIAL_FIELDS` + `validateDefinition:360-375` bloqueia `password,passwd,pwd,secret,username,usr,host,hostname,port,database,dbname,dsn,connection_string,url,jdbc*,credential` (regex `jdbc|dsn|connection_string` e `jdbc_`/`jdbcurl`). Mensagem `Reference service_id only`.
  - `assertServiceExists:410-419` — allowlist `type ∈ [pgsql_query,oracle,sqlsrv,informix]` (`:416`); outro tipo → `BadRequestException`.
  - Egress: `NamedQueryResource.php:237-251` `getConnection()->cursor(sql, bindings)` usa **conexão do service_id FK** gerida por `ServiceManager`/`DatabaseManager`; não há `Http::get($userUrl)` nem `SgcConnectionClient` reexposto em `_query`. `ServiceProvider.php:28-53` prova egress só via `type` registrado.
  - SGC legado: `inventory-api-query-contract.md:410` linha 15 = Deprecate — `SgcResolver` futuro opcional atrás de `SecretStore`, não de usuário HTTP.
- **Severidade:** High.
- **Teste:** vetores com `jdbcUrl`, `host`, `password`, `connection_string`, `credential` em `definition` → `BadRequestException`; checa ausência de `file_get_contents(http`/`Guzzle` com URL de usuário em `src/`.

### 4.4 XXE

- **Mitigação:** `_query` não consome XML de usuário (payload é JSON `parameters` + `params_json`). O único XML do legado era `SgcConnectionClient.java:136-150` SOAP com `FEATURE_SECURE_PROCESSING` + `disallow-doctype-decl` + `ACCESS_EXTERNAL_DTD/SCHEMA=""` e `max-response-bytes 1MiB` — **não reexposto** em Named Queries. `stripLiteralsAndComments` (`NamedSqlCompiler.php:157`) não expande entidades.
- **Severidade:** Low (N/A mitigado).
- **Teste:** prova que `src/` não contém `libxml_disable_entity_loader` pendente nem `DOMDocument::loadXML($userInput)`; `grep XXE|ENTITY` zero em `_query`.

### 4.5 DoS (budgets hierárquicos cluster-safe)

- **Mitigação (preserva Java `SqlExecutionLimits.java:27-48,94-97` + `QueryExecutionBudget.java:39-75`):**
  - Defaults: `JsonQueryCompiler::DEFAULT_BUDGETS:35-47` (`max_rows 10000`, `max_parameters 100`, `max_parameter_value_length 4096`, `max_in_items 100`, `max_subquery_executions 500`, `max_total_rows 10000`, `max_total_bytes 10485760`, `query_timeout_seconds 45`).
  - Validação: `validateRequestParameters:630-638` (≤100 params), `validateValue:640-645` (≤4096), `validateInItemCount:654-659` (≤100), `validateSubqueryExecutions:661-666` (≤500), `validateBindCount:647-652` (binds ≤100).
  - Execução: `NamedQueryResource.php:243-252` `maxRows = min(budgets.max_rows, parent->getMaxRecordsLimit())` (`BaseDbService.php:99-105`); `collectRows:307-326` streaming com `QueryExecutionBudget.checkDeadline/acceptRow/verifyFinalBody`; `applyToConnection:172-194` reduz `PDO::ATTR_TIMEOUT` + `SET LOCAL statement_timeout / MAX_EXECUTION_TIME` pelo deadline restante (`statementTimeoutSeconds:66-77` = `min(max, ceil(remainingNanos/1s))`).
  - Cluster-safe: `QueryExecutionBudget.php:19-21` + `budgets.md:51-58` — `budgets` lido **direto do DB por request** (`forService()->with(publishedRevision)->first()` :211-215) sem `static/cache` retido; `publish` com `lockForUpdate` visível a todos os nós no próximo SELECT; sem sticky session (VIP `172.31.18.240`).
- **Severidade:** High.
- **Teste:** vetores excedendo cada limite → `400/504`; prova `statementTimeoutSeconds` reduz com deadline; prova leitura DB direta sem cache local.

### 4.6 Definições maliciosas (publish gate)

- **Mitigação:**
  - `validateDefinition:356-408` — bloqueia credential fields, valida `service_id`, `name` regex `^[A-Za-z][A-Za-z0-9_-]{0,127}$`, `sql` required, `description` string, `assertReadOnly(sql)`, arrays `parameters/output_schema/budgets`, `max_rows` positivo, `parameter.name` regex.
  - `publish:256-335` — revalida `assertReadOnly` com/sem `allow_mutation` (flag futura `budgets.allow_mutation`), `DialectCapabilities::assertSupportedForServiceType` bloqueia `FOR JSON`/JSON/path quando driver não suporta, `lock_version` otimista (`assertLockVersion:421-426` → `ConflictResourceException`).
  - `rename:85-88` bloqueado (`Delete and recreate`); `is_active` imutável até `disable/publish`.
- **Severidade:** High.
- **Teste:** publish com `"; DROP"`, `FOR UPDATE`, `max_rows=-1`, `jdbcUrl`, `parameter name=1bad` → falha; `lock_version` divergente → 409.

### 4.7 XSS (output_schema sanitizado)

- **Mitigação:**
  - `output_schema` validado como array de objetos com `name` string; não contém HTML — retornado via `ResourcesWrapper::cleanResources` → `json_encode` com escaping; DF admin Angular sanitiza bindings.
  - `NamedQueryAudit.php:22,105` — logs sanitizados (só `checksum/budgets/max_rows`), nunca `sql/bind/secret`.
  - `inventory-api-query-contract.md:284` envelope legado `result` com `getColumnLabel` lowercased, sem `innerHTML` arbitrário.
- **Severidade:** Medium.
- **Teste:** `output_schema` com `<script>alert(1)</script>` ou `"><svg/onload=` — servidor retorna JSON escapado, não executa; checa `response Content-Type: application/json` e ausência de `htmlspecialchars` bypass.

### 4.8 Privilege escalation (admin vs execução)

- **Mitigação (planos isoláveis):**
  - Admin: `ServiceProvider.php:34-53` registra `SystemResourceManager::addType('named_query' → NamedQueryAdminResource)` (`:47-52`) — `system/named_query` via `BaseSystemResource` (acesso `admin` role, não `service` RBAC).
  - Execução: `ServiceProvider.php:55-78` registra `ServiceManager::addType('pgsql_query' → QueryPostgreSql)` + `HasNamedQueryResource` injeta `_query` — `api/v2/{service}/_query/{name}` via `BaseRestResource` (acesso por `role.services[].component=_query/{name}` com `verb_mask`).
  - Sem rota paralela: `ServiceProvider` não chama `Route::`; `HasNamedQueryResource.php:10-18` só adiciona handler; `NamedQueryResource.php:216-220` exige permissão concreta antes de FX.
- **Severidade:** Critical.
- **Teste:** prova `ServiceProvider` registra `df.system.resource` e `df.service` separadamente; token com apenas `_query/{name}` GET não acessa `system/named_query`; e vice-versa.

---

## 5. Egress allowlist (não SSRF aberto)

| Camada | Allowlist | Bloqueio |
|---|---|---|
| Service type | `pgsql_query, oracle, sqlsrv, informix` (`NamedQueryRepository.php:416`) | Outro tipo → `400 does not support Named Queries` |
| Credential fields | nenhum `jdbc/url/host/port/password/...` em `definition` (`NamedQueryRepository.php:23-30,360-375`) | `400 must not include credential field` |
| Conexão | `service_id FK → Service::find → DatabaseManager::connection` (sem `Http::get($userSupplied)`) | Sem `fetch` arbitrário em `src/` |
| SGC | Deprecate (`inventory-api-query-contract.md:410`) — futuro `SgcResolver → SecretStore` opcional, não usuário | Sem `getConexaoById` via parâmetro HTTP em `_query` |
| Rede | Datasets apontam para hosts internos allowlisted via `ServiceConfig` (fora de `_query`) | `connector-clean-room.md:282-293` gate jurídico para OTN/EULA/ILA |

---

## 6. Severidade — escala e SLA

| Severidade | Definição | SLA pré-cutover |
|---|---|---|
| **Critical** | RCE/SQLi, auth bypass, privilege escalation total | Correção obrigatória; cutover bloqueado sem fix |
| **High** | SSRF egress, DoS sem limite, publish gate bypass | Correção ou aceite formal assinado por Security + Product |
| **Medium** | XSS refletido via schema, info disclosure não sensível | Correção ou aceite com plano de mitigação em 30 dias |
| **Low** | XXE N/A, best-practice hardening | Aceite tácito com registro |

---

## 7. Reuso de RBAC/events nativos

- **RBAC:** `Session::checkServicePermission`/`getServicePermissions` (`vendor/dreamfactory/df-core/src/Utility/Session.php:35,73`) — único enforcement; sem `parallelAuth`.
- **Events:** `NamedQueryResource.php:135-170` `getEventMap()` + ciclo nativo `PreProcessApiEvent`/`PostProcessApiEvent`/`ApiEvent` via `RestHandler` (`adr-named-query.md:56-64`); `ServiceEventMapper` para `service_event_map`.
- **Audit:** `NamedQueryAudit.php:19-67` sem SQL/bind/secret; `Cache::tags(['named_query'])` invalidação.

---

## 8. Suites de abuso — valores vs identificadores

| Suite | Arquivo | Foco | Exemplo de vetor |
|---|---|---|---|
| **Valores** | `extensions/df-named-query/tests/AbuseSuiteTest.php: valores*` | Binds tipados, literais/comments não substituídos, coerção | `vin="' OR 1=1 --"` , `p_NumMonth="6; DROP"` |
| **Identificadores** | `AbuseSuiteTest.php: identificadores*` | `assertReadOnly`, DDL/DML, `;`, `FOR UPDATE`, `INTO` | `SELECT *; DELETE ...`, `SELECT INTO archive` |

As duas suites **não compartilham** fixtures — cada uma com seu `provider` de vetores. Falha em uma não mascara a outra.

---

## 9. Como rodar

```sh
# Abuse suite isolada (extensão) — sem DB externo
php vendor/bin/phpunit --testsuite "Yamaha Extensions" --filter AbuseSuite --verbose

# Toda a extensão
php vendor/bin/phpunit --testsuite "Yamaha Extensions" --verbose

# Feature (inclui AbuseSuite espelho em tests/Feature)
php vendor/bin/phpunit --testsuite Feature --filter AbuseSuite --verbose

# Sprint 3 gate (deve passar após RQ-045)
php vendor/bin/phpunit --testsuite Feature --filter TddUltraSprint3
```

CI: `.github/workflows/ci-matrix.yml:29-67` (secret/sbom/license) + `field-*` (`postgres-15:87`, `oracle-21c:134`, `sqlserver-2022:186`, `informix-14_10:236`) — AbuseSuite roda em `ubuntu-latest` (sem vendor) e no campo real em `self-hosted`.

---

## 10. High/Critical — correção ou aceite formal

> **Regra:** qualquer achado **High** ou **Critical** com `Estado = Pendente` bloqueia `GO-LIVE` até correção ou aceite formal.

| ID | Achado | Severidade | Mitigação proposta | Estado | Aceite (se pendente) |
|---|---|---|---|---|---|
| T-01 | Injection via valores | Critical | Binds + coerceValue | Mitigado | — |
| T-02 | Injection via identificadores (DDL/;) | Critical | assertReadOnly + publish revalidação | Mitigado | — |
| T-03 | Auth bypass em `_query/{name}` | Critical | Session RBAC concreto + list filtering | Mitigado | — |
| T-09 | Privilege escalation admin↔exec | Critical | Planos isoláveis system vs _query | Mitigado | — |
| T-04 | SSRF egress via definição | High | validateDefinition + allowlist type + FK | Mitigado | — |
| T-06 | DoS budgets/timeout | High | budgets hierárquicos + deadline | Mitigado | — |
| T-07 | Definições maliciosas publish | High | publish gate + lock_version + dialect gate | Mitigado | — |

Se novo High/Critical surgir após pen-test, registrar aqui:

```
| T-XX | Título | High/Critical | Correção proposta | Pendente | Data: ___ Ass: Security ___ Product ___ Risk ___ |
```

Sem assinatura, o cutover não é autorizado.

---

## 11. Arquivos com `file:line`

| Arquivo | Papel | Linha |
|---|---|---|
| `docs/architecture/threat-model.md` | **Este** modelo STRIDE RQ-045 | `1` |
| `docs/architecture/inventory-api-query-contract.md` | Freeze contrato api-query (8 queries + gq-lote/ gq-inspecao I/U) | `1,44,392` |
| `docs/architecture/rbac.md` | RBAC nativo `_query/{name}` sem paralelo | `1,11,19,32,42` |
| `docs/architecture/budgets.md` | Budgets hierárquicos cluster-safe | `1,35,51` |
| `docs/architecture/adr-named-query.md` | ADR `_query` vs API Builder vs adaptador | `15,56,68,95` |
| `extensions/df-named-query/src/Query/NamedSqlCompiler.php` | `assertReadOnly` + `compile` com binds `(*SKIP)(*F)` | `24,60,143,157` |
| `extensions/df-named-query/src/Repositories/NamedQueryRepository.php` | `validateDefinition`, `FORBIDDEN_CREDENTIAL_FIELDS`, `publish` gate, `assertServiceExists` allowlist | `23,256,356,410` |
| `extensions/df-named-query/src/Resources/NamedQueryResource.php` | `handleGET`, `listAccessComponents`, `execute` com RBAC + budgets | `18,27,98,213,307` |
| `extensions/df-named-query/src/Resources/NamedQueryAdminResource.php` | Admin `system/named_query` isolável | `14,38,50,96` |
| `extensions/df-named-query/src/Query/JsonQueryCompiler.php` | `DEFAULT_BUDGETS`, allowlists, budgets validators | `21,35,630` |
| `extensions/df-named-query/src/Query/QueryExecutionBudget.php` | `statementTimeoutSeconds`, `applyToConnection`, deadline | `25,66,168` |
| `extensions/df-named-query/src/Services/DialectCapabilities.php` | `assertSupportedForServiceType` gate por dialeto | `1,230,247` |
| `extensions/df-named-query/src/ServiceProvider.php` | Registra `system/named_query` + `pgsql_query/_query` isoláveis | `17,34,55` |
| `extensions/df-named-query/src/Services/HasNamedQueryResource.php` | Injeta `_query` no service | `9` |
| `extensions/df-named-query/src/Services/NamedQueryAudit.php` | Audit sanitizado sem SQL/bind | `19,27,105` |
| `extensions/df-named-query/tests/AbuseSuiteTest.php` | **Suite de abuso** valores vs identificadores + admin/egress | `1` |
| `tests/Feature/AbuseSuiteTest.php` | Espelho Feature da suite | `1` |
| `extensions/df-named-query/tests/NamedSqlCompilerTest.php` | Prova binds literais/casts | `11,26,46` |
| `extensions/df-named-query/tests/NamedQueryResourceTest.php` | Prova `maxRows` budget | `10` |
| `docs/ci-matrix.md` | Matriz CI campo real + como rodar | `1,25,87,343` |

---

*RQ-045 — STRIDE modelado, mitigação mapeada para código com `file:line`, suites de abuso separadas para valores/identificadores, admin plane isolável (`system/named_query` ≠ `_query`), egress por allowlist, RBAC/events nativos reutilizados, High/Critical só com correção ou aceite formal antes de cutover.*
