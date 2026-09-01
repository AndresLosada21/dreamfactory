<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use DreamFactory\Core\Models\Service;

/**
 * RQ-087 — SGA→SGC Orchestrator
 * Orquestra autenticação SGA (validarLogin + getPerfilUsuario) → resolução SGC via ServiceConfig FK + SecretStore + ClusterInvalidationService + SgcCircuitBreaker half-open
 * - ServiceConfig FK: dataset resolve via service_id sem duplicar URL (preserva Secrets)
 * - SecretStore: credenciais via SecretRotationService AES-GCM (nunca em logs)
 * - ClusterInvalidationService: invalidação nq:cache_generation em todos os nós
 * - SgcCircuitBreaker: fallback elegível apenas se closed/half-open, registra sucessos/falhas
 * - mapSgaPerfilToDfRole: traduz perfil SGA (MBeanPerfil) para role DreamFactory nativo
 */
class SgaSgcOrchestrator
{
    private SgaClient $sga;
    private SgcConnectionClient $sgc;
    private SgcCircuitBreaker $breaker;
    private ClusterInvalidationService $invalidation;
    private SecretRotationService $secrets;

    public function __construct(
        ?SgaClient $sga = null,
        ?SgcConnectionClient $sgc = null,
        ?SgcCircuitBreaker $breaker = null,
        ?ClusterInvalidationService $invalidation = null,
        ?SecretRotationService $secrets = null
    ) {
        $this->sga = $sga ?? new SgaClient();
        $this->sgc = $sgc ?? new SgcConnectionClient();
        $this->breaker = $breaker ?? new SgcCircuitBreaker();
        $this->invalidation = $invalidation ?? new ClusterInvalidationService();
        $this->secrets = $secrets ?? new SecretRotationService();
    }

    /**
     * Autentica via SGA validarLogin; fallback para auth local se SGA inalcançável
     * @return array{via:string, codUsuario:string, perfil?:array, fallback?:bool, cache_generation?:int}
     */
    public function authenticateOrFallback(string $codUsuario, string $dscSenha, string $nomSistema): array
    {
        try {
            $loginResult = $this->sga->validarLogin($codUsuario, $dscSenha, $nomSistema);
            if (isset($loginResult['error']) || isset($loginResult['@@@ERRO@@@'])) {
                throw new \RuntimeException('SGA auth returned erro marker');
            }
            // Enriquecer com perfil SGA → DF role
            $perfil = null;
            $dfRole = 'user';
            try {
                $perfil = $this->sga->getPerfilUsuario($codUsuario, $nomSistema);
                $dfRole = $this->mapSgaPerfilToDfRole($perfil);
            } catch (\Throwable $e) {
                // Perfil opcional — log sanitizado
                Log::info('sga.perfil.fallback', ['codUsuario' => $codUsuario, 'error' => substr($e->getMessage(), 0, 100)]);
                $perfil = ['perfil' => 'user', 'sglSistema' => $nomSistema];
            }

            $this->logSanitized('sga.auth.success', ['codUsuario' => $codUsuario, 'nomSistema' => $nomSistema, 'dfRole' => $dfRole]);
            return [
                'via' => 'SGA',
                'codUsuario' => $codUsuario,
                'nomSistema' => $nomSistema,
                'perfil' => $perfil,
                'dfRole' => $dfRole,
                'fallback' => false,
                'cache_generation' => $this->currentCacheGeneration(),
            ];
        } catch (\Throwable $e) {
            // SGA inalcançável ou @@@ERRO@@@ — fallback elegível para auth local
            $this->logSanitized('sga.auth.fallback', ['codUsuario' => $codUsuario, 'nomSistema' => $nomSistema, 'error' => $this->sanitizeError($e->getMessage())]);
            // Não logar dscSenha
            return [
                'via' => 'fallback',
                'codUsuario' => $codUsuario,
                'nomSistema' => $nomSistema,
                'fallback' => true,
                'fallback_reason' => $this->sanitizeError($e->getMessage()),
                'dfRole' => 'user',
                'cache_generation' => $this->currentCacheGeneration(),
            ];
        }
    }

