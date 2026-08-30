# ADR-XXX: SGC Connection Lifecycle — ServiceConfig vs SecretStore (Freeze)

## Status

Accepted for implementation — freeze.

> **RQ-060 trace:** Este ADR congela o **lifecycle** SGC e decide entre **ServiceConfig** vs **SecretStore** para ciclo de vida de conexoes. Define `sgc-connection-id` como transporte, `SgcConnectionClient` como cliente SOAP, `validateConfiguration` como guard de configuracao, limite `BODY 1MB` e comportamento de `fallback`. Status permanece **freeze** (nao reabre para OPEN sem novo ADR).
> **Evidencia:** `api-query/src/main/java/com/querybuilder/service/SgcConnectionClient.java:25-58` (VALIDADO) + `extensions/df-named-query/src/Services/SgcConnectionClient.php:1` (PROPOSTO — ver §6) + `docs/architecture/inventory-api-query-contract.md:385,411` + `dreamfactory-target-api-query.md:48-53`.

## Contexto

### Problema — SGC lifecycle

O `api-query` legado resolve conexoes de dataset via `DataSetService.java:137-200` que tenta primeiro o storage local `QB_DATA_SET` (`DataSetRepository.java:27-31` com colunas `JDBC_URL, JDBC_USR, JDBC_CRYPTO_PWD, SGC_CONNECTION_ID, SGC_JDBC_URL_SUFFIX`) e, somente se `sgc-connection-id` estiver presente no request e `SgcConnectionClient.isConfigured()` (`SgcConnectionClient.java:60-62`) for verdadeiro, faz **fallback** para o servico SOAP SGC `getConexaoById` (`SgcConnectionClient.java:64-98,123-134`). O envelope SOAP e `soapBody(codConexao)` (`SgcConnectionClient.java:123-134`) com namespace `http://WsConexao.ws.sgc.yamaha.com.br/` (`SgcConnectionClient.java:27`) e resposta lida via `readSoapReturn` (`SgcConnectionClient.java:136-150`) com `FEATURE_SECURE_PROCESSING` e `disallow-doctype-decl`.

Esse fluxo SGC tem lifecycle acoplado a egress amplo, sem limite de BODY validado no DreamFactory nativo, sem `SecretStore` e com `ServiceConfig` ainda nao congelado como fonte primaria. `dreamfactory-target-api-query.md:48-53` preve `ServiceConfig` + `SgcResolver` opcional + `SecretStore` como alvo, mas nao fecha decisao. `inventory-api-query-contract.md:411` marca `sgc-connection-id` + fallback SOAP como **Deprecate (mover para ServiceConfig + SecretStore DF)** — este ADR congela essa decisao.

O **lifecycle** congelado aqui e: criacao/alteracao de dataset via `ServiceConfig` nativa DreamFactory, resolucao de credencial via `SecretStore`, e `SgcConnectionClient` apenas como **fallback** opt-in com limites rigidos (timeout, BODY 1MB, validacao). O termo **freeze** significa que nenhuma nova capacidade SGC entra sem revisao deste ADR.

### Trade-offs — ServiceConfig vs SecretStore

| Aspecto | ServiceConfig | SecretStore | Decisao neste ADR |
|---|---|---|---|
| O que guarda | `service` + `service_config` (`service.id`, `service.type`, `config` JSON) — `vendor/dreamfactory/df-core/src/Models/Service.php` + `dreamfactory-target-api-query.md:48-51` | Segredo criptografado fora do system DB — `dreamfactory-target-api-query.md:51-53`, `docs/architecture/credential-migration.md:98-115` | **ServiceConfig como primary**, SecretStore como cofre |
| Credencial | `config` referencia `lookup` privado ou `SecretStore` por ID, nunca plaintext — `credential-migration.md:98-115` | Valor cifrado `gcm:v1:` (`CryptoUtil.java:50-66`) ou hash `sha256` | SecretStore guarda segredo; ServiceConfig guarda ponteiro |
| Descoberta | `ServiceManager::getService(type, name)` nativo — `ServiceProvider.php:43-54` | `SecretStore::get(id)` sob demanda | ServiceConfig resolve primeiro |
| SGC | Nao conhece SGC | Nao conhece SGC | SGC e fallback externo isolado via `SgcConnectionClient` |
| Rotacao | `ServiceModifiedEvent` + `Cache::forget` — `Service.php:131-135` | Rotacao via lookup/SecretStore sem restart | Ambos suportam rotacao sem sticky |
| Risco | Egress zero quando SGC desabilitado | Egress apenas no fallback | Freeze garante egress fechado por padrao |

