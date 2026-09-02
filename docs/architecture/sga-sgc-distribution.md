# SGA/SGC — distribuição (E10)

## Contrato

- Toda URL/host SGA/SGC vem de ambiente com default versionado no código.
- Compose nunca hardcode: usa `${VAR:-default}` (`docker-compose.yml:19-22`).
- `.env-dist` documenta overrides (seção SGA/SGC).
- Clientes SOAP recusam endpoint fora do allowlist, sem userinfo, só `http(s)`.

## Arquivos

- `extensions/df-named-query/src/Services/SgaClient.php:19,30,41`
- `extensions/df-named-query/src/Services/SgcConnectionClient.php:19,30,42`
- `extensions/df-named-query/src/Services/SgaDatabaseSyncService.php` (upsert `sgc-{id}-...`)
- `extensions/df-named-query/src/Resources/SgaSyncConnectionsResource.php` (admin)
- `extensions/df-named-query/README.md`
- `docker-compose.yml:19-22`, `.env-dist` (seção SGA/SGC)

## Instalar em ambiente novo

1. Copiar `.env-dist` para `.env` e ajustar `SGA_ENDPOINT/SGC_ENDPOINT/*ALLOWLIST`
   (ou exportar as 4 vars antes do compose).
2. `docker compose config` mostra os valores resolvidos.
3. `docker compose build && docker compose up -d`.
4. No SGA, sistema `DF`, aba Conexões: vincular os bancos.
5. No DF, página Database: `Sync SGA` (admin). Re-sync é idempotente.

## Evidência campo (2026-09-02)

- `POST sga_login/connections` → `200 total=1 created sgc-3509-oee oracle OEE`;
  re-sync → `200 updated=1`; grade exibe editável; `npm test` PASS.
