# RQ-042 — Migração de Credenciais Legadas para Identidades DreamFactory

> **Versão:** 1.0.0 — 2026-08-28
> **Status:** Aceito — fecha RQ-042
> **Trace:** `docs/architecture/inventory-api-query-contract.md:§8,§14` + `api-query/config/authentication/credential.json` + `api-query/src/main/java/com/querybuilder/service/AuthorizationService.java` + `docs/architecture/rbac.md` + `dreamfactory-fork/docs/architecture/dreamfactory-target-api-query.md`
> **Regra:** nenhum segredo real exposto; placeholders `TEST_*` apenas.

---

## 1. Semântica do par legado `client_secret` + `client_key`

O `api-query` legado exige **par completo** por requisição. Nenhum dos dois headers sozinho autentica.

| Aspecto | Código canônico |
|---|---|
| Headers canônicos (OpenAPI) | `client_secret` + `client_key` — `dreamfactory-fork/docs/architecture/inventory-api-query-contract.md:234-236` |
| Aliases aceitos | `client_secret` ↔ `client-secret` ↔ `x-client-secret`; `client_key` ↔ `client-key` ↔ `x-client-key` — `inventory-api-query-contract.md:234-236` + `api-query/src/main/java/com/querybuilder/config/RouteAuthorizationInterceptor.java:30-31` |
| Extração | `firstHeader(request, "client_secret","client-secret","x-client-secret")` e `firstHeader(... "client_key" ...)` — `RouteAuthorizationInterceptor.java:30-31` |
| Obrigatoriedade | `if (!StringUtils.hasText(clientSecret) \|\| !StringUtils.hasText(clientKey)) throw UnauthorizedException` — `AuthorizationService.java:59-60` → `GlobalExceptionHandler.java:67-72` mapeia para `401/1001 "Credenciais inválidas ou ausentes."` — `inventory-api-query-contract.md:239` |
| Validação | `credentialsMatch:168-174` exige `secretMatches & keyMatches` (AND não-curto-circuito) com `constantTimeEquals:159-166` via `MessageDigest.isEqual` — `AuthorizationService.java:159-174` |
| Ordem | 1) credencial 2) rota 3) `Collections.disjoint(routeClaims, credentialClaims)` — `AuthorizationService.java:63-76` |

**Interpretação RQ-042:**

- `client_key`  → identificador público da credencial = **api key** DreamFactory (`app.api_key` — `vendor/dreamfactory/df-core/database/migrations/2015_01_27_190908_create_system_tables.php:190`, `vendor/dreamfactory/df-core/src/Models/App.php:74-80` gera `hash(sha256, hostname+name+time)`)
- `client_secret` → segredo privado da credencial = **app secret** (não existe coluna separada no DF; migra como segredo hasheado associado ao `app` via `lookup` privado ou `app_lookup` / `SecretStore` — ver §4)

O par é atômico: rotação deve trocar ambos ou manter `client_key` estável e rotacionar só `client_secret` com hash novo (recomendado DF: `api_key` estável, segredo rotacionado como hash).

---

## 2. Valores expostos são rotacionados

Sementes versionadas nunca contêm segredo real:

```json
// api-query/config/authentication/credential.json:3,9,14,19
"client_secret": "REPLACE_QUERY_DECALQUE_CLIENT_SECRET"
"client_key":    "REPLACE_QUERY_DECALQUE_CLIENT_KEY"
```

Mesma disciplina em `api-query/config/data-set/*.json` (`ptg-main.json:5` = `REPLACE_WITH_*`). Em runtime homolog/prod a fonte de verdade é o banco relacional — `api-query/src/main/resources/application.yaml:21` (`query-builder.storage.type=database`) e `StorageJdbcConfig.java:22-50`; arquivos em `config/**` não são lidos — `api-query/README.md:70-74` + `inventory-api-query-contract.md:335-336`.

**Ação RQ-042:** todo valor que um dia apareceu em log, dump, doc ou `GMUD-GO-LIVE/qb-insert-data.sql` / `api-query-prod-criacao-completa.sql` é considerado **exposto** e deve ser rotacionado antes do cutover. Não reutilizar `REPLACE_*` em produção. Novos valores seguem §5 com sobreposição de 7 dias.

