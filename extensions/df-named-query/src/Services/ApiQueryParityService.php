<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * Paridade api-query (seed APOIOPYMACP V2) — Fase A: superficie.
 *
 * Cria/atualiza de forma idempotente:
 * - 7 roles `qb-claim-<claim>` (uma por claim do seed);
 * - 8 services `qb-ds-<dataset>` (sqlsrv/oracle/informix/pgsql) com
 *   host/porta/database/username do seed e credenciais vivas via SGC
 *   (sgc-connection-id do seed); sem SGC, usa lookup-key placeholder
 *   que o dono preenche — senhas NUNCA em codigo, log ou banco local
 *   em claro fora do cofre do DF.
 * Rotas (27) e queries nomeadas entram na Fase B sobre esta base.
 */
class ApiQueryParityService
{
    public const CLAIMS = [
        'query_decalque',
        'query_gq_eficaz',
        'query_gq_lote',
        'query_gq_mi',
        'adm_api_query',
        'query_hora_hora',
        'query_export_plan',
    ];

    /** slug => [type, jdbc, user, sgcId|null] (hosts do seed, sem segredos). */
    public const DATASETS = [
        'gq-eficaz' => ['sqlsrv', 'jdbc:sqlserver://172.31.16.122:1433;databaseName=SYSYAGQP;encrypt=true;trustServerCertificate=true;', 'sysyagq_prod', 931],
        'gq-mi-pymac' => ['informix', 'jdbc:informix-sqli://172.31.192.78:6534/pymac3_ymda_pub:INFORMIXSERVER=ifmxd4_part01', 'lymdaact', 932],
        'gq-mi-wms' => ['oracle', 'jdbc:oracle:thin:@//172.31.16.46:1521/WMSYMAP', 'bdwms_yma', 933],
        'infmx-pymac' => ['informix', 'jdbc:informix-sqli://172.31.192.78:6534/pymac3_ymda_pub:INFORMIXSERVER=ifmxd4_part01', 'lymdaact', null],
        'py-local' => ['pgsql', 'jdbc:postgresql://172.31.16.110:5432/dbase_pymac', 'pymac_local', 180],
        'pymac-ifx' => ['informix', 'jdbc:informix-sqli://172.31.192.78:6534/pymac3_ymda_pub:INFORMIXSERVER=ifmxd4_part01', 'lymdasys3', null],
        'py-ptg' => ['pgsql', 'jdbc:postgresql://172.31.192.68:5433/ynslf_ymda_a_pub', 'ynslf_ymda_pub_a3', 903],
        'sgpi-hml' => ['oracle', 'jdbc:oracle:thin:@172.31.18.46:1521/OEEHML', 'oee', 424],
    ];

    private SgcConnectionClient $sgc;

    public function __construct(?SgcConnectionClient $sgc = null)
    {
        $this->sgc = $sgc ?? new SgcConnectionClient();
    }

    /**
     * @return array{roles:array,datasets:array,skipped:array}
     */
    public function sync(): array
    {
        $report = ['roles' => [], 'datasets' => [], 'skipped' => []];
        foreach (self::CLAIMS as $claim) {
            $name = 'qb-claim-' . $claim;
            $role = Role::where('name', $name)->first();
            if (!$role) {
                $role = new Role();
                $role->name = $name;
                $role->description = mb_substr('Paridade api-query: claim ' . $claim, 0, 255);
                $role->is_active = 1;
                $role->save();
                $report['roles'][] = ['claim' => $claim, 'role' => $name, 'new' => true];
            } else {
                $report['roles'][] = ['claim' => $claim, 'role' => $name, 'new' => false];
            }
        }
        foreach (self::DATASETS as $slug => [$type, $jdbc, $user, $sgcId]) {
            try {
                $report['datasets'][] = $this->syncDataset($slug, $type, $jdbc, $user, $sgcId);
            } catch (\Throwable $e) {
                $report['skipped'][] = ['dataset' => $slug, 'reason' => 'config indisponivel'];
            }
        }
        $this->log('apiq.parity.one', ['roles' => count($report['roles']), 'datasets' => count($report['datasets']), 'skipped' => count($report['skipped'])]);
        return $report;
    }

