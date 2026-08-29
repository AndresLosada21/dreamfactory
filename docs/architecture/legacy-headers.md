# RQ-043 — Middleware de Headers e Rotas Legadas

> **Versão:** 1.0.0 — 2026-08-28
> **Status:** Aceito — fecha RQ-043
> **Trace:** `docs/architecture/inventory-api-query-contract.md:§8,§7,§11` + `api-query/src/main/java/com/querybuilder/config/RouteAuthorizationInterceptor.java:30-31` + `api-query/src/main/java/com/querybuilder/service/AuthorizationService.java:59-76,59-174,133-158` + `dreamfactory-fork/docs/architecture/rbac.md` + `extensions/df-named-query/src/Resources/NamedQueryResource.php:execute checkPermission` + `dreamfactory-fork/docs/architecture/credential-migration.md:§1`

---

## 1. Contrato de aliases — inventário congelado §8

| Header canônico (OpenAPI) | Aliases aceitos | Código legado | Obrigatório |
|---|---|---|---|
| `client_secret` | `client-secret`, `x-client-secret` | `RouteAuthorizationInterceptor.java:30` + `AuthorizationService.java:59-60` | Sim, ambos não-vazios — `AuthorizationService.java:59` + `GlobalExceptionHandler.java:67-72` → `401/1001` |
| `client_key` | `client-key`, `x-client-key` | `RouteAuthorizationInterceptor.java:31` | Sim, ambos não-vazios |

Alias inclui underscore `_`, hífen `-` e `x-` prefix — `inventory-api-query-contract.md:234-236`. Comparação legado em tempo constante via `MessageDigest.isEqual` — `AuthorizationService.java:159-166` (`credentialsMatch:168-174` exige `secretMatches & keyMatches` com `&` não-curto-circuito). Falha sem credencial → `UnauthorizedException` → `401/1001` — `GlobalExceptionHandler.java:67-72`; claim insuficiente → `ForbiddenException` → `403/1003` — `GlobalExceptionHandler.java:74-79`.

---

## 2. Par completo — AND não-curto-circuito

Espelha `AuthorizationService.java:59-60`:

```java
// AuthorizationService.java:59-60
if (!StringUtils.hasText(clientSecret) || !StringUtils.hasText(clientKey)) {
    throw new UnauthorizedException("Cabeçalhos client_secret e client_key são obrigatórios");
}
// + credentialsMatch:168-174
boolean secretMatches = constantTimeEquals(providedSecret, expected.getClientSecret());
boolean keyMatches = constantTimeEquals(providedKey, expected.getClientKey());
return secretMatches & keyMatches; // & não-curto-circuito
```

Middleware equivalente em `LegacyHeaderMiddleware.php`:

- Extrai `secret = firstHeader(client_secret|client-secret|x-client-secret)` e `key = firstHeader(client_key|client-key|x-client-key)` — `RouteAuthorizationInterceptor.java:30-31,40-48`.
- Se **qualquer** alias legado presente (`x-client-secret` ou `client-key` etc.), exige par completo: `hasSecret & hasKey` (avalia ambos antes do `&`, não-curto-circuito) — se `false` → `401 UnauthorizedException` (`DreamFactory\Core\Exceptions\UnauthorizedException.php:11-24` → HTTP 401).
- Se nenhum alias legado presente, não impõe `401` — deixa passar para RBAC nativo via `Session` / `X-DreamFactory-Api-Key` / `JWT`.
- Se ambos ausentes e legacy header enviado incompleto, mensagem genérica `"Credenciais inválidas ou ausentes."` — preserva `AuthorizationService.java:60` + `GlobalExceptionHandler.java:69`.

---

## 3. Normalização de aliases → canônico antes de NamedQueryResource ou ApiDocs

`handle()` normaliza antes de `next($request)`:

```php
$secret = firstHeader(SECRET_ALIASES); $key = firstHeader(KEY_ALIASES);
if (hasText($secret)) $request->headers->set('client_secret', $secret);
if (hasText($key)) $request->headers->set('client_key', $key);
```

