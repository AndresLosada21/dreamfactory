<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Yamaha\DreamFactory\NamedQuery\Http\HealthCheckService as HttpHealthCheckService;
use Yamaha\DreamFactory\NamedQuery\Services\ClusterInvalidationService;
use Yamaha\DreamFactory\NamedQuery\Services\SecretRotationService;
use Yamaha\DreamFactory\NamedQuery\Services\SgaClient;
use Yamaha\DreamFactory\NamedQuery\Services\SgcConnectionClient;

/**
 * RQ-071 - Alias em Services para compatibilidade com src/Services/HealthCheckService.php
 * Delega para Http\HealthCheckService mantendo liveness sem DB e readiness com DB + SGA 172.31.16.89/SGA + SGC 172.31.16.89/SGC
 * Usa ClusterInvalidationService nq:cache_generation e SecretRotationService
 */
class HealthCheckService extends HttpHealthCheckService
{
    public const SGA_ENDPOINT = HttpHealthCheckService::SGA_ENDPOINT;
    public const SGC_ENDPOINT = HttpHealthCheckService::SGC_ENDPOINT;

    public function __construct(?float $startedAt = null, ?SgaClient $sga = null, ?SgcConnectionClient $sgc = null, ?ClusterInvalidationService $invalidation = null, ?SecretRotationService $secrets = null)
    {
        parent::__construct($startedAt, $sga, $sgc, $invalidation, $secrets);
    }
}
