<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Wave 1 — espelha usuarios/perfis do SGA em leitura no DF.
 *
 * Fonte: WsAcesso.getListaUsuarioBySistema(sglSistema) +
 * getListaPerfilUsuario(codUsuario, sglSistema). Papel mapeado pelo
 * SgaSgcOrchestrator::mapSgaPerfilToDfRole (DF_ADMIN->admin).
 * Sem perfil => conta desativada no DF (gestao continua no SGA).
 * Acessos/objetos (menus) entram no report como needs_attention:
 * nao viram RBAC DF. Senhas nunca sao definidas aqui (login via
 * sga_login/sync espelha a senha sob demanda); segredos nunca em log.
 */
class SgaUserSyncService
{
    public const NOM_SISTEMA_DEFAULT = 'DF';
    public const MAX_USERS = 200;

    private SgaClient $sga;
    private SgaSgcOrchestrator $orchestrator;

    public function __construct(?SgaClient $sga = null, ?SgaSgcOrchestrator $orchestrator = null)
    {
        $this->sga = $sga ?? new SgaClient();
        $this->orchestrator = $orchestrator ?? new SgaSgcOrchestrator();
    }

    /**
     * @return array{nomSistema:string,total:int,created:array,updated:array,deactivated:array,skipped:array,needs_attention:array}
     */
    public function sync(string $nomSistema = self::NOM_SISTEMA_DEFAULT): array
    {
        $nomSistema = trim($nomSistema) ?: self::NOM_SISTEMA_DEFAULT;
        $lista = $this->sga->getListaUsuarioBySistema($nomSistema);
        $report = [
            'nomSistema' => $nomSistema,
            'total' => count($lista),
            'created' => [],
            'updated' => [],
            'deactivated' => [],
            'skipped' => [],
            'needs_attention' => [],
        ];
        $n = 0;
        foreach ($lista as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (++$n > self::MAX_USERS) {
                $report['skipped'][] = ['codUsuario' => '?', 'reason' => 'limite de 200 usuarios por sync'];
                break;
            }
            $this->syncOne($row, $nomSistema, $report);
        }
        $this->log('sga_admin_sync.one', [
            'section' => 'users',
            'nomSistema' => $nomSistema,
            'total' => $report['total'],
            'created' => count($report['created']),
            'updated' => count($report['updated']),
            'deactivated' => count($report['deactivated']),
            'skipped' => count($report['skipped']),
        ]);
        return $report;
    }

    private function syncOne(array $row, string $nomSistema, array &$report): void
    {
        $codUsuario = trim((string) ($row['codUsuario'] ?? $row['matricula'] ?? $row['login'] ?? ''));
        if ($codUsuario === '') {
            $report['skipped'][] = ['codUsuario' => '?', 'reason' => 'registro sem codUsuario'];
            return;
        }
        try {
            $perfis = $this->sga->getListaPerfilUsuario($codUsuario, $nomSistema);
        } catch (\Throwable $e) {
            $report['skipped'][] = ['codUsuario' => $codUsuario, 'reason' => 'perfis indisponiveis'];
            return;
        }
        if ($perfis === []) {
            $user = User::where('username', $codUsuario)->first();
            if ($user && (int) $user->is_active !== 0) {
                $user->is_active = 0;
                $user->save();
                $report['deactivated'][] = ['codUsuario' => $codUsuario];
            } else {
                $report['skipped'][] = ['codUsuario' => $codUsuario, 'reason' => 'sem perfil no SGA'];
            }
            return;
        }
        $dfRole = 'viewer';
        foreach ($perfis as $perfil) {
            if (!is_array($perfil)) {
                continue;
            }
            $mapped = $this->orchestrator->mapSgaPerfilToDfRole($perfil);
            if ($mapped === 'admin') {
                $dfRole = 'admin';
                break;
            }
            $dfRole = $mapped;
        }
        $email = trim((string) ($row['dscEmail'] ?? $row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $codUsuario)) . '@sga.local';
        }
        $user = User::where('username', $codUsuario)->first();
        $isNew = ($user === null);
        if ($isNew) {
            $user = new User();
            $user->username = $codUsuario;
        }
        $user->email = $email;
        $user->name = mb_substr(trim((string) ($row['nomUsuario'] ?? $row['nome'] ?? $codUsuario)), 0, 80);
        $user->is_sys_admin = ($dfRole === 'admin') ? 1 : 0;
        $user->is_active = 1;
        $user->save();

        $entry = ['codUsuario' => $codUsuario, 'role' => $dfRole];
        if ($isNew) {
            $report['created'][] = $entry;
        } else {
            $report['updated'][] = $entry;
        }
        $accessCount = 0;
        foreach ($perfis as $perfil) {
            if (is_array($perfil) && isset($perfil['idPerfil'])) {
                $accessCount += 1;
            }
        }
        if ($accessCount > 0) {
            $report['needs_attention'][] = $entry + ['reason' => $accessCount . ' perfil(is) SGA: objetos/menus nao viram RBAC DF'];
        }
        $this->log('sga_admin_sync.user', ['codUsuario' => $codUsuario, 'role' => $dfRole, 'new' => $isNew]);
    }

    private function log(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, ['password', 'dscsenha', 'refsenha', 'secret', 'credentials'], true)) {
                $safe[$k] = '[REDACTED]';
            } else {
                $safe[$k] = $v;
            }
        }
        try {
            Log::info($event, $safe);
        } catch (\Throwable $ignored) {
        }
    }
}