Downstream pode consumir `$request->header('client_secret')` sem enumerar 6 variantes. Aplica a `NamedQueryResource.php:82-106 handleGET/handlePOST → execute():218 checkPermission` e a geração OpenAPI (`ApiDocs`/`QueryRouteOpenApiService`). Não duplica resolução de header.

---

## 4. Preserva longest-prefix para rota (max length match)

Espelha `AuthorizationService.java:68-71` + `133-158`:

```java
// AuthorizationService.java:68-71
RouteDefinition routeDefinition = current.routes().stream()
    .filter(route -> matchesRoute(route.getRoute(), normalizedPath))
    .max(Comparator.comparingInt(route -> normalizeRoute(route.getRoute()).length()))
    .orElseThrow(...);
// normalizeRoute:133-146 remove /api/v1, garante / prefix
// matchesRoute:148-158 — equals ou startsWith(route+"/"), tolera barra final
```

PHP equivalente em `LegacyHeaderMiddleware.php`:

- `normalizeRoute($route):33-46` — trim, garante `/`, remove `/api/v1`, `"/"` fallback — `AuthorizationService.java:133-146`.
- `matchesRoute(configuredRoute, normalizedPath):48-58` — equals ou `startsWith(normalizedRoute+"/")`, tolera `/` final — `AuthorizationService.java:148-158`.
- `findLongestMatchingRoute(routes, requestPath):60-71` — filtra `matchesRoute` e pega `max(strlen(normalizeRoute(route)))` — `AuthorizationService.java:68-71`. Evita shadowing: rota mais específica vence.
- Mesma semântica em `RouteRepository.java:126-138` (normalização na persistência) e `EndpointWindowService.java:225-248` (referência em `inventory-api-query-contract.md:227`).

---

## 5. Endpoints nativos não contornam autorização — reutilizar Session::checkServicePermission

Este middleware **não** autentica nem autoriza rota; apenas normaliza e exige par completo quando legado presente. Autorização efetiva:

- `NamedQueryResource.php:218` chama `$this->checkPermission($this->getAction(), $this->resource)` antes de ler `NamedQuery` ou compilar — `BaseRestResource.php:104-116` monta `path = getFullPathName()+"/"+resource → _query/{name}` e chama `Session::checkServicePermission(operation=getAction(), service, component=_query/{name}, requestor)`.
- `Session::checkServicePermission` — `vendor/dreamfactory/df-core/src/Utility/Session.php:35-64` converte `action` para `VerbsMask` e lança `ForbiddenException` se `verb & mask == 0` (salvo `ServiceManager::isAccessException`). `getServicePermissions:73-170` resolve cadeia `exact → component wildcard → service wildcard → all`.
- `ServiceManager::handleServiceRequest` — `vendor/dreamfactory/df-core/src/Services/ServiceManager.php:484-499` já faz `checkServicePermission` antes de `getService()->handleRequest`; o resource reforça por componente para evitar liberação via serviço sem componente — `rbac.md:26`.
- **Sem autorização paralela:** não existe `parallelAuth`, `allowList` custom ou segundo guard. `grep -r parallelAuth` sem ocorrências no pacote — `rbac.md:30`. `HasNamedQueryResource.php:9-19` só registra handler. `NamedQueryRepository` e `NamedQueryAudit` não chamam `handleRequest(..., checkPermission=false)` — `rbac.md:24-25`.

Uso correto — aplicar middleware onde headers legados existem (rota legada `/api/v1/query-builder/**`, `/api/v1/query-param/**` ou compat shims), **sem** desabilitar `checkPermission` nos endpoints nativos `/api/v2/{service}/_query/**`. Qualquer chamada que chegue a `_query` passa por `Session::*` nativo.

---

## 6. Registro em DreamFactory (app/Http/Middleware vs extensão)

DreamFactory 4.x / Laravel 13 registra aliases via `bootstrap/app.php:23` (`->withMiddleware(alias)`). `app/Http/Kernel.php:16-18` é legado (mantido para compat). RQ-043 registra em ambos:

