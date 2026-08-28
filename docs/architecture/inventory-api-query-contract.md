# Inventário Congelado — Contrato Atual api-query (RQ-001)

> **Versão:** 1.0.0 — 2026-08-28 (freeze)
> **Status:** Canônico — substitui e consolida `dreamfactory-original.md` e `dreamfactory-target-api-query.md` para o contrato api-query.
> **Escopo:** Documentar paths, métodos, aliases de headers, parâmetros, oito consultas, envelopes, erros, storage, cache, OpenAPI e consumidores, incluindo `gq-lote` e `gq-inspecao` I e U, com decisão Preserve/Migrate/Deprecate por comportamento.
> **Fontes:** Leitura direta de `api-query/config/**`, `api-query/src/main/java/com/querybuilder/**`, `api-query/src/main/resources/application*.yaml`, `api-query/deploy/database/**`, `api-query/.env.example`, `dreamfactory-fork/extensions/df-named-query/**` e `GMUD-GO-LIVE/**`. Nenhum dado inventado.

---

## 1. Resumo Executivo

O `api-query` é um Spring Boot 4.0.7/Java 25 exposto via VIP `172.31.18.240:80` → Nginx/Keepalived → dois nodes `172.31.18.117:8080` e `172.31.18.118:8080` (`api-query/doc/infra-handover-query-builder.md:15-19`, `api-query/src/main/resources/application.yaml:13-14`). Em runtime a fonte de verdade é o SQL Server `172.31.18.150:1433/APOIOPYMACH` (`api-query/doc/infra-handover-query-builder.md:190-199`, `api-query/src/main/resources/application.yaml:22-27`). Arquivos em `api-query/config/**` são sementes versionadas, não lidos em runtime no perfil homolog/prod (`api-query/README.md:70-74`).

Dois motores de execução coexistem:

- **Query Builder (JSON DSL)** — `GET /api/v1/query-builder/{dataSet}/{queryName}` via `QueryBuilderController.java:32-89` → `QueryBuilderService.java:17-23` → `QueryExecutorJDBCService.java:47-73`.
- **Query Param (SQL parametrizado)** — `GET /api/v1/query-param/{dataSet}/{queryName}` via `QueryParameterController.java:28-76` → `QueryParameterService.java:36-55` → `JdbcQueryRepository.java:48-109`.

Ambos compartilham `RouteAuthorizationInterceptor.java:26-38`, `AuthorizationService.java:53-77`, `EndpointWindowService.java:75-105`, `SqlExecutionLimits.java:50-116` e `QueryExecutionBudget.java:39-75`.

---

## 2. Fontes de Verdade Cite file:line

| Domínio | Arquivos |
|---|---|
| Queries JSON | `api-query/config/query/acasala.json:1-33`, `chassi.json:1-28`, `motor.json:1-26`, `pymac-part-number.json:1-34`, `pymac-origin-destination.json:1-12`, `wms-part-number.json:1-53`, `user-claim.json:1-50`, `gq-inspecao.sql:1-176` |
| Datasets | `api-query/config/data-set/ptg-main.json:1-6`, `ptg-test.json:1-6`, `py-ptg.json:1-7`, `gq-mi-wms.json:1-7`, `gq-mi-pymac.json:1-7`, `gq-eficaz.json:1-8` |
| AuthN/AuthZ | `api-query/config/authentication/credential.json:1-22`, `api-query/config/authorization/route.json:1-82`, `GMUD-GO-LIVE/qb-insert-data.sql:804-991`, `GMUD-GO-LIVE/api-query-prod-criacao-completa.sql:1097-1193` |
| Schedule | `api-query/config/schedule/windows.json:1-4` |
| Controllers | `api-query/src/main/java/com/querybuilder/controller/QueryBuilderController.java:32-89`, `QueryParameterController.java:28-76`, `ConfigController.java:39-291`, `HealthController.java:38-79`, `CryptoController.java:14-31` |
| Autorização | `api-query/src/main/java/com/querybuilder/service/AuthorizationService.java:24-181`, `api-query/src/main/java/com/querybuilder/config/RouteAuthorizationInterceptor.java:11-49`, `api-query/src/main/java/com/querybuilder/config/WebMvcConfig.java:16-20` |
| Execução | `api-query/src/main/java/com/querybuilder/service/DataSetService.java:26-245`, `QueryExecutorJDBCService.java:37-280`, `QueryParameterService.java:16-56`, `QueryBuilderService.java:8-24`, `repository/JdbcQueryRepository.java:29-182`, `repository/QueryRepository.java:22-318`, `config/SqlExecutionLimits.java:14-141`, `config/QueryExecutionBudget.java:11-171` |
| Erros | `api-query/src/main/java/com/querybuilder/config/GlobalExceptionHandler.java:35-171`, `dto/ErrorType.java:3-33`, `dto/ErrorMessageResponse.java:6-55` |
| Storage | `api-query/src/main/java/com/querybuilder/config/StorageJdbcConfig.java:21-57`, `repository/CredentialRepository.java:21-134`, `repository/RouteRepository.java:21-148`, `repository/DataSetRepository.java:18-111`, `repository/WindowRepository.java:15-68`, `deploy/database/query-builder-storage-sqlserver.sql:19-162`, `deploy/database/query-builder-storage-v1-to-v2.sql:77-822` |
| Cache | `api-query/src/main/java/com/querybuilder/service/AuthorizationService.java:83-120`, `DataSetService.java:34-99`, `EndpointWindowService.java:44-58` |
| OpenAPI | `api-query/src/main/java/com/querybuilder/config/OpenApiConfig.java:12-39`, `service/QueryRouteOpenApiService.java:43-347` |
| Segurança/crypto/SGC | `api-query/src/main/java/com/querybuilder/util/CryptoUtil.java:17-93`, `service/SgcConnectionClient.java:25-152`, `util/QueryParamValueConverter.java:10-73`, `util/ResultSetUtil.java:11-252`, `config/RuntimeSecurityValidator.java:8-29` |
| DreamFactory nativo | `dreamfactory-fork/extensions/df-named-query/database/definitions/py-ptg.json:1-29`, `gq-mi-wms.json:1-18`, `gq-mi-pymac.json:1-25`, `gq-eficaz.json:1-13`, `py-local.json:1-13`, `src/Resources/NamedQueryResource.php:13-132`, `src/Query/NamedSqlCompiler.php:6-107`, `database/migrations/2026_08_19_000001_create_named_query_tables.php:8-51` |
| Infra/consumidores | `api-query/doc/infra-handover-query-builder.md:1-20`, `api-query/deploy/network-topology.md:11`, `api-query/doc/security-pentest-readiness-2026-08-04.md:39`, `api-query/.env.example:1-45`, `api-query/src/main/resources/application.yaml:1-74` |

---

## 3. Tabela Canônica — Oito Consultas + gq-lote (e extras GMUD)

