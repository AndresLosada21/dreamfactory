<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;
use Yamaha\DreamFactory\NamedQuery\Http\HealthCheckService;

/**
 * RQ-071 — Health resource for liveness, readiness and detailed checks
 * Routes: /health (legacy = liveness), /health/ready, /health/detailed
 */
class HealthResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'health';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handleGET()
    {
        $service = new HealthCheckService();

        // resource empty => /health legacy => liveness
        if (empty($this->resource) || $this->resource === 'health') {
            $payload = $service->liveness();
            $this->setNoCacheHeaders();
            return $payload;
        }

        if ($this->resource === 'ready' || $this->resource === 'readiness') {
            $payload = $service->readiness();
            $this->setNoCacheHeaders();
            $status = ($payload['status'] ?? 'ok') === 'ok' ? 200 : 503;
            // DreamFactory will map return array to 200; we set status via header hint
            // For real 503, throw RestException or set response code via ServiceResponse
            if ($status === 503) {
                // Use RestException to get 503, but include payload
                throw new \DreamFactory\Core\Exceptions\RestException(503, json_encode($payload), 503);
            }
            return $payload;
        }

        if ($this->resource === 'detailed') {
            // Detailed requires admin
            $this->checkAdmin();
            $payload = $service->detailed();
            $this->setNoCacheHeaders();
            $status = ($payload['status'] ?? 'ok') === 'ok' ? 200 : 503;
            if ($status === 503) {
                throw new \DreamFactory\Core\Exceptions\RestException(503, json_encode($payload), 503);
            }
            return $payload;
        }

        throw new \DreamFactory\Core\Exceptions\NotFoundException("Health check '{$this->resource}' not found.");
    }

    private function checkAdmin(): void
    {
        // Require admin role — via Session::checkServicePermission or getCurrentUserId + isAdmin
        try {
            $userId = Session::getCurrentUserId();
            if (empty($userId)) {
                throw new \DreamFactory\Core\Exceptions\UnauthorizedException('Admin required for detailed health');
            }
            // Try RBAC check
            if (method_exists(Session::class, 'checkServicePermission')) {
                // Detailed health is system-level, require GET on system
                // If check fails, it throws
                try {
                    Session::checkServicePermission('system', 'GET');
                } catch (\Throwable $e) {
                    throw new \DreamFactory\Core\Exceptions\ForbiddenException('Admin required for detailed health');
                }
            }
        } catch (\DreamFactory\Core\Exceptions\UnauthorizedException | \DreamFactory\Core\Exceptions\ForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \DreamFactory\Core\Exceptions\ForbiddenException('Admin required for detailed health');
        }
    }

    private function setNoCacheHeaders(): void
    {
        try {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Health-Status: ok');
        } catch (\Throwable $ignored) {}
    }

    public function getApiDocPaths()
    {
        return [
            '/' . static::RESOURCE_NAME => [
                'get' => [
                    'summary' => 'Liveness probe (legacy /health)',
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/' . static::RESOURCE_NAME . '/ready' => [
                'get' => [
                    'summary' => 'Readiness probe',
                    'responses' => ['200' => ['description' => 'ok'], '503' => ['description' => 'degraded']],
                ],
            ],
            '/' . static::RESOURCE_NAME . '/detailed' => [
                'get' => [
                    'summary' => 'Detailed health (admin only)',
                    'responses' => ['200' => ['description' => 'ok'], '403' => ['description' => 'forbidden'], '503' => ['description' => 'degraded']],
                ],
            ],
        ];
    }

    public function getEventMap()
    {
        return [];
    }
}