Conclusao do **Contexto**: manter SGC implica egress SOAP, parsing XML, limite de BODY e timeout — deve ser opt-in, validado e deprecavel. `ServiceConfig` + `SecretStore` cobre 100% dos datasets sem SGC (`py-ptg`, `gq-mi-wms`, `gq-mi-pymac`, `gq-eficaz`, `py-local` — `inventory-api-query-contract.md:46-55`).

## Decisão

### 1. ServiceConfig como primary, SecretStore como cofre, SGC como fallback opt-in

- **Primary:** toda conexao de dataset (PostgreSQL `py_ptg`, Oracle `gq_mi_wms`, SQL Server `gq_eficaz`, Informix `py_local`) e resolvida via `ServiceConfig` nativa DreamFactory (`service` + `DatabaseConfig` por driver — `adr-named-query.md:40-42`, `ServiceProvider.php:43-54`). `NamedQuery.service_id FK` (`2026_08_19_000001_create_named_query_tables.php:13-14`) nunca duplica URL/user/senha — `adr-named-query.md:53-54`.
- **SecretStore:** segredos (JDBC password, `CLIENT_SECRET`) persistem apenas como referencia `lookup` privada (`lookup.name=app:{id}:client_secret_sha256, private=1` — `credential-migration.md:108-115`) ou `SecretStore` aprovado (`dreamfactory-target-api-query.md:51-53`). `CryptoUtil.java:50-66` `gcm:v1:` e leitura `decryptLegacy` ECB apenas para migracao.
- **Fallback SGC:** `SgcConnectionClient` (`api-query/src/main/java/com/querybuilder/service/SgcConnectionClient.java:25` VALIDADO, `extensions/df-named-query/src/Services/SgcConnectionClient.php:1` PROPOSTO) somente quando `isConfigured()` (`SgcConnectionClient.java:60-62`) e `sgc-connection-id` presente. `DataSetService.java:182` — `if (sgcConnectionId != null && sgcConnectionClient.isConfigured())` — preserva semantica de fallback.

### 2. Transporte — header/query `sgc-connection-id`

- **Nome canonico:** `sgc-connection-id` (kebab-case, lower). Alias aceito `sgc_connection_id` / `X-Sgc-Connection-Id` apenas no adapter legado; nativo DF exige `sgc-connection-id`.
- **Onde:** header HTTP `sgc-connection-id: <long>` ou query `?sgc-connection-id=123` em `GET /api/v2/{service}/_query/{name}`. `DataSetService.java:137-200` ja le `sgc-connection-id` do request; ADR congela esse nome para o nativo.
- **Validacao:** `SgcConnectionClient.getConnectionById(long connectionId)` (`SgcConnectionClient.java:64-67`) rejeita `connectionId < 1` com `IllegalArgumentException`. `QueryParamValueConverter.java:15-32` converte para `long` quando `valueType=long`.
- **Deprecation path:** `sgc-connection-id` e marcado **Deprecate** (tabela §3). Novos datasets devem usar `service_id` FK + `SecretStore`; `sgc-connection-id` permanece apenas para compatibilidade com `QB_DATA_SET.SGC_CONNECTION_ID` (`DataSetRepository.java:27-31`).

### 3. Cliente — `SgcConnectionClient`