| # | Query | Dataset | Tipo | Método | Path legado (route.json) | Path nativo DF | Parâmetro(s) | valueType | Required | Claim | Orquestração |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `acasala` | `py-ptg` | JSON STRUCTURED | `GET` | `/query-builder/py-ptg/acasala` → `api-query/config/authorization/route.json:3` | `/api/v2/py_ptg/_query/acasala` (`py-ptg.json:5` service `py_ptg`) | `cma` | — (string implícito) | `cma` | `query_decalque` | `QueryBuilderController.java:53` |
| 2 | `chassi` | `py-ptg` | JSON STRUCTURED | `GET` | `/query-builder/py-ptg/chassi` → `route.json:7` | `/api/v2/py_ptg/_query/chassi` | `vin` (`LIKE`) | — | `vin` | `query_decalque` | `QueryBuilderController.java:53` |
| 3 | `motor` | `py-ptg` | JSON STRUCTURED | `GET` | `/query-builder/py-ptg/motor` → `route.json:10` | `/api/v2/py_ptg/_query/motor` | `carcaca` (`LIKE`) | — | `carcaca` | `query_decalque` | `QueryBuilderController.java:53` |
| 4 | `wms-part-number` | `gq-mi-wms` | JSON STRUCTURED | `GET` | `/query-builder/gq-mi-wms/wms-part-number` → `route.json:15` | `/api/v2/gq_mi_wms/_query/wms-part-number` (`gq-mi-wms.json:4`) | `part_number` + opcionais `subinventario`,`setcode`,`enderecofrom` | `string` (`wms-part-number.json:23,35,39,47`) | `part_number` | `query_gq_mi` | `QueryBuilderController.java:53` |
| 5 | `pymac-part-number` | `gq-mi-pymac` | JSON STRUCTURED | `GET` | `/query-builder/gq-mi-pymac/pymac-part-number` → `route.json:19` | `/api/v2/gq_mi_pymac/_query/pymac-part-number` (`gq-mi-pymac.json:5`) | `part_number` + opcionais `origin`,`destination` | `string` (`pymac-part-number.json:19,27,28`) | `part_number` | `query_gq_mi` | `QueryBuilderController.java:53` |
| 6 | `pymac-origin-destination` | `gq-mi-pymac` | JSON STRUCTURED | `GET` | `/query-builder/gq-mi-pymac/pymac-origin-destination` → `route.json:23` | `/api/v2/gq_mi_pymac/_query/pymac-origin-destination` (`gq-mi-pymac.json:17`) | — (sem filtros) | — | — | `query_gq_mi` | `QueryBuilderController.java:53` |
| 7 | `gq-inspecao` | `gq-eficaz` | SQL | `GET` | `/query-param/gq-eficaz/gq-inspecao` → `route.json:27` | `/api/v2/gq_eficaz/_query/gq-inspecao` (`gq-eficaz.json:5`) | `p_Operation` | string (`:p_Operation` em `gq-inspecao.sql:175`) | `p_Operation` | `query_gq_eficaz` | `QueryParameterController.java:44` |
| 8 | `gq-lote` | `py-local` | SQL | `GET` | `/query-param/py-local/gq-lote` → `GMUD-GO-LIVE/qb-insert-data.sql:893` / `query-builder-restore-gmud-go-live-routes.sql:38` | `/api/v2/py_local/_query/gq-lote` (`py-local.json:5` service `py_local`) | `p_NumMonth` | `integer` (`py-local.json:8`) | `p_NumMonth` | `query_gq_lote` (`qb-insert-data.sql:819-820`) | `QueryParameterController.java:44` |
| 9* | `bom-plan` | `pymac-ifx` | SQL | `GET` | `/query-param/pymac-ifx/bom-plan` → `qb-insert-data.sql:899` | — (sem definition DF versionada; runtime via QB_QUERY) | `p_item`,`p_area_provider`,`p_area_user` | string (trim) | `p_item` + opcionais areas | `query_export_plan` | `QueryParameterController.java:44` |
| 10* | `bom-sgpi` | `sgpi-hml` | SQL | `GET` | `/query-param/sgpi-hml/bom-sgpi` → `qb-insert-data.sql:905` | — (sem definition DF) | `p_item`,`p_area_provider`,`p_area_user` | string | `p_item` | `query_export_plan` | `QueryParameterController.java:44` |

> * `#9` e `#10` são extras GMUD GO-LIVE (`GMUD-GO-LIVE/api-query-prod-criacao-completa.sql:38-40`, `qb-insert-data.sql:9-10,118-287`) — não constam do `config/query/**` legado mas estão no storage produtivo. São inventariados aqui para congelamento.

**Paths legados vs nativos:**

- **Legado externo (consumidor):** sempre prefixado `/api/v1` no wire (`AuthorizationService.java:142-145` remove `/api/v1` antes de matching; `QueryRouteOpenApiService.java:296-299` re-adiciona para documentação). Ex.: consumidor chama `GET http://172.31.18.240/api/v1/query-builder/py-ptg/acasala?cma=...` (`api-query/README.md:194,357`).
- **Storage interno (route.json):** gravado sem `/api/v1` (`route.json:3` = `/query-builder/py-ptg/acasala`), normalizado em `RouteRepository.java:126-138` e `AuthorizationService.java:133-146`.
- **Nativo DreamFactory:** `GET|POST /api/v2/{service}/_query/{name}` (`dreamfactory-fork/docs/architecture/adr-named-query.md:15-17`, `NamedQueryResource.php:22-62` aceita `GET` com query params e `POST` com body JSON/`params_json`).

**Métodos:**

- Consulta: somente `GET` (`QueryBuilderController.java:53`, `QueryParameterController.java:44`). `POST` só existe no lado DF nativo (`NamedQueryResource.php:40-62`).
- Admin legado: `GET/POST/PUT/DELETE /api/v1/files/**` (`api-query/README.md:393-399`, `route.json:31-79`).
- Admin v2 relacional: `GET/POST/PUT/DELETE /api/v1/config/**` (`ConfigController.java:40-214`, `WebMvcConfig.java:18`), e `POST /api/v1/config/cache/clear` (`ConfigController.java:218-224`).
- Sistema: `GET /health` (`HealthController.java:38-79`), `GET /v3/api-docs` e `GET /swagger-ui.html` condicionais (`application.yaml:62-73`, `OpenApiConfig.java:12-39`).

---

## 4. Detalhes por Query

### 4.1 acasala — `api-query/config/query/acasala.json:1-33`

```json
from: vic_s_int_bprd_ctl vsibc INNER JOIN vic_s_int_eprd_ctl vsiec ON vsiec.plan_stamp_image = substr(vsibc.eg_stamp_key,1,5)||'-'||substr(vsibc.eg_stamp_key,7,6)
select: vsibc.effect_stamp_image vin, substr(vsibc.eg_stamp_key,1,5)||'-'||substr(vsibc.eg_stamp_key,7,6) nr_carcaca, vsibc.assy_datetime data_hora_acasala, vsibc.assy_date dt_prod_chassi, vsiec.assy_date dt_prod_carcaca, vsibc.stamp_key cma
filter: groupId cma required [cma] → vsibc.stamp_key = :cma
```

- **DF espelho:** `dreamfactory-fork/extensions/df-named-query/database/definitions/py-ptg.json:4-11` (`budgets.max_rows: 1`).

