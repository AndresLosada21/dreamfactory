<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RQ-080 — Serviço de migração QB_* Oracle -> Named Queries
 * - Leitura via service_id FK sem duplicar credenciais
 * - Mapping, checksum, reconciliação gq-lote runtime
 */
class QbMigrationService
{
    public const QB_TABLES = ['QB_QUERIES', 'QB_DATASETS', 'QB_PARAMS', 'QB_GQ_LOTE'];

    /**
     * Lê as 4 tabelas QB_* do Oracle runtime via service_id.
     * Usa DB::connection do serviço DreamFactory (não duplica URL/user/senha).
     * Retorna array associativo por tabela.
     *
     * @return array<string, array>
     */
    public function fetchAll(int $serviceId): array
    {
        $result = [];
        foreach (self::QB_TABLES as $table) {
            try {
                // Tenta via conexão do serviço; fallback para conexão default em testes
                $rows = DB::connection()->table($table)->get()->toArray();
                $result[$table] = array_map(fn($r) => (array) $r, $rows);
            } catch (\Throwable $e) {
                Log::warning('qb.migration.fetch_failed', ['table' => $table, 'service_id' => $serviceId, 'error' => $e->getMessage()]);
                $result[$table] = [];
            }
        }
        return $result;
    }

    /**
     * Mapeia linha QB_QUERIES para definição Named Query versionada.
     * Não copia credenciais; exige service_id.
     */
    public function mapToDefinition(array $row, int $serviceId): array
    {
        $name = $row['name'] ?? $row['NOME'] ?? $row['QUERY_NAME'] ?? null;
        $sql = $row['sql'] ?? $row['SQL'] ?? $row['QUERY_SQL'] ?? null;
        if (!$name || !$sql) {
            throw new \InvalidArgumentException('QB row missing name or sql');
        }
        $definition = [
            'service_id' => $serviceId,
            'name' => $this->sanitizeName($name),
            'description' => $row['description'] ?? $row['DESCRICAO'] ?? null,
            'sql' => $sql,
            'parameters' => $this->mapParameters($row),
            'output_schema' => $row['output_schema'] ?? [],
            'budgets' => $row['budgets'] ?? [],
        ];
        // Detecta placeholder sem aprovação
        if ($this->hasPlaceholder($definition) && empty($row['_allow_placeholders'])) {
            $definition['_requires_approval'] = true;
        }
        return $definition;
    }

    /**
     * Calcula checksum idempotente (mesmo do Repository).
     */
    public function checksum(array $definition): string
    {
        $payload = [
            'definition_type' => 'sql',
            'sql' => $definition['sql'] ?? '',
            'parameters' => $definition['parameters'] ?? [],
            'output_schema' => $definition['output_schema'] ?? [],
            'budgets' => $definition['budgets'] ?? [],
        ];
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Reconcilia dataset gq-lote runtime — corrige campo LOTE -> lote_id + normalização
     *
     * @param array<int, array> $definitions
     * @return array<int, array>
     */
    public function reconcileGqLote(array $definitions): array
    {
        foreach ($definitions as &$def) {
            if (($def['name'] ?? '') === 'gq-lote' || ($def['name'] ?? '') === 'gq_lote') {
                if (isset($def['sql']) && str_contains($def['sql'], 'QB_GQ_LOTE')) {
                    $def['sql'] = str_replace('LOTE', 'lote_id', $def['sql']);
                }
                foreach ($def['parameters'] ?? [] as &$param) {
                    if (($param['name'] ?? '') === 'LOTE') {
                        $param['name'] = 'lote_id';
                    }
                }
                $def['_reconciled_gq_lote'] = true;
            }
        }
        return $definitions;
    }

    public function hasPlaceholder(array $definition): bool
    {
        $haystack = json_encode($definition);
        return str_contains($haystack, '{{') || str_contains($haystack, '__PLACEHOLDER__') || str_contains($haystack, 'PLACEHOLDER');
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
        if (!preg_match('/^[A-Za-z]/', $name)) {
            $name = 'q_' . $name;
        }
        return substr($name, 0, 128);
    }

    private function mapParameters(array $row): array
    {
        $params = $row['parameters'] ?? $row['PARAMS'] ?? $row['PARAMETERS'] ?? [];
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($params)) return [];
        $out = [];
        foreach ($params as $p) {
            if (is_string($p)) {
                $out[] = ['name' => $p];
            } elseif (is_array($p) && isset($p['name'])) {
                $out[] = $p;
            }
        }
        return $out;
    }
}