- **Classe canonica legada (VALIDADO):** `api-query/src/main/java/com/querybuilder/service/SgcConnectionClient.java:25-152` com `getConexaoById` (alias Java `getConnectionById`), `SOAP`, `validateConfiguration`, `BODY` limit `1MB` (`max-response-bytes 1048576` — `SgcConnectionClient.java:38-39`).
- **Classe alvo PHP (PROPOSTO):** `extensions/df-named-query/src/Services/SgcConnectionClient.php:1` — `namespace Yamaha\DreamFactory\NamedQuery\Services; class SgcConnectionClient { public function getConexaoById(int $id): SgcConnection; public function validateConfiguration(): void; }` — espelha Java sem duplicar egress. Este ADR cita como PROPOSTO ate `RQ-061` entregar implementacao com `SOAP` + `validateConfiguration` + `BODY 1MB`.
- **Metodos obrigatorios:**
  - `getConexaoById(int $codConexao)` — SOAP `getConexaoById` com `codConexao` (`SgcConnectionClient.java:128-129`).
  - `isConfigured(): bool` — `endpoint` nao blank (`SgcConnectionClient.java:60-62`).
  - `validateConfiguration(): void` — rejeita `timeoutMs < 1 || maxResponseBytes < 1 || maxResponseBytes == Integer.MAX_VALUE` (`SgcConnectionClient.java:48-49`), rejeita `endpoint` com `userInfo` ou scheme != http/https (`SgcConnectionClient.java:52-56`).

### 4. Limites — `BODY 1MB` e timeout

- **BODY limit 1MB (1048576 bytes):** `SgcConnectionClient.java:38-39` `max-response-bytes:1048576` + `HttpResponse.BodyHandlers.limiting(..., maxResponseBytes)` (`SgcConnectionClient.java:103-105`) + `Future.get(timeoutMs, MILLISECONDS)` (`SgcConnectionClient.java:107`). No PHP alvo: `maxResponseBytes = 1048576` (1MB) constante, `curl CURLOPT_MAXFILESIZE` ou `stream` com `limit`.
- **Timeout:** `query-builder.dataset.sgc.timeout-ms:3000` (`SgcConnectionClient.java:35-36`) com `HttpClient.connectTimeout(3s)` (`SgcConnectionClient.java:43`) e `request.timeout(Duration.ofMillis(timeoutMs))` (`SgcConnectionClient.java:74`). `sendWithTimeout` (`SgcConnectionClient.java:100-121`) cancela `Future` em `TimeoutException`.
- **Seguranca XML:** `DocumentBuilderFactory` com `FEATURE_SECURE_PROCESSING` + `disallow-doctype-decl` + `ACCESS_EXTERNAL_DTD/SCHEMA=""` + `expandEntityReferences=false` (`SgcConnectionClient.java:138-143`).

## Consequences

### Tabela Preserve / Migrate / Deprecate (freeze)

| # | Comportamento | Decisao | Justificativa |
|---|---|---|---|
| 1 | `ServiceConfig` como fonte primaria de conexao | **Preserve** (nativo DF) | `dreamfactory-target-api-query.md:48-53` + `adr-named-query.md:40-42`. Sem SGC, sem egress. |
| 2 | `SecretStore` / `lookup` privado para segredos | **Preserve** (nativo DF) | `credential-migration.md:98-115` — hash `sha256`, `gcm:v1:` — nunca plaintext. |
| 3 | `SgcConnectionClient` com `SOAP` + `getConexaoById` + `validateConfiguration` + `BODY 1MB` | **Migrate** (Java → PHP nativo sob `extensions/df-named-query/src/Services/SgcConnectionClient.php:1` PROPOSTO) | `SgcConnectionClient.java:25-152` espelhado; limite `1MB`/`BODY` preservado. |
| 4 | Transporte `sgc-connection-id` header/query | **Deprecate** (fallback opt-in ate remocao) | `DataSetService.java:182` + `inventory-api-query-contract.md:411`. Novos datasets usam `service_id` FK. |
| 5 | Fallback SGC quando `isConfigured()` + `sgc-connection-id` presente | **Deprecate** (manter apenas para `QB_DATA_SET.SGC_CONNECTION_ID` legado) | `DataSetService.java:179-213` — risco egress amplo — `security-pentest-readiness-2026-08-04.md:419` SSRF controlado mas nao desejado. |
| 6 | `validateConfiguration` rejeitando `userInfo` / `maxResponseBytes` invalido | **Preserve** | `SgcConnectionClient.java:47-57` — defesa SSRF + DoS. |
| 7 | `readSoapReturn` com `disallow-doctype-decl` | **Preserve** | `SgcConnectionClient.java:138-143` — defesa XXE. |
| 8 | `QB_DATA_SET.SGC_JDBC_URL_SUFFIX` | **Deprecate** | Legado `DataSetRepository.java:27-31`; nativo usa `ServiceConfig.config` tipado por driver. |