### 4.2 chassi — `api-query/config/query/chassi.json:1-28`

```json
from: vic_s_int_bprd_ctl vib
select: vib.plan_stamp_image vin, vib.stamp_key cma, vib.model_name designacao_comercial, vib.color_type tipo_cor, vib.color_code codigo_cor, substr(vib.modelvarit_code,1,4) modelo_chassi, substr(vib.plan_assyline,3,1) linha_montagem, substr(vib.assy_date,1,4) ano_fabricacao
filter: groupId vin required [vin] → vib.plan_stamp_image LIKE :vin
```

- **DF espelho:** `py-ptg.json:12-19` (`budgets.max_rows: 1`).

### 4.3 motor — `api-query/config/query/motor.json:1-26`

```json
from: vic_s_int_eprd_ctl vie
select: vie.plan_stamp_image nr_carcaca, vie.stamp_key cma, vie.model_name modelo, substr(vie.plan_assyline,3,1) linha_montagem, vie.parent_part_no modelo_producao, substr(vie.parent_part_no,1,4) modelo_chassi
filter: groupId carcaca required [carcaca] → vie.plan_stamp_image LIKE :carcaca
```

- **DF espelho:** `py-ptg.json:20-27`.

### 4.4 wms-part-number — `api-query/config/query/wms-part-number.json:1-53`

```json
from: (select ... from bdwms_yma.ym_wms_vw_lpn_erp where instr(cod_part_number,'-')>0) wms
select: wms.part_number part_number, wms.part_name part_name, wms.origin origin, wms.destination destination, wms.qtd_saldo qtd_saldo, wms.subinventario subinventario, wms.enderecofrom enderecofrom, wms.nrolpn nrolpn, wms.setcode setcode
filters: part_number required (=, string); subinventario optional (=, string); setcode optional (=, string); enderecofrom optional (LIKE, string)
```

- **DF espelho:** `gq-mi-wms.json:4-16` reescrito como SQL com cláusulas `(:param IS NULL OR col = :param)` (`gq-mi-wms.json:7`).

### 4.5 pymac-part-number — `api-query/config/query/pymac-part-number.json:1-34`

```json
from: (select itemno part_number, max(item_name) item_name, supplier origin, usercd destination, sum(oh_qty) qty_calc from lymdaact.de_int_im where oh_qty>0 and info_date=(select max(info_date) from lymdaact.de_int_im) group by itemno, supplier, usercd) pymac
select: pymac.part_number, pymac.item_name, pymac.origin, pymac.destination, pymac.qty_calc
filters: part_number required (=, string); filtros_opcionais optional [origin,destination] (=, string)
```

- **DF espelho:** `gq-mi-pymac.json:4-15`.

### 4.6 pymac-origin-destination — `api-query/config/query/pymac-origin-destination.json:1-12`

```json
from: (select code origin_destination, code name from (select distinct trim(supplier) code from lymdaact.de_int_im where info_date=(select max(info_date) ...) union select distinct trim(usercd) code ...)) od
select: od.origin_destination, od.name
filters: — (sem filtros)
```

- **DF espelho:** `gq-mi-pymac.json:16-23` (`parameters: []`).

### 4.7 user-claim — `api-query/config/query/user-claim.json:1-50` (interna; não exposta em `route.json`)

```json
from: public.user u
select: u.id, u.user_name, u.email, u.full_name
subQueries: roleItems bindKey userId mainResultKey u.id from public.user_role ur INNER JOIN role r ON ur.id_user=r.id select ur.id_role, r.role_name filter roleId required mergeKey claims
```

- Variante legada `src/main/resources/query/user-claim-old.json:1-50` usa `user_role ur` como join direto e subquery invertida.
- **Não roteada** (ausente em `route.json:1-82`); uso reservado para resolução de claims interna.

---

## 5. gq-inspecao — `api-query/config/query/gq-inspecao.sql:1-176` e `dreamfactory-fork/.../gq-eficaz.json:1-13`

**Natureza:** SQL parametrizado puro (`QUERY_TYPE='SQL'` em `QueryRepository.java:37-42`, `deploy/database/query-builder-storage-v1-to-v2.sql:140-154`).

**SQL (trecho canônico):**

```sql
-- api-query/config/query/gq-inspecao.sql:159-176
FROM TB_INSPECAO_DEFEITO_CHASSI d
INNER JOIN TB_INSPECAO_PRODUTO_CHASSI pc ON pc.CD_CHASSI_PYMAC = d.CD_CHASSI_PYMAC
...
INNER JOIN TB_EVENTO_INSP eI ON TRIM(CONCAT_WS('|',TRIM(d.CD_CHASSI_PYMAC),TRIM(d.CD_PECA1),TRIM(d.CD_PECA2),TRIM(d.CD_DEFEITO),TRIM(d.CD_LOCACAO))) = TRIM(ei.UNIQUE_IDENTITY)
    AND ei.TABLE_NAME = 'TB_INSPECAO_DEFEITO_CHASSI' and ei.OPERATION = :p_Operation and ei.REQUEST_DATE is null
ORDER BY d.DTT_INSPECAO DESC  -- ou ORDER BY NEWID() no snapshot GO-LIVE qb-insert-data.sql:465-466
```

**Parâmetro:** `p_Operation` — `WHERE ei.OPERATION = :p_Operation` (`gq-inspecao.sql:175`). Substituído por `?` em `JdbcQueryRepository.java:62-72` via regex `:p_[A-Za-z0-9_]+` (`JdbcQueryRepository.java:62`).

**Output schema (50 colunas alias):** `gq-eficaz.json:9` lista exatamente 50 `output_schema` entries (de `linha` a `metadados.gq.pymacOn`), espelhando os 50 `AS [alias]` do SQL (`gq-inspecao.sql:3-158`), incluindo 3 colunas JSON (`[inspecao.pecas]` `:46`, `[triagem.defeitosInspecionados]` `:89`, `[investigacao.pecasCausadoras]` `:110`) materializadas via `JSON_QUERY(... FOR JSON PATH)` e desserializadas em `ResultSetUtil.java:229-251`.

### 5.1 gq-inspecao I — `p_Operation = 'I'`

- **Semântica:** `INSERT` — defeitos recém-apontados. Prova de compatibilidade VIP `2026-08-05` retornou 114 registros / 267.903 bytes em 1,464s (`api-query/doc/security-pentest-readiness-2026-08-04.md:39`).
- **Exemplo wire:** `GET /api/v1/query-param/gq-eficaz/gq-inspecao?p_Operation=I` (`api-query/README.md:194`, `config/authorization/route.json:27` exige `query_gq_eficaz`).
- **DF nativo:** `POST /api/v2/gq_eficaz/_query/gq-inspecao` com `{"p_Operation":"I"}` ou `{"params_json":"{\"p_Operation\":\"I\"}"}` (`NamedQueryResource.php:51-59`).

### 5.2 gq-inspecao U — `p_Operation = 'U'`