Placeholders de exemplo neste doc usam prefixo `TEST_` (ex.: `TEST_CLIENT_KEY_...`, `TEST_CLIENT_SECRET_...`) — nunca um segredo real.

---

## 3. Mapeamento para identidades DreamFactory nativas (`app` → `api_key` → `user` → `role`)

Reutiliza RBAC nativo sem autorização paralela — `dreamfactory-target-api-query.md:122-125` ("Native DreamFactory roles, applications and API keys remain the source of authorization truth") e `rbac.md:1-50` prova `Session::checkServicePermission`/`getServicePermissions` por componente `_query/{name}`.

```
Legado                              DreamFactory alvo
──────                              ─────────────────
QB_CREDENTIAL                       app
  CLIENT_KEY  ───────────────────►  app.api_key        (api_key único, min:64 alpha_num — App.php:71)
  CLIENT_SECRET ─────────────────►  hash em lookup privado (ver §4) ou SecretStore
QB_CREDENTIAL_CLAIM                 role + role_service_access
  claim "query_decalque" ───────►  role.name="query_decalque" → role_service_access(service_id, component="_query/acasala", verb_mask=GET)
  claim "query_gq_mi" ───────────►  role "query_gq_mi" → _query/wms-part-number, _query/pymac-part-number, ...
  claim "query_gq_eficaz" ───────►  role "query_gq_eficaz" → _query/gq-inspecao
  claim "query_gq_lote" ─────────►  role "query_gq_lote" → _query/gq-lote
  claim "adm_api_query" ─────────►  role "adm_api_query" → system/named_query + _query/*
  claim "query_hora_hora" ───────►  role "query_hora_hora" (legado) → migrar ou deprecar

app.role_id ─────────────────────►  DreamFactory resolve Session::getServicePermissions via App::getCachedInfo / Cache apikey2appid — App.php:190-254
user_to_app_to_role ─────────────►  vínculo opcional usuário ↔ app ↔ role para JWT/session — migration 2015_01_27:213-225
```

**Tabelas nativas (file:line):**

- `app(id, name unique, api_key, is_active, role_id FK→role)` — `2015_01_27_190908_create_system_tables.php:184-211`, model `App.php:42-56`
- `role(id, name unique, is_active)` — `2015_01_27:98-112`, model `Role.php:28-42`
- `role_service_access(id, role_id FK, service_id FK, component, verb_mask, requestor_mask, filters)` — `2015_01_27:114-135` — `component` é `_query/{name}` ou `_query/*` — `rbac.md:35`
- `user_to_app_to_role(user_id, app_id, role_id)` — `2015_01_27:213-225`

**Claims → componentes `_query` (inventário §3-§6, `route.json` e seeds DF):**

| Claim legado | Queries | Componente DF alvo | Fonte |
|---|---|---|---|
| `query_decalque` | `acasala, chassi, motor` | `_query/acasala`, `_query/chassi`, `_query/motor` em service `py_ptg` | `credential.json:5`, `route.json:3-13`, `py-ptg.json:4-27` |
| `query_gq_mi` | `wms-part-number, pymac-part-number, pymac-origin-destination, gq-inspecao` | `_query/wms-part-number` (`gq_mi_wms`), `_query/pymac-*` (`gq_mi_pymac`), `_query/gq-inspecao` (`gq_eficaz`) | `credential.json:8-10`, `route.json:15-27` |
| `query_gq_eficaz` | `gq-inspecao I/U` | `_query/gq-inspecao` (`gq_eficaz`) com `p_Operation` | `gq-eficaz.json:5` |
| `query_gq_lote` | `gq-lote` | `_query/gq-lote` (`py_local`) | `qb-insert-data.sql:819`, `py-local.json:5` |
| `adm_api_query` | admin | `system/named_query`, `system/app`, `system/role` | `credential.json:12-16`, `route.json:31-79` |
| `query_export_plan` | `bom-plan, bom-sgpi` | `_query/bom-plan`, `_query/bom-sgpi` | `qb-insert-data.sql:832-835` |