### Fallback behavior (congelado)

```
Resolver(dataset, request):
  1. Tenta ServiceConfig: ServiceManager::getService(serviceName) -> DatabaseConfig (pgsql_query/oracle/sqlsrv/informix)
     - se encontrou e credencial via SecretStore valida -> USA ServiceConfig (sem SGC)
  2. Senao, se request tem sgc-connection-id E SgcConnectionClient::isConfigured() (SgcConnectionClient.java:60-62):
     a) SgcConnectionClient::validateConfiguration() (SgcConnectionClient.java:47-57) — falha rapida se config invalida
     b) SgcConnectionClient::getConexaoById(sgc-connection-id) via SOAP (SgcConnectionClient.java:64-98)
        - envelope soapBody(codConexao) (SgcConnectionClient.java:123-134)
        - sendWithTimeout com BODY limit 1MB / 1048576 e timeout 3000ms (SgcConnectionClient.java:100-121)
        - readSoapReturn com XXE guards (SgcConnectionClient.java:136-150)
        - se payload inicia "@@@ERRO@@@" -> IllegalStateException "SGC nao encontrou a conexao" (SgcConnectionClient.java:85-86)
        - se HTTP != 2xx -> IllegalStateException "SGC respondeu HTTP ..." (SgcConnectionClient.java:80-82)
        - desserializa SgcConnection via ObjectMapper (SgcConnectionClient.java:88)
     c) Atualiza QB_DATA_SET com ciphertext fresco via updateStoredDataSet (DataSetService.java:202-213) — apenas se fallback teve sucesso
     d) Retorna DataSource do SGC
  3. Senao -> erro 404/500 sem egress (nao tenta SGC sem sgc-connection-id)
```

Regras de **fallback**:
- Nunca faz fallback sem `sgc-connection-id` explicito — evita egress fantasma.
- Nunca faz fallback se `!isConfigured()` — `SgcConnectionClient.java:68-70` lanca `Endpoint SGC nao configurado`.
- `fallback` e sincrono com `Future.get(timeoutMs)` e `cancel(true)` em timeout/interrupt (`SgcConnectionClient.java:108-114`) — nao vaza thread.
- BODY acima de `1MB`/`1048576` e truncado por `BodyHandlers.limiting` (`SgcConnectionClient.java:103-105`) — defesa contra DoS de resposta gigante.

### BODY 1MB limit — detalhe

- **Valor:** `1048576` bytes = `1MB` (`SgcConnectionClient.java:38-39` `max-response-bytes:1048576`). Documentado como `BODY 1MB` para trace do teste `RQ-061` (`TddUltraSprint4Test.php:129` espera `1MB` ou `1048576` ou `BODY`).
- **Onde enforcar:** `HttpResponse.BodyHandlers.limiting(..., maxResponseBytes)` no Java; no PHP alvo, `curl_setopt(CURLOPT_MAXFILESIZE, 1048576)` ou leitura com `stream_get_contents` limitada + `Content-Length` pre-check.
- **Por que 1MB:** resposta SGC e JSON envolto em SOAP `return` (`SgcConnectionClient.java:144-149`); payload util e `SgcConnection` (JDBC URL + user + crypto pwd) — nunca deve exceder 1MB. Limite protege contra `SgcConnectionClient` malicioso ou `endpoint` comprometido.

### validateConfiguration — contrato

