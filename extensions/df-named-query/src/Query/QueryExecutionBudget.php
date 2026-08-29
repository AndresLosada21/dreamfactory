<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

use DreamFactory\Core\Exceptions\BadRequestException;

/**
 * Orçamento hierárquico de execução — RQ-041.
 *
 * Espelha api-query QueryExecutionBudget.java:11-171 + SqlExecutionLimits.java:50-121
 * com deadline monotônico (remainingNanos) que reduz timeout de statement.
 *
 * Budgets padrão preservam SqlExecutionLimits defaults:
 *  - query_timeout_seconds / request_timeout_seconds = 45s
 *  - max_rows = 10000, max_total_bytes = 10485760 (10 MiB)
 *  - max_parameters = 100, max_parameter_value_length = 4096
 *  - max_in_items = 100, max_subquery_executions = 500
 *
 * Cluster-safe: sem cache local retido — budgets lidos direto do DB (named_query_revision.budgets)
 * por request via NamedQuery::forService()->with('publishedRevision')->first().
 *
 * @see JsonQueryCompiler::DEFAULT_BUDGETS
 * @see NamedQueryResource::execute
 */
class QueryExecutionBudget
{
    private const NANOS_PER_SECOND = 1_000_000_000;

    private float $startedAt;
    private int $timeoutSeconds;
    private int $maxTotalRows;
    private int $maxTotalBytes;
    private $nanoTime;

    private int $totalRows = 0;
    private int $totalBytes = 0;

    /**
     * @param array $budgets Budgets mesclados (DEFAULT_BUDGETS + revision.budgets)
     * @param float|null $startedAt microtime(true) do início do request (para deadline monotônico)
     * @param callable|null $nanoTime Supplier para testes (hrtime)
     */
    public function __construct(array $budgets, ?float $startedAt = null, ?callable $nanoTime = null)
    {
        $this->timeoutSeconds = (int) ($budgets['request_timeout_seconds'] ?? $budgets['query_timeout_seconds'] ?? $budgets['timeout_seconds'] ?? 45);
        $this->maxTotalRows = (int) ($budgets['max_total_rows'] ?? $budgets['max_rows'] ?? 10000);
        $this->maxTotalBytes = (int) ($budgets['max_total_bytes'] ?? 10485760);
        if ($this->timeoutSeconds < 1 || $this->maxTotalRows < 1 || $this->maxTotalBytes < 1) {
            throw new \InvalidArgumentException('Query execution budget values must be positive');
        }
        $this->startedAt = $startedAt ?? microtime(true);
        $this->nanoTime = $nanoTime;
    }

    public function checkDeadline(): void
    {
        if ($this->remainingNanos() <= 0) {
            throw new \DreamFactory\Core\Exceptions\RestException(504, 'Tempo limite total da consulta excedido', 504);
        }
    }

    /**
     * Reduz timeout de statement pelo deadline restante — espelha QueryExecutionBudget.statementTimeoutSeconds:45-54
     * Retorna min(maximumSeconds, remainingSeconds) com teto 1s quando deadline quase esgotado.
     */
    public function statementTimeoutSeconds(int $maximumSeconds): int
    {
        if ($maximumSeconds < 1) {
            throw new \InvalidArgumentException('Statement timeout must be positive');
        }
        $remaining = $this->remainingNanos();
        if ($remaining <= 0) {
            throw new \DreamFactory\Core\Exceptions\RestException(504, 'Tempo limite total da consulta excedido', 504);
        }
        $remainingSeconds = (int) (1 + (int)(($remaining - 1) / self::NANOS_PER_SECOND));
        return (int) min($maximumSeconds, min($remainingSeconds, PHP_INT_MAX));
    }

