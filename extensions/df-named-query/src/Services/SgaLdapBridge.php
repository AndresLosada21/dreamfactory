<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;

/**
 * E10 — Ponte LDAP (SGA) + SGC.
 * Autentica no mesmo AD do SGA via bind LDAP com a credencial
 * informada no login (nunca gravada, nunca logada) e autoriza
 * via SGA getPerfilUsuario (sem senha) com mapa DF_ADMIN->admin.
 */
class SgaLdapBridge
{
    public const TIMEOUT_S = 3;

    private SgaClient $sga;
    private SgaSgcOrchestrator $orchestrator;

    public function __construct(?SgaClient $sga = null, ?SgaSgcOrchestrator $orchestrator = null)
    {
        $this->sga = $sga ?? new SgaClient();
        $this->orchestrator = $orchestrator ?? new SgaSgcOrchestrator();
    }

    public function testConnection(string $host, int $port): array
    {
        $conn = @ldap_connect($host, $port);
        if ($conn === false) {
            throw new \RuntimeException('LDAP unreachable: ' . $host);
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, self::TIMEOUT_S);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        @ldap_close($conn);
        try {
            Log::info('ldap.testconnection.success', ['host' => $host, 'port' => $port]);
        } catch (\Throwable $ignored) {
        }

        return ['host' => $host, 'port' => $port, 'reachable' => true];
    }

    public function buildUserDn(string $codUsuario, string $baseDn): string
    {
        $codUsuario = trim($codUsuario);
        if ($codUsuario === '' || trim($baseDn) === '') {
            throw new \InvalidArgumentException('codUsuario and baseDn required');
        }
        $escaped = addcslashes($codUsuario, ',+"\\<>;');
        if (str_contains($codUsuario, '@') || str_contains(strtoupper($codUsuario), 'CN=')) {
            return $codUsuario;
        }

        return 'CN=' . $escaped . ',' . trim($baseDn);
    }

    public function authenticate(string $codUsuario, string $dscSenha, string $host, int $port, string $baseDn, string $sglSistema = 'DF'): array
    {
        if (trim($codUsuario) === '' || trim($dscSenha) === '' || trim($host) === '' || trim($baseDn) === '') {
            throw new \InvalidArgumentException('codUsuario, credentials, host and baseDn required');
        }
        $conn = @ldap_connect($host, $port);
        if ($conn === false) {
            $this->log('ldap.auth.connect_fail', ['host' => $host, 'codUsuario' => $codUsuario]);
            throw new \RuntimeException('LDAP unreachable');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, self::TIMEOUT_S);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        $userDn = $this->buildUserDn($codUsuario, $baseDn);
        $bound = @ldap_bind($conn, $userDn, $dscSenha);
        unset($dscSenha);
        if ($bound !== true) {
            $this->log('ldap.auth.bind_fail', ['host' => $host, 'codUsuario' => $codUsuario]);
            @ldap_close($conn);
            throw new \RuntimeException('LDAP credentials rejected');
        }
        @ldap_close($conn);
        $perfil = $this->sga->getPerfilUsuario($codUsuario, $sglSistema);
        $dfRole = $this->orchestrator->mapSgaPerfilToDfRole($perfil);
        $this->log('ldap.auth.success', ['host' => $host, 'codUsuario' => $codUsuario, 'dfRole' => $dfRole]);

        return [
            'via' => 'LDAP+SGA',
            'codUsuario' => $codUsuario,
            'sglSistema' => $sglSistema,
            'perfil' => $perfil,
            'dfRole' => $dfRole,
        ];
    }

    private function log(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, ['dscsenha', 'password', 'secret', 'credentials', 'userdn'], true)) {
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