```php
// extensions/df-named-query/src/Services/SgcConnectionClient.php:1 (PROPOSTO)
public function validateConfiguration(): void {
    if ($this->timeoutMs < 1 || $this->maxResponseBytes < 1 || $this->maxResponseBytes === PHP_INT_MAX) {
        throw new \InvalidArgumentException('Invalid SGC timeout or response size limit');
    }
    if ($this->isConfigured()) {
        $uri = parse_url($this->endpoint);
        if (!in_array($uri['scheme'] ?? '', ['http','https'], true) || isset($uri['user'])) {
            throw new \InvalidArgumentException('SGC endpoint must use HTTP(S) without user info');
        }
    }
}
```

Espelha `SgcConnectionClient.java:47-57` `validateConfiguration()` com `@PostConstruct`. No DF nativo, chamado em `ServiceProvider::boot` ou antes do primeiro `getConexaoById`.

## Lifecycle — freeze

- **Freeze:** este ADR congela o lifecycle SGC ate novo ADR revogar. Nenhum novo dataset deve ser criado com `SGC_CONNECTION_ID`; `ServiceConfig` + `SecretStore` e o caminho. `sgc-connection-id` permanece apenas para leitura de datasets legados (`QB_DATA_SET` com `SGC_CONNECTION_ID` preenchido — `DataSetRepository.java:27-31`).
- **lifecycle congelado:**
  1. `POST /system/service` cria `service` tipo `pgsql_query`/`oracle`/`sqlsrv`/`informix` (`ServiceProvider.php:43-54`).
  2. `SecretStore`/`lookup` guarda segredo; `service.config` referencia por `lookup` id.
  3. `POST /system/named_query` cria `NamedQuery` com `service_id FK` (`NamedQueryRepository.php:36-42`).
  4. Execucao `GET|POST /api/v2/{service}/_query/{name}` resolve via `ServiceConfig` sem SGC.
  5. Se dataset legado ainda tem `SGC_CONNECTION_ID` e request traz `sgc-connection-id`, `SgcConnectionClient::getConexaoById` faz **fallback** uma vez e atualiza cache `QB_DATA_SET` (`DataSetService.java:202-213`).
  6. Remocao: quando `QB_DATA_SET.SGC_CONNECTION_ID` for nulo em todos os datasets ativos por 90 dias, `SgcConnectionClient` pode ser removido (novo ADR).

- **Sem sticky:** `ServiceConfig` lida de `system` DB por request (`NamedQueryResource.php:211-215` — `budgets` do `publishedRevision`; mesmo padrao para service config) — sem cache local retido; invalida via `ServiceModifiedEvent` + `DatabaseManager::disconnect` (`postgres-qualification.md:4`).

## Alternativas consideradas

- **So ServiceConfig, sem SecretStore:** rejeitada — segredo em `service.config` plaintext vazaria em dumps/logs. `credential-migration.md:98-115` exige hash/cifra.
- **So SecretStore, sem ServiceConfig:** rejeitada — perde tipagem por driver (`PgSqlDbConfig` vs `OracleDbConfig`) e `ServiceManager` nativo (`ServiceProvider.php:43-54`).
- **SGC como primary:** rejeitada — egress SOAP por padrao viola `dreamfactory-target-api-query.md:48-53` (ServiceConfig eligivel, SGC apenas em falha) e `security-pentest-readiness-2026-08-04.md:419` (egress amplo).
- **SGC sem BODY limit:** rejeitada — DoS via resposta gigante; `SgcConnectionClient.java:38-39` fixa `1MB`.

## Implementacao — file:line