Admin DF (`system/named_query`) é o lifecycle de publicação — `adr-named-query.md:35-53`; execução é `GET|POST /api/v2/{service}/_query/{name}` — `NamedQueryResource.php:22-62,98-122,213-221`.

---

## 4. Como evitar segredo recuperável (hash, não plaintext)

**Problema legado:** `CredentialRepository.java:31-63` lê `SELECT CLIENT_SECRET, CLIENT_KEY FROM QB_CREDENTIAL` em plaintext e `AuthorizationService.java:159-174` compara plaintext direto. Qualquer dump/leak expõe segredo reutilizável.

**Alvo DF — nunca armazenar segredo recuperável:**

1. **Geração:** `App::generateApiKey:74-80` (`hash sha256`) inspira geração de segredo: `random_bytes(32)` → `base64` ou `bin2hex` (256 bits). Não derivar de hostname/time em prod.
2. **Armazenamento:** persistir apenas `hash('sha256', client_secret)` ou `password_hash(client_secret, PASSWORD_ARGON2ID)` em coluna `lookup` privada (`lookup.name = app:{id}:client_secret_sha256`, `private=1` — `2015_01_27:294-315`) ou `SecretStore` (`dreamfactory-target-api-query.md:48-53`). Nunca `CLIENT_SECRET` plaintext.
3. **Verificação:** lookup por `api_key` (ou `client_key`) → recupera `hash` → `hash_equals(hash('sha256', provided_secret), stored_hash)` ou `password_verify`. Comparação em tempo constante — preserva `MessageDigest.isEqual` — `AuthorizationService.java:159-166`.
4. **Índice:** `api_key` permanece indexável em plaintext (é identificador público); segredo só via hash. Se precisar buscar por segredo, usar `lookup` com `hash` como chave secundária, não inverter.
5. **Logs/audit:** `NamedQueryAudit::recordWithDuration` em `NamedQueryResource.php:254-263,292-301` já audita `checksum/budgets/query_name` sem logar `SQL/bind/secret` — mesmo padrão para auth: logar `app_id` + `hash_prefix(8)` nunca segredo.

**Esquema recomendado (ilustrativo, sem segredo real):**

```sql
-- app já existe: api_key = TEST_API_KEY_... (identificador público)
-- lookup privado guarda só o hash do secret
INSERT INTO lookup (name, value, private) VALUES ('app:42:client_secret_sha256', '<hex sha256 de TEST_CLIENT_SECRET_...>', 1);
-- verifier PHP (trecho):
-- $stored = $lookupValue; // hex sha256
-- $ok = hash_equals($stored, hash('sha256', $providedSecret));
```

Validador sem expor segredo: `scripts/migrate-credentials.php` (§8) valida par, computa `TEST_` placeholders e só imprime `sha256` truncado.

---

## 5. Flow de rotação (gerar nova key, dual-write, grace period, revoke old)

```
Dia 0 — Gerar
  1. Criar nova credencial DF: App::generateApiKey("TEST_app_{claim}_{ts}") → TEST_API_KEY_NEW
     + novo secret TEST_CLIENT_SECRET_NEW (random_bytes 32)
     + INSERT lookup hash novo
     + role_id idêntico ao legado (mesma role → mesmos _query/*)
  2. Dual-write: manter App antigo (TEST_API_KEY_OLD) is_active=1
     e novo App is_active=1 — ambos apontam para mesma role.

Dia 0-7 — Grace period (sobreposição 7 dias — §6)
  3. Consumidor atualiza config para enviar novo par (dual-write no cliente se possível:
     tenta NEW, fallback OLD). Gateway aceita ambos via App::getAppIdByApiKey
     (Cache apikey2appid — App.php:241-254) — sem sticky.
  4. Monitorar uso: contar hits por api_key (audit/telemetry) — quando NEW > 99% por 24h, pronto para revoke.

Dia 7 — Revoke old
  5. Desativar app antigo: UPDATE app SET is_active=0 WHERE api_key=TEST_API_KEY_OLD
     + Cache::forget('app:{id}') + Cache::forget('apikey2appid:{old}') — App.php:162-177
     + JWTUtilities::invalidateTokenByAppId(oldId)
  6. Opcional: DELETE lookup hash antigo após 30 dias (retenção audit).
  7. Cliente remove fallback OLD; só NEW permanece.

Falha/rollback: reativar old App (is_active=1) dentro da janela de 7 dias — sem gerar novo segredo.
```