- **Semântica:** `UPDATE` — defeitos reavaliados/triados; mesmo predicado `ei.OPERATION = :p_Operation` com `U`. Prova retornou 290 registros / 690.721 bytes em 7,746s (`security-pentest-readiness-2026-08-04.md:39`). O envelope e colunas são idênticos ao I; muda apenas o conjunto filtrado por `TB_EVENTO_INSP`.
- **Fila:** `ei.REQUEST_DATE IS NULL` indica polling de eventos pendentes — consumidor deve consumir e marcar `REQUEST_DATE` a montante (fora do escopo api-query).

---

## 6. gq-lote — `dreamfactory-fork/extensions/df-named-query/database/definitions/py-local.json:1-13`, `GMUD-GO-LIVE/qb-insert-data.sql:8-19`

**Storage legado:** `qb-insert-data.sql:9-16` (`ID='gq-lote'`, `FILE_NAME='gq-lote.sql'`).

**SQL canônico DF (normalizado):**

```sql
-- py-local.json:7
SELECT lote, id_montagem_motocicleta, nr_fabricacao, nr_inicio_serial, nr_posicao_serial, nr_qtd_digitos_serial, nr_qtd_producao
FROM TB_MONTAGEM_MOTOCICLETA
WHERE dt_montagem >= now() - (:p_NumMonth || ' months')::interval AND lote IS NOT NULL
-- variante Oracle legada em qb-insert-data.sql:11-16 usa (:p_NumMonth || ' months')::interval
```

**Parâmetro:** `p_NumMonth` — `integer` `required: true` (`py-local.json:8`). Erro dedicado se tipo inválido via `QueryParamValueConverter.java:25` → `BadRequestException` (`ErrorType.BAD_REQUEST:12-15`).

**Output schema:** 7 colunas (`py-local.json:9`): `lote`, `id_montagem_motocicleta`, `nr_fabricacao`, `nr_inicio_serial`, `nr_posicao_serial`, `nr_qtd_digitos_serial`, `nr_qtd_producao`.

**Budget:** `max_rows: 10000` (`py-local.json:10`), prova 6 meses = 2.999 registros / 536.408 bytes em 4,753s (`security-pentest-readiness-2026-08-04.md:39`).

**Wire:** `GET /api/v1/query-param/py-local/gq-lote?p_NumMonth=6` (`api-query/doc/infra-handover-query-builder.md:336`), claim `query_gq_lote` (`qb-insert-data.sql:819`).

### Extras GMUD (para congelamento)

- `bom-plan` (`pymac-ifx`) — `qb-insert-data.sql:118-287` — `p_item` (required), `p_area_provider`, `p_area_user` (opcionais via `CASE WHEN IS NULL`).
- `bom-sgpi` (`sgpi-hml`) — `qb-insert-data.sql:20-117` — Oracle hierarchical `CONNECT BY` com mesmos 3 params.

---

## 7. Paths, Métodos e Roteamento

| Camada | Path exposto | Método | Handler | Protegido | Arquivo |
|---|---|---|---|---|---|
| Query Builder | `/api/v1/query-builder/{dataSet}/{queryName}` | `GET` | `QueryBuilderController.java:53` | Sim (`WebMvcConfig.java:18`) | `WebMvcConfig.java:18` |
| Query Param | `/api/v1/query-param/{dataSet}/{queryName}` | `GET` | `QueryParameterController.java:44` | Sim | `WebMvcConfig.java:18` |
| Admin v1 (legado) | `/api/v1/files`, `/api/v1/files/{sub}` `/api/v1/files/download/{sub}` `/api/v1/files/upload/{sub}` | `GET/POST/PUT/DELETE` | `FileManager` (v1) → substituído por `ConfigController.java:40` | Sim (`route.json:31-79`) | `route.json:31-79` |
| Admin v2 | `/api/v1/config/credentials`, `/routes`, `/datasets`, `/queries`, `/windows`, `/cache/clear` | `GET/POST/PUT/DELETE` | `ConfigController.java:54-214` | Sim (`WebMvcConfig.java:18`) | `WebMvcConfig.java:18` |
| Crypto | `/api/v1/files/crypto/encrypt` (`CryptoController.java:15`) / legado `/api/v1/crypto/encrypt` | `POST text/plain` | `CryptoController.java:25` | Sim (claim `adm_api_query` via `/api/v1/files/**` em `route.json:31-79`; + `dev` profile) | `CryptoController.java:11-15` |
| Health | `/health` | `GET` | `HealthController.java:38` | Não | `HealthController.java:38` |
| OpenAPI | `/v3/api-docs`, `/swagger-ui.html` | `GET` | `springdoc` | Não (flag) | `application.yaml:62-73` |
| DF nativo | `/api/v2/{service}/_query` (lista) e `/api/v2/{service}/_query/{name}` (exec) | `GET` lista, `GET|POST` exec | `NamedQueryResource.php:22-62` | Sim (DreamFactory RBAC `listAccessComponents:64-80`) | `NamedQueryResource.php:13-132` |
| DF admin | `system/named_query` | `POST/PATCH` (draft/publish) | `NamedQueryAdminResource.php` | Sim | `adr-named-query.md:30-34` |

**Normalização de rota:** `AuthorizationService.java:133-146` normaliza removendo `/api/v1` e barra final; `matchesRoute:148-158` usa melhor prefixo específico (max length). Mesmo em `EndpointWindowService.java:225-248`. `RouteRepository.java:126-138` normaliza na persistência.

---

## 8. Headers — Aliases Canônicos

| Header canônico (OpenAPI) | Aliases aceitos | Código | Obrigatório |
|---|---|---|---|
| `client_secret` | `client-secret`, `x-client-secret` | `RouteAuthorizationInterceptor.java:30` + `AuthorizationService.java:59-60` | Sim, ambos não-vazios (`AuthorizationService.java:59`, `GlobalExceptionHandler.java:67-72` → `401/1001`) |
| `client_key` | `client-key`, `x-client-key` | `RouteAuthorizationInterceptor.java:31`, `OpenApiConfig.java:23-32` (securitySchemes `clientSecret`/`clientKey` tipo `APIKEY` header) | Sim |

- Comparação em tempo constante via `MessageDigest.isEqual` (`AuthorizationService.java:159-166`), `credentialsMatch:168-174`.
- Falha sem credencial → `UnauthorizedException` → `401/1001` (`GlobalExceptionHandler.java:67-72`); claim insuficiente → `ForbiddenException` → `403/1003` (`GlobalExceptionHandler.java:74-79`).
- Ordem de validação: primeiro credencial (`AuthorizationService.java:63-66`), depois rota (`68-71`), depois `Collections.disjoint` de claims (`74-76`).

---

## 9. Parâmetros — Semântica de Filtros

### 9.1 JSON DSL (`QueryExecutorJDBCService.java:150-280`)

- `FilterGroup` com `required: [...]` e `optional: [...]` (`acasala.json:23-24`). `validateRequiredFilters:208-254`:
  - Se algum `required` do grupo referenciado estiver faltando → `400 "Parametro(s) obrigatorio(s) ausente(s): ..."`.
  - Se nenhum grupo `required` aplicável → `400 "Parametro(s) obrigatorio(s): ..." ` ou `"Informe ao menos um grupo ..."` .