    private function syncDataset(string $slug, string $type, string $jdbc, string $user, ?int $sgcId): array
    {
        $parsed = $this->parseJdbc($type, $jdbc);
        $password = '{apiq.' . $slug . '.password}';
        $via = 'lookup';
        if ($sgcId !== null) {
            try {
                $conn = $this->sgc->getConexaoById($sgcId);
                if (!empty($conn['refLogin'])) {
                    $user = (string) $conn['refLogin'];
                }
                if (!empty($conn['refSenha'])) {
                    $password = (string) $conn['refSenha'];
                    $via = 'sgc:' . $sgcId;
                }
                $live = $this->parseJdbc($type, (string) ($conn['refUrl'] ?? ''));
                if ($live['host'] !== '') {
                    $parsed = $live + $parsed;
                }
            } catch (\Throwable $ignored) {
            }
        }
        $name = 'qb-ds-' . $slug;
        $service = Service::where('name', $name)->first();
        $isNew = ($service === null);
        if ($isNew) {
            $service = new Service();
            $service->name = $name;
            $service->label = mb_substr($slug, 0, 80);
            $service->type = $type;
            $service->is_active = 1;
            $service->save();
        }
        $config = is_array($service->config) ? $service->config : [];
        $config['host'] = $parsed['host'];
        $config['port'] = $parsed['port'];
        $config['database'] = $parsed['database'];
        $config['username'] = $user;
        $config['password'] = $password;
        if (isset($parsed['service_name'])) {
            $config['service_name'] = $parsed['service_name'];
        }
        if (isset($parsed['server'])) {
            $config['server'] = $parsed['server'];
        }
        $service->type = $type;
        $service->description = mb_substr('Paridade api-query dataset ' . $slug . ' (via ' . $via . ')', 0, 255);
        $service->config = $config;
        $service->save();
        $this->log('apiq.parity.dataset', ['dataset' => $slug, 'service' => $name, 'new' => $isNew, 'via' => explode(':', $via)[0]]);
        return ['dataset' => $slug, 'service' => $name, 'type' => $type, 'new' => $isNew];
    }

    private function parseJdbc(string $type, string $url): array
    {
        $url = trim($url);
        $out = ['host' => '', 'port' => 0, 'database' => ''];
        if ($type === 'sqlsrv' && preg_match('#^jdbc:sqlserver://([^;:/]+)(?::(\d+))?#i', $url, $m)) {
            $out['host'] = $m[1];
            $out['port'] = !empty($m[2]) ? (int) $m[2] : 1433;
            if (preg_match('/databaseName\s*=\s*([^;]+)/i', $url, $mm)) {
                $out['database'] = trim($mm[1]);
            }
        } elseif ($type === 'informix' && preg_match('#^jdbc:informix-sqli://([^:/]+)(?::(\d+))?/([^:;]+)#i', $url, $m)) {
            $out['host'] = $m[1];
            $out['port'] = !empty($m[2]) ? (int) $m[2] : 9088;
            $out['database'] = $m[3];
            if (preg_match('/INFORMIXSERVER\s*=\s*([^:;\s]+)/i', $url, $mm)) {
                $out['server'] = trim($mm[1]);
            }
        } elseif ($type === 'oracle' && preg_match('#^jdbc:oracle:thin:@(//)?([^:/]+)(?::(\d+))?[:/]([^\s;]+)#i', $url, $m)) {
            $out['host'] = $m[2];
            $out['port'] = !empty($m[3]) ? (int) $m[3] : 1521;
            $out['database'] = $m[4];
            $out['service_name'] = $m[4];
        } elseif (preg_match('#^jdbc:[a-z]+://([^:/]+)(?::(\d+))?/([^?;]*)#i', $url, $m)) {
            $out['host'] = $m[1];
            $out['port'] = !empty($m[2]) ? (int) $m[2] : 5432;
            $out['database'] = $m[3];
        }
        return $out;
    }

    private function log(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            $lk = strtolower((string) $k);
            $safe[$k] = in_array($lk, ['password', 'refsenha', 'secret', 'credentials', 'dscsenha'], true) ? '[REDACTED]' : $v;
        }
        try {
            Log::info($event, $safe);
        } catch (\Throwable $ignored) {
        }
    }
}
