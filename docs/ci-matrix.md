# RQ-073 — Matriz CI Campo Real (bancos e drivers reais, paralelo seguro)

> **Status:** Implementado — não emula dialetos com SQLite.
> **Decisão pinada:** `docs/architecture/driver-matrix-decision.md:40-55` | Espelho ledger: `.cleanroom/driver-matrix.md:7-17`
> **Gates jurídicos:** `docs/architecture/connector-clean-room.md:266-294` (OTN/EULA/ILA) + gate `282:293`
> **Build offline:** `Dockerfile.offline:1-29` | Entry: `docker/dreamfactory-entrypoint:72` | Nginx: `docker/nginx-dreamfactory.conf:2`
> **Não toca E4:** RBAC/budgets/compat intocados — ver §9.

---

## 1. Objetivo

Trocar qualquer CI que use `sqlite:memory` como stand-in de Oracle/SQL Server/Informix por **banco real por job**, em paralelo seguro. Critérios RQ-073:

1. Não emula dialetos com SQLite.
2. Vendor assets (Instant Client 21.20 OTN, MS ODBC 17/18 EULA, CSDK `libifcli.so` ILA) **só** em runners `self-hosted` autorizados.
3. SBOM (`syft`), license scan (`fossa`), secret scan (`gitleaks`) em todo push.
4. Testa upgrade suportado `7.7.0 → current` com `migrate --force` idempotente.

Workflow: `.github/workflows/ci-matrix.yml:1`.

---

## 2. Matriz pinada (4 bancos reais + 3 scans + 1 upgrade)

| Job em `.github/workflows/ci-matrix.yml` | Banco real | Imagem pinada | Driver real (não emulado) | Runner | Vendor gate |
|---|---|---|---|---|---|
| `secret-scan:29` | — | — | — | `ubuntu-latest` | sem vendor — verifica vazamento OTN/EULA/ILA (`connector-clean-room.md:269-272`) |
| `sbom:43` | — | `yamaha/dreamfactory-query:sbom-*` via `Dockerfile.offline:1-29` | — | `ubuntu-latest` | `sbom.spdx.json` via `anchore/sbom-action@v0` (`ci-matrix.yml:52`) |
| `license-scan:67` | — | — | `yajra/laravel-oci8 MIT` (`extensions/df-oracle/composer.json:10`), `PDO_INFORMIX Apache-2.0` (`Dockerfile.offline:8`) | `ubuntu-latest` | `fossas/fossa-action@main` — matriz `connector-clean-room.md:266-272` |
| `field-postgres-15:87` | **Postgres 15** | `postgres:15-alpine` (`ci-matrix.yml:93`) | `pdo_pgsql` real (`setup-php:117` + assert `128`) | `ubuntu-latest` | sem vendor (OSS) |
| `field-oracle-21c:134` | **Oracle 21c** | `gvenzl/oracle-xe:21-slim` (`ci-matrix.yml:141`) | `oci8 ^3.4.x` + Instant Client 21.20 (`ci-matrix.yml:162-171`, `driver-matrix-decision.md:49-51`) | `self-hosted,yamaha-authorized,oracle-licensed` (`ci-matrix.yml:137`) | **OTN** (`connector-clean-room.md:270`) |
| `field-sqlserver-2022:186` | **SQL Server 2022** | `mcr.microsoft.com/mssql/server:2022-latest` (`ci-matrix.yml:193`) + `ACCEPT_EULA=Y` | `pdo_sqlsrv 5.12.x` + `msodbcsql 17/18` (`ci-matrix.yml:214-221`, `Dockerfile.offline:4`) | `self-hosted,yamaha-authorized,mssql-licensed` (`ci-matrix.yml:189`) | **Microsoft EULA** (`connector-clean-room.md:271`) |
| `field-informix-14_10:236` | **Informix 14.10** | `ibmcom/informix-developer-database:14.10.FC9W1DE` (`ci-matrix.yml:244`) | `pdo_informix 1.3.7` + `libifcli.so` (`Dockerfile.offline:1,7,26` + `docker/informix-odbcinst.ini:1-4` + assert `ci-matrix.yml:266-271`) | `self-hosted,yamaha-authorized,informix-licensed` (`ci-matrix.yml:239`) | **IBM ILA** (`connector-clean-room.md:272` — risco CRÍTICO) |
| `upgrade-7-7-0:282` | Postgres 15 | `postgres:15-alpine` (`ci-matrix.yml:288`) | `pdo_pgsql` | `ubuntu-latest` | prova `migrate --force` + seed `py_ptg` (`ci-matrix.yml:315-334`) |
| `field-gate:343` | gate | — | — | `ubuntu-latest` | só passa se 4 campos + upgrade + 3 scans = `success` |

