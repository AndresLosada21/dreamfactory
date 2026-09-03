<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\Log;
use Yamaha\DreamFactory\NamedQuery\Services\SgaJobSyncService;

/**
 * R1 — jobs SGA em leitura (somente admin).
 *
 * POST /api/v2/sga_login/jobs
 *
 * Leitura read-only no MySQL do SGA com credenciais vivas do SGC
 * (nunca persistidas). Sem segredos em log.
 */
class SgaSyncJobsResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'jobs';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handlePOST()
    {
        $this->checkAdmin();
        try {
            $report = (new SgaJobSyncService())->sync();
        } catch (\RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }
        try {
            Log::info('sga_admin_sync.report', ['section' => 'jobs', 'total' => $report['total'] ?? 0]);
        } catch (\Throwable $ignored) {
        }
        return $report;
    }

    private function checkAdmin(): void
    {
        try {
            if (method_exists(Session::class, 'isSysAdmin')) {
                if (!Session::isSysAdmin()) {
                    throw new ForbiddenException('Admin required for SGA jobs sync.');
                }
                return;
            }
            $userId = Session::getCurrentUserId();
            if (empty($userId)) {
                throw new UnauthorizedException('Admin required for SGA jobs sync.');
            }
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ForbiddenException('Admin required for SGA jobs sync.');
        }
    }

    public function getApiDocPaths()
    {
        return [
            '/' . static::RESOURCE_NAME => [
                'post' => [
                    'summary' => 'SGA jobs: batches internos em leitura (admin)',
                    'responses' => [
                        '200' => ['description' => 'report'],
                        '400' => ['description' => 'SGA failure'],
                        '403' => ['description' => 'forbidden'],
                    ],
                ],
            ],
        ];
    }

    public function getEventMap()
    {
        return [];
    }
}
