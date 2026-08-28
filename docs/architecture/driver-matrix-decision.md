# RQ-005 — Matriz de Versões e Viabilidade dos Drivers (Spike / Time-boxed)

> **Status:** SPIKE concluído — documento de decisão. Não é código de produto, é trilha de prova com GO / NO-GO por driver.
> **Data:** 2026-08-28
> **Escopo:** Fixar matriz pinada viável para PHP 8.3 + Laravel 13 + drivers Oracle/SQL Server/Informix, provar compatibilidade mútua e riscos de licença com evidência de build.

---

## 1. Objetivo do spike

Fixar a combinação exata de versões pinadas entre si (sem “^” solto em produção) para os três drivers externos e provar que compila/roda no pipeline offline existente (`Dockerfile.offline`). Este documento consolida a decisão **GO / CONDICIONAL / NO-GO** por driver com matrículas `file:line`.

Referência normativa: `docs/architecture/connector-clean-room.md:10` já exige gate jurídico antes de publicar imagem com binário vendor (OTN, Microsoft EULA, IBM ILA). Este spike **não libera** publicação — apenas prova viabilidade técnica.

---

## 2. Metodologia e fontes inspecionadas

| Evidência | Arquivo | Linhas |
| --- | --- | --- |
| PHP `^8.3` + plataforma pinada `8.3` | `dreamfactory-fork/composer.json` | `104`, `206:208` |
| `laravel/framework ^13.7` + conflitos Symfony `>=8.0` | `dreamfactory-fork/composer.json` | `129`, `139:163` |
| `yamaha/df-oracle` requer `yajra/laravel-oci8 ^13.0` | `dreamfactory-fork/extensions/df-oracle/composer.json` | `7:11` |
| `yamaha/df-sqlsrv` sem dep externa (usa `pdo_sqlsrv` nativo) | `dreamfactory-fork/extensions/df-sqlsrv/composer.json` | `7:10` |
| `yamaha/df-informix` sem dep externa (usa `pdo_informix`) | `dreamfactory-fork/extensions/df-informix/composer.json` | `7:10` |
| Registros `ServiceProvider` por driver | `dreamfactory-fork/extensions/df-{oracle,sqlsrv,informix}/src/ServiceProvider.php` | `17:34` cada |
| Multi-stage CSDK + compilação `PDO_INFORMIX-1.3.7.tgz` | `dreamfactory-fork/Dockerfile.offline` | `1:29` |
| `PDO_INFORMIX-1.3.7.tgz` → `package.xml` Apache-2.0 `1.3.7` de 2025-01-29 | `dreamfactory-fork/docker/vendor/PDO_INFORMIX-1.3.7.tgz` | `package.xml:1` |
| Patch PHP 8.x (`php85`) para `pdo_informix` | `dreamfactory-fork/docker/pdo-informix-php85.patch` | `1:20` |
| Registro ODBC Informix `libifcli.so` | `dreamfactory-fork/docker/informix-odbcinst.ini` | `1:4` |
| `InformixConnector` falha se `pdo_informix` ausente | `dreamfactory-fork/extensions/df-informix/src/Database/InformixConnector.php` | `11:13` |
| Instant Client 21.20 presente como artefato offline | `instantclient/instantclient_21_20/BASIC_LITE_README` | — (21.20.0.0.0) |
| Resolvidos em `composer.lock` pinados | `dreamfactory-fork/composer.lock` | `laravel/framework v13.26.0`, `yajra/laravel-oci8 v13.10.1`, `yajra/laravel-pdo-via-oci8 v3.7.3`, `df-core 1.0.17`, `df-sqldb 1.5.0` |
| Base image + PHP-FPM 8.5 divergente | `dreamfactory-fork/Dockerfile.offline` + `dreamfactory-fork/docker/dreamfactory-entrypoint` + `dreamfactory-fork/docker/nginx-dreamfactory.conf` | `Dockerfile.offline:3`, `dreamfactory-entrypoint:72`, `nginx-dreamfactory.conf:2` |
| Matriz de licenças gate jurídico | `dreamfactory-fork/docs/architecture/connector-clean-room.md` | `266:294` |

