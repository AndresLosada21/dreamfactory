# ADR v2 — SGA + SGC Nativo (freeze)

## Status
Accepted — freeze v2 — 2026-09-01

## Contexto
`api-query` legado (Spring 3.0.2 / Hibernate 3.6.3 / `PBEWithMD5AndDES`) consome dois WSDLs SOAP em `172.31.16.89`:
- `SGC` (`http://172.31.16.89/SGC/` — `WsConexao.java:34` `@WebService @SOAPBinding(RPC)` `getConexaoById(codConexao):String` → `@@@ERRO@@@` ou JSON `MBeanConexao` com 16 campos incluindo `refSenha` criptografada via `framework/lib/LibCriptografia.java:18` `PBEWithMD5AndDES/CBC/PKCS5Padding` + `BASE64Encoder`)
- `SGA` (`http://172.31.16.89/SGA/` — `WsAcesso.java:30` `validarLogin(codUsuario,dscSenha,nomSistema)` → `MBeanAcessoMenu`, `getPerfilUsuario(codUsuario,sglSistema)` → `MBeanPerfil`, `getUsuarioByMatricula` → `MBeanUsuario`, `sessionSecond=10`, `WsWorkflow.java:insertDocument`)

Design aprovado em `designSgaSgcIntegration` (299e7b0) após discovery em `C:\Users\carlos\Desktop\Projetos-Yamaha\SGC` e `SGA` com allowlist `[172.31.16.89]`.

## Decisão
Substituir `api-query` sidecar por integração nativa DreamFactory:

### Preserve
- `ServiceConfig` FK (`service_id`) como fonte preferida de DataSource — resolve `dataset → service_id` sem duplicar URL/credenciais, registra apenas ID.
- `SecretStore` + `SecretRotationService` para credenciais — nunca logar `refSenha`/`dscSenha`/`secret`, apenas `host`/`checksum`/`key_id`.
- `ClusterInvalidationService` (`nq:cache_generation`) para invalidação cluster-safe de metadata/caches sem sticky session.
- `StructuredLogService` + `MetricsService` + `RequestTracingMiddleware` para observabilidade com cardinalidade ≤1000 e `request_id`.

### Migrate
- **SgcConnectionClient v2** (`172.31.16.89/SGC`) — reescrever `dreamfactory-fork/extensions/df-named-query/src/Services/SgcConnectionClient.php`:
  - `WSDL 172.31.16.89/SGC` com `allowlist=[172.31.16.89]` + `validateConfiguration` rejeita `userInfo` e exige `http(s)`.
  - `BODY_LIMIT=1048576` (1MiB) — rejeita `validateConfiguration(['body'=>...])` e `getConexaoById` soapBody/response >1MB.
  - `TIMEOUT_MS=3000` + `Future.get/cancel` via `curl CURLOPT_TIMEOUT_MS` / `stream_context timeout=ceil(3000/1000)`.
  - `XXE disallow-doctype-decl` + `ACCESS_EXTERNAL_DTD=""` + `LIBXML_NONET` + `<!ENTITY` rejeitado, `LIBXML_DTDATTR|LIBXML_COMPACT`.
  - `@@@ERRO@@@` sanitizado — `str_starts_with`/`str_contains` em body e `innerJson`, loga apenas `host`/`codConexao`/`status`.
  - Logs só `host`/`checksum` via `logSanitized` com `[REDACTED]` para `xml/body/password/secret`.
  - `readSoapReturn` extrai `//return` ou `//getConexaoByIdReturn` ou `//Body` com `DOMXPath` + `LIBXML_NONET`.
- **SgaClient** (`172.31.16.89/SGA`) — novo `dreamfactory-fork/extensions/df-named-query/src/Services/SgaClient.php`:
  - `WSDL 172.31.16.89/SGA` com `validarLogin(codUsuario,dscSenha,nomSistema)` → `MBeanAcessoMenu`, `getUsuarioByMatricula`, `getPerfilUsuario(codUsuario,sglSistema)` → `MBeanPerfil`, `getPerfilById`.
  - Mesmas guards SGC: allowlist `172.31.16.89`, `BODY 1MB 1048576`, `timeout 3000ms` via `curl`, `XXE disallow-doctype`, `@@@ERRO@@@` sanitizado, `readSoapReturn` com `LIBXML_NONET`.
  - `sessionSecond=10` propagado via `ClusterInvalidationService` `nq:cache_generation` + `SgcCircuitBreaker` half-open; sem segredo em `Log::info('sga.validarLogin', ['codUsuario'=>..., 'nomSistema'=>...])`.
