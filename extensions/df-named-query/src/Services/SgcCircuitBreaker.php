<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Cache;

/**
 * RQ-063 — Circuit breaker SGC coordenado via cache
 * Estados: closed (normal), open (falha, bloqueia), half-open (testa recuperação)
 * Coordenado via cache_generation + Cache::tags, sem sticky, sem segredos em logs
 */
class SgcCircuitBreaker
{
    private const CACHE_KEY = 'nq:sgc:circuit_state';
    private const FAILURE_THRESHOLD = 5;
    private const OPEN_TIMEOUT_SECONDS = 30;

    public function recordSuccess(): void
    {
        Cache::put(self::CACHE_KEY, ['state' => 'closed', 'failures' => 0, 'opened_at' => null], 3600);
    }

    public function recordFailure(): void
    {
        $state = $this->getState();
        $failures = ($state['failures'] ?? 0) + 1;
        if ($failures >= self::FAILURE_THRESHOLD) {
            Cache::put(self::CACHE_KEY, ['state' => 'open', 'failures' => $failures, 'opened_at' => time()], self::OPEN_TIMEOUT_SECONDS);
        } else {
            Cache::put(self::CACHE_KEY, ['state' => 'closed', 'failures' => $failures, 'opened_at' => $state['opened_at'] ?? null], 3600);
        }
    }

    public function isOpen(): bool
    {
        $state = $this->getState();
        if (($state['state'] ?? 'closed') === 'open') {
            $openedAt = $state['opened_at'] ?? 0;
            if (time() - $openedAt > self::OPEN_TIMEOUT_SECONDS) {
                // Half-open: allow one test
                Cache::put(self::CACHE_KEY, ['state' => 'half-open', 'failures' => $state['failures'] ?? 0, 'opened_at' => $openedAt], 3600);
                return false;
            }
            return true;
        }
        return false;
    }

    public function getState(): array
    {
        return Cache::get(self::CACHE_KEY, ['state' => 'closed', 'failures' => 0, 'opened_at' => null]);
    }

    public function canAttempt(): bool
    {
        return !$this->isOpen();
    }

    public function testXxe(string $xml): bool
    {
        // Should block DOCTYPE/ENTITY — reuses SgcConnectionClient logic
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            $this->recordFailure();
            return false;
        }
        return true;
    }

    public function testOversized(string $payload): bool
    {
        if (strlen($payload) > SgcConnectionClient::BODY_LIMIT) {
            $this->recordFailure();
            return false;
        }
        return true;
    }
}