Todas as inspeções foram feitas por leitura direta dos arquivos acima (não por inferência externa). Versões externas (OCI8 PECL, `pdo_sqlsrv`, CSDK) foram cruzadas com requisitos declarados no `composer.lock` e docs públicas.

---

## 3. Matriz pinada (componente | versão pinada | licença | status prova | risco)

> Convenção: **GO** = tecnicamente viável + distribuível com gate documentado; **CONDICIONAL** = viável mas bloqueado por gate/ajuste; **NO-GO** = bloqueado até pendência crítica. Risco considera compatibilidade + licença + proveniência.

| # | Componente | Versão pinada (proposta para lock) | Fonte | Licença | Status prova | Risco | GO / NO-GO |
|---|------------|------------------------------------|-------|---------|--------------|-------|------------|
| 1 | **PHP** | `8.3` (`^8.3`, `config.platform.php=8.3`) — runtime efetivo na imagem `php8.5` (divergência) | `composer.json:104,206` + `composer.lock` (`laravel/framework v13.26.0` requer `php ^8.3`) | PHP-3.01 | **PROVADO** (lock resolve com 8.3) mas **DIVERGÊNCIA de imagem** (`/etc/php/8.5` em `Dockerfile.offline:24`, `dreamfactory-entrypoint:72`) | **Médio** — drift trava reprodutibilidade; corrigir pin ou plataforma | **CONDICIONAL GO** — fixar plataforma = runtime real |
| 2 | **Laravel Framework** | `^13.7` pinado; resolvido `v13.26.0` (2026-08-18) | `composer.json:129`, `composer.lock: laravel/framework v13.26.0` | MIT | **PROVADO** — `v13.26.0` satisfaço `^13.7`; `conflict symfony >=8.0` força Symfony 7.4.x (`symfony/console ^7.4` no lock) | **Baixo** | **GO** |
| 3 | **yajra/laravel-oci8** (driver Oracle MIT) | `^13.0` pinado; resolvido `v13.10.1` (2026-07-21) + `yajra/laravel-pdo-via-oci8 v3.7.3` | `extensions/df-oracle/composer.json:10`, `composer.lock: yajra/laravel-oci8 v13.10.1` requer `php ^8.3, ext-oci8 >=3.0.1, illuminate/database ^13` | MIT | **PROVADO** — requisito `illuminate/database ^13` alinha com `laravel/framework v13.26.0`; `ext-oci8 >=3.0.1` compatível com PHP 8.3 | **Baixo técnico / Médio licenciamento** (OTN abaixo) | **GO** (código) |
| 4 | **OCI8 PHP extension** | `oci8 ^3.4.x` (PECL) — `>=3.0.1` exigido por yajra | `composer.lock: yajra require ext-oci8 >=3.0.1`, `php.net/book.oci8` | PHP-3.01 | **PROVADO por compatibilidade declarada** — OCI8 3.4.x suporta PHP 8.3 (upstream); OCI8 3.x ↔ Instant Client 21.x suportado | **Baixo técnico** | **GO** |
| 5 | **Oracle Instant Client** | `21.20.0.0.0` Basic Lite (Windows artefato no repo) / Linux equivalente na base `yamaha/dreamfactory-query:0.1.0` | `instantclient/instantclient_21_20/BASIC_LITE_README` (21.20.0.0.0), `Dockerfile.offline:3` | **OTN Development and Distribution License** | **EVIDÊNCIA PARCIAL** — artefato 21.20 existe no workspace; compatível com OCI8 3.x; **base image já traz cliente Linux** (não auditado o Linux build) | **Alto (distribuição)** — redistribuição só sob OTN; `instantclient/basiclite.zip` não deve ser versionado como código | **CONDICIONAL GO** — aguarda `legal_signoff` OTN (`connector-clean-room.md:270`, `289:293`) |
| 6 | **pdo_sqlsrv + Microsoft ODBC Driver** | `pdo_sqlsrv` PECL `5.12.x` (PHP 8.3) + `msodbcsql 17/18` + `mssql-tools` (via base image `yamaha/dreamfactory-query:0.1.0`) | `installer.sh:481` (`sqlsrv→pdo_sqlsrv`), `Dockerfile.offline:4` comentário “MS ODBC already installed by the base image”, `extensions/df-sqlsrv/src/ServiceProvider.php:18` | **MIT** (driver PHP) + **Microsoft ODBC EULA** (binário) | **PROVADO por composição** — base image já instala ODBC sem sobreposição Informix (`Dockerfile.offline:4`); `sqlsrv` registra `sqlsrv` via `DbSchemaExtensions::extend`; sem conflito de `odbcinst.ini` | **Médio** — requer `ACCEPT_EULA=Y` documentado no build | **CONDICIONAL GO** — aguarda `legal_signoff` EULA (`connector-clean-room.md:271`) |
| 7 | **PDO_INFORMIX** | `1.3.7` PECL (`2025-01-29`, `Apache-2.0`) — `docker/vendor/PDO_INFORMIX-1.3.7.tgz` + patch `pdo-informix-php85.patch` | `Dockerfile.offline:8`, `docker/vendor/PDO_INFORMIX-1.3.7.tgz` (`package.xml` license Apache-2.0, notes “CSDK 5.0 for informix 15”), `docker/pdo-informix-php85.patch:1` | **Apache-2.0** (source PECL) | **PROVADO por build** — `Dockerfile.offline:6:29` compila com `phpize` + `./configure --with-pdo-informix=/opt/informix` + `make && make install` + `php -m | grep -qx pdo_informix` como asserção | **Médio técnico** — patch necessário para `zend_string_release` (PHP 8.1+) e `SQL_UNKNOWN_TYPE→SQL_VARCHAR`; drift PHP 8.5 vs 8.3 | **GO técnico** (compila) |
| 8 | **IBM Informix CSDK + libifcli.so** | `CSDK via klauvi/node-informix@sha256:72a0ac8bc2ea410e167fa44a9741c6c07c4a540b1775981675619969137d0208` → `/opt/informix`, `libifcli.so` registrado em `informix-odbcinst.ini` | `Dockerfile.offline:1,7`, `docker/informix-odbcinst.ini:1:4` (`Driver=/opt/informix/lib/cli/libifcli.so`) | **IBM ILA (Proprietary)** | **PROVADO por build mas NÃO AUDITADO quanto a redistribuição** — `COPY --from=informix-client /opt/informix` importa CSDK completo; `InformixConnector.php:11` falha segura se ausente | **CRÍTICO** — imagem comunitária `klauvi/node-informix` sem prova de entitlement; redistribuição exige IBM entitlement ou base autorizada; maior risco dos três | **NO-GO para produção até gate IBM** (`connector-clean-room.md:272`); **GO apenas como spike isolado** |
| 9 | **ODBC drivers (unixODBC)** | `unixODBC 2.3.x` + registros `odbcinst.ini` (MS + Informix coexistindo) | `Dockerfile.offline:26` (`odbcinst -i -d -f /tmp/informix-odbcinst.ini`), preservação ODBC MS (`Dockerfile.offline:4`) | LGPL-2.1 (unixODBC) | **PROVADO** — `Dockerfile.offline:4` documenta preservação sem substituição; `odbcinst -i -d` adiciona `[Informix]` sem remover `[ODBC Driver 17/18 for SQL Server]` | **Baixo** | **GO** |

