<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;

/**
 * R1 — jobs SGA em leitura, 100% lado DF.
 *
 * O SGA nao tem templates de email (o DF e o dono) e o sendEmail e so
 * disparo; os jobs (ControllerJob, tabela ym_inf_tab_job) sao batches
 * internos sem superficie SOAP. Leitura read-only via MySQL com as
 * credenciais vivas do SGC (conexao YM_SGA), nunca persistidas.
 * A tabela nao tem coluna de segredo; mesmo assim so os campos
 * publicos sao expostos. Sem segredos em log.
 */
class SgaJobSyncService
{
    public const NOM_SISTEMA_DEFAULT = 'DF';
    public const CONEXAO_YM_SGA = 3502;
    public const MAX_ROWS = 200;

    private SgcConnectionClient $sgc;

    public function __construct(?SgcConnectionClient $sgc = null)
    {
        $this->sgc = $sgc ?? new SgcConnectionClient();
    }

    /**
     * @return array{total:int,jobs:array,needs_attention:array}
     */
    public function sync(): array
    {
        $conn = $this->sgc->getConexaoById(self::CONEXAO_YM_SGA);
        $pdo = $this->connect($conn);
        $sql = 'SELECT id_job, sts_job, dat_inicio, dat_fim, tpo_job,'
            . ' dat_cadastro, ref_login_cad'
            . ' FROM ym_inf_tab_job'
            . ' ORDER BY dat_inicio DESC LIMIT ' . self::MAX_ROWS;
        $jobs = [];
        foreach ($pdo->query($sql, \PDO::FETCH_ASSOC) as $row) {
            $jobs[] = [
                'id' => (int) ($row['id_job'] ?? 0),
                'status' => (string) ($row['sts_job'] ?? ''),
                'datInicio' => (string) ($row['dat_inicio'] ?? ''),
                'datFim' => (string) ($row['dat_fim'] ?? ''),
                'tipo' => (int) ($row['tpo_job'] ?? 0),
                'datCadastro' => (string) ($row['dat_cadastro'] ?? ''),
                'refLoginCad' => (string) ($row['ref_login_cad'] ?? ''),
            ];
        }
        $report = ['total' => count($jobs), 'jobs' => $jobs, 'needs_attention' => []];
        $byStatus = [];
        foreach ($jobs as $job) {
            $st = $job['status'] !== '' ? $job['status'] : '?';
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        }
        foreach ($byStatus as $st => $n) {
            $report['needs_attention'][] = ['status' => $st, 'count' => $n, 'reason' => 'codigos de status SGA sem dicionario no DF'];
        }
        $this->log('sga_admin_sync.one', ['section' => 'jobs', 'total' => count($jobs)]);
        return $report;
    }

    private function connect(array $conn): \PDO
    {
        $url = trim((string) ($conn['refUrl'] ?? ''));
        $db = trim((string) ($conn['refDatabase'] ?? ''));
        $user = (string) ($conn['refLogin'] ?? '');
        $pass = (string) ($conn['refSenha'] ?? '');
        if (!preg_match('#^jdbc:mysql://([^:/]+)(?::(\d+))?/([^?;]*?)(\?.*)?$#i', $url, $m)) {
            throw new \RuntimeException('YM_SGA url nao e MySQL parseavel');
        }
        $host = $m[1];
        $port = !empty($m[2]) ? (int) $m[2] : 3306;
        if ($m[3] !== '') {
            $db = $m[3];
        }
        if ($host === '' || $db === '' || $user === '') {
            throw new \RuntimeException('YM_SGA sem host/database/login');
        }
        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
    }

    private function log(string $event, array $ctx): void
    {
        try {
            Log::info($event, $ctx);
        } catch (\Throwable $ignored) {
        }
    }
}
