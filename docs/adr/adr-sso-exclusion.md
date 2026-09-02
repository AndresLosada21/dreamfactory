# ADR-002 — SSO fora de escopo + SGA Facade + OAuth Proxy (RQ-SSO-01/02/03)

## Contexto
DreamFactory premium tem SSO LDAP/SAML/OAuth2 comercial. Fork Yamaha opta por manter `Session::checkServicePermission` (df-core) + `SGA` (`172.31.16.89/SGA`) `validarLogin`/`getPerfilUsuario` (`WsAcesso.java:30`) como IdP, e OAuth2 como proxy externo, evitando reimplementar SAML.

## Decisão
- **Fora de escopo**: SAML/LDAP nativo DreamFactory — `ServiceProvider.php:72` (tracing) e `Session::checkServicePermission('named_query', verb)` (AGENTS.md:3) permanecem
- **SGA Facade**: `extensions/df-named-query/src/Services/SgaClient.php:1` espelha `SGA/src/main/java/br/com/yamaha/sga/facade/ws/WsAcesso.java:30` — `validarLogin(codUsuario,dscSenha,nomSistema)` / `getPerfilUsuario` / `getUsuarioByMatricula` via SOAP `http://172.31.16.89/SGA/` com allowlist `172.31.16.89`, BODY 1MB, XXE guards
- **OAuth Proxy**: `extensions/df-named-query/src/Http/OAuthProxy.php` — encaminha `Authorization: Bearer` para `df-oauth` (`dreamfactory/df-oauth 1.0.3`, `composer.json:123`) sem duplicar `Session` — `df-oauth` já é OSS
- **Integração**: `SgaSgcOrchestrator.php:217` orquestra `authenticateOrFallback` via `ServiceConfig FK` + `SecretStore` + `ClusterInvalidationService`

## Consequências
- Sem premium SSO, mas coberto por SGA interno Yamaha + OAuth2 OSS
- Testes: `Http/OAuthProxyTest.php` 3/3 (RQ-SSO-03), `SgaClientTest` já cobre `validarLogin`

## Validação
- `docker exec dreamfactory php -m | grep -E "SgaClient"` via `class_exists('Yamaha\DreamFactory\NamedQuery\Services\SgaClient')` → true (já validado)
- `curl -X POST http://localhost:18082/api/v2/user/session -d '{"email":"admin@yamaha.local","password":"..."}'` → JWT
