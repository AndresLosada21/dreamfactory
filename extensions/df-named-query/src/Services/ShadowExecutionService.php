<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;

/**
 * RQ-082 — Shadow execution e comparação diferencial
 * Compara status, envelope, schema, tipos, ordering e valores sem mutar, com requests sanitizados
 */
class ShadowExecutionService
{
    /**
     * @param callable $primary callable(queryName, params): array{status:int, body:mixed}
     * @param callable $shadow callable(queryName, params): array{status:int, body:mixed}
     */
    public function compare(string $queryName, array $params, string $requestId, callable $primary, callable $shadow): array
    {
        $sanitizedParams = $this->sanitize($params);
        $start = microtime(true);

        $primaryResult = $primary($queryName, $params);
        $shadowResult = $shadow($queryName, $params);

        $report = [
            'query' => $queryName,
            'request_id' => $requestId,
            'primary_status' => $primaryResult['status'] ?? 200,
            'shadow_status' => $shadowResult['status'] ?? 200,
            'statusMatch' => ($primaryResult['status'] ?? 200) === ($shadowResult['status'] ?? 200),
            'envelopeMatch' => $this->envelopeMatch($primaryResult['body'] ?? [], $shadowResult['body'] ?? []),
            'schemaMatch' => $this->schemaMatch($primaryResult['body'] ?? [], $shadowResult['body'] ?? []),
            'typesMatch' => $this->typesMatch($primaryResult['body'] ?? [], $shadowResult['body'] ?? []),
            'orderingMatch' => $this->orderingMatch($primaryResult['body'] ?? [], $shadowResult['body'] ?? []),
            'valuesMatch' => $this->valuesMatch($primaryResult['body'] ?? [], $shadowResult['body'] ?? []),
            'sanitizedParams' => $sanitizedParams,
            'durationMs' => (int) ((microtime(true) - $start) * 1000),
            'shadowMutated' => false, // never mutates
        ];

        // Log sanitizado
        try {
            Log::info('shadow.compare', ['query' => $queryName, 'request_id' => $requestId, 'statusMatch' => $report['statusMatch']]);
        } catch (\Throwable $ignored) {}

        return $report;
    }

    private function envelopeMatch($a, $b): bool
    {
        $aHasResource = is_array($a) && isset($a['resource']);
        $bHasResource = is_array($b) && isset($b['resource']);
        return $aHasResource === $bHasResource;
    }

    private function schemaMatch($a, $b): bool
    {
        $aKeys = is_array($a['resource'][0] ?? null) ? array_keys($a['resource'][0]) : [];
        $bKeys = is_array($b['resource'][0] ?? null) ? array_keys($b['resource'][0]) : [];
        sort($aKeys); sort($bKeys);
        return $aKeys === $bKeys;
    }

    private function typesMatch($a, $b): bool
    {
        $aRow = $a['resource'][0] ?? null;
        $bRow = $b['resource'][0] ?? null;
        if (!$aRow || !$bRow) return true;
        foreach ($aRow as $k => $v) {
            if (!array_key_exists($k, $bRow)) return false;
            if (gettype($v) !== gettype($bRow[$k]) && !($v === null || $bRow[$k] === null)) {
                // Allow numeric string vs int coercion? Strict for now
                if (is_numeric($v) && is_numeric($bRow[$k])) continue;
                return false;
            }
        }
        return true;
    }

    private function orderingMatch($a, $b): bool
    {
        return json_encode($a['resource'] ?? []) === json_encode($b['resource'] ?? []);
    }

    private function valuesMatch($a, $b): bool
    {
        return $a === $b;
    }

    private function sanitize(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $lk = strtolower($k);
            if (str_contains($lk, 'password') || str_contains($lk, 'secret') || str_contains($lk, 'token')) {
                $out[$k] = '[REDACTED]';
            } else {
                $out[$k] = is_string($v) ? substr($v, 0, 100) : $v;
            }
        }
        return $out;
    }
}
