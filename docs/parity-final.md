# Paridade DreamFactory — Estado Final E12

## Centralização SGA/SGC (6 conexões ativas)
- **SGA/SGC sync** `sgc-*` via `SgaDatabaseSyncService` — credenciais nunca em log, via `sgc-connection-id`
- **Fixes** `0f62fa3`: `INFORMIXSERVER` → `server`, `pgsql` → `pgsql_query`, `named_binds` emulado no Informix
- **Serviços**:
  - `sgc-3509-oee` Oracle OEEPROD `172.31.16.46:1521`
  - `sgc-645871-sysyagqp` SQL Server SYSYAGQP `172.31.16.122:1433`
  - `sgc-9500-pymac-publica` Informix pymac3_ymda_pub `172.31.192.78:6534` server `ifmxd4_part01`
  - `sgc-3508-bdwms` Oracle WMSYMAP `172.31.16.46:1521`
  - `sgc-645540-pymac-apoio` Postgres dbase_pymac `172.31.16.110:5432` (pgsql_query)
  - `sgc-645902-pymac-pub-a3` Postgres ynslf_ymda_a_pub `172.31.192.68:5433` (pgsql_query)
- **Prova**: `SELECT 1` via cada serviço OK (6/6) — ver `test_nq_exec2.php`

## Named Queries 10/10 publicadas
Import via `named-query:import --publish` (definições em `extensions/df-named-query/database/definitions` mapeadas para `sgc-*`):

| Serviço | Query | Claim |
|---|---|---|
| sgc-645902-pymac-pub-a3 | acasala, chassi, motor | query_decalque |
| sgc-645871-sysyagqp | gq-inspecao | query_gq_eficaz |
| sgc-9500-pymac-publica | pymac-part-number, pymac-origin-destination, bom-plan | query_gq_mi |
| sgc-3508-bdwms | wms-part-number | query_gq_mi |
| sgc-645540-pymac-apoio | gq-lote | query_gq_lote |
| sgc-3509-oee | bom-sgpi | query_export_plan |

Todas com `is_active=1`, `published_revision`, `max_rows` e `parameters` validados. Execução com binds dummy OK (478 rows para origin-destination, 909 para gq-lote).

Endpoint DF: `GET /api/v2/{service}/_query/{name}?param=...` com `X-DreamFactory-Api-Key`

## RBAC 7 roles + Apps
Roles `qb-claim-*` (7) + `role_service_access` (`_query` e `_query/*`, verb 3 GET|POST, requestor 1 API) por serviço conforme tabela acima. `adm_api_query` tem acesso a todos.

Apps `qb-app-*` (7) com `api_key` 64 chars e `app_role_map` por serviço:
- qb-app-query_decalque → 42
- qb-app-query_gq_eficaz → 38
- qb-app-query_gq_mi → 39,40
- qb-app-query_gq_lote → 41
- qb-app-query_export_plan / hora_hora → 29
- qb-app-adm_api_query → todos

Teste rápido (dentro do container):
```
curl -H "X-DreamFactory-Api-Key: e85b8744ae0c9871..." \
  "http://localhost/api/v2/sgc-645902-pymac-pub-a3/_query/acasala?cma=TEST"
```

## Uso pronto
- **Sync SGA**: adicionar/remover conexão no SGA (sistema DF) → `Sync SGA` no Admin → serviço `sgc-{id}-*` criado/atualizado automaticamente
- **API Docs**: `http://localhost:18082/dreamfactory/dist/#/api-docs` lista `_query`
- **Validação**: `npm test` → `ALL VALIDATE PASS` (SGA/SGC/RBAC/PREMIUM/E2E/WAVE1-3/PARITY)

## Próximos passos (você)
- Validar cada rota com dados reais (ex: `cma` válido para `acasala`)
- Trocar `client_key` placeholders pelos `api_key` acima nos consumidores
- Remover `allow_placeholders` se necessário; habilitar `INFORMIXSERVER` já corrigido