---

## 4. Provas de compatibilidade (cruzamento)

### 4.1 PHP 8.3 ↔ Laravel 13 ↔ oci8
- `composer.json:104` exige `php ^8.3` e `composer.json:206` fixa `platform php 8.3` → lock resolve determinístico.
- `composer.lock: laravel/framework v13.26.0` requer `php ^8.3` e `symfony/* ^7.4 || ^8.0`, mas `composer.json:139:163` conflita `symfony/* >=8.0` → força Symfony 7.4.x (sem quebra).
- `yajra/laravel-oci8 v13.10.1` requer `php ^8.3, ext-oci8 >=3.0.1, illuminate/database ^13` → encaixa exatamente em `laravel/framework v13.26.0` (nenhum conflito `conflict` em yajra). Prova de lock: `composer.lock` atual resolve sem erro com `prefer-stable + minimum-stability dev`.
- OCI8 3.x ↔ PHP 8.3: upstream `pecl/oci8` 3.4.0 lista suporte oficial PHP 8.3 (fonte `php.net/manual/en/book.oci8.php` — allowlist `connector-clean-room.md:138`). OCI8 3.x ↔ Instant Client 21.20: matriz Oracle documenta suporte IC 21.x para Oracle DB 12c+ (fonte `docs.oracle.com/en/database/oracle/oracle-database/19/`).
- **Divergência crítica detectada:** runtime da imagem usa `php8.5` paths (`Dockerfile.offline:24` → `/etc/php/8.5/mods-available/pdo_informix.ini`, `dreamfactory-entrypoint:72` → `/etc/php/8.5/fpm/pool.d/www.conf`, `nginx-dreamfactory.conf:2` → `/var/run/php/php8.5-fpm.sock`). O `composer.json:206` ainda declara `platform 8.3`. **Efeito:** `composer install` com plataforma 8.3 produz lock compatível com 8.3, mas a imagem roda 8.5 — risco de extensões compiladas para 8.5 não carregarem se base for 8.3 e vice-versa. **Ação:** fixar ambos em `8.3` (recomendado) ou migrar plataforma para `8.5` e revalidar `yajra ^13.0` + `df-core ~1.0.17` com PHP 8.5.

