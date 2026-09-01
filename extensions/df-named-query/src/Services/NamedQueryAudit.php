<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use DreamFactory\Core\Events\ServiceModifiedEvent;
use DreamFactory\Core\Utility\Session;

/**
 * RQ-014 — Auditoria do ciclo de vida de Named Query.
 *
 * Registra ator, revisao, fonte, request ID, duracao e outcome sem vazar
 * SQL/bind/secret (usa apenas checksum/budgets). Invalida caches relacionados.
 *
 * Integracao: Repository (create/revise/publish/disable/delete) e Resource execute
 * (success/failure). Usa mecanismos nativos DF (Log::info, Cache::tags).
 */
class NamedQueryAudit
{
    /**
     * Registra um evento de auditoria sanitizado.
     *
     * @param string $action create|revise|publish|unpublish|disable|delete|execute|execute_failure
     * @param array $ctx actor_id, service_id, query_id, query_name, revision, revision_id, checksum, budgets, duration_ms, outcome, error_code, source
     */
    public static function record(string $action, array $ctx = []): void
    {
        $outcome = $ctx['outcome'] ?? 'success';
        $requestId = $ctx['request_id'] ?? self::requestId();
        $actorId = $ctx['actor_id'] ?? Session::getCurrentUserId();
        $durationMs = $ctx['duration_ms'] ?? null;

        $payload = [
            'audit' => 'named_query',
            'action' => $action,
            'actor_id' => $actorId !== null ? (int) $actorId : null,
            'service_id' => isset($ctx['service_id']) ? (int) $ctx['service_id'] : null,
            'query_id' => isset($ctx['query_id']) ? (int) $ctx['query_id'] : null,
            'query_name' => $ctx['query_name'] ?? null,
            'revision' => isset($ctx['revision']) ? (int) $ctx['revision'] : null,
            'revision_id' => isset($ctx['revision_id']) ? (int) $ctx['revision_id'] : null,
            'checksum' => $ctx['checksum'] ?? null,
            'budgets' => self::sanitizeBudgets($ctx['budgets'] ?? null),
            'request_id' => $requestId,
            'duration_ms' => $durationMs !== null ? (int) $durationMs : null,
            'outcome' => $outcome,
            'error_code' => $ctx['error_code'] ?? null,
            'source' => $ctx['source'] ?? 'api',
        ];

        // Remove nulls for cleaner log; keep actor_id null explicit? filter null values but keep 0
        $filtered = array_filter($payload, static function ($v) {
            return $v !== null && $v !== '';
        });

        try {
            Log::info('named_query.audit', $filtered);
        } catch (\Throwable $e) {
            // fallback: error_log sanitized
            error_log('[named_query.audit] ' . json_encode($filtered, JSON_UNESCAPED_SLASHES));
        }

        if (in_array($action, ['create', 'revise', 'publish', 'unpublish', 'disable', 'delete'], true)) {
            self::invalidate($payload['service_id'], $payload['query_name']);
        }
    }

    public static function requestId(): string
    {
        try {
            $req = request();
            if ($req) {
                foreach (['X-Request-ID', 'X-REQUEST-ID', 'x-request-id'] as $header) {
                    $id = $req->header($header);
                    if (!empty($id)) {
                        return (string) $id;
                    }
                }
                // Also via Symfony header bag case-insensitive
                $id = $req->headers->get('X-Request-ID');
                if (!empty($id)) {
                    return (string) $id;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Fallback via $_SERVER
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string) $_SERVER['HTTP_X_REQUEST_ID'];
        }

        try {
            return (string) Str::uuid();
        } catch (\Throwable $e) {
            return uniqid('req_', true);
        }
    }

    /**
     * Sanitiza budgets para nao vazar segredos; mantem apenas max_rows int.
     */
    public static function sanitizeBudgets($budgets): ?array
    {
        if (!is_array($budgets)) {
            return null;
        }
        $out = [];
        if (isset($budgets['max_rows'])) {
            $out['max_rows'] = (int) $budgets['max_rows'];
        }
        // Allow timeout if present as int (non-secret)
        if (isset($budgets['timeout_ms']) && is_numeric($budgets['timeout_ms'])) {
            $out['timeout_ms'] = (int) $budgets['timeout_ms'];
        }

        return empty($out) ? null : $out;
    }

    /**
     * Invalida caches relacionados: tenta tags named_query e service_{id},
     * fallback para forget de chaves conhecidas.
     */
    public static function invalidate(?int $serviceId, ?string $queryName = null): void
    {
        // Tenta Cache::tags (redis/memcached)
        try {
            if (method_exists(Cache::class, 'tags')) {
                // tags may throw if driver does not support
                try {
                    Cache::tags(['named_query'])->flush();
                } catch (\Throwable $e) {
                    // driver without tags support
                }
                if ($serviceId !== null) {
                    try {
                        Cache::tags(['named_query', 'service_' . $serviceId])->flush();
                    } catch (\Throwable $e) {
                    }
                    try {
                        Cache::tags(['service_' . $serviceId])->flush();
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        // RQ-070 cluster-safe: event bus garante convergência em todos os nós sem sticky, SLA <5s via ShouldDispatchAfterCommit + Cache::tags flush
        if ($serviceId !== null) {
            try {
                event(new ServiceModifiedEvent(['service_id' => $serviceId]));
            } catch (\Throwable $e) {
            }
        }

        // Fallback: forget chaves prefixadas conhecidas
        $prefixes = [];
        if ($serviceId !== null) {
            $prefixes[] = 'service_' . $serviceId . ':named_query';
            $prefixes[] = 'service_' . $serviceId . ':_query';
            $prefixes[] = 'named_query:service_' . $serviceId;
        }
        $prefixes[] = 'named_query';

        foreach ($prefixes as $prefix) {
            try {
                // Se a key for exata:
                Cache::forget($prefix);
            } catch (\Throwable $e) {
            }
            // Tenta esquecer keys via Cacheable stored keys? Nao ha enumeracao confiavel em file driver.
        }

        // Tambem invalida service cache via ServiceCacheable prefix (host/port/db etc) nao previsivel aqui;
        // o flush via tags acima cobre o caso de driver com tags; para file driver, o proximo request
        // ira miss devido a lock_version bump e published_revision_id mudança (leitura DB direta, nao cacheada).
        // Mantemos Log de invalidacao sem SQL.
        try {
            Log::debug('named_query.cache.invalidate', array_filter([
                'service_id' => $serviceId,
                'query_name' => $queryName,
                'tag' => 'named_query',
            ]));
        } catch (\Throwable $e) {
        }
    }

    /**
     * Helper para medir duracao e registrar com outcome automatico.
     *
     * @param string $action
     * @param array $ctx
     * @param float $start microtime(true)
     * @param string $outcome
     * @param string|null $errorCode
     */
    public static function recordWithDuration(string $action, array $ctx, float $start, string $outcome = 'success', ?string $errorCode = null): void
    {
        $ctx['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        $ctx['outcome'] = $outcome;
        if ($errorCode !== null) {
            $ctx['error_code'] = $errorCode;
        }
        self::record($action, $ctx);
    }
}
