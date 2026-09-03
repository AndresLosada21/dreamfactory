<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;

/**
 * Wave 2 — leitura da trilha de auditoria SGA, 100% lado DF.
 *
 * Sem operacao SOAP de auditoria no SGA (ServiceAcessLog e so MVC), o DF le
 * direto o MySQL do SGA (tabela ym_sga_tab_log_acesso + joins perfil_usuario,
 * usuario, perfil), read-only, com as credenciais vivas do SGC (conexao
 * YM_SGA). Credenciais nunca sao persistidas; nenhum segredo em log
 * (aqui nem trafega senha: so leitura de trilha).
 */
class SgaAuditSyncService
{
    public const NOM_SISTEMA_DEFAULT = 'DF';
    public const ID_SISTEMA_DF = 1215570;
    public const CONEXAO_YM_SGA = 3502;
    public const MAX_ROWS = 500;

    private SgcConnectionClient $sgc;

    public function __construct(?SgcConnectionClient $sgc = null)
    {
        $this->sgc = $sgc ?? new SgcConnectionClient();
    }

    /**
     * @return array{nomSistema:string,idSistema:int,datStart:string,datEnd:string,total:int,events:array}
     */
    public function sync(string $nomSistema = self::NOM_SISTEMA_DEFAULT, int $idSistema = self::ID_SISTEMA_DF, string $datStart = '', string $datEnd = ''): array
    {
        $nomSistema = trim($nomSistema) ?: self::NOM_SISTEMA_DEFAULT;
        if ($idSistema < 1) {
            throw new \InvalidArgumentException('idSistema must be >=1');
        }
        if ($datEnd === '') {
            $datEnd = date('Y-m-d');
        }
        if ($datStart === '') {
            $datStart = date('Y-m-d', strtotime('-7 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datEnd)) {
            throw new \InvalidArgumentException('datStart/datEnd must be YYYY-MM-DD');
        }
        $conn = $this->sgc->getConexaoById(self::CONEXAO_YM_SGA);
        $pdo = $this->connect($conn);
        $sql = 'SELECT l.id_log_acesso, l.dat_acesso, l.ref_maquina,'
            . ' u.cod_usuario, u.nom_usuario, p.nom_perfil'
            . ' FROM ym_sga_tab_log_acesso l'
            . ' JOIN ym_sga_tab_perfil_usuario pu ON pu.id_perfil_usuario = l.id_perfil_usuario'
            . ' JOIN ym_sga_tab_usuario u ON u.id_usuario = pu.id_usuario'
            . ' JOIN ym_sga_tab_perfil p ON p.id_perfil = pu.id_perfil'
            . ' WHERE p.id_sistema = :sys AND l.dat_acesso BETWEEN :start AND :end'
            . ' ORDER BY l.dat_acesso DESC LIMIT ' . self::MAX_ROWS;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':sys' => $idSistema,
            ':start' => $datStart . ' 00:00:00',
            ':end' => $datEnd . ' 23:59:59',
        ]);
        $events = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => (int) ($row['id_log_acesso'] ?? 0),
                'datAcesso' => (string) ($row['dat_acesso'] ?? ''),
                'refMaquina' => (string) ($row['ref_maquina'] ?? ''),
                'codUsuario' => (string) ($row['cod_usuario'] ?? ''),
                'nomUsuario' => (string) ($row['nom_usuario'] ?? ''),
                'nomPerfil' => (string) ($row['nom_perfil'] ?? ''),
            ];
        }
        $report = [
            'nomSistema' => $nomSistema,
            'idSistema' => $idSistema,
            'datStart' => $datStart,
            'datEnd' => $datEnd,
            'total' => count($events),
            'events' => $events,
        ];
        $this->log('sga_admin_sync.one', [
            'section' => 'audit',
            'nomSistema' => $nomSistema,
            'idSistema' => $idSistema,
            'total' => count($events),
        ]);
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