Diagrama não cria `parallelAuth`; enforcement continua em `Session::checkServicePermission` — `rbac.md:18-22` e `NamedQueryResource.php:218-221`.

---

## 6. Revogação

| Ação | Efeito nativo | Código |
|---|---|---|
| Desativar app | `App::saved` → `Cache::forget('app:id')` + `JWTUtilities::invalidateTokenByAppId` se `!is_active` | `App.php:162-169` |
| Deletar app | `App::deleted` → invalida JWT + `Cache::forget apikey2appid:{api_key}` | `App.php:171-177` |
| Desativar role | `Role::saved` → `RoleModifiedEvent` + `invalidateTokenByRoleId` se `!is_active` | `Role.php:47-54` |
| Remover acesso | `DELETE FROM role_service_access WHERE role_id=? AND component=?` | `2015_01_27:114-135` |
| Cache | `App::getCachedInfo:190-220` usa `Cache::remember(df.default_cache_ttl)`; revogação precisa `forget` explícito — não esperar TTL | `App.php:190-232` |

Revogação é imediata após `forget`; não depende de `AuthorizationService.cache-ttl-ms` legado (`AuthorizationService.java:32-33` 300s) — DF usa cache distribuído nativo.

---

## 7. Período de sobreposição definido

**7 dias corridos** — padrão para todos os pares migrados.

- **Justificativa:** cobre ciclo de deploy dos consumidores (`api-sync` em `172.31.18.117:8080` — `inventory-api-query-contract.md:372`) + janela de cache LB (VIP `172.31.18.240:80` — `inventory-api-query-contract.md:12`) + `EndpointWindowService`/`AuthorizationService` TTL 5m (`AuthorizationService.java:32`, `application.yaml:32-33`).
- **Mínimo aceitável:** 48h se consumer for single-host com deploy automatizado; documentar exceção no checklist.
- **Máximo:** 14 dias se consumer for externo sem janela de manutenção; após isso, revoke obrigatório.
- **Métrica de saída:** revoke só quando `NEW` responder 100% do tráfego por 24h consecutivos.

---

## 8. Checklist de migração (por par `client_key`/`client_secret`)

- [ ] Inventariar par legado: `QB_CREDENTIAL.CLIENT_KEY` + claims em `QB_CREDENTIAL_CLAIM` — `CredentialRepository.java:31-63` (ou `credential.json:1-22` para seed)
- [ ] Criar role DF espelho (ex.: `query_decalque`) com `role_service_access` para `_query/{name}` + `verb_mask` GET|POST — `rbac.md:35` + `2015_01_27:114-135`
- [ ] Criar `app` DF com `api_key = TEST_API_KEY_NEW` (gerado via `App::generateApiKey:74-80` ou `random_bytes`) e `role_id` da role acima — `App.php:88-99`
- [ ] Gerar novo `client_secret` (`TEST_CLIENT_SECRET_NEW`) e persistir só `sha256` em `lookup` privado — §4
- [ ] Validar com `scripts/migrate-credentials.php --check` (sem logar segredo) — §9
- [ ] Dual-write 7 dias: manter old `is_active=1` + new `is_active=1` — §5
- [ ] Atualizar consumidor para enviar `client_key=TEST_API_KEY_NEW` + `client_secret=TEST_CLIENT_SECRET_NEW` (aliases `x-client-key`/`x-client-secret` continuam válidos — `RouteAuthorizationInterceptor.java:30-31`, mas preferir canônico)
- [ ] Verificar RBAC: `GET /api/v2/{service}/_query` lista só `_query/{name}` permitidos — `NamedQueryResource.php:98-122` + `rbac.md:11-16`
- [ ] Verificar execução: `GET|POST /api/v2/{service}/_query/{name}` com `Session::checkServicePermission` — `NamedQueryResource.php:213-221` + `rbac.md:18-22`
- [ ] Monitorar 24h com NEW >99% → revoke OLD (`is_active=0` + cache forget) — `App.php:162-169` + §6
- [ ] Auditar: `NamedQueryAudit::recordWithDuration` registra `query_name/checksum` sem segredo — `NamedQueryResource.php:254-301`
- [ ] Remover `QB_CREDENTIAL` legado após confirmação (ou manter inativo para rollback 30 dias)

