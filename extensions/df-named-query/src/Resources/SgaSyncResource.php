<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Resources\BaseRestResource;
use Illuminate\Support\Facades\Log;
use Yamaha\DreamFactory\NamedQuery\Services\SgaClient;
use Yamaha\DreamFactory\NamedQuery\Services\SgaSgcOrchestrator;

/**
 * E10 — SGA sync-login (sem senha em log).
 *
 * POST /api/v2/system/sga_sync
 * Body: {"codUsuario":"...","dscSenha":"...","nomSistema":"DF"}
 *
 * O SGA valida a credencial (AD) e os acessos do sistema DF.
 * Em sucesso espelha a conta no DF (username = codUsuario, email/nome
 * do SGA, admin se DF_ADMIN, senha espelhada) para o login padrao
 * do DF aceitar a mesma credencial. Gestao de usuarios continua no SGA:
 * sem perfil => 403 + conta desativada no DF.
 */
class SgaSyncResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'sga_sync';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handlePOST()
    {
        $data = $this->getPayloadData();
        if (!is_array($data)) {
            $data = [];
        }
        $codUsuario = trim((string) ($data['codUsuario'] ?? ''));
        $dscSenha = (string) ($data['dscSenha'] ?? '');
        $nomSistema = trim((string) ($data['nomSistema'] ?? 'DF')) ?: 'DF';

        if ($codUsuario === '' || $dscSenha === '') {
            throw new BadRequestException('codUsuario and dscSenha are required.');
        }
        if (strlen($codUsuario) > 60 || strlen($dscSenha) > 256) {
            throw new BadRequestException('Invalid credentials payload.');
        }

        $sga = new SgaClient();
        $orchestrator = new SgaSgcOrchestrator();

        try {
            $login = $sga->validarLogin($codUsuario, $dscSenha, $nomSistema);
        } catch (\Throwable $e) {
            $this->log('sga_sync.rejected', ['codUsuario' => $codUsuario, 'nomSistema' => $nomSistema]);
            unset($dscSenha);
            throw new UnauthorizedException('SGA rejected the credentials.');
        }
        unset($login);

        try {
            $usuario = $sga->getUsuarioByMatricula($codUsuario);
        } catch (\Throwable $e) {
            $usuario = [];
        }
        try {
            $perfil = $sga->getPerfilUsuario($codUsuario, $nomSistema);
        } catch (\Throwable $e) {
            $this->log('sga_sync.no_profile', ['codUsuario' => $codUsuario, 'nomSistema' => $nomSistema]);
            unset($dscSenha);
            $this->deactivate($codUsuario);
            throw new ForbiddenException('No SGA profile for this system.');
        }

        $dfRole = $orchestrator->mapSgaPerfilToDfRole(is_array($perfil) ? $perfil : []);
        $email = trim((string) ($usuario['dscEmail'] ?? ''));
        $nomUsuario = trim((string) ($usuario['nomUsuario'] ?? $codUsuario));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $codUsuario)) . '@sga.local';
        }
        $parts = preg_split('/\s+/', $nomUsuario, -1, PREG_SPLIT_NO_EMPTY);
        $firstName = $parts ? $parts[0] : $codUsuario;
        $lastName = $parts && count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : $codUsuario;

        $user = User::where('username', $codUsuario)->orWhere('email', $email)->first();
        $isNew = ($user === null);
        if ($isNew) {
            $user = new User();
            $user->name = mb_substr($nomUsuario, 0, 255);
            $user->username = $codUsuario;
            $user->email = $email;
        }
        $user->name = mb_substr($nomUsuario, 0, 255);
        $user->first_name = mb_substr($firstName, 0, 255);
        $user->last_name = mb_substr($lastName, 0, 255);
        $user->username = $codUsuario;
        // O mutator do model já aplica Hash::make — passar a senha pura.
        $user->password = $dscSenha;
        unset($dscSenha);
        $user->is_active = 1;
        $user->is_sys_admin = ($dfRole === 'admin') ? 1 : 0;
        $user->save();

        $this->log('sga_sync.success', ['codUsuario' => $codUsuario, 'nomSistema' => $nomSistema, 'dfRole' => $dfRole]);

        return [
            'codUsuario' => $codUsuario,
            'email' => $user->email,
            'nomSistema' => $nomSistema,
            'dfRole' => $dfRole,
            'is_sys_admin' => (bool) $user->is_sys_admin,
        ];
    }

    private function deactivate(string $codUsuario): void
    {
        try {
            $user = User::where('username', $codUsuario)->first();
            if ($user) {
                $user->is_active = 0;
                $user->save();
            }
        } catch (\Throwable $ignored) {
        }
    }

    private function log(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, ['dscsenha', 'password', 'secret', 'credentials'], true)) {
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

    public function getApiDocPaths()
    {
        return [
            '/' . static::RESOURCE_NAME => [
                'post' => [
                    'summary' => 'SGA sync-login: valida no SGA e espelha conta/papel no DF',
                    'responses' => [
                        '200' => ['description' => 'synced'],
                        '401' => ['description' => 'SGA rejected'],
                        '403' => ['description' => 'no SGA profile'],
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