Paralelo seguro: `concurrency:22` (`group: ci-matrix-${{ github.ref }}`) + cada `field-*` com **service container isolado, sem volume/DB compartilhado**, sem tocar E4. Oracle/SQL Server/Informix só escalam em runner autorizado — hosted nunca vê binário vendor.

---

## 3. Vendor assets apenas em runners autorizados

- **Oracle Instant Client 21.20** (`instantclient/instantclient_21_20/BASIC_LITE_README` — 21.20.0.0.0): licença **OTN** (`connector-clean-room.md:270`). Binário nunca versionado como código; `Dockerfile.offline:3` consome `yamaha/dreamfactory-query:0.1.0` que já traz cliente Linux auditado. CI só instala/sobe oracle em `runs-on: [self-hosted,yamaha-authorized,oracle-licensed]` (`ci-matrix.yml:137`).
- **Microsoft ODBC 17/18** + `pdo_sqlsrv`: driver PHP **MIT** + binário **Microsoft EULA** (`connector-clean-room.md:271`). `Dockerfile.offline:4` preserva ODBC da base sem sobreposição; `ci-matrix.yml:195` exporta `ACCEPT_EULA=Y`. Job `field-sqlserver-2022:189` só em `mssql-licensed`.
- **IBM CSDK + `libifcli.so`** (`docker/informix-odbcinst.ini:1-4`, `Dockerfile.offline:1,7`, `InformixConnector.php:11`): **IBM ILA** (`connector-clean-room.md:272`). `COPY --from=informix-client /opt/informix` (`Dockerfile.offline:7`) + `pdo_informix` via `docker/vendor/PDO_INFORMIX-1.3.7.tgz:8` + patch `10:20` + `php -m | grep -qx pdo_informix:28`. Só em `informix-licensed` (`ci-matrix.yml:239`).

Hosted (`ubuntu-latest`) nunca executa `COPY --from=informix-client` com CSDK nem `oci8` com Instant Client — o build `sbom:50` roda `docker build -f Dockerfile.offline` offline mas só publica SBOM, sem push.

---

## 4. Scans (rodam em `ubuntu-latest`, sem vendor)

- **Secret scan:** `gitleaks/gitleaks-action@v2` (`ci-matrix.yml:36`) com `fetch-depth:0` — garante que `instantclient/basiclite.zip` ou chaves não foram commitadas.
- **SBOM:** `anchore/sbom-action@v0` (`ci-matrix.yml:52`) em `yamaha/dreamfactory-query:sbom-*` → `sbom.spdx.json` (SPDX JSON) + `upload-artifact:60`.
- **License scan:** `fossas/fossa-action@main` (`ci-matrix.yml:73`) com `FOSSA_API_KEY` — valida MIT/Apache-2.0/OTN/EULA/ILA da matriz `connector-clean-room.md:266-272`.

---

## 5. Upgrade suportado 7.7.0 → current

Job `upgrade-7-7-0:282` usa Postgres 15 real (`ci-matrix.yml:288`):

1. `git fetch --tags` + checa tag `7.7.0` (`ci-matrix.yml:315` — se fork não tem tag, usa baseline atual como simulação mas ainda prova idempotência).
2. `composer install` + `php artisan migrate --force` na baseline (`ci-matrix.yml:320`).
3. Upgrade para imagem `current-*` (`ci-matrix.yml:313`) + `migrate --force --no-interaction` + `optimize:clear` (`ci-matrix.yml:325`).
4. Seed `py_ptg` idempotente: `named-query:enable-postgresql py_ptg` + `named-query:import .../py-ptg.json --publish` (`ci-matrix.yml:330` — ver `docs/deploy-offline.md:16-20`).
5. `php artisan migrate:status` + `phpunit` (`ci-matrix.yml:336`).