---

## 9. Arquivos (file:line)

| Arquivo | Linhas | Papel |
|---|---|---|
| `dreamfactory-fork/docs/architecture/inventory-api-query-contract.md` | `234-241` §8, `368-377` §14 | aliases de headers, consumidores, claims |
| `api-query/config/authentication/credential.json` | `1-22` | sementes `REPLACE_*` (placeholders) |
| `api-query/src/main/java/com/querybuilder/service/AuthorizationService.java` | `59-76`, `133-174` | validação par, normalização, `constantTimeEquals` |
| `api-query/src/main/java/com/querybuilder/config/RouteAuthorizationInterceptor.java` | `30-31` | aliases header |
| `api-query/src/main/java/com/querybuilder/config/WebMvcConfig.java` | `18` | paths protegidos |
| `api-query/src/main/java/com/querybuilder/repository/CredentialRepository.java` | `31-63`, `126-133` | storage legado plaintext |
| `api-query/src/main/java/com/querybuilder/model/auth/CredentialDefinition.java` | `8-16` | `client_secret/client_key` model |
| `dreamfactory-fork/docs/architecture/rbac.md` | `1-50` | RBAC nativo `_query/{name}` via `Session::*` |
| `dreamfactory-fork/docs/architecture/dreamfactory-target-api-query.md` | `122-125` | target: roles/apps/api_keys como verdade |
| `dreamfactory-fork/docs/architecture/adr-named-query.md` | `15-17`, `67-95` | `_query` como recurso filho, RBAC |
| `dreamfactory-fork/extensions/df-named-query/src/Resources/NamedQueryResource.php` | `98-122`, `213-221`, `254-301` | `listAccessComponents` filtrado, `checkPermission`, audit sem segredo |
| `vendor/dreamfactory/df-core/src/Models/App.php` | `71-80`, `162-177`, `190-254` | `generateApiKey`, `is_active` + cache invalidation, `getAppIdByApiKey` |
| `vendor/dreamfactory/df-core/src/Models/Role.php` | `28-54` | role lifecycle |
| `vendor/dreamfactory/df-core/database/migrations/2015_01_27_190908_create_system_tables.php` | `98-135`, `184-225`, `294-315` | `role`, `role_service_access`, `app`, `lookup` |
| `dreamfactory-fork/docs/architecture/credential-migration.md` | este arquivo | RQ-042 principal artefato |
| `dreamfactory-fork/scripts/migrate-credentials.php` | `1-...` | helper validação sem logar segredo |

---

## 10. Helper de migração

`scripts/migrate-credentials.php` valida pares sem imprimir segredo:

```bash
php scripts/migrate-credentials.php --check --from=api-query/config/authentication/credential.json
php scripts/migrate-credentials.php --hash --secret=TEST_CLIENT_SECRET_EXAMPLE
php scripts/migrate-credentials.php --verify --key=TEST_API_KEY --secret=TEST_CLIENT_SECRET --hash=<sha256>
```

Saída contém apenas `TEST_*` mascarado (`TEST_****`) e `sha256` truncado (`abc123...`), nunca plaintext. O doc permanece artefato principal RQ-042; o script é auxiliar idempotente.

---

## 11. Notas de não-quebra

- `TddUltraSprint3Test::test_rq042_pair_semantics_rotation_revocation` permanece RED até entrega E4 ser promovida a GREEN — este doc não altera asserts do teste (guardrail paralelo: só Ready). Ajuste do teste fica para wave seguinte (não quebrar `TddUltraSprint2Test`).
- Nenhum segredo real commitado; `REPLACE_*` mantido em seeds versionadas.