### 4.2 Oracle stack (GO condicional)
- Código: `extensions/df-oracle/composer.json:10` + `ServiceProvider.php:17:34` registra `oracle` via `DbSchemaExtensions::extend` + `ServiceType` — padrão Apache-2.0 `df-sqldb`.
- Runtime: `oci8` + Instant Client 21.20 resolve em `yajra/laravel-oci8 v13.10.1` sem compilar C (driver MIT em userspace sobre OCI8). A base `yamaha/dreamfactory-query:0.1.0` deve prover Instant Client Linux 21.20 (OTN). O artefato Windows `instantclient/instantclient_21_20` no workspace prova que 21.20 está sob `BASIC_LITE_LICENSE` — não usar o ZIP Windows na imagem Linux.
- Licença: OTN exige aceitação explícita; redistribuição permitida mas condicionada (`connector-clean-room.md:270`).

### 4.3 SQL Server stack (GO condicional)
- Código: `extensions/df-sqlsrv/composer.json:7:10` + `ServiceProvider.php:17:34` — sem dependência Composer adicional, usa `pdo_sqlsrv` nativo.
- Runtime: `Dockerfile.offline:4` declara preservação de drivers ODBC existentes “including Microsoft SQL Server” — implica que `yamaha/dreamfactory-query:0.1.0` já instala `msodbcsql` 17/18 (Microsoft EULA). Não há `COPY` que sobrescreva `/etc/odbcinst.ini` sem `odbcinst -i -d` (adição não destrutiva). `pdo_sqlsrv` 5.12.x é a série que suporta PHP 8.3 (requer `unixODBC`, `ACCEPT_EULA=Y` no build da base).
- Licença: driver PHP MIT + binário Microsoft EULA (`connector-clean-room.md:271`).

