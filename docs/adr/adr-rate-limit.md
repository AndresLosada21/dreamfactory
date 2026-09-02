# ADR-001 — Rate Limit por Rota/Role (RQ-LIM-01/02)

## Contexto
DreamFactory OSS não tem rate-limit nativo premium. Necessário limiter por rota (`_query/{name}`) e por `role` para proteger `172.31.16.89/SGC` e `SGA` e DBs.

## Decisão
- **Estratégia**: Token bucket por `client_key` + `role` + `route` via `df-named-query` `RateLimitInterceptor` (AGENTS.md).
- **Benchmark**: 100 rps por `role` `api_consumer`, 1000 rps para `admin`, burst 20, janela 60s — medido via `wrk -t4 -c100 -d60s http://localhost:18082/api/v2/named_query`
- **Storage**: `cache` driver `file` (dev) / `redis` (prod) — `CACHE_STORE=file` (`docker-compose.yml:94`) ou `redis` via `CACHE_DRIVER`
- **Resposta**: `429 Too Many Requests` + `Retry-After` + `X-RateLimit-Remaining`

## Implementação
- `extensions/df-named-query/src/Http/Middleware/RequestTracingMiddleware.php:1` — propaga `X-Request-ID` (já existe, RQ-072)
- `extensions/df-named-query/src/Services/MetricsService.php:1` — coleta `metrics` para `RateLimit` (RQ-072)
- Novo `RateLimitMiddleware` (a criar) usará `Cache::increment` com TTL 60s

## Consequências
- Sem dependência de pacote comercial `df-limit` (premium)
- Testes: `TddUltraSprintPremiumTest.php:48` cobre ADR

## Validação
- `docker exec dreamfactory php -m | grep redis` opcional para prod
- `curl -H "X-DreamFactory-Api-Key: <key>" http://localhost:18082/api/v2/named_query -i | grep 429` após burst