- `isGroupApplicable:256-260` só aplica grupo se todos `required` presentes.
- `buildWhereClause:185-206` suporta `=, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, IS NULL, IS NOT NULL` (`QueryExecutorService.java:208-222` espelha via jOOQ).
- `LIKE` não adiciona `%` — consumidor envia padrão completo.
- `IN/NOT IN`: split por `,` (`QueryExecutorJDBCService.java:195`), `validateInItemCount` por `max-in-items` (`SqlExecutionLimits.java:70-74`).
- `valueType`: `QueryParamValueConverter.convert:15-32` — `string` (identidade), `long/integer`, `double/number/decimal`, `boolean`, `date` (`LocalDate`), `datetime` (`LocalDateTime`); inferência se `null/blank` (`infer:34-41`). Erro → `BadRequestException` com `"Invalid value for parameter 'x'. Expected: ..."` (`QueryParamValueConverter.java:70-72`).
- Limites de entrada: `validateRequestParameters:50-55` (≤ `max-parameters`), `validateValue:57-62` (≤ `max-parameter-value-length`), `validateBindCount:64-68`, `validateInItemCount`, `validateSubqueryExecutions:77-80`.

### 9.2 SQL parametrizado (`JdbcQueryRepository.java:48-110`)

- Regex `:p_[A-Za-z0-9_]+` (`JdbcQueryRepository.java:62`, `QueryRouteOpenApiService.java:159`). Substituído por `?` (`JdbcQueryRepository.java:72`).
- `normalizeParameterValue:112-125` remove aspas simples/duplas externas se presentes.
- `setParameter:130-182` usa tipo Java nativo; `null` → `Types.NULL`.
- Mesmos limites de `SqlExecutionLimits` aplicados (`JdbcQueryRepository.java:58,69,84`).

---

## 10. Envelopes

### 10.1 Sucesso — Query Builder/Param (`QueryBuilderController.java:80-88`, `QueryParameterController.java:63-74`)

```json
{
  "elapsed_time": 123,
  "request_id": "uuid (UUID.randomUUID:61,52)",
  "timestamp": "yyyy-MM-dd HH:mm:ss.SSS (SimpleDateFormat:78,64)",
  "result_count": 2,
  "result": [ { "col_label": "valor", ... } ]
}
```

- `result` é `List<Map<String,Object>>` normalizado: `ResultSetMetaData.getColumnLabel` (`JdbcQueryRepository.java:95`, `QueryExecutorJDBCService.java:128`) lowercased em `ResultSetUtil.java:64` ou com alias após último espaço (`QueryExecutorJDBCService.java:129-130`). JSON embutido desserializado em `ResultSetUtil.java:229-251`.
- `elapsed_time`: `Calendar.getInstance` diff (`QueryBuilderController.java:60-75`).
- `request_id`: logado em `INFO` com `parameter_count` (`QueryBuilderController.java:63-65`); nomes só em `DEBUG`.
- **DF nativo:** `{"resource": [ {...}, ... ]}` (`NamedQueryResource.php:107`), truncado em `collectRows:110-121` por `maxRows:123-131`.

### 10.2 Erro — `ErrorMessageResponse.java:6-55` + `GlobalExceptionHandler.java:40-159`

```json
{
  "erroCode": 1400,
  "errorMessage": "Parametro(s) obrigatorio(s): cma",
  "timestamp": "2026-08-26 14:00:00.000"
}
```

| HTTP | erroCode (`ErrorType.java:3-15`) | Quando | Mensagem |
|---|---|---|---|
| 400 | 1400 `BAD_REQUEST` | `BadRequestException` (`GlobalExceptionHandler.java:46-51`), `InvalidRequestException:53-58`, `MissingServletRequestParameter`, `MethodArgumentTypeMismatch`, `MethodArgumentNotValid`, `ConstraintViolation` (`103-144`), `MaxUpload` desvio → 413 |
| 401 | 1001 `UNAUTHORIZED` | `UnauthorizedException` (`67-72`) — sempre genérica `"Credenciais inválidas ou ausentes."` |
| 403 | 1003 `FORBIDDEN` | `ForbiddenException` (`74-79`) — inclui rota não autorizada (`AuthorizationService.java:71`), claim insuficiente (`75`), janela bloqueada (`EndpointWindowService.java:90-104`) — mensagem genérica em 403 mas janela detalha em `describe:162-172` |
| 404 | 1004 `RESOURCE_NOT_FOUND` | `NotFoundException` (`40-44`), `NoResourceFoundException:146-152` |
| 405 | 1405 `METHOD_NOT_ALLOWED` | `HttpRequestMethodNotSupportedException:118-122` |
| 409 | 1409 `CONFLICT` | `ConflictException` (`60-65`) — credencial/rota já existe (`CredentialRepository.java:78`, `RouteRepository.java:74`), deleção protegida (`ConfigService.java:73-98`) |
| 413 | 1413 `PAYLOAD_TOO_LARGE` | `MaxUploadSizeExceededException:110-115` (multipart 2MB/3MB em `application.yaml:9-11`) |
| 504 | 5504 `GATEWAY_TIMEOUT` | `QueryExecutionTimeoutException:89-94` — timeout SQL (`SqlExecutionLimits.java:103-112`) ou deadline total (`QueryExecutionBudget.java:39-54`) |
| 500 | 5000 `INTERNAL_SERVER_ERROR` | `RuntimeException:81-87` — genérico, nunca expõe SQL/stack ao cliente |

---

## 11. Storage

### 11.1 Runtime atual: SQL Server relacional (database-only)

- `query-builder.storage.type=database` (`application.yaml:21`), `StorageJdbcConfig.java:22-50` cria `HikariDataSource` (`pool query-builder-config-storage`, 5 max, 10s connect, 60s idle, 5min lifetime) a partir de `QUERY_BUILDER_STORAGE_JDBC_URL/USERNAME/PASSWORD/DRIVER` (`application.yaml:22-26`, `.env.example:16-20`).
- Sem fallback para arquivos em homolog/prod; `api-query/README.md:70-74`.

**Tabelas v2 (relacionais):** `deploy/database/query-builder-storage-v1-to-v2.sql:77-312`:

| Tabela | PK | Conteúdo | Repositório |
|---|---|---|---|
| `QB_CREDENTIAL` | `CREDENTIAL_ID IDENTITY` `UQ CLIENT_KEY` | `CLIENT_SECRET, CLIENT_KEY` | `CredentialRepository.java:35-40` |
| `QB_CREDENTIAL_CLAIM` | `(CREDENTIAL_ID, CLAIM)` | claims por credencial | `CredentialRepository.java:46-53` |
| `QB_ROUTE` | `ROUTE_ID IDENTITY` `UQ ROUTE` | `ROUTE` normalizada | `RouteRepository.java:35-37` |
| `QB_ROUTE_CLAIM` | `(ROUTE_ID, CLAIM)` | claims por rota | `RouteRepository.java:44-50` |
| `QB_DATA_SET` | `DATASET_NAME` | `JDBC_URL, JDBC_USR, JDBC_CRYPTO_PWD, SGC_CONNECTION_ID, SGC_JDBC_URL_SUFFIX` | `DataSetRepository.java:27-31` |
| `QB_QUERY` | `QUERY_NAME` `CK QUERY_TYPE IN ('STRUCTURED','SQL')` | `FROM_TABLE` ou `SQL_TEXT` | `QueryRepository.java:31-42` |
| `QB_QUERY_SELECT/JOIN/FILTER_GROUP/FILTER`, `QB_QUERY_SUBQUERY*` | compostas | normalização JSON → relacional | `QueryRepository.java:157-298` |
| `QB_WINDOW` | `WINDOW_ID IDENTITY` | `ROUTE, TIMEZONE, DAYS, START_TIME, END_TIME` `CK START<>END` | `WindowRepository.java:24-32` |
| `QB_SCHEMA_VERSION` | `VERSION` | `1=Oracle legacy, 2=Relacional v2` | `query-builder-storage-sqlserver.sql:108-124` |

**Migração v1→v2:** `query-builder-storage-v1-to-v2.sql:21-74` preserva `BACKUP_QB_*_V1`, valida JSON (`ISJSON:314-352`), unicidade (`381-416`), migra credenciais/rotas/datasets/queries (`419-729`), janelas (`731-773`), e garante `adm_api_query` (`775-799`) e `VERSION=2` (`801-805`).

**DDL legado v1:** `query-builder-storage-sqlserver.sql:19-162` (cinco tabelas `QB_QUERY, QB_DATA_SET, QB_AUTHENTICATION, QB_AUTHORIZATION, QB_ENDPOINT_WINDOW` com `CONTENT NVARCHAR(MAX)`).

### 11.2 Sementes versionadas (não runtime)

- `api-query/config/query/*.json`, `data-set/*.json`, `authentication/credential.json`, `authorization/route.json`, `schedule/windows.json` — placeholders (`REPLACE_WITH_*` em `credential.json:3,9,14,19` e `ptg-main.json:5`).

### 11.3 DreamFactory storage (paralelo)

- Migrations `named_query` + `named_query_revision` (`2026_08_19_000001_create_named_query_tables.php:11-50`), consumo via `NamedQueryResource.php:86-107`; import idempotente `ImportNamedQueries.php`.

---

## 12. Cache

| Cache | TTL config | Default | Código | Invalidação |
|---|---|---|---|---|
| Autorização (credentials+routes snapshot) | `query-builder.authorization.cache-ttl-ms` (`AuthorizationService.java:32-33`) | 300000 (5m) (`application.yaml:32-33`) | `AuthorizationSnapshot:176-180` + `refreshIfExpired:98-120` com `nextRefreshAttemptAtMs` throttling 5s (`112`) | startup `@PostConstruct loadConfigurations:44-47`, `reloadConfigurations:49-51` sob `synchronized`, e `ConfigService.java:63-101` após `insert/update/delete` de credenciais/rotas |
| Dataset/DataSource Hikari | `query-builder.dataset.cache-ttl-ms` (`DataSetService.java:34-35`) | 300000 (`application.yaml:45-46`) | `CachedDataSource:235-241` + `ConcurrentHashMap dataSourceCache:37` + `ReentrantReadWriteLock cacheLifecycleLock:38` | `withDataSource:47-87` double-checked locking; `clearCache:89-99`, `invalidateDataset:101-114` chamados por `ConfigService.java:120,128,176` |
| Window/Endpoint | `query-builder.window.cache-ttl-ms` (`EndpointWindowService.java:45-46`) | 300000 (`application.yaml:52`) + `timezone America/Manaus:53` | `WindowSnapshot:256` + `refreshIfExpired:197-217` | `@PostConstruct loadWindows:59-62`, `reloadWindows:64-70`, `clearCache:107-112` via `ConfigService.java:164,170,176` |
| Health probe | n/a | 5000ms hard-coded | `HealthController.java:26` `PROBE_CACHE_MS=5_000` + `AtomicReference lastPing:30-31` | `probeStorage:81-110` com `SELECT 1` ou `SELECT 1 FROM DUAL` e `queryTimeout 2s:98` |

- `TTL=0` significa sem cache (recarrega a cada uso) — documentado em `AuthorizationService.java:85-86`, `EndpointWindowService.java:184-185`, `DataSetService.java:238-239`.

---

## 13. OpenAPI

- **Config base:** `OpenApiConfig.java:15-32` — `title Query Builder API v1`, `securitySchemes clientSecret/clientKey (APIKEY header)`. `queryRouteOpenApiCustomizer:35-38` delega a `QueryRouteOpenApiService`.
- **Geração dinâmica:** `QueryRouteOpenApiService.java:60-81` itera `authorizationService.getRoutes()` filtrando `route.startsWith("/query-builder/") || "/query-param/"`, `toApiPath:296-299` prefixa `/api/v1`, `buildPathItem:83-88` escolhe `buildQueryBuilderPathItem:90-107` (lê `QueryBuilderService.loadQuery`) vs `buildQueryParamPathItem:109-126` (lê `QueryRepository.findSqlText`).
- **Parâmetros:** `buildParameters:128-143` coleta `FilterGroup` de main+subQueries, `globallyRequiredParams:216-225` (só se um único grupo required), `collectParameterDocs:227-274` gera `required/description/valueType`. `buildSqlParameters:158-176` extrai `:p_Name` via regex e marca todos `required:true`.
- **Responses:** `defaultResponses:145-156` — `200 ArraySchema dynamicObject`, `400/401/403/404/500` com `errorSchema:192-197` (`erroCode, errorMessage, timestamp`).
- **Flags:** `springdoc.cache.disabled:true` (`application.yaml:62-63`), `api-docs.enabled=${QUERY_BUILDER_OPENAPI_ENABLED:false}` (`application.yaml:66`), `swagger-ui.enabled=${QUERY_BUILDER_SWAGGER_UI_ENABLED:false}` (`application.yaml:68-69`); `application-prod.yaml:1-5` desabilita ambos em prod.

---

## 14. Consumidores

| Consumidor | Rotas consumidas (prova VIP 2026-08-05) | Claim | Evidência |
|---|---|---|---|
| `api-sync` (YMAH0117) host `172.31.18.117:8080` (`deploy/network-topology.md:11,66,80`) | `gq-inspecao I`, `gq-inspecao U`, `gq-lote` (6 meses) | `query_gq_eficaz` (`route.json:27`) + `query_gq_lote` (`qb-insert-data.sql:819`) + `query_gq_mi` centralizada em `tsysgqmi/rwm7scv1` (`credential.json:7-11`) | `security-pentest-readiness-2026-08-04.md:39` — HTTP 200, 1,4s/267KB, 7,7s/690KB, 4,7s/536KB dentro do timeout 30s do consumidor |
| Decalque/PTG interno | `acasala, chassi, motor` (`py-ptg`) | `query_decalque` (`credential.json:3-6`, `route.json:3-13`) | `route.json:3-13`, `py-ptg.json` |
| GQ MI WMS/Pymac | `wms-part-number`, `pymac-part-number`, `pymac-origin-destination` | `query_gq_mi` (`credential.json:8-10`, `route.json:15-25`) | `route.json:15-25` |
| Admin `devapiquery/kwp8bqt4` | `/api/v1/files/**` e `/api/v1/config/**` | `adm_api_query` (`credential.json:12-16`, `route.json:31-79`) | `route.json:31-79`, `ConfigController.java:42` |
| Expo Plan | `bom-plan, bom-sgpi` | `query_export_plan` (`qb-insert-data.sql:832-835`, `899-907`) | `qb-insert-data.sql:832-835` |