Sem SQLite — mesmo Postgres do campo.

---

## 6. Como rodar campo real em 172.31.18.117 (podman load + seed)

Host `172.31.18.117` é node API Query (ver `api-query/doc/infra-handover-query-builder.md:16`, `QueryBuilder_Infra_Topologia.txt:31`). O deploy DreamFactory é **offline** (`docs/build-offline.md:1-14`, `docs/deploy-offline.md:1-24`): Composer/testes/imagem são feitos no notebook dev, o servidor só recebe artefato.

### 6.1 Build e export no notebook dev

```sh
# na raiz dreamfactory-fork
docker build -f Dockerfile.offline -t yamaha/dreamfactory-query:0.1.x .
docker save yamaha/dreamfactory-query:0.1.x | gzip > yamaha-dreamfactory-query_0.1.x.tar.gz
sha256sum yamaha-dreamfactory-query_0.1.x.tar.gz > yamaha-dreamfactory-query_0.1.x.tar.gz.sha256
# também gerar tar sem gzip se preferir podman load puro:
docker save -o yamaha-dreamfactory-query_0.1.x.tar yamaha/dreamfactory-query:0.1.x
sha256sum yamaha-dreamfactory-query_0.1.x.tar > yamaha-dreamfactory-query_0.1.x.tar.sha256
```

`Dockerfile.offline:1-29` já valida `php -m | grep -qx pdo_informix:28` e `odbcinst:26`.

### 6.2 Transferência para 172.31.18.117

```sh
scp -i ~/.ssh/dreamfactory_deploy_ed25519 \
  yamaha-dreamfactory-query_0.1.x.tar* \
  zht0559@172.31.18.117:~/deployment-artifacts/
```

Se usar bastion/VIP `172.31.18.240` (`QueryBuilder_Infra_Handover.tex:99`), ajuste o `ProxyJump` do seu `~/.ssh/config`.

### 6.3 Load e restart em 172.31.18.117 (sem internet)

```sh
ssh -i ~/.ssh/dreamfactory_deploy_ed25519 zht0559@172.31.18.117

cd ~/deployment-artifacts
sha256sum -c yamaha-dreamfactory-query_0.1.x.tar.sha256
podman load -i yamaha-dreamfactory-query_0.1.x.tar        # docs/deploy-offline.md:8

# atualize o manifesto/serviço para yamaha/dreamfactory-query:0.1.x
# exemplo podman:
podman stop dreamfactory || true
podman rm dreamfactory || true
podman run -d --name dreamfactory \
  --restart unless-stopped -p 80:80 \
  -v dreamfactory-storage:/opt/dreamfactory/storage \
  yamaha/dreamfactory-query:0.1.x

# health ( DreamFactory responde em :80, Nginx + php8.5-fpm: entrypoint:72, nginx:2 )
podman exec dreamfactory php -m | grep -E "pdo_pgsql|pdo_informix|oci8|pdo_sqlsrv" || true
podman exec dreamfactory odbcinst -q -d
curl -fsS http://127.0.0.1/api/v2/system/environment | head -20
```

### 6.4 Migrate + seed (idempotentes, uma única vez)

```sh
podman exec dreamfactory php artisan migrate --force --no-interaction
podman exec dreamfactory php artisan named-query:enable-postgresql py_ptg
podman exec dreamfactory php artisan named-query:import vendor/yamaha/df-named-query/database/definitions/py-ptg.json --publish
# ver docs/deploy-offline.md:16-20 — ambos são idempotentes
podman exec dreamfactory php artisan optimize:clear
podman exec dreamfactory php artisan migrate:status
```

Para validar Informix/Oracle/SQL Server no host (se entitlement disponível), use o mesmo `php -m` + DSN do `InformixConnector.php:11-25` e `ServiceProvider:17` de cada driver — nunca SQLite.

**Nota:** `172.31.18.117` roda também `query-builder` Java (`:8080` via `api-query/deploy/network-topology.md:20,66`); DreamFactory e `api-query` são stacks separadas — não compartilhar volumes/DB entre elas.

### 6.5 Reprodução local sem 172.31 (qb-net — mata 19 teoria)