- **Fonte canônica:** `extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php:1-180` — PSR-4 `Yamaha\DreamFactory\NamedQuery\Http\Middleware\LegacyHeaderMiddleware`.
- **Wrapper nativo:** `app/Http/Middleware/LegacyHeaderMiddleware.php:1-22` — estende o canônico; existe para satisfazer contrato `TddUltraSprint3Test:88-90` que aceita tanto extensão quanto `app/Http/Middleware`.
- **Registro em Kernel:** `app/Http/Kernel.php:15-18` — `routeMiddleware['legacy.headers'] => LegacyHeaderMiddleware::class` (documentado; compat).
- **Registro em Bootstrap:** `bootstrap/app.php:23-26` — `$middleware->alias(['legacy.headers' => LegacyHeaderMiddleware::class])` (efetivo no runtime Laravel 13).

Aplicar no pipeline que serve compatibilidade legada, ex.:

```php
Route::middleware(['api', 'legacy.headers'])->group(fn () => /* shim /api/v1/query-builder/** */);
Route::middleware(['legacy.headers'])->group(fn () => /* /api/v2/{service}/_query/** se quiser alias legado também em nativo */);
```

Sem isso, aliases `x-client-secret`/`client-secret` não chegam ao `Request::header()` canônico esperável por libs que só leem `client_secret`.

---

## 7. Arquivos

| Arquivo | Papel | file:line âncora |
|---|---|---|
| `extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php` | **Fonte canônica** — normaliza aliases, par completo `&` não-curto-circuito, normalizeRoute/matchesRoute/findLongestMatchingRoute, delega para RBAC nativo | `:8-180` |
| `app/Http/Middleware/LegacyHeaderMiddleware.php` | Wrapper — estende canônico; satisfaz `TddUltraSprint3Test:88-90` | `:11-21` |
| `app/Http/Kernel.php` | Registro `routeMiddleware['legacy.headers']` (compat) | `:15-18` |
| `bootstrap/app.php` | Registro efetivo Laravel 13 `$middleware->alias(['legacy.headers'=>...])` | `:23-26` |
| `docs/architecture/legacy-headers.md` | **Este arquivo** — contrato RQ-043 | este arquivo |
| `docs/architecture/rbac.md` | Prova RBAC nativo `Session::*` sem parallelAuth, `checkPermission:218` | `:18-22,30` |
| `docs/architecture/credential-migration.md` | Semântica do par, rotação/revogação, §1 aliases | `:10-22` |
| `docs/architecture/inventory-api-query-contract.md` | Tabelas §8 headers, §7 roteamento, §11 storage | `:234-241,214-228` |
| `api-query/src/main/java/com/querybuilder/config/RouteAuthorizationInterceptor.java` | Origem `firstHeader` com 3 aliases | `:30-31,40-48` |
| `api-query/src/main/java/com/querybuilder/service/AuthorizationService.java` | `authorize:59-76` (par, credencial→rota→claim), `normalizeRoute:133-146`, `matchesRoute:148-158`, `constantTimeEquals:159-166`, `credentialsMatch:168-174`, `max(...)` longest-prefix `:68-71` | `:59-76,133-174` |
| `vendor/dreamfactory/df-core/src/Utility/Session.php` | `checkServicePermission:35-64`, `getServicePermissions:73-170` | `:35-64,73-170` |
| `vendor/dreamfactory/df-core/src/Resources/BaseRestResource.php` | `checkPermission:104-116`, `getPermissions:123-133` | `:104-133` |
| `extensions/df-named-query/src/Resources/NamedQueryResource.php` | `execute:218 checkPermission`, `listAccessComponents:109-124`, `handleGET:27-83` | `:109-124,218` |
| `tests/Feature/TddUltraSprint3Test.php` | `test_rq043_header_aliases_underscore_hyphen_x:86-98`, `test_rq043_longest_prefix_and_native_no_bypass:100-103` | `:86-103` |

---

## 8. Notas de não-quebra

- `TddUltraSprint3Test` permanece RED até entrega ser promovida a GREEN (sprint 3 E4 será virado para GREEN depois) — guardrail paralelo: só Ready. Este artefato não altera asserts do teste; apenas satisfaz contrato (arquivos nos paths aceitos, aliases underscore/hífen/x-, par completo, longest-prefix, sem bypass nativo).
- `TddUltraSprint2Test` permanece verde — RQ-043 não altera `NamedQueryResource.php:218`, `rbac.md` nem budgets.