- Todos consumidores passam pelo VIP (`doc/infra-handover-query-builder.md:58-63`); `server.tomcat.connection-timeout=60s` (`application.yaml:17`) e `HealthController.java:38` usado pelo LB.

---

## 15. Segurança — Crypto e SGC

- **Crypto:** `CryptoUtil.java:17-36` exige `QUERY_BUILDER_SECRET_KEY` de 16/24/32 bytes UTF-8 (`application.yaml:54-55`, `.env.example:8`). `encrypt:50-66` gera `gcm:v1:` + `Base64(iv+ciphertext)` com `AES/GCM/NoPadding` `GCM_IV_BYTES=12` `GCM_TAG_BITS=128`. `decrypt:39-48` lê `gcm:v1:` via `decryptGcm:68-82` ou fallback `AES/ECB/PKCS5Padding` legado (`LegacyTransformation:23`, `decryptLegacy:84-92` com warning once).
- **SGC:** `SgcConnectionClient.java:25-152` — SOAP `getConexaoById` (`soapBody:123-134`), endpoint `${QUERY_BUILDER_SGC_ENDPOINT}` (`32`), timeout `${QUERY_BUILDER_SGC_TIMEOUT_MS:3000}` (`35-36`), `max-response-bytes 1MB` (`38-39`), `validateConfiguration:47-57` rejeita `userInfo` e limita tamanho, `sendWithTimeout:100-121` com `BodyHandlers.limiting` e `Future.get` com `TimeUnit.MILLISECONDS`, `readSoapReturn:136-150` com `FEATURE_SECURE_PROCESSING` e `disallow-doctype-decl`. `DataSetService.java:137-200` tenta local primeiro e só faz fallback SGC se `sgc-connection-id` presente e `isConfigured()` (`DataSetService.java:182`), atualizando `QB_DATA_SET` com ciphertext fresco (`updateStoredDataSet:202-213`).
- **Validação de runtime:** `RuntimeSecurityValidator.java:18-28` exige `Spring profile` explícito e proíbe `dev` combinado.

---

## 16. Decisões por Comportamento — Preserve / Migrate / Deprecate

> Cada linha abaixo é uma decisão de congelamento para a migração DreamFactory. `Preserve` = manter idêntico no adapter; `Migrate` = mover para nativo `_query`; `Deprecate` = remover após janela.

| # | Comportamento | Decisão | Justificativa |
|---|---|---|---|
| 1 | `GET /api/v1/query-builder/{ds}/{q}` JSON DSL com `FilterGroup`/`LIKE`/`IN` | **Preserve** (adapter interno → depois **Migrate** para `GET\|POST /api/v2/{service}/_query/{name}`) | Contrato em uso por 6 queries (`route.json:3-25`). Lógica idêntica em `QueryExecutorJDBCService.java:150-206` e já espelhada em `df-named-query` SQL (`py-ptg.json`, `gq-mi-*.json`). Adapter preserva compat até migração de consumidores — `dreamfactory-target-api-query.md:122-123`. |
| 2 | `GET /api/v1/query-param/{ds}/{q}` SQL `:p_Name` | **Preserve** (adapter → **Migrate**) | Usado por `gq-inspecao` e `gq-lote` (`QueryParameterController.java:44`, `JdbcQueryRepository.java:62-72`). DF nativo já compila mesmo SQL via `NamedSqlCompiler.php:9-43`. |
| 3 | `gq-inspecao` `p_Operation=I` | **Preserve** | Polling de inserts; 114 rows prova (`security-pentest...md:39`). SQL `ei.OPERATION=:p_Operation AND REQUEST_DATE IS NULL` (`gq-inspecao.sql:175`). |
| 4 | `gq-inspecao` `p_Operation=U` | **Preserve** | Polling de updates; 290 rows prova. Mesmo envelope/SQL que I, apenas conjunto distinto. |
| 5 | `gq-lote` `p_NumMonth` integer | **Preserve** | Histórico `TB_MONTAGEM_MOTOCICLETA` (`py-local.json:7`). Prova 2.999 rows. Claim dedicado `query_gq_lote` (`qb-insert-data.sql:819`). |
| 6 | Aliases de headers `client_secret` ↔ `client-secret` ↔ `x-client-secret` (e `_key`) | **Preserve** | `RouteAuthorizationInterceptor.java:30-31`; consumidores heterogêneos. Remover quebra compat. |
| 7 | Normalização de rota (remove `/api/v1`, prefixo mais específico) | **Preserve** | `AuthorizationService.java:133-158`, `RouteRepository.java:126-138`. Sem isso, shadowing de rotas. |
| 8 | Envelopes `elapsed_time/request_id/timestamp/result_count/result` | **Preserve** no adapter; **Migrate** nativo para `{"resource":[...]}` (`NamedQueryResource.php:107`) | `QueryBuilderController.java:80-86`. DF nativo tem contrato distinto (`connector-clean-room.md:1-17`). Adapter faz tradução. |
| 9 | Erros `erroCode/errorMessage/timestamp` com codes `1400/1001/1003/1004/5504/5000` | **Preserve** no adapter | `ErrorType.java:3-16`, `GlobalExceptionHandler.java:40-94`. DF nativo usa `DreamFactory\Core\Exceptions` — mapear no adapter. |
| 10 | SubQueries `user-claim` (`roleItems` `mergeKey claims`) | **Migrate** (reimplementar como `JOIN` ou `WITH` no DF) | `user-claim.json:26-47` executa N+1 `executeSubQuery:69-95` limitado por `max-subquery-executions 500` (`SqlExecutionLimits.java:77-80`). Nativo deve evitar N+1; `adr-named-query.md:37-44` exige validação. |
| 11 | Storage SQL Server relacional `QB_CREDENTIAL/ROUTE/DATA_SET/QUERY/WINDOW` | **Migrate** (para `system` + `named_query/named_query_revision` DF) | `query-builder-storage-v1-to-v2.sql:77-312` já migrou de document store. Próximo passo: `2026_08_19_000001_create_named_query_tables.php:11-50` como source of truth — `adr-named-query.md:9-13`. |
| 12 | Cache TTL 5m + throttling 5s (`AuthorizationService.java:112`, `EndpointWindowService.java:209`) | **Preserve** semântica, **Migrate** para cache distribuído DF | `dreamfactory-target-api-query.md:136` exige invalidação distribuída; adapter mantém TTL local até cutover. |
| 13 | Window per-endpoint `schedule/windows.json` / `QB_WINDOW` | **Preserve** | `EndpointWindowService.java:75-105`, `application.yaml:51-53`. Sem equivalente nativo ainda; manter como middleware até RBAC agendado nativo. |
| 14 | OpenAPI dinâmico `QueryRouteOpenApiService.java:60-176` | **Preserve** no adapter; **Migrate** para `DreamFactory OpenAPI` nativo | `OpenApiConfig.java:12-39`. Em prod, desabilitado por `QUERY_BUILDER_OPENAPI_ENABLED=false` (`application.yaml:66`, `application-prod.yaml:1-5`). |
| 15 | Datasets com `sgc-connection-id` + fallback SOAP | **Deprecate** (mover para `ServiceConfig` + `SecretStore` DF) | `DataSetService.java:179-213`, `SgcConnectionClient.java:60-98`. `dreamfactory-target-api-query.md:47-53` prevê `ServiceConfig` + `SgcResolver` opcional. Fallback atual é risco — `security-pentest...md:419` SSRF controlado mas egress amplo. |
| 16 | Crypto `gcm:v1:` + fallback `ECB` legado | **Preserve** leitura `ECB` só para migração; **Migrate** escrita para DF `SecretStore` | `CryptoUtil.java:39-92`. `ECB` mantido só para `decryptLegacy:84-92`. Novos valores devem usar `SecretStore` (`dreamfactory-target-api-query.md:53`). |
| 17 | `POST /api/v1/files/**` admin v1 | **Deprecate** (substituído por `POST /api/v1/config/**`) | `ConfigController.java:35` comenta `Substitui /api/v1/files/**`. `query-builder-storage-v1-to-v2.sql:775` confirma. Manter adapter read-only até migração, depois remover. |
| 18 | `POST /api/v1/files/crypto/encrypt` | **Deprecate** (restrito a `dev`) | `CryptoController.java:11-15`, `RuntimeSecurityValidator.java:25` proíbe `dev`+outro profile. Em prod deve ser removido do artefato ou mTLS — `security-pentest...md:SEC-09`. |
| 19 | `GET /health` com cache 5s + `SELECT 1` probe | **Preserve** | `HealthController.java:26-110`. Usado por Nginx/Keepalived (`doc/infra-handover...md:125,145-149`). |
| 20 | `GET /v3/api-docs` + `swagger-ui` flag-gated | **Preserve** em homolog, **Deprecate** em prod | `application.yaml:62-73`, `application-prod.yaml:1-5`. `security-pentest...md:SEC-08` mitigado com flags. |
| 21 | `valueType` coerção (`QueryParamValueConverter.java:15-32`) | **Preserve** | Evita `ORA-01722` (`api-query/README.md:807-820`). DF `NamedSqlCompiler.php:73-87` faz coerção similar. |
| 22 | `ORDER BY NEWID()` vs `ORDER BY d.DTT_INSPECAO DESC` em `gq-inspecao` | **Preserve** `NEWID()` para polled-queue fairness | Snapshot GO-LIVE `qb-insert-data.sql:466` usa `NEWID()`; config semente `gq-inspecao.sql:176` usa `DTT_INSPECAO DESC`. Congelado como `NEWID()` em prod — documentar diferença. |
| 23 | `bom-plan`/`bom-sgpi` GO-LIVE | **Preserve** até GO-LIVE; **Migrate** para DF após | `qb-insert-data.sql:20-287`, `query-builder-restore-gmud...sql:39-40`. Não em `route.json` legado mas em `QB_QUERY` produtivo. |

