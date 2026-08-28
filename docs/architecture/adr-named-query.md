# ADR: Native Named Query Resource

## Status

Accepted for implementation.

> **RQ-003 trace:** Este ADR fecha o critério **needs-decision** ao provar pacotes, ServiceProvider, persistência versionada, eventos, fronteiras `_query` vs API Builder vs adaptador legado e política de upgrade/rebase com upstream **7.7.0** sem sidecar permanente. Status permanece **Accepted** (não reabre para OPEN).

## Context

Base é `dreamfactory/dreamfactory` **7.7.0** (`config/app.php:7`). O fork substitui `api-query` por recurso nativo DreamFactory sem criar sidecar permanente nem plataforma paralela de administração/autorização (`docs/architecture/dreamfactory-target-api-query.md:3-5`). Definições ficam no system database e referenciam `service.id`; nunca duplicam URL/user/senha.

## Decision

Named Queries são recurso filho `_query` de um service SQL DreamFactory. Definições são versionadas no system database e só a revisão apontada por `published_revision_id` é executável. O lifecycle administrativo é `system/named_query`; a execução é `GET|POST /api/v2/{service}/_query/{name}` dentro do pipeline nativo de RBAC/eventos/OpenAPI.

### 1. Packages — `yamaha/df-named-query` + deps

