<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\Log;
use Yamaha\DreamFactory\NamedQuery\Services\SgaUserSyncService;

/**
 * Wave 1 — sync de usuarios/perfis SGA para leitura no DF (somente admin).
 *
 * POST /api/v2/sga_login/users
 * Body opcional: {"nomSistema":"DF"}
 *
 * Espelha contas (sem senha) e desativa quem perdeu perfil no SGA.
 * Sem segredos em log.
 */
class SgaSyncUsersResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'users';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handlePOST()
    {
        $this->checkAdmin();
        $data = $this->getPayloadData();
        if (!is_array($data)) {
            $data = [];
        }
        $nomSistema = trim((string) ($data['nomSistema'] ?? $data['nom_sistema'] ?? SgaUserSyncService::NOM_SISTEMA_DEFAULT));
        if ($nomSistema === '') {
            throw new BadRequestException('nomSistema is required.');
        }
        if (strlen($nomSistema) > 30) {
            throw new BadRequestException('Invalid nomSistema.');
        }
        try {
            $report = (new SgaUserSyncService())->sync($nomSistema);
        } catch (\RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }
        try {
            Log::info('sga_admin_sync.report', ['section' => 'users', 'nomSistema' => $nomSistema, 'total' => $report['total'] ?? 0]);
        } catch (\Throwable $ignored) {
        }
        return $report;
    }

    private function checkAdmin(): void
    {
        try {
            if (method_exists(Session::class, 'isSysAdmin')) {
                if (!Session::isSysAdmin()) {
                    throw new ForbiddenException('Admin required for SGA users sync.');
                }
                return;
            }
            $userId = Session::getCurrentUserId();
            if (empty($userId)) {
                throw new UnauthorizedException('Admin required for SGA users sync.');
            }
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ForbiddenException('Admin required for SGA users sync.');
        }
    }

    public function getApiDocPaths()
    {
        return [
            '/' . static::RESOURCE_NAME => [
                'post' => [
                    'summary' => 'SGA users sync: espelha usuarios/perfis no DF (admin)',
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