    /**
     * Resolve DataSource: prefere ServiceConfig FK local; SGC apenas se sgc-connection-id presente + breaker permite + falha elegível
     * @param string $dataset nome do service
     * @param int|null $sgcId sgc-connection-id do header/request
     * @return array{via:string, service_id?:int, connection?:array, sgcId?:int}
     */
    public function resolveConnection(string $dataset, ?int $sgcId = null, array $requestContext = []): array
    {
        $sgcId = $sgcId ?? $requestContext['sgc-connection-id'] ?? $requestContext['sgc_connection_id'] ?? null;
        $sgcId = $sgcId !== null ? (int) $sgcId : null;

        // 1. Tenta ServiceConfig FK local preferido (sem duplicar credenciais)
        try {
            $service = Service::where('name', $dataset)->first();
            if ($service && !empty($service->id)) {
                Log::info('dataset.resolve.local', ['dataset' => $dataset, 'service_id' => $service->id, 'via' => 'ServiceConfig']);
                // Se já temos config válida local e sgcId não foi forçado, retorna local
                if ($sgcId === null || !$this->sgc->isConfigured()) {
                    return ['via' => 'ServiceConfig', 'dataset' => $dataset, 'service_id' => $service->id];
                }
                // Se sgcId forçado mas local existe, ainda prioriza local (configuração explícita)
                // Somente fallback SGC quando local falha — ver catch abaixo
                if ($service->config !== null) {
                    // Config existe localmente — retorna local
                    return ['via' => 'ServiceConfig', 'dataset' => $dataset, 'service_id' => $service->id];
                }
            }
        } catch (\Throwable $e) {
            Log::info('dataset.resolve.local_failed', ['dataset' => $dataset, 'error' => $this->sanitizeError($e->getMessage())]);
            // Falha elegível — tenta SGC fallback abaixo
        }

        // 2. Fallback SGC apenas se elegível: sgcId presente + isConfigured + breaker permite
        if ($sgcId !== null && $sgcId > 0 && $this->sgc->isConfigured() && $this->breaker->canAttempt()) {
            try {
                $conn = $this->sgc->getConexaoById($sgcId);
                $this->breaker->recordSuccess();
                // Persiste apenas ID sem duplicar senha — SecretRotationService
                $serviceId = $this->findServiceId($dataset);
                if ($serviceId) {
                    try {
                        $this->invalidation->invalidateSource($serviceId);
                    } catch (\Throwable $ignored) {}
                }
                Log::info('dataset.resolve.sgc.success', ['dataset' => $dataset, 'sgcId' => $sgcId, 'via' => 'SGC']);
                return ['via' => 'SGC', 'dataset' => $dataset, 'sgcId' => $sgcId, 'connection' => $this->redactConnection($conn), 'service_id' => $serviceId];
            } catch (\Throwable $e) {
                $this->breaker->recordFailure();
                $this->logSanitized('dataset.resolve.sgc.failed', ['dataset' => $dataset, 'sgcId' => $sgcId, 'error' => $this->sanitizeError($e->getMessage())]);
                // Se SGC falhou mas tínhamos ServiceConfig local, fallback para local
                try {
                    $service = Service::where('name', $dataset)->first();
                    if ($service && !empty($service->id)) {
                        return ['via' => 'ServiceConfig', 'dataset' => $dataset, 'service_id' => $service->id, 'fallback_from' => 'SGC'];
                    }
                } catch (\Throwable $ignored) {}
                throw new \RuntimeException('SGC fallback failed for dataset ' . $dataset . ': ' . $this->sanitizeError($e->getMessage()));
            }
        }

        // 3. Nenhuma fonte resolvida
        if ($sgcId !== null && !$this->breaker->canAttempt()) {
            Log::info('dataset.resolve.breaker_open', ['dataset' => $dataset, 'sgcId' => $sgcId]);
            throw new \RuntimeException('SGC circuit breaker is open for dataset ' . $dataset);
        }

        return ['via' => 'ServiceConfig', 'dataset' => $dataset, 'sgcId' => $sgcId, 'note' => 'no SGC fallback — ServiceConfig preferred'];
    }

    /**
     * Traduz MBeanPerfil SGA para role DreamFactory nativo
     * MBeanPerfil: idPerfil, nomPerfil, sglPerfil, dscPerfil, lista de MBeanAcessoMenu
     */
    public function mapSgaPerfilToDfRole(array $perfil): string
    {
        $raw = $perfil['sglPerfil'] ?? $perfil['nomPerfil'] ?? $perfil['perfil'] ?? $perfil['role'] ?? 'user';
        $raw = is_string($raw) ? trim(strtolower($raw)) : 'user';
        // Mapeamento legado → DF role
        $map = [
            'administrador' => 'admin',
            'admin' => 'admin',
            'gerente' => 'manager',
            'manager' => 'manager',
            'consulta' => 'viewer',
            'viewer' => 'viewer',
            'operador' => 'operator',
            'operator' => 'operator',
            'usuario' => 'user',
            'user' => 'user',
        ];
        return $map[$raw] ?? 'user';
    }

    private function findServiceId(string $dataset): ?int
    {
        try {
            $service = Service::where('name', $dataset)->first();
            return $service ? (int) $service->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function redactConnection(array $conn): array
    {
        $redacted = $conn;
        foreach (['refSenha', 'password', 'secret', 'credentials'] as $k) {
            if (isset($redacted[$k])) {
                $redacted[$k] = '[REDACTED]';
            }
        }
        return $redacted;
    }

    private function currentCacheGeneration(): int
    {
        try {
            return (int) Cache::get(ClusterInvalidationService::GENERATION_KEY, 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function logSanitized(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            if (in_array(strtolower($k), ['xml', 'body', 'password', 'secret', 'credentials', 'dscsenha'], true)) {
                $safe[$k] = '[REDACTED]';
            } else {
                $safe[$k] = $v;
            }
        }
        try {
            Log::info($event, $safe);
        } catch (\Throwable $ignored) {}
    }

    private function sanitizeError(string $msg): string
    {
        $msg = preg_replace('/password[^;]*/i', '[REDACTED]', $msg);
        $msg = preg_replace('/secret[^;]*/i', '[REDACTED]', $msg);
        $msg = preg_replace('/dscSenha[^;]*/i', '[REDACTED]', $msg);
        return substr($msg, 0, 200);
    }
}