- **SgaSgcOrchestrator** (`SGA→SGC via ServiceConfig FK`) — novo `dreamfactory-fork/extensions/df-named-query/src/Services/SgaSgcOrchestrator.php`:
  - `authenticateOrFallback(codUsuario,dscSenha,nomSistema)` → `SgaClient::validarLogin` → `getPerfilUsuario` → `mapSgaPerfilToDfRole(MBeanPerfil→DF role)` via `ServiceConfig FK` + `SecretStore`; fallback para auth local DreamFactory se SGA inalcançável ou `@@@ERRO@@@`, nunca loga `dscSenha`.
  - `resolveConnection(dataset, sgcId)` → `Service::where('name',dataset)->first()` preferido; `SgcConnectionClient::getConexaoById(sgcId)` apenas se `sgc-connection-id` presente + `isConfigured()` + `SgcCircuitBreaker::canAttempt()` (half-open testa recuperação), `recordSuccess`/`recordFailure` + `ClusterInvalidationService::invalidateSource` com `nq:cache_generation` bump.
  - `mapSgaPerfilToDfRole(array $perfil)` traduz `sglPerfil/nomPerfil/perfil` (`administrador`→`admin`, `gerente`→`manager`, etc) com `strtolower` + mapa legado.
- **SecretRotationService** (`PBEWithMD5AndDES → AES-GCM`) — estender `dreamfactory-fork/extensions/df-named-query/src/Services/SecretRotationService.php`:
  - Manter `decryptAesGcm` + `migrateAesGcmToSecretStore` + `getSecret` (RQ-081).
  - Adicionar `decryptPbeLegacy(encryptedBase64,password,iterationCount=1000)` — `BASE64` decode, salt 8 bytes, `PBKDF2 MD5` → `des-cbc`/`des-ede3-cbc` com `openssl_decrypt`, cobre `LibCriptografia.java:18` legado.
  - Adicionar `migratePbeToAesGcm(legacyBase64,pbePassword,keyId,aesKey,iv,tag)` → `decryptPbeLegacy` + `openssl_encrypt aes-256-gcm` + `migrateAesGcmToSecretStore`, loga `secret.rotation.pbe_migrated`.
  - Adicionar `isPbeLegacy` + `getSecretWithPbeFallback` com migração automática, `LOG [REDACTED]` apenas `key_id`.
- **ServiceProvider** (`df.sga/df.sgc/df.sga-sgc-orchestrator`) — estender `dreamfactory-fork/extensions/df-named-query/src/ServiceProvider.php:55`:
  - Registrar singletons `SgcConnectionClient` → `df.sgc`, `SgaClient` → `df.sga`, `SgcCircuitBreaker` → `df.sgc.circuit-breaker`, `SgaSgcOrchestrator` → `df.sga-sgc-orchestrator` com injeção de `SgaClient`, `SgcConnectionClient`, `SgcCircuitBreaker`, `ClusterInvalidationService`, `SecretRotationService`.

### Deprecate
- `api-query` sidecar e `PBEWithMD5AndDES` (`SGA/FacadeAcess.java:32` importa `WsConexao` do SGC) — remover após `RQ-084` canário/rollback/cutover com `ShadowExecutionService` (comparação `status/envelope/schema/tipos/ordering/valores`) e `docs/runbooks/canary.md`.
- `MBeanConexao.refSenha` plaintext em memória — sempre via `SecretStore` + `ClusterInvalidationService`, `password` nunca em `StructuredLogService::redact`.

## Consequências
- **Segurança:** allowlist impede SSRF para hosts não-autorizados; XXE/DTD desabilitados previnem `ENTITY`/`DOCTYPE`; BODY 1MB + timeout 3000ms mitigam DoS; `@@@ERRO@@@` sanitizado evita vazamento de stacktrace; segredos nunca em logs.
- **Compatibilidade:** `ServiceConfig FK` preserva datasets existentes; fallback `authenticateOrFallback` mantém login DF se SGA off; `SgcCircuitBreaker` half-open converge em todos os nós via `Cache::tags`.
- **Migração:** `SecretRotationService::isPbeLegacy` + `getSecretWithPbeFallback` permite migração online `PBE→AES-GCM` sem downtime; `QbMigrationService` + `ConfigReconciliationService` validam `QB_*` checksums.
- **Observabilidade:** `MetricsService` (latency/rows/bytes/rejects/pools ≤1000) + `StructuredLogService` (`request_id` + redact) + `HealthCheckService` readiness 503 quando SGA/SGC inalcançáveis.

## Alternativas consideradas
- Manter `api-query` como proxy: rejeitado — duplica stack Java, mantém `PBEWithMD5AndDES` fraco, aumenta latência e ponto de falha.
- Consumir SGA/SGC via `api-query` HTTP interno: rejeitado — não resolve `SSRF`/`XXE` no Java legado, mantém `BASE64Encoder` sem AES-GCM.

## Referências
- `SGC/src/main/java/br/com/yamaha/sgc/ws/WsConexao/WsConexao.java:34` `getConexaoById` + `MBeanConexao.java:16` + `framework/lib/LibCriptografia.java:18`
- `SGA/src/main/java/br/com/yamaha/sga/facade/ws/WsAcesso.java:30` `validarLogin/getPerfilUsuario` + `sessionSecond=10` + `WsWorkflow.java:insertDocument` + `FacadeAcess.java:32`
- `dreamfactory-fork/extensions/df-named-query/src/Services/SgcConnectionClient.php:1` v2 endurecido (`BODY 1048576`, `TIMEOUT 3000`, `allowlist 172.31.16.89`, `XXE disallow-doctype`)
- `dreamfactory-fork/docs/architecture/adr-sgc.md` vs `adr-sga-sgc-v2.md` (este freeze v2 substitui)