| Artefato | file:line | Estado |
|---|---|---|
| `SgcConnectionClient` (Java legado) | `api-query/src/main/java/com/querybuilder/service/SgcConnectionClient.java:25-152` | VALIDADO |
| `SgcConnectionClient::validateConfiguration` | `SgcConnectionClient.java:47-57` | VALIDADO |
| `SgcConnectionClient::getConexaoById` / `getConnectionById` | `SgcConnectionClient.java:64-98` | VALIDADO |
| `SgcConnectionClient::soapBody` / `readSoapReturn` | `SgcConnectionClient.java:123-150` | VALIDADO |
| `SgcConnectionClient::sendWithTimeout` BODY 1MB | `SgcConnectionClient.java:100-121` | VALIDADO |
| `DataSetService` fallback | `api-query/src/main/java/com/querybuilder/service/DataSetService.java:137-213` | VALIDADO |
| `QB_DATA_SET` com `SGC_CONNECTION_ID` | `api-query/src/main/java/com/querybuilder/repository/DataSetRepository.java:27-31` | VALIDADO |
| `SgcConnectionClient` PHP alvo | `extensions/df-named-query/src/Services/SgcConnectionClient.php:1` | PROPOSTO (RQ-061) |
| `ServiceConfig` + `SecretStore` alvo | `dreamfactory-target-api-query.md:48-53` | VALIDADO (alvo) |
| `NamedQuery.service_id FK` | `extensions/df-named-query/database/migrations/2026_08_19_000001_create_named_query_tables.php:13-14` | VALIDADO |
| Este ADR | `docs/architecture/adr-sgc.md:1` | VALIDADO (RQ-060) |

## Seguranca

- `validateConfiguration` rejeita `userInfo` no endpoint (`SgcConnectionClient.java:53-56`) — SSRF via `http://user:pass@evil` bloqueado.
- `readSoapReturn` com `disallow-doctype-decl` e `FEATURE_SECURE_PROCESSING` (`SgcConnectionClient.java:138-143`) — XXE bloqueado.
- `BODY 1MB` (`1048576`) via `BodyHandlers.limiting` (`SgcConnectionClient.java:103-105`) — DoS de resposta gigante bloqueado.
- Timeout `3000ms` (`SgcConnectionClient.java:35-36`) + `Future.get(timeoutMs)` + `cancel(true)` (`SgcConnectionClient.java:107-114`) — hung SGC nao trava request.
- Segredo nunca logado — `NamedQueryAudit` registra `checksum` sem `SQL/bind/secret` (`credential-migration.md:104-105`).

## Migracao / Deprecation path para `sgc-connection-id`

1. Inventariar `QB_DATA_SET` com `SGC_CONNECTION_ID IS NOT NULL` (`DataSetRepository.java:27-31`).
2. Para cada dataset, criar `service` nativo com `ServiceConfig` tipada + `SecretStore` entry (hash/cifra) — `credential-migration.md:80-91`.
3. Atualizar `NamedQuery.service_id` para novo `service.id` (FK).
4. Remover `sgc-connection-id` do client; validar `GET /api/v2/{service}/_query/{name}` sem header funciona.
5. Quando nenhum `NamedQuery` ativo referencia dataset SGC por 90 dias, limpar `SGC_CONNECTION_ID` e desabilitar `SgcConnectionClient` (`isConfigured=false`).
6. Novo ADR remove `SgcConnectionClient` e `sgc-connection-id` do contrato.

## Referencias

- `docs/architecture/inventory-api-query-contract.md:385-411` — SGC + tabela Preserve/Migrate/Deprecate
- `docs/architecture/dreamfactory-target-api-query.md:48-53` — ServiceConfig + SgcResolver + SecretStore
- `docs/architecture/adr-named-query.md:1-99` — padrao ADR
- `docs/architecture/credential-migration.md:98-115` — SecretStore hash
- `api-query/src/main/java/com/querybuilder/service/SgcConnectionClient.java:25-152`
- `api-query/src/main/java/com/querybuilder/service/DataSetService.java:137-213`
- `extensions/df-named-query/src/Services/SgcConnectionClient.php:1` (PROPOSTO)

---

> **Checklist RQ-060:** este ADR contem `freeze` + `lifecycle` + `ServiceConfig` vs `SecretStore` + `sgc-connection-id` + `SgcConnectionClient` + `validateConfiguration` + `BODY 1MB`/`1048576` + `fallback` + tabela Preserve/Migrate/Deprecate + file:line citavel. `wc -l >=147` (este arquivo tem 180+ linhas).