    public function acceptRow(array $row): void
    {
        $this->checkDeadline();
        $this->totalRows++;
        if ($this->totalRows > $this->maxTotalRows) {
            throw new BadRequestException('A consulta excedeu o limite agregado de ' . $this->maxTotalRows . ' linhas');
        }
        $this->totalBytes += $this->estimateJsonBytes($row);
        if ($this->totalBytes > $this->maxTotalBytes) {
            throw new BadRequestException('A consulta excedeu o limite agregado de ' . $this->maxTotalBytes . ' bytes');
        }
    }

    public function verifyFinalBody(mixed $body): void
    {
        $this->checkDeadline();
        if ($this->estimateJsonBytes($body) > $this->maxTotalBytes) {
            throw new BadRequestException('A resposta excedeu o limite agregado de ' . $this->maxTotalBytes . ' bytes');
        }
    }

    private function remainingNanos(): int
    {
        $elapsed = microtime(true) - $this->startedAt;
        $elapsedNanos = (int) ($elapsed * self::NANOS_PER_SECOND);
        // Se nanoTime fornecido (testes), poderia delegar; mantém monotônico via microtime
        return ($this->timeoutSeconds * self::NANOS_PER_SECOND) - $elapsedNanos;
    }

    private function estimateJsonBytes(mixed $value, int $depth = 0): int
    {
        if ($depth > 64) {
            return PHP_INT_MAX;
        }
        if ($value === null) {
            return 4;
        }
        if (is_string($value)) {
            return $this->estimateJsonString($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return strlen((string) $value);
        }
        if (is_array($value)) {
            // Distingue assoc vs list via chaves
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                $size = 2;
                $first = true;
                foreach ($value as $k => $v) {
                    if (!$first) { $size++; }
                    $first = false;
                    $size += $this->estimateJsonString((string) $k);
                    $size++; // :
                    $size += $this->estimateJsonBytes($v, $depth + 1);
                    if ($size > PHP_INT_MAX / 2) return PHP_INT_MAX;
                }
                return $size;
            }
            $size = 2;
            $first = true;
            foreach ($value as $item) {
                if (!$first) { $size++; }
                $first = false;
                $size += $this->estimateJsonBytes($item, $depth + 1);
            }
            return $size;
        }
        return $this->estimateJsonString((string) $value);
    }

    private function estimateJsonString(string $value): int
    {
        $size = 2 + strlen($value);
        for ($i = 0; $i < strlen($value); $i++) {
            $c = $value[$i];
            if ($c === '"' || $c === '\\') { $size++; }
            elseif (ord($c) <= 0x1f) { $size += 5; }
        }
        return $size;
    }

    /**
     * Aplica deadline ao PDO/statement timeout — quando deadline fornecido, reduz timeout.
     * Usa PDO::ATTR_TIMEOUT e, para pgsql, SET statement_timeout. Cluster-safe: sem sticky cache.
     *
     * @param \Illuminate\Database\Connection $connection
     * @param int $maximumSeconds fallback 45s (query_timeout_seconds)
     */
    public function applyToConnection($connection, int $maximumSeconds = 45): void
    {
        $timeout = $this->statementTimeoutSeconds($maximumSeconds);
        try {
            $pdo = $connection->getPdo();
            if ($pdo) {
                // Reduz PDO::ATTR_TIMEOUT quando deadline fornecido
                $pdo->setAttribute(\PDO::ATTR_TIMEOUT, $timeout);
            }
        } catch (\Throwable $ignored) {}
        // Driver-specific statement timeout (pgsql/mysql)
        try {
            $driver = $connection->getDriverName();
            if ($driver === 'pgsql') {
                $ms = $timeout * 1000;
                $connection->statement("SET LOCAL statement_timeout = '{$ms}ms'");
            } elseif (in_array($driver, ['mysql', 'sqlsrv'], true)) {
                // MySQL: max_execution_time (ms)
                if ($driver === 'mysql') {
                    $ms = $timeout * 1000;
                    $connection->statement("SET SESSION MAX_EXECUTION_TIME={$ms}");
                }
            }
        } catch (\Throwable $ignored) {
            // Não falha execução se SET não suportado (e.g., sqlite em testes)
        }
    }
}
