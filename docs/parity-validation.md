# Paridade validada com dados reais — 10/10 executam

Data: 2026-09-03. Execução via `ServiceManager::getService()->getConnection()->select()` com binds compilados (`NamedSqlCompiler`). Contagens apenas, sem dump de dados.

## Queries x serviço (todas `is_active=1`, publicadas)

| Serviço DF | Named query | Claim | Parâmetros reais usados | Linhas | Status |
|---|---|---|---|---|---|
| sgc-645902-pymac-pub-a3 | acasala | query_decalque | cma=SGA210014405 | 1 | OK |
| sgc-645902-pymac-pub-a3 | chassi | query_decalque | vin=9C6KE1200A0055820 | 1 | OK |
| sgc-645902-pymac-pub-a3 | motor | query_decalque | carcaca=G3F2E-009151 | 1 | OK |
| sgc-645871-sysyagqp | gq-inspecao | query_gq_eficaz | p_Operation=U | 596 | OK |
| sgc-9500-pymac-publica | pymac-part-number | query_gq_mi | part_number=1B225872000080, origin=K-00, destination=9219 | 1 | OK |
| sgc-9500-pymac-publica | pymac-origin-destination | query_gq_mi | — | 478 | OK |
| sgc-3508-bdwms | wms-part-number | query_gq_mi | part_number=1S414452000080 | 31 | OK |
| sgc-645540-pymac-apoio | gq-lote | query_gq_lote | p_NumMonth=12 | 5613 | OK |
| sgc-9500-pymac-publica | bom-plan | — (catálogo) | p_item=BC5D00E000, áreas 9529/9539 e variações | 0 | OK-execução, 0 por dados (sem match `vic_int_bdeg_bsasypl` x `vic_int_bodyprd` por `assyplan_key`; subetapas `ext`, `vibb`, `im` têm linhas) |
| sgc-3509-oee | bom-sgpi | query_export_plan | p_item=0002-0520-01, 5279/9219 | 2–3 | OK |

Notas:
- `api-query/config/authorization/route.json` cobre 7 rotas (acasala, chassi, motor, wms-part-number, pymac-part-number, pymac-origin-destination, gq-inspecao). `gq-lote`, `bom-plan`, `bom-sgpi` são catálogo extra já publicado.
- Endpoint DF: `GET /api/v2/{service}/_query/{name}?param=...` com `X-DreamFactory-Api-Key` do app do claim.
- Amostras acima são chaves válidas encontradas nos bancos (ex.: `SGA210014405`, `1B225872000080/K-00/9219`, `0002-0520-01/5279/9219`).

## RBAC pronto
- Roles `qb-claim-*` (7) com `role_service_access` (`_query`, `_query/*`, verb 3 GET|POST, requestor 1 API) por serviço (ver `parity-final.md`).
- Apps `qb-app-*` (7) com `app_role_map` por serviço. Chaves (64 chars) **não constam neste doc** — ver em Admin `#/api-keys` ou `app` no banco. Mapeamento claim→app:
  - query_decalque → qb-app-query_decalque → sgc-645902-pymac-pub-a3
  - query_gq_eficaz → qb-app-query_gq_eficaz → sgc-645871-sysyagqp
  - query_gq_mi → qb-app-query_gq_mi → sgc-9500-pymac-publica, sgc-3508-bdwms
  - query_gq_lote → qb-app-query_gq_lote → sgc-645540-pymac-apoio
  - query_export_plan / query_hora_hora → qb-app-* → sgc-3509-oee
  - adm_api_query → qb-app-adm_api_query → todos (6)
- Consumidores: trocar `client_key`/`client_secret` do `api-query` por `X-DreamFactory-Api-Key` do app correspondente. Sem JWT adicional para app+role (testado: sem sessão retorna 400 pedindo JWT apenas quando sem key/role válida; com key válida o AccessCheck aplica a role).

## Reprodução
- `docker exec dreamfactory php /tmp/test_nq_real2.php` (7/10 com linhas na 1ª passada)
- `docker exec dreamfactory php /tmp/test_nq_retry.php` + `test_bom2.php` (acasala, pymac-part-number, bom-sgpi com linhas)
- `docker exec dreamfactory php /tmp/debug_bomplan2.php` (prova 0 do bom-plan é dado: `body-join 0`, `body-count 0`)
- `npm test` → `ALL VALIDATE PASS`