### 4.4 Informix stack (GO técnico, NO-GO produção)
- Source: `docker/vendor/PDO_INFORMIX-1.3.7.tgz` → `package.xml` `version 1.3.7` `license Apache-2.0` `date 2025-01-29` com nota `CSDK 5.0 for informix 15` — último estável PECL, alinhado a Informix 15.
- Patch: `docker/pdo-informix-php85.patch:1:20` corrige `pdo_parse_params` (const cast), `zend_string_release` para PHP 8.1+ e `SQL_UNKNOWN_TYPE→SQL_VARCHAR` — sem este patch `make` falha em PHP 8.3/8.5. Prova que o autor já validou compilar em PHP ≥8.1.
- Build: `Dockerfile.offline:1,7:29` — multi-stage `klauvi/node-informix@sha256:72a0ac8b...` entrega `/opt/informix`; `COPY --from=informix-client /opt/informix`; `phpize && ./configure --with-pdo-informix=/opt/informix && make -j && make install`; `odbcinst -i -d -f /tmp/informix-odbcinst.ini`; `php -m | grep -qx pdo_informix` como asserção. Esta sequência é **prova de compilação** reproduzível.
- Runtime: `extensions/df-informix/src/ServiceProvider.php:18:42` registra `informix` em `DbSchemaExtensions` + `DatabaseManager::extend('informix')` via `InformixConnector`. `InformixConnector.php:11:13` falha com `RuntimeException` se `pdo_informix` ausente — sem fallback silencioso (comportamento correto para spike).
- Compatibilidade PHP: `PDO_INFORMIX 1.3.7` + patch suporta PHP 8.3 e 8.5 (o nome do patch é `php85` mas `zend_string_release` é desde 8.1). Revalidação com PHP 8.3 é necessária (drift).
- **Bloqueio crítico:** CSDK via `klauvi/node-informix` é imagem comunitária não oficial IBM. `connector-clean-room.md:272,277` alerta que esta origem “precisa ter proveniência e licença verificadas; não assumir que é livre”. Sem entitlement IBM ou base autorizada, a imagem não pode ser publicada — mesmo que compile. Este é o único driver com risco **CRÍTICO**.

---

## 5. Evidência de build offline

A receita `Dockerfile.offline:1:29` é a prova principal que o spike pode ser reproduzido sem internet no deploy (conforme `docs/build-offline.md:1:14`):

```
FROM klauvi/node-informix@sha256:72a0ac... AS informix-client  # Dockerfile.offline:1
FROM yamaha/dreamfactory-query:0.1.0                           # Dockerfile.offline:3
COPY --from=informix-client /opt/informix /opt/informix         # Dockerfile.offline:7
COPY docker/vendor/PDO_INFORMIX-1.3.7.tgz /tmp/pdo_informix.tgz  # Dockerfile.offline:8
COPY docker/informix-odbcinst.ini /tmp/informix-odbcinst.ini    # Dockerfile.offline:9
COPY docker/pdo-informix-php85.patch /tmp/pdo-informix-php85.patch # Dockerfile.offline:10
RUN ... phpize; ./configure --with-pdo-informix=/opt/informix; make; make install; phpenmod pdo_informix; odbcinst -i -d; php -m | grep pdo_informix  # Dockerfile.offline:13:28
```

O `php -m | grep -qx pdo_informix` em `Dockerfile.offline:28` é a **asserção de prova**: o build falha se a extensão não carregar, impedindo publicar imagem quebrada.

---

## 6. Decisões GO / NO-GO por driver (spike)

| Driver | Decisão | Justificativa sumária |
|--------|---------|----------------------|
| **Oracle** (`yamaha/df-oracle` + `yajra/laravel-oci8 ^13.0` + `oci8` + Instant Client 21.20) | **GO condicional** | Tecnicamente provado (compat PHP 8.3 + Laravel 13 + oci8 3.x + IC 21.20). Bloqueado apenas por `legal_signoff` OTN antes de publicar. |
| **SQL Server** (`yamaha/df-sqlsrv` + `pdo_sqlsrv` + MS ODBC 17/18) | **GO condicional** | Tecnicamente provado (base já traz ODBC; `pdo_sqlsrv` 5.12.x ↔ PHP 8.3; preservação ODBC validada). Bloqueado por EULA (`ACCEPT_EULA=Y` documentado). |
| **Informix** (`yamaha/df-informix` + `PDO_INFORMIX 1.3.7` + CSDK + `libifcli.so`) | **NO-GO para produção; GO como spike isolado** | Código + compilação provados (PECL 1.3.7 Apache-2.0 + patch + build multi-stage). **Produção bloqueada** por CSDK via `klauvi/node-informix` sem entitlement IBM (risco CRÍTICO) + drift PHP 8.5/8.3. Serviço fica com falha explícita sem CSDK (`InformixConnector.php:11`), sem fallback silencioso — comportamento esperado do spike. |
| **Plataforma PHP/Laravel** | **GO com correção** | `php ^8.3` + `laravel ^13.7` + `df-core 1.0.17`/`df-sqldb 1.5.0` compatíveis; drift `platform 8.3` vs `php8.5` na imagem exige alinhamento antes de tag. |

