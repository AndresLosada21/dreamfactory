# Driver Matrix — RQ-005 Spike (espelho clean-room)

> Espelho conciso de `docs/architecture/driver-matrix-decision.md` para o ledger. Fonte canônica é o documento em `docs/architecture/`. Time-boxed spike — não é código de produto.

## Matriz pinada (componente | versão pinada | licença | status prova | risco)

| Componente | Versão pinada | Licença | Status prova | Risco | Decisão |
|------------|---------------|---------|--------------|-------|---------|
| PHP | `^8.3` (`config.platform.php=8.3`) — runtime efetivo `php8.5` na imagem (divergência) | PHP-3.01 | Provado no lock; divergência `Dockerfile.offline:24` (`/etc/php/8.5`) vs `composer.json:206` (`platform 8.3`); `dreamfactory-entrypoint:72`, `nginx-dreamfactory.conf:2` | Médio — drift reprodutibilidade | **CONDICIONAL GO** — alinhar platform = runtime |
| Laravel Framework | `^13.7` → resolvido `v13.26.0` (2026-08-18) | MIT | Provado `composer.lock: laravel/framework v13.26.0`; `conflict symfony >=8.0` (`composer.json:139:163`) força Symfony 7.4 | Baixo | **GO** |
| yajra/laravel-oci8 | `^13.0` → `v13.10.1` + `yajra/laravel-pdo-via-oci8 v3.7.3` | MIT | Provado `composer.lock: yajra/laravel-oci8 v13.10.1` requer `php ^8.3, ext-oci8 >=3.0.1, illuminate/database ^13` ↔ `laravel v13.26.0` | Baixo técnico / Médio licenciamento | **GO** (código) |
| OCI8 PHP extension | `>=3.0.1` (pinar `3.4.x` PECL para PHP 8.3) | PHP-3.01 | Provado por compatibilidade declarada `yajra require ext-oci8 >=3.0.1`; OCI8 3.4 ↔ PHP 8.3 (`php.net/book.oci8`) | Baixo | **GO** |
| Oracle Instant Client | `21.20.0.0.0` Basic Lite (`instantclient/instantclient_21_20/BASIC_LITE_README`) / Linux equivalente em `yamaha/dreamfactory-query:0.1.0` (`Dockerfile.offline:3`) | **OTN** | Evidência parcial — artefato 21.20 existe no workspace (Windows `.dll`); base Linux já traz cliente; compat OCI8 3.x ↔ IC 21.x | Alto (distribuição OTN) | **CONDICIONAL GO** — `legal_signoff` OTN (`connector-clean-room.md:270`, `289:293`) |
| pdo_sqlsrv + Microsoft ODBC Driver | `pdo_sqlsrv 5.12.x` + `msodbcsql 17/18` (via base `yamaha/dreamfactory-query:0.1.0`, `Dockerfile.offline:4` “already installed”) | MIT (driver) + **Microsoft EULA** (binário) | Provado por composição — `extensions/df-sqlsrv/src/ServiceProvider.php:17:34` registra `sqlsrv`; preservação ODBC sem sobreposição | Médio — requer `ACCEPT_EULA=Y` | **CONDICIONAL GO** — `legal_signoff` EULA (`connector-clean-room.md:271`) |
| PDO_INFORMIX | `1.3.7` PECL (`2025-01-29`, Apache-2.0, `package.xml`) — `docker/vendor/PDO_INFORMIX-1.3.7.tgz` + `docker/pdo-informix-php85.patch:1:20` | Apache-2.0 | **Provado por build** — `Dockerfile.offline:8,13:28` `phpize && ./configure --with-pdo-informix=/opt/informix && make && php -m | grep -qx pdo_informix` | Médio — patch `zend_string_release` + `SQL_UNKNOWN_TYPE` necessário | **GO técnico** |
| IBM Informix CSDK + libifcli.so | `klauvi/node-informix@sha256:72a0ac8bc2ea410e167fa44a9741c6c07c4a540b1775981675619969137d0208` → `/opt/informix`, `libifcli.so` (`docker/informix-odbcinst.ini:1:4`) | **IBM ILA** (proprietário) | Provado por build mas origem não auditada — `Dockerfile.offline:1,7` `COPY --from=informix-client /opt/informix`; `InformixConnector.php:11` falha segura | **CRÍTICO** — imagem comunitária sem entitlement | **NO-GO produção / GO spike isolado** (`connector-clean-room.md:272`) |
| ODBC (unixODBC) | `unixODBC 2.3.x` + `odbcinst` (MS + Informix coexistindo) | LGPL-2.1 | Provado — `Dockerfile.offline:4` preserva MS; `Dockerfile.offline:26` `odbcinst -i -d -f /tmp/informix-odbcinst.ini` adiciona `[Informix]` | Baixo | **GO** |

## Evidência de build offline

`Dockerfile.offline:1:29` é a receita reproduzível: multi-stage `klauvi/node-informix` → `phpize`/`configure`/`make`/`php -m | grep pdo_informix` como asserção. Ver `docs/architecture/driver-matrix-decision.md:5` para citação completa.

## Decisões GO / NO-GO (resumo spike)

- **Oracle:** GO condicional (OTN gate)
- **SQL Server:** GO condicional (EULA gate)
- **Informix:** NO-GO produção até entitlement IBM; GO como spike isolado (falha explícita `InformixConnector.php:11`)
- **Plataforma:** GO com correção do drift `platform 8.3` vs `php8.5` (`Dockerfile.offline:24`, `dreamfactory-entrypoint:72`, `nginx-dreamfactory.conf:2`)

## Pendência crítica detectada

O lock resolve PHP 8.3 mas a imagem roda PHP 8.5 — fixar `8.3` em todos os lugares ou migrar `composer.json:206` para `8.5` e revalidar `yajra ^13.0` + `df-core 1.0.17`.

Referências canônicas: `docs/architecture/connector-clean-room.md:266:294` (matriz de licenças), `docs/architecture/driver-matrix-decision.md` (prova detalhada).