---

## 17. Matriz Storage × Cache × OpenAPI × Consumidores

| Aspecto | Estado congelado | Código |
|---|---|---|
| Storage write | `ConfigController.java:60-214` valida (`CredentialRepository.validate:127-133`, `RouteRepository.validate:140-147`, `DataSetRepository.validate:94-110`, `QueryRepository.validate:307-318`, `WindowRepository.validate:56-64`) e persiste relacional; `ConfigService.java:63-101` faz `reloadConfigurations` após mutação | `ConfigController.java:60-214` |
| Storage read | `AuthorizationService.currentSnapshot:83-96` + `DataSetService.withDataSource:47-87` + `QueryRepository.findStructured:56-97` / `findSqlText:45-53` | `AuthorizationService.java:83-96`, `DataSetService.java:47-87` |
| Cache clear | `POST /api/v1/config/cache/clear` → `ConfigService.clearRuntimeCaches:173-177` (reload auth + windows + `DataSetService.clearCache:89-99`) | `ConfigController.java:218-224` |
| OpenAPI docs | `GET /v3/api-docs` gera paths dinamicamente de `RouteRepository` + `QueryRepository` | `QueryRouteOpenApiService.java:60-176` |
| Consumidor preflight | `api-sync` validado VIP 2026-08-05 com limites atuais dentro de `query-timeout 45s` + `request-timeout 45s` + `max-rows 10000` | `SqlExecutionLimits.java:27-45`, `QueryExecutionBudget.java:25-36` |

---

## 18. Referências de Arquivos Alterados/Criados

| Arquivo | Ação | file:line âncora |
|---|---|---|
| `dreamfactory-fork/docs/architecture/inventory-api-query-contract.md` | **Criado** (canônico RQ-001) | este arquivo |
| `dreamfactory-fork/docs/architecture/dreamfactory-original.md:1-101` | **Preservado** (referência histórica DF 7.7.0) | `dreamfactory-original.md:1` |
| `dreamfactory-fork/docs/architecture/dreamfactory-target-api-query.md:1-137` | **Preservado** (alvo nativo) | `dreamfactory-target-api-query.md:1` |
| `dreamfactory-fork/docs/architecture/adr-named-query.md:1-51` | **Preservado** (decisão `_query`) | `adr-named-query.md:15-17` |
| `dreamfactory-fork/docs/architecture/connector-clean-room.md:1-17` | **Preservado** (controles clean-room) | `connector-clean-room.md:1-17` |

---

## 19. Critérios de Aceite RQ-001 — Checklist

- [x] Inventário versionado referencia código e config atuais com `file:line` (Seção 2 — 40+ arquivos citados)
- [x] Inclui `gq-lote` e `gq-inspecao` I e U (Seções 5–6, Tabela 3 linhas 7–8)
- [x] Cada comportamento com decisão Preserve/Migrate/Deprecate (Seção 16 — 23 linhas)
- [x] Oito consultas documentadas + extras GO-LIVE (Seções 3–4, Tabela 3)
- [x] Paths, métodos, aliases de headers, parâmetros, envelopes, erros, storage, cache, OpenAPI, consumidores (Seções 7–15)
- [x] Sem dados inventados — toda afirmação ancorada em leitura direta

---

## 20. Próximos Passos (fora do freeze)

1. Adaptador legado `query-builder/query-param` dentro do ciclo nativo DF (após RBAC por `_query`).
2. Corte de `/api/v1/files/**` e `CryptoController` em prod.
3. Migração de `SGC` para `SecretStore` DF.
4. Invalidação distribuída de cache (substituir TTL local).

