# Parity Report — RQ-083 (8 consultas)

**Status**: Aprovado pelos owners — 8/8 consultas certificadas

## Consultas
- py_ptg, gq_mi_wms, gq_eficaz, py_local, gq_lote, q1, q2, q3 — todas com fixtures em `database/definitions/*.json`

## Verificações
- Aliases: OK (snake_case → camelCase via ResultNormalizer)
- Nulls: OK (verificado `IS NULL`)
- Datas: OK (ISO8601, timezone UTC)
- JSON: OK (jsonb colunas)
- Binários: OK (bytea)
- Ordering: exceções explícitas documentadas (ORDER BY determinístico exceto `gq_lote` com `LOTE` → `lote_id`)

## Owners
- Aprovado: @AndresLosada21 — 2026-09-01

## Evidência
- `extensions/df-named-query/tests/ParityTest.php` — 8/8 green
