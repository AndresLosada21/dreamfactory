# SLO — Named Query HA (RQ-074)

## SLO Aprovado
- **Disponibilidade**: 99.9% (43m downtime/mês)
- **Latência p95**: <200ms (query simples), <500ms (complexa)
- **Erro rate**: <0.1%
- **Pool pressure**: <80% utilização

## Multi-nó / VIP
- VIP drain seguro: `drain -> 503 readiness -> remove from LB`
- Rolling update sem downtime, sem sticky session

## Sem retry automático de SQL em execução
- Query em execução não retenta automaticamente em failover; cliente deve reenviar

## Soak 24h
- Sem crescimento ilimitado: memory <10% growth /24h, sem leak
- Pool stable, cache_generation converge <=2s

## Pool pressure / Slow query
- Budget `max_rows`, `max_bytes`, `timeout 45s` + `MetricsService` rejects
- Slow query cancelamento via `QueryExecutionBudget::checkDeadline` + `statement_timeout`

## Evidência
- `LoadSoakTest.php` simula carga multi-nó + pool pressure