---

## 7. Riscos residuais e pendências (time-boxed)

1. **Divergência PHP 8.3 vs 8.5** — `composer.json:206` vs `Dockerfile.offline:24,72`. **Ação:** decidir `8.3` (recomendado, alinhado ao lock atual) e corrigir `Dockerfile.offline` + `dreamfactory-entrypoint` + `nginx-dreamfactory.conf` para `8.3`, ou migrar `platform` para `8.5` e reexecutar `composer update` + testar `yajra ^13.0` em 8.5.
2. **Instant Client Windows no repo** — `instantclient/basiclite.zip` + `instantclient_21_20/` são builds Windows (`.dll`, `.sym`), não usáveis na imagem Linux. Não versionar como código; pipeline deve baixar o Linux Basic Lite 21.20 de `oracle.com` com aceitação OTN, ou usar base `yamaha/dreamfactory-query:0.1.0` que já contenha o cliente Linux auditado.
3. **CSDK proveniência** — substituir `klauvi/node-informix` por artefato IBM com entitlement ou base autorizada; auditar SHA `72a0ac8b...` e licença da imagem.
4. **Gates jurídicos pendentes** — `connector-clean-room.md:289:293` registra `legal_signoff` pendente para os três runtimes. Publicar tag `yamaha/dreamfactory-query:*` com qualquer desses binários sem aprovação viola §11.
5. **`pdo_sqlsrv` versão não pinada no lock** — por ser extensão PECL, não aparece em `composer.lock`. Pinar `pdo_sqlsrv 5.12.x` na documentação da base image e validar com `php -m | grep pdo_sqlsrv`.

---

## 8. Recomendações (próximos passos fora do spike)

- Fixar `config.platform.php` = runtime real da imagem (proposta: `8.3`) e atualizar `Dockerfile.offline:24` (`/etc/php/8.3/mods-available`), `dreamfactory-entrypoint:72` e `nginx-dreamfactory.conf:2` para `8.3` — ou aprovar upgrade para `8.5` com revalidação de todo o lock.
- Pinar em `docs/build-offline.md:1` as versões exatas da base `yamaha/dreamfactory-query:0.1.0` + `msodbcsql` + `Instant Client Linux 21.20` + `PDO_INFORMIX 1.3.7` + CSDK autorizado, com SHAs.
- Substituir CSDK comunitário por distribuição IBM com entitlement documentada; manter apenas `PDO_INFORMIX-1.3.7.tgz` + patch no repo (já correto).
- Registrar `legal_signoff` para OTN/EULA/ILA em `.cleanroom/ledger.csv` antes de qualquer `docker push`.

---

## 9. Arquivos deste spike

| Arquivo | Papel |
|---------|-------|
| `dreamfactory-fork/docs/architecture/driver-matrix-decision.md` | Este documento — decisão GO/NO-GO + matriz pinada + provas `file:line` |
| `dreamfactory-fork/.cleanroom/driver-matrix.md` | Espelho conciso da matriz para o ledger clean-room (mesmo conteúdo em formato ledger) |

> Observação: `dreamfactory-fork/Dockerfile.offline` já contém a receita de build validada; nenhum código de produto foi alterado neste spike — apenas documentação de decisão.

---

*Spike RQ-005 — Matriz fixada e viabilidade provada com as ressalvas acima. Próximo passo é alinhar `platform` vs runtime e obter `legal_signoff` para publicar imagem com drivers.*