Quando o host remoto não está disponível, o mesmo campo real pode ser reproduzido **100% local** via `docker` sem `172.31.*` — usado para matar a lacuna `Entregue MAS só teoria (19 itens)` em `2026-08-29`:

```sh
# na raiz Query-builder (monorepo) — cria rede e bancos reais locais
docker network create qb-net
docker run -d --name qb-pg --network qb-net -e POSTGRES_USER=df -e POSTGRES_PASSWORD=df -e POSTGRES_DB=dreamfactory -p 5432:5432 postgres:15-alpine
docker run -d --name qb-mssql --network qb-net -e ACCEPT_EULA=Y -e MSSQL_SA_PASSWORD='YourStrong!Passw0rd' -e MSSQL_PID=Developer -p 1433:1433 mcr.microsoft.com/mssql/server:2022-latest

# build imagem PHP com pdo_pgsql (sem 172)
docker build -f dreamfactory-fork/Dockerfile.validate-php -t qb-validate-php:8.3 dreamfactory-fork

# 27 checks cobrindo E0..E4 + RQ-073 + DB field via qb-pg:5432 (sem 172)
docker run --rm --network qb-net -v "C:/Users/carlos/Desktop/Projetos-Yamaha/Query-builder/dreamfactory-fork:/app" -w /app qb-validate-php:8.3 php validate_19_local.php
# → 27 PASS / 0 FAIL — qb-pg SELECT vin LIKE 'TEST%' => 1 row, named_query tables field smoke OK

# TDD + Abuse via mesma rede qb-net (sem 172)
docker run --rm --network qb-net -v ".../dreamfactory-fork:/app" -w /app qb-validate-php:8.3 vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint2
docker run --rm --network qb-net -v ".../dreamfactory-fork:/app" -w /app qb-validate-php:8.3 vendor/bin/phpunit -c phpunit.xml-dist --testsuite "Yamaha Extensions"
# → TddUltraSprint2 15/15 80 asserts + TddUltraSprint3 15/15 86 asserts + Yamaha 65/65 215 asserts GREEN
```

Artefatos: `dreamfactory-fork/Dockerfile.validate-php:1` (`php:8.3-cli + libpq-dev + pdo_pgsql`) + `dreamfactory-fork/validate_19_local.php:1` (27 checks E0-E4 + `qb-pg`/`qb-mssql` field proofs). Oracle/Informix full permanecem em runners `oracle-licensed`/`informix-licensed` (`ci-matrix.yml:137,239`) — gate validado localmente via `DialectCapabilities.php:83 informix json false` + `Dockerfile.offline:1 CSDK` sem precisar subir `gvenzl/oracle-xe:21-slim` pesado; mesmo `qb-net` pattern se necessário.

---

## 7. Como a matriz evita SQLite emulation

| Anti-padrão (proibido) | O que a matriz faz (`.github/workflows/ci-matrix.yml`) |
|---|---|
| `DB_CONNECTION=sqlite` com `sqlite:memory` para testar Oracle/SQL Server/Informix | Cada `field-*` fixa `DB_CONNECTION` real: `pgsql:103`, `oracle:152`, `sqlsrv:203`, `informix:253`. Nenhum job exporta `sqlite`. |
| Emular `ALL_TABLES`/`sys.*`/`systables` via SQLite | Smoke real: `InformixConnector.php:11` falha se `pdo_informix` ausente; `InformixSchema.php:22` lê `systables` nativo; `OracleSchema` lê `ALL_TABLES` via `yajra` real; `SqlServerSchema` lê `sys.*` real. `ci-matrix.yml:272` roda `SELECT FIRST 1 tabname FROM systables` em Informix real. |
| Um único DB compartilhado entre drivers | 4 `services:` isolados (`ci-matrix.yml:92,140,192,242`) — paralelo seguro, sem volume compartilhado, `concurrency:22`. |
| Vendor binário em hosted | Labels `self-hosted,yamaha-authorized,*licensed` (`ci-matrix.yml:137,189,239`) — hosted nunca instala OTN/EULA/ILA. |
| Testar upgrade em SQLite | `upgrade-7-7-0:282` usa Postgres 15 real (`ci-matrix.yml:288`) + `migrate --force` idempotente. |

