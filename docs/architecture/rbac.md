# RBAC — Named Queries (`_query`) nativo DreamFactory (RQ-040)

Este documento prova RQ-040: mapeamento 1:1 de Named Queries para RBAC nativo via `Session::checkServicePermission` / `Session::getServicePermissions` — sem autorização paralela.

## Contrato de componentes

- Descoberta (lista): `GET /api/v2/{service}/_query` → componentes `_query/{name}` (um por query publicada+ativa).
- Execução: `GET|POST /api/v2/{service}/_query/{name}` → componente concreto `_query/{name}` com `Verbs::GET` (lista) ou `Verbs::GET|POST` (exec conforme `getAction()`).
- Tipos de service habilitados: `pgsql_query` (`src/Services/QueryPostgreSql.php:8`), `oracle`, `sqlsrv`, `informix` (`src/Repositories/NamedQueryRepository.php:416`).

## Listagem não revela sem permissão

- Fonte canônica: `listAccessComponents():98-122` em `src/Resources/NamedQueryResource.php:98` — enumera `NamedQuery::forService(serviceId)->where is_active && published_revision_id` (`:99-105`) e filtra `if (!empty($this->getPermissions($name)))` (`:107`) para emitir `_query/{name}`. `BaseRestResource::getPermissions` (`vendor/dreamfactory/df-core/src/Resources/BaseRestResource.php:123-133`) delega a `Session::getServicePermissions(service, component=_query/{name}, requestor)`.
- Resposta direta filtrada: `handleGET():25-72` quando `resource` vazio (`:36`) aplica o mesmo filtro (`:60-69` e `:42-50` para `include_capabilities`) antes de `ResourcesWrapper::cleanResources`. Isso garante que `GET /api/v2/{service}/_query` não vaza nomes sem permissão mesmo quando o chamador não consulta `listAccessComponents` primeiro.
- `Session::getServicePermissions` (`vendor/dreamfactory/df-core/src/Utility/Session.php:73-170`) resolve cadeia `exact → component wildcard → service wildcard → all`; `getPermissions` sem recurso checa serviço; com recurso `_query/{name}` checa componente concreto (suporta `_query/*`, `_query/{id}` templates e `*`).

## Execução verifica componente concreto

- `execute():213-296` chama ` $this->checkPermission($this->getAction(), $this->resource)` (`:218`) antes de qualquer leitura de `NamedQuery` ou compilação. `BaseRestResource::checkPermission` (`vendor/dreamfactory/df-core/src/Resources/BaseRestResource.php:104-116`) monta `path = getFullPathName() + '/' + resource` → `_query/{name}` e chama `Session::checkServicePermission(operation=getAction(), service, component=_query/{name}, requestor)`.
- `Session::checkServicePermission` (`vendor/dreamfactory/df-core/src/Utility/Session.php:35-64`) converte `action` para `VerbsMask` e lança `ForbiddenException` se `verb & mask == 0` (salvo `ServiceManager::isAccessException`).
- `handleGET` para recurso nomeado e `handlePOST:83-104` ambos delegam a `execute()`, então `POST /api/v2/{service}/_query/{name}` também exige `POST` (ou verbo mapeado) no componente `_query/{name}`.

## Chamadas internas seguem policy explícita (sem bypass)

- `NamedQueryRepository` e `NamedQueryAudit` não chamam `ServiceManager::handleRequest(..., $checkPermission=false)` nem `Session::` com bypass. `execute()` audita após `checkPermission`; `Repository` é usado apenas por `NamedQueryAdminResource` (`src/Resources/NamedQueryAdminResource.php:14` — `BaseSystemResource`, pipeline `system/named_query` separado) e não reexpõe leitura de `_query`. Não há segundo guard paralelo (ex.: `parallelAuth`, `allowList` custom) — todo enforcement passa por `Session::*` nativo.
- `ServiceManager::handleServiceRequest` (`vendor/dreamfactory/df-core/src/Services/ServiceManager.php:484-499`) já faz `checkServicePermission` antes de `getService()->handleRequest`; o resource reforça com check por componente para evitar que permissão em `service` sem componente libere qualquer query.

## Sem autorização paralela após migração

- Removida qualquer checagem fora de `Session::checkServicePermission`/`getServicePermissions`. `grep -r parallelAuth` não retorna ocorrências no pacote; `NamedQueryResource` referencia apenas `Session::` via `checkPermission`/`getPermissions`/`Session::getCurrentUserId` (audit). `HasNamedQueryResource` (`src/Services/HasNamedQueryResource.php:9-19`) só registra handler — sem guarda.

## Admin vs execução

- Admin: `system/named_query` (`ServiceProvider.php:35-53`, `src/Resources/NamedQueryAdminResource.php:14`) — CRUD versionado com `lock_version`, `publish`. Não expõe execução.
- Execução: `_query` filho do service de dados — RBAC por `role.services[].component` no DreamFactory (ex.: `{"service":"my_pg","component":"_query/orders_by_customer","verb_mask":3}` para GET|POST).

## Testes

- `TddUltraSprint3Test::test_rq040_*` (3 casos) validam `listAccessComponents`+`getPermissions`, `checkPermission`+`_query/{name}`, e ausência de `parallelAuth`/bypass. `TddUltraSprint2Test` permanece verde (não toca RQ-040).

## Arquivos

- `extensions/df-named-query/src/Resources/NamedQueryResource.php:25,36,60,98,107,213,218`
- `extensions/df-named-query/src/Services/HasNamedQueryResource.php:9`
- `extensions/df-named-query/src/ServiceProvider.php:35,55`
- `vendor/dreamfactory/df-core/src/Resources/BaseRestResource.php:104,123`
- `vendor/dreamfactory/df-core/src/Utility/Session.php:35,73`
- `vendor/dreamfactory/df-core/src/Services/ServiceManager.php:484`
- `docs/architecture/rbac.md` (este arquivo)
- `docs/architecture/adr-named-query.md:67,95` (referência de fronteira e Security/RBAC)
