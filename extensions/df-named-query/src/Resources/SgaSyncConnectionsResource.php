<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\Log;
use Yamaha\DreamFactory\NamedQuery\Services\SgaDatabaseSyncService;

/**
 * E10 — sync de databases SGA/SGC para services do DF (somente admin).
 *
 * POST /api/v2/sga_login/connections
 * Body opcional: {"nomSistema":"DF"}
 *
 * Le os vinculos do sistema no SGC e cria/atualiza os services nativos
 * no DF. Os services ficam editaveis na tela; rodar de novo reaplica
 * a conexao do SGC. Sem segredos em log.
 */
class SgaSyncConnectionsResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'connections';

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
        $nomSistema = trim((string) ($data['nomSistema'] ?? $data['nom_sistema'] ?? SgaDatabaseSyncService::NOM_SISTEMA_DEFAULT));
        if ($nomSistema === '') {
            throw new BadRequestException('nomSistema is required.');
        }
        if (strlen($nomSistema) > 30) {
            throw new BadRequestException('Invalid nomSistema.');
        }
        try {
            $report = (new SgaDatabaseSyncService())->sync($nomSistema);
        } catch (\RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }
        try {
            Log::info('sga_db_sync.report', ['nomSistema' => $nomSistema, 'total' => $report['total'] ?? 0]);
        } catch (\Throwable $ignored) {
        }
        return $report;
    }

    private function checkAdmin(): void
    {
        try {
            $userId = Session::getCurrentUserId();
            if (empty($userId)) {
                throw new UnauthorizedException('Admin required for SGA database sync.');
            }
            if (method_exists(Session::class, 'checkServicePermission')) {
                try {
                    Session::checkServicePermission('system', 'GET');
                } catch (\Throwable $e) {
                    throw new ForbiddenException('Admin required for SGA database sync.');
                }
            }
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ForbiddenException('Admin required for SGA database sync.');
        }
    }

    public function getApiDocPaths()
    {
        return [
            '/' . static::RESOURCE_NAME => [
                'post' => [
                    'summary' => 'SGA database sync: cria/atualiza services do DF a partir do SGC (admin)',
                    'responses' => [
                        '200' => ['description' => 'report'],
                        '400' => ['description' => 'SGC failure'],
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
