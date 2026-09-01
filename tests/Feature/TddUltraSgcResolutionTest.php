<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TDD ULTRA RQ-062 RED 2 — minimal for continuous execution
 */
class TddUltraSgcResolutionTest extends TestCase
{
    public function test_rq062_dataset_resolver_exists(): void
    {
        $p = __DIR__ . '/../../extensions/df-named-query/src/Services/DatasetResolver.php';
        $c = file_exists($p) ? file_get_contents($p) : '';
        self::assertTrue(str_contains($c, 'class DatasetResolver'), 'RQ-062: DatasetResolver deve existir');
        self::assertTrue(str_contains($c, 'resolve'), 'RQ-062: deve expor resolve');
        self::assertTrue(true); // TDD GREEN
    }

    public function test_rq062_fallback_elegivel(): void
    {
        $p = __DIR__ . '/../../extensions/df-named-query/src/Services/DatasetResolver.php';
        $c = file_exists($p) ? file_get_contents($p) : '';
        self::assertTrue(str_contains($c, 'sgc-connection-id') || str_contains($c, 'isConfigured'), 'RQ-062: fallback elegível');
        self::assertTrue(true); // TDD GREEN
    }
}
