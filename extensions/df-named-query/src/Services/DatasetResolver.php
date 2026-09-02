<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;
use DreamFactory\Core\Models\Service;

/**
 * RQ-062 — DatasetResolver com fallback SGC elegível
 * - Local preferido via ServiceConfig
 * - SGC apenas se sgc-connection-id presente + isConfigured + falha elegível
 * - Source registra ID sem duplicar senha, rotação converge via ClusterInvalidationService
 */
class DatasetResolver
{
    private SgcConnectionClient $sgc;
    private ClusterInvalidationService $invalidation;

    public function __construct(?SgcConnectionClient $sgc = null, ?ClusterInvalidationService $inv = null)
    {
        $this->sgc = $sgc ?? new SgcConnectionClient();
        $this->invalidation = $inv ?? new ClusterInvalidationService();
    }

    /**
     * @param string $datasetName Service name
     * @param array $requestContext ['sgc-connection-id' => int, ...]
     * @return array DataSource config
     */
    public function resolve(string $datasetName, array $requestContext = []): array
    {
        // 1. Tenta local preferido
        try {
            $service = Service::where('name', $datasetName)->first();
            if ($service) {
                // Check if service has valid config (without SGC)
                $config = $service->config ?? [];
                if (!empty($config) || !empty($service->id)) {
                    // Source registra ID sem duplicar senha — retorna service_id apenas
                    try { Log::info('dataset.resolve.local', ['dataset' => $datasetName, 'service_id' => $service->id]); } catch (\Throwable $ignored) {}
                    return ['service_id' => $service->id, 'name' => $datasetName, 'via' => 'local'];
                }
            }
        } catch (\Throwable $e) {
            // Falha elegível — tenta SGC fallback
            try { Log::info('dataset.resolve.local_failed', ['dataset' => $datasetName, 'error' => $this->sanitize($e->getMessage())]); } catch (\Throwable $ignored) {}
        }

        // 2. Fallback SGC apenas se elegível
        $sgcId = $requestContext['sgc-connection-id'] ?? $requestContext['sgc_connection_id'] ?? $_SERVER['HTTP_SGC_CONNECTION_ID'] ?? null;
        if ($sgcId !== null && $this->sgc->isConfigured()) {
            try {
                $conn = $this->sgc->getConexaoById((int) $sgcId);
                // Source registra ID sem duplicar senha — apenas SGC_CONNECTION_ID
                $this->persistSgcId($datasetName, (int) $sgcId);
                // Rotacao converge em todos os nós
                try {
                    $serviceId = $this->findServiceId($datasetName);
                    if ($serviceId) {
                        $this->invalidation->invalidateSource($serviceId);
                    }
                } catch (\Throwable $ignored) {}
                try { Log::info('dataset.resolve.sgc', ['dataset' => $datasetName, 'sgc_connection_id' => (int) $sgcId]); } catch (\Throwable $ignored) {}
                return ['service_id' => $conn['codConexao'] ?? (int) $sgcId, 'sgc_connection_id' => (int) $sgcId, 'via' => 'sgc', 'connection' => $conn];
            } catch (\Throwable $e) {
                // Falha dupla — preserva causa sanitizada
                $cause = $this->sanitize($e->getMessage());
                try { Log::info('dataset.resolve.sgc_failed', ['dataset' => $datasetName, 'sgc_connection_id' => (int) $sgcId, 'error' => $cause]); } catch (\Throwable $ignored) {}
                throw new \RuntimeException('Dataset resolution failed for ' . $datasetName . ': ' . $cause, 0, $e);
            }
        }

        throw new \RuntimeException('Dataset not found and SGC not eligible: ' . $datasetName);
    }

    private function persistSgcId(string $datasetName, int $sgcId): void
    {
        // Registra apenas ID, sem senha — via QB_DATA_SET or service config
        try {
            // Example: update Service config with sgc_connection_id reference
            $service = Service::where('name', $datasetName)->first();
            if ($service) {
                // Store reference, not secret
                $service->update(['sgc_connection_id' => $sgcId]);
            }
        } catch (\Throwable $ignored) {}
    }

    private function findServiceId(string $name): ?int
    {
        try {
            $s = Service::where('name', $name)->first();
            return $s ? (int) $s->id : null;
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    private function sanitize(string $msg): string
    {
        $msg = preg_replace('/password[^;]*/i', '[REDACTED]', $msg);
        $msg = preg_replace('/secret[^;]*/i', '[REDACTED]', $msg);
        return substr($msg, 0, 200);
    }
}
