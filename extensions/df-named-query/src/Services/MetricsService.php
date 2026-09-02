<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;

/**
 * RQ-072 — Serviço de métricas para Named Queries
 * Mede latency, rows, bytes, rejects e pools com cardinalidade controlada.
 */
class MetricsService
{
    private const MAX_SERIES = 1000;
    private const MAX_QUERY_NAME_LEN = 64;

    /** @var array<string, array> series key => metrics */
    private array $series = [];
    private array $order = []; // LRU order

    private array $rejectCounters = [];
    private array $pools = [];

    /**
     * @param array $ctx service_id, query_name, latencyMs, rows, bytes, outcome, rejectReason, pool, request_id
     * RQ-072 measures durationMs via microtime latencyMs
     */
    public function recordExecution(array $ctx): void
    {
        try {
            $start = microtime(true);
            $serviceId = isset($ctx['service_id']) ? (int) $ctx['service_id'] : 0;
            $queryName = self::sanitizeQueryName($ctx['query_name'] ?? 'unknown');
            $latencyMs = isset($ctx['latencyMs']) ? (int) $ctx['latencyMs'] : (isset($ctx['durationMs']) ? (int) $ctx['durationMs'] : (isset($ctx['duration_ms']) ? (int) $ctx['duration_ms'] : (int) ((microtime(true) - $start) * 1000)));
            $rows = isset($ctx['rows']) ? (int) $ctx['rows'] : 0;
            $bytes = isset($ctx['bytes']) ? (int) $ctx['bytes'] : 0;
            $outcome = self::sanitizeOutcome($ctx['outcome'] ?? 'success');
            $rejectReason = isset($ctx['rejectReason']) ? self::sanitizeRejectReason($ctx['rejectReason']) : null;
            $requestId = $ctx['request_id'] ?? null;

            $key = $serviceId . '|' . $queryName . '|' . $outcome;
            $this->touchSeries($key);

            if (!isset($this->series[$key])) {
                $this->series[$key] = [
                    'service_id' => $serviceId,
                    'query_name' => $queryName,
                    'outcome' => $outcome,
                    'count' => 0,
                    'latencyMs_sum' => 0,
                    'latencyMs_min' => PHP_INT_MAX,
                    'latencyMs_max' => 0,
                    'rows_sum' => 0,
                    'bytes_sum' => 0,
                    'rejects' => 0,
                ];
            }
            $s = &$this->series[$key];
            $s['count']++;
            $s['latencyMs_sum'] += $latencyMs;
            $s['latencyMs_min'] = min($s['latencyMs_min'], $latencyMs);
            $s['latencyMs_max'] = max($s['latencyMs_max'], $latencyMs);
            $s['rows_sum'] += $rows;
            $s['bytes_sum'] += $bytes;
            if ($rejectReason) {
                $s['rejects']++;
                $this->incrementReject($rejectReason);
            }

            // Log sanitizado — sem SQL/binds/secret
            $payload = [
                'metric' => 'named_query.execution',
                'service_id' => $serviceId,
                'query_name' => $queryName,
                'outcome' => $outcome,
                'latencyMs' => $latencyMs,
                'rows' => $rows,
                'bytes' => $bytes,
                'request_id' => $requestId,
            ];
            if ($rejectReason) {
                $payload['reject_reason'] = $rejectReason;
            }
            // redact via StructuredLogService if available, else manual
            $payload = $this->sanitizePayload($payload);
            Log::info('named_query.metrics', $payload);
        } catch (\Throwable $e) {
            // Métrica nunca quebra execução
            try {
                error_log('[named_query.metrics] ' . $e->getMessage());
            } catch (\Throwable $ignored) {}
        }
    }

    public function incrementReject(string $reason): void
    {
        $reason = self::sanitizeRejectReason($reason);
        $this->rejectCounters[$reason] = ($this->rejectCounters[$reason] ?? 0) + 1;
        try {
            Log::info('named_query.metrics', $this->sanitizePayload([
                'metric' => 'named_query.reject',
                'reject_reason' => $reason,
                'count' => $this->rejectCounters[$reason],
            ]));
        } catch (\Throwable $ignored) {}
    }

    public function observePool(string $pool, int $size): void
    {
        $pool = self::sanitizePool($pool);
        $this->pools[$pool] = $size;
        try {
            Log::info('named_query.metrics', $this->sanitizePayload([
                'metric' => 'named_query.pool',
                'pool' => $pool,
                'size' => $size,
            ]));
        } catch (\Throwable $ignored) {}
    }

    public function snapshot(): array
    {
        return [
            'series' => array_values($this->series),
            'series_count' => count($this->series),
            'rejects' => $this->rejectCounters,
            'pools' => $this->pools,
            'cardinality_ok' => count($this->series) <= self::MAX_SERIES,
        ];
    }

    private function touchSeries(string $key): void
    {
        // LRU eviction
        if (isset($this->series[$key])) {
            // move to end
            $idx = array_search($key, $this->order, true);
            if ($idx !== false) {
                array_splice($this->order, $idx, 1);
            }
            $this->order[] = $key;
            return;
        }
        if (count($this->series) >= self::MAX_SERIES) {
            $oldest = array_shift($this->order);
            if ($oldest !== null) {
                unset($this->series[$oldest]);
            }
        }
        $this->order[] = $key;
    }

    public static function sanitizeQueryName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($name));
        if ($name === '' || $name === null) {
            $name = 'unknown';
        }
        return substr($name, 0, self::MAX_QUERY_NAME_LEN);
    }

    public static function sanitizeOutcome(string $outcome): string
    {
        $allowed = ['success', 'failure', 'reject', 'timeout', 'error'];
        return in_array($outcome, $allowed, true) ? $outcome : 'unknown';
    }

    public static function sanitizeRejectReason(string $reason): string
    {
        $reason = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($reason));
        $reason = substr($reason, 0, 32);
        $allowed = ['budget_exceeded', 'timeout', 'max_rows', 'max_bytes', 'max_params', 'rate_limit', 'unauthorized', 'validation', 'unknown'];
        // allow sanitized but enum check fallback
        if (in_array($reason, $allowed, true)) {
            return $reason;
        }
        // map common
        if (str_contains(strtolower($reason), 'budget')) return 'budget_exceeded';
        if (str_contains(strtolower($reason), 'timeout')) return 'timeout';
        return $reason !== '' ? $reason : 'unknown';
    }

    public static function sanitizePool(string $pool): string
    {
        $pool = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($pool));
        return substr($pool, 0, 32) ?: 'unknown';
    }

    private function sanitizePayload(array $payload): array
    {
        // Remove SQL/binds/secret if accidentally present (cardinality + secrecy)
        $forbidden = ['sql', 'binds', 'bindings', 'secret', 'password', 'token', 'authorization', 'credentials'];
        foreach ($forbidden as $k) {
            unset($payload[$k]);
        }
        return $payload;
    }
}