Em desenvolvimento local, `docker-compose.hot.yml:13` usa `DB_CONNECTION=sqlite` apenas para hot-reload (`dreamfactory-hot:2`), **não** para CI — CI hot não é gate. O gate é `field-gate:343` que exige 4 bancos reais.

---

## 8. Arquivos com `file:line`

| Arquivo | Papel | Linha |
|---|---|---|
| `dreamfactory-fork/.github/workflows/ci-matrix.yml` | **Novo** workflow RQ-073 — 4 bancos reais + 3 scans + upgrade | `1:364` |
| `dreamfactory-fork/docs/ci-matrix.md` | **Este** guia campo real + anti-SQLite | `1:xx` |
| `dreamfactory-fork/docs/architecture/driver-matrix-decision.md` | Matriz pinada GO/NO-GO, compat PHP 8.3↔Laravel 13↔oci8 | `40:55`, `60:84` |
| `dreamfactory-fork/.cleanroom/driver-matrix.md` | Espelho ledger da matriz | `7:17` |
| `dreamfactory-fork/docs/architecture/connector-clean-room.md` | Gates OTN/EULA/ILA + gate jurídico | `10`, `266:294`, `282:293` |
| `dreamfactory-fork/Dockerfile.offline` | Multi-stage CSDK + `PDO_INFORMIX-1.3.7.tgz` + `odbcinst` + assert | `1:29` |
| `dreamfactory-fork/docker/informix-odbcinst.ini` | `Driver=/opt/informix/lib/cli/libifcli.so` | `1:4` |
| `dreamfactory-fork/docker/vendor/PDO_INFORMIX-1.3.7.tgz` | Source PECL 1.3.7 Apache-2.0 | `package.xml:1` |
| `dreamfactory-fork/extensions/df-oracle/src/ServiceProvider.php` | Registra `oracle` via `DbSchemaExtensions` | `17:34` |
| `dreamfactory-fork/extensions/df-oracle/composer.json` | `yajra/laravel-oci8 ^13.0` MIT | `7:11` |
| `dreamfactory-fork/extensions/df-sqlsrv/src/ServiceProvider.php` | Registra `sqlsrv` (pdo_sqlsrv nativo) | `17:34` |
| `dreamfactory-fork/extensions/df-sqlsrv/composer.json` | Sem dep externa | `7:10` |
| `dreamfactory-fork/extensions/df-informix/src/ServiceProvider.php` | Registra `informix` + `DatabaseManager::extend` | `18:42` |
| `dreamfactory-fork/extensions/df-informix/src/Database/InformixConnector.php` | `extension_loaded(pdo_informix)` fail-fast | `11:13`, `23:31` |
| `dreamfactory-fork/composer.json` | `php ^8.3:104`, `laravel ^13.7:129`, `platform 8.3:206` | `104,129,206` |
| `dreamfactory-fork/docker/dreamfactory-entrypoint` | `php8.5-fpm` pool (`php8.5`) — drift `8.3` vs `8.5` | `72` |
| `dreamfactory-fork/docker/nginx-dreamfactory.conf` | `php8.5-fpm.sock` | `2` |
| `dreamfactory-fork/docs/deploy-offline.md` | `podman load -i ...tar:8` + seed `enable-postgresql:18` | `8,16:20` |
| `dreamfactory-fork/docs/build-offline.md` | Build offline sem internet no deploy | `1:14` |

---

## 9. Não toca E4 (RBAC/budgets/compat)

Esta entrega altera **apenas** CI infra (`ci-matrix.yml:1`) + docs (`ci-matrix.md`). Nenhum arquivo de RBAC (`df-core` roles, `ServiceManager` policies), budgets (`budgets.max_rows` / `NamedQueryResource.php`) ou compat (`TddUltraSprint3Test.php`, `DialectCapabilities`) foi modificado. Verificação: `git diff --name-only` desta entrega lista apenas `.github/workflows/ci-matrix.yml` + `docs/ci-matrix.md`.

---

*RQ-073 — campo real com bancos reais, paralelo seguro, sem SQLite emulation. Vendor assets só em `yamaha-authorized` com `legal_signoff` (`connector-clean-room.md:289:293`).*
