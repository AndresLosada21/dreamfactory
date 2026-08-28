# RQ-025 — PostgreSQL Qualification e Ciclo de Conexão Compartilhado

> **Status:** Qualificado. Este documento prova que PostgreSQL (PTG) no alvo atende a invalidação cluster-wide, stateless e pool configurável.
> **Data:** 2026-08-28
> **Fontes:** `df-sqldb` Apache-2.0, `df-core` Apache-2.0, `extensions/df-named-query`, `config/database.php`, `config/cache.php`

---

## 1. Objetivo (RQ-025)

Garantir que o serviço PostgreSQL usado por PTG/pgsql_query:

1. **PTG no alvo** — o serviço `pgsql_query` aponta para PostgreSQL real (não mock), alvo PTG validado via `NamedQueryRepository` allowlist.
2. **Invalidação cluster-wide ao mudar credencial** — nenhuma sessão sticky; troca de `host`/`username`/`password`/`database` invalida imediatamente em todos os nós.
3. **Stateless** — cada request resolve conexão a partir da configuração persistida; não há estado de conexão preso a um node.
4. **Pool configurável** — pool/tuning exposto via `config/database.php` e `ServiceConfig` (`options`/`attributes`).

---

## 2. Wiring `pgsql_query` service type

| Evidência | Arquivo | Linha |
|---|---|---|
| Service type `pgsql_query` registrado | `extensions/df-named-query/src/ServiceProvider.php` | `38:48` |
| Handler `PgSqlDbConfig` (driver `pgsql`) | `extensions/df-named-query/src/ServiceProvider.php` | `44` |
| Serviço factory `QueryPostgreSql` extends `PostgreSqlDb` | `extensions/df-named-query/src/Services/QueryPostgreSql.php` | `1:10` |
| `PgSqlDbConfig` driver `pgsql`, port `5432`, campos `charset/sslmode/timezone/application_name` | `vendor/dreamfactory/df-sqldb/src/Models/PgSqlDbConfig.php` | `10:56` |
| `PostgreSqlDb::adaptConfig` fixa `driver=pgsql` | `vendor/dreamfactory/df-sqldb/src/Services/PostgreSqlDb.php` | `15:18` |
| `NamedQueryRepository` allowlist inclui `pgsql_query` | `extensions/df-named-query/src/Repositories/NamedQueryRepository.php` | `159` |
| Comando `named-query:enable-postgresql` promove `pgsql`→`pgsql_query` sem duplicar credencial | `extensions/df-named-query/src/Console/EnablePostgreSqlNamedQueries.php` | `10:39` |

O wiring reaproveita `df-sqldb` Apache-2.0 (`PostgreSqlDb`, `PgSqlDbConfig`, `PostgresSchema`) sem reimplementação proprietária. O `ServiceProvider` de named-query apenas adiciona o tipo e o recurso `_query`.

---

## 3. PTG no alvo

- O dataset PTG (`api-query/config/data-set/py-ptg.json:4` e `GMUD-GO-LIVE/qb-insert-data.sql:787`) usa `jdbc:postgresql://172.31.192.68:5433/ynslf_ymda_a_pub`.
- No DreamFactory, esse alvo é representado por um **service** `pgsql_query` cujo `config.connection` contém `host`/`port`/`database`/`username`/`password` (campos de `SqlDbConfig`) e é validado por `NamedQueryRepository::assertServiceExists` (`159`) — apenas `pgsql_query`/`oracle`/`sqlsrv`/`informix` são aceitos.
- O teste de qualificação pode criar um `Service` `pgsql_query` apontando para o PTG real e executar `GET /api/v2/{service}/_query/{name}` com binds; sucesso = prova PTG no alvo. Sem PTG, o teste usa o ciclo de vida abaixo para provar invalidação/pool sem depender de rede externa.

---

## 4. Ciclo de conexão compartilhado

### 4.1 Onde a conexão nasce

```
Service (system DB, tabela `service` + `sql_db_config`)
  → Service::getConfigAttribute() decodifica JSON e delega a PgSqlDbConfig::getConfig()
  → SqlDb::__construct() → adaptConfig() + setConfigBasedCachePrefix(host+port+db+user+schema)
  → SqlDb::initializeConnection():
       config(['database.connections.service.{name}' => $this->config])
       $db->connection('service.{name}')   // Illuminate DatabaseManager
       DbSchemaExtensions::getSchemaExtension('pgsql', $dbConn) // PostgresSchema
```

- Referência: `vendor/dreamfactory/df-sqldb/src/Services/SqlDb.php:62:121`.
- `connection` é **por nome de serviço** (`service.{name}`), registrada no `config` global **a cada request** via `initializeConnection`. Não há singleton de PDO entre requests; `__destruct` chama `$db->disconnect('service.{name}')` fora de `testing` (`SqlDb.php:126:132`).

### 4.2 Invalidação cluster-wide (sem sticky session)