- **Package:** `yamaha/df-named-query` `0.1.0` — `extensions/df-named-query/composer.json:2`
- **Descrição/licença/type:** `library`, `Apache-2.0` — `composer.json:3-5`
- **Autoload:** `Yamaha\DreamFactory\NamedQuery\` → `src/` — `composer.json:11-15`
- **Deps:** `dreamfactory/df-system ~0.6.5` + `dreamfactory/df-sqldb ~1.5.0` — `composer.json:7-9` (compatível com `dreamfactory-fork/composer.json:127-128` que exige `df-sqldb ~1.5.0`, `df-system ~0.6.5`)
- **Auto-discovery:** `extra.laravel.providers: Yamaha\DreamFactory\NamedQuery\ServiceProvider` — `composer.json:16-20` — prova instalação limpa registra recurso sem rotas paralelas (apenas DreamFactory nativas).
- **Path repo (fork root):** `dreamfactory-fork/composer.json:51-57` declara `type:path` `extensions/df-named-query` com `require: yamaha/df-named-query ^0.1` (`composer.json:135`).
- **Comandos:** `EnablePostgreSqlNamedQueries` (`src/Console/EnablePostgreSqlNamedQueries.php:8`) + `ImportNamedQueries` (`src/Console/ImportNamedQueries.php:12`) registrados em `ServiceProvider.php:23-25` apenas quando `runningInConsole`.
- **Collision (pacote incompatível falha claramente):** `ServiceProvider.php:43-54` guarda `getServiceType('pgsql_query')` antes de `addType`; se tipo já existe com `config_handler` diferente de `PgSqlDbConfig` ou factory incompatível, lança `RuntimeException` com mensagem `Service type 'pgsql_query' collision...` (fail-fast, sem overwrite silencioso — `vendor/dreamfactory/df-core/src/Services/ServiceManager.php:369` faria `$this->types[$name]= $type` sem checar). Mesmo para `SystemResourceManager` (`src/ServiceProvider.php:34-41`) — `vendor/dreamfactory/df-system/src/Components/SystemResourceManager.php:182` faria overwrite; o guard evita. `composer.json` root `repositories` tipo `path` + `symlink:false` (`dreamfactory-fork/composer.json:51-57`) impede duplicação física.
- **Enable/disable/uninstall testados:**
  - **Enable:** `php artisan named-query:enable-postgresql {service}` — `src/Console/EnablePostgreSqlNamedQueries.php:10-40` promove `pgsql→pgsql_query` sem duplicar credencial (`$service->type = 'pgsql_query'; $service->save()`), falha clara se `type !== pgsql` (`:27-31`), idempotente se já `pgsql_query` (`:22-26`).
  - **Disable (lifecycle, não deleta):** `NamedQueryRepository::disable()` (`src/Repositories/NamedQueryRepository.php:67-84`) faz `is_active=false + lock_version++` com `lockForUpdate` + `ConflictResourceException`; `NamedQueryAdminResource::handlePATCH` (`src/Resources/NamedQueryAdminResource.php:64-70`) expõe via `PATCH /system/named_query/{id} {is_active:false, lock_version:N}`; revert de service type é `UPDATE service SET type='pgsql' WHERE name=?` (simétrico ao enable, sem migração destrutiva).
  - **Uninstall:** `php artisan migrate:rollback` executa `database/migrations/2026_08_19_000001_create_named_query_tables.php:53-60` (`down()` dropa FK `published_revision_id` antes de `named_query_revision`/`named_query`); `composer remove yamaha/df-named-query && php artisan package:discover` remove o provider do bootstrap — sem rotas órfãs.
  - **Sem rotas paralelas:** pacote não registra `Route::` próprio; apenas estende `ServiceManager::addType` + `SystemResourceManager::addType` + `HasNamedQueryResource::getResourceHandlers()` (`src/Services/HasNamedQueryResource.php:9-19`), então instalação limpa expõe só `system/named_query` e `/{service}/_query`.

### 2. ServiceProvider — `boot`/`register` com `addType`

- **Classe:** `extensions/df-named-query/src/ServiceProvider.php:17`
- **`boot():19-26`** — `loadMigrationsFrom(__DIR__.'/../database/migrations')` (`:21`) + `commands([EnablePostgreSqlNamedQueries, ImportNamedQueries])` (`:23-25`) quando `runningInConsole`. Não registra rotas paralelas.
- **`register():28-55`**:
  - Singleton `DialectCapabilities` (`:31-32`) alias `df.named-query.capabilities` — contrato driver-independent (`src/Services/DialectCapabilities.php:1`, `src/Query/DialectCapabilities.php:1`).
  - `$this->app->resolving('df.system.resource', fn(SystemResourceManager $r) => $r->addType(new SystemResourceType([...])))` (`:34-41`): `name=named_query`, `label=Named Queries`, `class_name=NamedQueryAdminResource::class` (`src/Resources/NamedQueryAdminResource.php:14`). Guard de colisão antes de `addType`.
  - `$this->app->resolving('df.service', fn(ServiceManager $s) => $s->addType(new ServiceType([...])))` (`:43-54`): `name=pgsql_query`, `label=PostgreSQL with Named Queries`, `group=ServiceTypeGroups::DATABASE` (`:48`), `config_handler=PgSqlDbConfig::class` (`:49` — `vendor/dreamfactory/df-sqldb/src/Models/PgSqlDbConfig.php`), `factory => QueryPostgreSql` (`:50-52` — `src/Services/QueryPostgreSql.php:7` extends `PostgreSqlDb` + `HasNamedQueryResource`). Guard de colisão idem.
- **Extensão para outros dialetos:** `HasNamedQueryResource` (`src/Services/HasNamedQueryResource.php:8-19`) injeta `NamedQueryResource::RESOURCE_NAME='_query'` (`src/Resources/NamedQueryResource.php:16`) em `getResourceHandlers()` — `oracle`/`sqlsrv`/`informix` recebem o mesmo recurso quando esses `ServiceProvider`s (`extensions/df-oracle|sqlsrv|informix/src/ServiceProvider.php:17-32`) registraram seus tipos; `NamedQueryRepository::assertServiceExists:279` allowlist `['pgsql_query','oracle','sqlsrv','informix']` prova habilitação.

### 3. Persistência versionada — `named_query` + `named_query_revision` com FK `service_id`

- **Migration:** `extensions/df-named-query/database/migrations/2026_08_19_000001_create_named_query_tables.php:1-61`
  - `up():9-51` — `Schema::create('named_query', ...)` (`:11`) com `increments id`, `unsignedInteger service_id`, `string name(128)`, `text description nullable`, `boolean is_active default false` (`:16`), `unsignedInteger published_revision_id nullable` (`:17`), `unsignedInteger lock_version default 1` (`:18`), `timestamp created_date/last_modified_date`, `unsignedInteger created_by_id/last_modified_by_id`, `foreign service_id → service.id onDelete cascade` (`:24`), `unique [service_id,name]` (`:25`).
  - `Schema::create('named_query_revision', ...)` (`:28`) com `increments id`, `unsignedInteger named_query_id`, `unsignedInteger revision`, `string definition_type(16)`, `longText sql nullable`, `json parameters/output_schema/budgets nullable`, `char checksum(64)`, `foreign named_query_id → named_query.id onDelete cascade` (`:43`), `unique [named_query_id,revision]` (`:44`).
  - `Schema::table('named_query', ...)` (`:47-50`): `foreign published_revision_id → named_query_revision.id onDelete restrict` (`:48-49`) — impede deletar revisão publicada sem despublicar (defesa `restrict`).
  - `down():53-60` seguro: `dropForeign(['published_revision_id'])` (`:55-57`) antes de `dropIfExists('named_query_revision')` (`:58`) e `named_query` (`:59`) — rollback idempotente. `up` roda uma vez via tabela `migrations`; re-`migrate` é no-op.
- **Modelos:**
  - `src/Models/NamedQuery.php:7` extends `BaseSystemModel` (`DreamFactory\Core\Models\BaseSystemModel`), `table=named_query` (`:9`), `fillable service_id,name,description,is_active,published_revision_id,lock_version,...` (`:11-20`), `casts service_id integer, is_active boolean` (`:22-27`), `guarded id,published_revision_id,lock_version` (`:29`), `hasMany revisions` (`:31-34`), `belongsTo publishedRevision` (`:36-39`), `scopeForService` (`:41-44`).
  - `src/Models/NamedQueryRevision.php:7` `table=named_query_revision` (`:9`), `fillable named_query_id,revision,definition_type,sql,parameters,output_schema,budgets,checksum` (`:11-22`), `casts json parameters/output_schema/budgets` (`:24-32`), `belongsTo namedQuery` (`:34-37`).
- **Draft vs published distinguíveis:** criação `is_active=false, lock_version=1, published_revision_id=null` (`src/Repositories/NamedQueryRepository.php:36-42`); `publish():188-189` seta `is_active=true, published_revision_id=revision.id, lock_version++`; `disable():75-76` seta `is_active=false`; listagens de execução só retornam `where is_active true and published_revision_id not null` (`src/Resources/NamedQueryResource.php:41-43, 53-55, 99-102`).
- **Sem cópia de credenciais:** `NamedQuery` nunca persiste URL/user/senha; `service_id FK` é única referência (`database/migrations/...:13-14`). `NamedQueryRepository::FORBIDDEN_CREDENTIAL_FIELDS:22-29` + `validateDefinition:222-238` bloqueia `password,passwd,pwd,secret,username,host,hostname,port,database,dbname,dsn,connection_string,url,jdbc*,credential` com `BadRequestException` com campo+regra (`:225,236`).

### 4. Eventos — `pre`/`post`/`final` via `ServiceEvent`

DreamFactory nativo dispara eventos em duas camadas; Named Query não cria dispatcher paralelo:

- **Service lifecycle (commit):** `Service::saved → ServiceModifiedEvent` (`vendor/dreamfactory/df-core/src/Models/Service.php:131-135`), `Service::deleted → ServiceDeletedEvent` (`:152-155`), ambos extendem `BaseServiceEvent implements ShouldDispatchAfterCommit` (`vendor/df-core/src/Events/BaseServiceEvent.php:8`) com `originalName` capturado (`:22-32`). `ServiceManager::purge()` (`vendor/dreamfactory/df-core/src/Services/ServiceManager.php:343-360`) limpa `Cache::forget('service_mgr:*')` ao receber esses eventos — prova invalidação cluster-wide para `pgsql_query` sem pool local retido.
- **Request lifecycle (resource):** `ServiceEvent extends Event {resource,data,makeData(resource)}` (`vendor/dreamfactory/df-core/src/Events/ServiceEvent.php:4-27`) é o envelope genérico de serviço; DreamFactory despacha `api.{service}.{resource}.pre_process` / `post_process` via `PreProcessApiEvent` (`vendor/dreamfactory/df-core/src/Events/PreProcessApiEvent.php:7` → `name+='.pre_process'`) e `PostProcessApiEvent` (`vendor/dreamfactory/df-core/src/Events/PostProcessApiEvent.php:7` → `.post_process`), ambos extendem `InterProcessApiEvent extends ApiEvent` (`vendor/dreamfactory/df-core/src/Events/InterProcessApiEvent.php:4`) que carrega `path, request, response, resource`. `_query` herda esse ciclo sem registrar listener próprio — `NamedQueryResource::execute():114-140` roda dentro do `ServiceManager::handleServiceRequest` (`vendor/dreamfactory/df-core/src/Services/ServiceManager.php:484-499`) que já checa `Session::checkServicePermission` e dispara pre/post; audit/correlation/OpenAPI usam os mesmos hooks.
- **Mapeamento declarativo:** `ServiceEventMapper` trait (`vendor/dreamfactory/df-core/src/Components/ServiceEventMapper.php:7-101`) expõe `service_event_map` no `config` do service (`ServiceEventMap::whereServiceId`), permitindo admins ligarem scripts a `pre/post` do `_query` sem código.
- **Publicação como evento lógico:** `NamedQueryRepository::publish():146-198` é o "final" do workflow (draft→published) — revalida `NamedSqlCompiler::assertReadOnly` (`:173`) + `DialectCapabilities::assertSupportedForServiceType` (`:180`) dentro de `DB::transaction` + `lockForUpdate`; não emite evento custom — reutiliza o `ServiceEvent` do request para `POST /system/named_query/{id}` (pre/post/final mapeiam para o PATCH publish).

### 5. Fronteiras — `_query` vs API Builder (`publication`) vs adaptador legado (depois de RBAC)

- **Nativo `_query` — contrato DreamFactory:** `GET|POST /api/v2/{service}/_query/{name}` (`docs/architecture/adr-named-query.md:15-17` deste ADR, `src/Resources/NamedQueryResource.php:16,23-95,114-193`). `RESOURCE_NAME='_query'` (`:16`), `getResourceIdentifier()='name'` (`:18-21`). `handleGET:23-70` — lista `forService(serviceId)->where is_active && published_revision_id` (`:41-43`), `capabilities` (`:26-31`), `include_capabilities` (`:36-49`); `handlePOST:72-95` executa; `execute():114-140` valida permissão `checkPermission(action, resource)` (`:116`), carrega `publishedRevision`, compila `NamedSqlCompiler::compile(sql, parameters, values)` (`:130-134`), limita por `budgets.max_rows` vs `parent->getMaxRecordsLimit()` (`:184-192`), e faz `cursor(compiled.sql, bindings)` com `collectRows` streaming (`:137-153`) para não materializar além do budget. RBAC nativo via `listAccessComponents:96-112` que enumera `_query/{name}` com `getPermissions(name)` — sem bypass.
- **API Builder (`publication`) — fronteira explícita:** `dreamfactory/df-api-builder` (`dreamfactory-fork/composer.json:68`) é o caminho de *publication* de APIs compostas; não é o mesmo que `_query`. `_query` é recurso filho de um service SQL concreto, com SQL validado e binds tipados; API Builder compõe múltiplos services. ADR não funde os dois — `_query` permanece no `ServiceManager` (`vendor/dreamfactory/df-core/src/Services/ServiceManager.php:369,699-721`) e `SystemResourceManager` (`vendor/dreamfactory/df-system/src/Components/SystemResourceManager.php:182`) nativos; publicação via `system/named_query` segue `BaseSystemResource` (`src/Resources/NamedQueryAdminResource.php:14-85`).
- **Adaptador legado — depois de RBAC (não agora):** legados `query-builder` (`GET /query-builder/{ds}/{q}` JSON DSL) e `query-param` (`GET /query-param/{ds}/{q}` SQL) permanecem como **adaptador interno após RBAC** — `docs/architecture/dreamfactory-target-api-query.md:122-123` ("Existing api-query routes and headers are implemented by an internal compatibility adapter"). `docs/architecture/inventory-api-query-contract.md:392-410` congela decisão: linha 1 `GET /api/v1/query-builder/{ds}/{q}` = **Preserve (adapter interno → depois Migrate para `GET|POST /api/v2/{service}/_query/{name}`)** (`:396`) até migração de consumidores; `connector-clean-room.md:132-135` reforça que conectores independentes não reintroduzem lógica proprietária. Este ADR não implementa o adapter; ele fica para depois que `envelopes + query catalog + RBAC por _query` estiverem completos — sem duplicar rotas paralelas agora.
- **Sem rotas paralelas hoje:** `ServiceProvider` não chama `Route::`; `HasNamedQueryResource::getResourceHandlers` (`src/Services/HasNamedQueryResource.php:10-18`) injeta `_query` no handler do service — prova que instalação limpa não cria `Route::get('/query-builder'...)` paralelo.

### 6. Upgrade/rebase policy com upstream 7.7.0 — SEM sidecar permanente

- **Upstream pinado:** `config/app.php:7` `version 7.7.0` é o pin canônico; `composer.json` exige `dreamfactory/df-core ~1.0.17`, `df-sqldb ~1.5.0`, `df-system ~0.6.5`, `laravel/framework ^13.7` (`dreamfactory-fork/composer.json:113-129`) — compatível com 7.7.0.
- **Rebase:** fork rebasa sobre `dreamfactorysoftware/dreamfactory` tag `7.7.0` (não merge de sidecar). Conflitos em `composer.lock`/`config/app.php` resolvem mantendo `version 7.7.0` + deps do fork (`yamaha/df-* ^0.1`). Migrations de named-query são *append-only* (`2026_08_19_...`) — não editam migrations upstream.
- **Sem sidecar:** `docs/architecture/dreamfactory-target-api-query.md:3-5` "without creating a permanent sidecar or a parallel administration and authorization platform. All new capabilities remain inside the native DreamFactory lifecycle." Prova: `NamedQueryAdminResource extends BaseSystemResource` (`src/Resources/NamedQueryAdminResource.php:14`) + `NamedQueryResource extends BaseRestResource` (`src/Resources/NamedQueryResource.php:14`) usam `Session::checkServicePermission` (`vendor/dreamfactory/df-core/src/Services/ServiceManager.php:492,528`) e cache `ServiceManager::purge` (`:343-360`) nativos.
- **Rollback:** desinstalar = `migrate:rollback` (`database/migrations/...:53-60`) + `composer remove yamaha/df-named-query`; rebase futuro não reintroduz sidecar porque o recurso é filho `_query` do service, não processo aparte.

## Lifecycle

- `named_query` holds the stable service-scoped name and publication pointer.
- `named_query_revision` holds an immutable definition revision.
- Only the revision referenced by `published_revision_id` is executable.
- Repository performs optimistic locking through `lock_version` (`src/Repositories/NamedQueryRepository.php:285-289`).

The administrative resource is `system/named_query` (`ServiceProvider.php:35-36`). It creates a draft with `POST`, creates a new revision with `PATCH /{id}`, and publishes the requested revision when `publish_revision_id` is included in the PATCH payload (`src/Resources/NamedQueryAdminResource.php:79-86`). These operations remain inside DreamFactory's system-service authorization pipeline. `PATCH` exige `lock_version` (`:57-59`); divergência lança `ConflictResourceException` (`src/Repositories/NamedQueryRepository.php:287`).

The source-service database account must have read-only database permissions. SQL validation is defense in depth; it is not a replacement for database privileges, particularly when a database exposes side-effecting functions.

## Security

- The first SQL compiler only accepts a single `SELECT` or `WITH` statement (`src/Query/NamedSqlCompiler.php:71-73`).
- User values are declared parameters and become unique bound placeholders (`src/Query/NamedSqlCompiler.php:37-54`, pattern `replaceParameters` com `(*SKIP)(*F)` em literais/comentários — `src/Query/NamedSqlCompiler.php:145-149`).
- Parameters inside string literals, quoted identifiers, and comments are not substituted.
- RBAC uses native components `_query` and `_query/{name}` (`src/Resources/NamedQueryResource.php:96-112`).

## Compatibility Boundary

The native endpoint has DreamFactory's response contract. The legacy `query-builder` and `query-param` paths remain a later internal adapter after RBAC, envelopes, and the query catalog are complete (`dreamfactory-target-api-query.md:122-123`, `inventory-api-query-contract.md:396`).
