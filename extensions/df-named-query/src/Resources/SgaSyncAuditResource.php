<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\Log;
use Yamaha\DreamFactory\NamedQuery\Services\SgaAuditSyncService;

/**
 * Wave 2 — trilha de auditoria SGA em leitura (somente admin).
 *
 * POST /api/v2/sga_login/audit
 * Body opcional: {"nomSistema":"DF","idSistema":1215570,"datStart":"YYYY-MM-DD","datEnd":"YYYY-MM-DD"}
 *
 * Leitura read-only no MySQL do SGA com credenciais vivas do SGC
 * (nunca persistidas). Sem segredos em log.
 */
class SgaSyncAuditResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'audit';

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
        $nomSistema = trim((string) ($data['nomSistema'] ?? $data['nom_sistema'] ?? SgaAuditSyncService::NOM_SISTEMA_DEFAULT));
        $idSistema = (int) ($data['idSistema'] ?? $data['id_sistema'] ?? SgaAuditSyncService::ID_SISTEMA_DF);
        $datStart = trim((string) ($data['datStart'] ?? $data['dat_start'] ?? ''));
        $datEnd = trim((string) ($data['datEnd'] ?? $data['dat_end'] ?? ''));
        if ($nomSistema === '' || strlen($nomSistema) > 30) {
            throw new BadRequestException('Invalid nomSistema.');
        }
        if ($idSistema < 1) {
            throw new BadRequestException('Invalid idSistema.');
        }
        foreach (['datStart' => $datStart, 'datEnd' => $datEnd] as $k => $v) {
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                throw new BadRequestException("Invalid $k, use YYYY-MM-DD.");
            }
        }
        try {
            $report = (new SgaAuditSyncService())->sync($nomSistema, $idSistema, $datStart, $datEnd);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw new BadRequestException($e->getMessage());
        }
        try {
            Log::info('sga_admin_sync.report', ['section' => 'audit', 'nomSistema' => $nomSistema, 'total' => $report['total'] ?? 0]);
        } catch (\Throwable $ignored) {
        }
        return $report;
    }

    private function checkAdmin(): void
    {
        try {
            if (method_exists(Session::class, 'isSysAdmin')) {
                if (!Session::isSysAdmin()) {
                    throw new ForbiddenException('Admin required for SGA audit sync.');
                }
                return;
            }
            $userId = Session::getCurrentUserId();
            if (empty($userId)) {
                throw new UnauthorizedException('Admin required for SGA audit sync.');
            }
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ForbiddenException('Admin required for SGA audit sync.');
        }
    }

    public function getApiDocPaths()
    {
        return [
            '/' . static::RESOURCE_NAME => [
                'post' => [
                    'summary' => 'SGA audit: trilha de acessos em leitura (admin)',
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