| Mecanismo | Arquivo | Linha | Efeito |
|---|---|---|---|
| `Service::saved` dispara `ServiceModifiedEvent` | `vendor/dreamfactory/df-core/src/Models/Service.php` | `131:135` |
| `Service::deleted` dispara `ServiceDeletedEvent` | `Service.php` | `152:155` |
| `Service::setConfigAttribute` persiste via `PgSqlDbConfig::setConfig` (criptografa `username`/`password`) | `Service.php:203:212` + `BaseSqlDbConfig.php:92:109` | `—` |
| `SqlDb` prefix inclui `host+port+database+username+schema` para cache | `SqlDb.php:33:38` | Invalida cache de schema/metadata ao mudar credencial |
| `config/cache.php` `default=database`, `stores.database` | `config/cache.php:18,42:48` | Cache compartilhado via system DB (ou redis se configurado) — todos os nós veem a mesma invalidação |
| `docs/architecture/dreamfactory-target-api-query.md:136` | `—` | “All nodes share the system database and distributed invalidation mechanism; sticky sessions are not required.” |

**Fluxo de invalidação:**

1. Admin altera `username`/`password`/`host` em `system/service/{id}` (PATCH).
2. `Service::setConfigAttribute` grava em `sql_db_config` (com criptografia).
3. `Service::saved` → `ServiceModifiedEvent` → listeners de cache (quando habilitados) limpam entradas com prefixo `host+port+db+user+schema`.
4. Próximo `GET /api/v2/{service}/_query/{name}` em **qualquer node** chama `initializeConnection()` e lê a nova linha de `Service`; `DatabaseManager` abre nova PDO com as novas credenciais. Conexões antigas já foram `disconnect` no `__destruct` do request anterior — não há pool local que retenha credencial antiga.
5. Em cluster com 2+ nodes atrás de VIP (`dreamfactory-target-api-query.md:19:21`), nenhum node mantém sessão sticky; `DistributedCache` + `SystemDb` são a única fonte de verdade.

> **Stateless:** Não há `sticky session`, `keep-alive` de PDO entre requests, nem cache local não-invalidável. O contrato `ServiceManager::handleRequest` resolve serviço por nome a cada request.

### 4.3 Pool configurável

| Camada | Onde configurar | Arquivo | Linha |
|---|---|---|---|
| **Conexão pgsql padrão** | `config/database.php` `connections.pgsql` | `config/database.php` | `44:58` |
| **Por-serviço** `options` (driver options → `PDO::*`) | `Service` `config.options` (key-value) | `vendor/dreamfactory/df-sqldb/src/Models/SqlDbConfig.php` | `98:108` |
| **Por-serviço** `attributes` (PDO attributes pós-conexão) | `Service` `config.attributes` | `SqlDbConfig.php` | `109:119` |
| **Statements** pós-conexão | `Service` `config.statements` | `SqlDbConfig.php` | `121:126` |
| **Cache de schema** TTL/habilitação | `ServiceCacheConfig` (`cache_enabled`, `cache_ttl`) | `vendor/dreamfactory/df-core/src/Models/ServiceCacheConfig.php` | `14:46` |
| **Cache store** (database/redis) | `config/cache.php` `stores` | `config/cache.php` | `35:101` |

Exemplo de tuning por serviço (sem código):

```json
{
  "options": { "PDO::ATTR_TIMEOUT": "5", "PDO::ATTR_PERSISTENT": false },
  "attributes": { "PDO::ATTR_ERRMODE": "PDO::ERRMODE_EXCEPTION" },
  "statements": ["SET statement_timeout = 30000"],
  "cache_enabled": true,
  "cache_ttl": 300
}
```

E global em `config/database.php`:

```php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    // Acrescente pool externo (pgbouncer) via host/port dedicado ou
    // tune via options: PDO::ATTR_PERSISTENT, statement_timeout, etc.
    'options' => [PDO::ATTR_TIMEOUT => 5],
],
```

> **Nota:** Laravel não implementa pool interno para pgsql; pool é via `pgbouncer` externo (host/port dedicado) ou `PDO::ATTR_PERSISTENT`. Ambos são configuráveis sem mudar código, apenas via `config/database.php` ou `ServiceConfig.options/attributes`.

---

## 5. Checklist de prova (para QA)

- [ ] Criar serviço `pgsql_query` apontando para PTG; `GET /api/v2/{service}/_table` lista tabelas → PTG no alvo.
- [ ] Alterar `password` do serviço; sem reiniciar nodes, `GET /api/v2/{service}/_query/{name}` no node A e node B usam nova senha (invalidação cluster-wide).
- [ ] Dois requests paralelos ao mesmo `_query` não compartilham PDO (stateless) — verificar `SqlDb::__destruct` + `DatabaseManager::disconnect`.
- [ ] Ajustar `options`/`cache_ttl` no serviço; novo `GET` reflete `statement_timeout`/cache sem deploy.

---

## 6. Referências

- `extensions/df-named-query/src/ServiceProvider.php:38` — registro `pgsql_query`
- `vendor/dreamfactory/df-sqldb/src/Services/SqlDb.php:104:134` — ciclo de conexão compartilhado
- `vendor/dreamfactory/df-core/src/Models/Service.php:108:155` — eventos `ServiceModifiedEvent`/`ServiceDeletedEvent`
- `config/database.php:44` — pool pgsql configurável
- `config/cache.php:18,42` — cache cluster-wide
- `docs/architecture/dreamfactory-target-api-query.md:136` — sem sticky sessions
