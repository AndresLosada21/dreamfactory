<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * E10 — espelha no DF os databases vinculados ao sistema no SGA/SGC.
 *
 * Fonte: SGC WsConexao.getListaConexaoSistema(nomSistema).
 * Para cada vinculo cria/atualiza um Service nativo do DF
 * (pgsql, sqlsrv, oracle, informix, firebird) com a config de conexao.
 * Idempotencia pelo prefixo do nome (sgc-{idConexao}-...), gerado so
 * por este sync: re-sync encontra o mesmo registro e atualiza. O sync
 * atualiza SOMENTE os campos de conexao (host/port/database/username/password + marcadores);
 * o resto da config do service (cache, schema etc.) e alteracoes
 * manuais nesses campos extras sao preservados. Rodar o sync de novo
 * reaplica host/port/database/username/password do SGC.
 *
 * Seguranca: credenciais nunca vao para log; motivos do relatorio
 * carregam so nomes/ids/razoes.
 */
class SgaDatabaseSyncService
{
    public const NOM_SISTEMA_DEFAULT = 'DF';
    public const MAX_CONNECTIONS = 200;

    /** tpoBanco (SGC) => [tipo do service DF, porta padrao] ou null (sem driver). */
    public const TYPE_MAP = [
        'POSTGRES' => ['pgsql_query', 5432],
        'SQL SERVER' => ['sqlsrv', 1433],
        'ORACLE' => ['oracle', 1521],
        'INFORMIX' => ['informix', 9088],
        'FIREBIRD' => ['firebird', 3050],
    ];

    private SgcConnectionClient $sgc;

    public function __construct(?SgcConnectionClient $sgc = null)
    {
        $this->sgc = $sgc ?? new SgcConnectionClient();
    }

    /**
     * @return array{nomSistema:string,total:int,created:array,updated:array,skipped:array,needs_attention:array}
     */
    public function sync(string $nomSistema = self::NOM_SISTEMA_DEFAULT): array
    {
        $nomSistema = trim($nomSistema) ?: self::NOM_SISTEMA_DEFAULT;
        $lista = $this->sgc->getListaConexaoSistema($nomSistema);
        $report = [
            'nomSistema' => $nomSistema,
            'total' => count($lista),
            'created' => [],
            'updated' => [],
            'skipped' => [],
            'needs_attention' => [],
        ];
        $n = 0;
        foreach ($lista as $conn) {
            if (!is_array($conn)) {
                continue;
            }
            if (++$n > self::MAX_CONNECTIONS) {
                $report['skipped'][] = ['nomConexao' => '?', 'reason' => 'limite de 200 vinculos por sync'];
                break;
            }
            $this->syncOne($conn, $nomSistema, $report);
        }
        $this->log('sga_db_sync.done', [
            'nomSistema' => $nomSistema,
            'total' => $report['total'],
            'created' => count($report['created']),
            'updated' => count($report['updated']),
            'skipped' => count($report['skipped']),
            'needs_attention' => count($report['needs_attention']),
        ]);
        return $report;
    }

    private function syncOne(array $conn, string $nomSistema, array &$report): void
    {
        $id = (int) ($conn['idConexao'] ?? $conn['codConexao'] ?? 0);
        $nomConexao = trim((string) ($conn['nomConexao'] ?? ''));
        if ($id < 1 || $nomConexao === '') {
            $report['skipped'][] = ['nomConexao' => $nomConexao !== '' ? $nomConexao : '?', 'reason' => 'vinculo sem id/nome'];
            return;
        }
        $tpo = strtoupper(trim((string) ($conn['tpoBanco'] ?? '')));
        if (!isset(self::TYPE_MAP[$tpo])) {
            $report['skipped'][] = ['idConexao' => $id, 'nomConexao' => $nomConexao, 'reason' => 'tpoBanco sem driver no DF: ' . ($tpo !== '' ? $tpo : '?')];
            return;
        }
        [$dfType, $defaultPort] = self::TYPE_MAP[$tpo];
        $parsed = $this->parseConnection($tpo, (string) ($conn['refUrl'] ?? ''), (string) ($conn['refDatabase'] ?? ''), $defaultPort);

        $service = $this->findSynced($id, $nomSistema);
        $isNew = ($service === null);
        if ($isNew) {
            // O handler de config exige id existente: salva o registro antes.
            $service = new Service();
            $service->name = $this->buildName($id, $nomConexao);
            $service->label = mb_substr($nomConexao, 0, 80);
            $service->type = $dfType;
            $service->is_active = 1;
            $service->save();
        }
        $config = is_array($service->config) ? $service->config : [];
        $config['host'] = $parsed['host'];
        $config['port'] = $parsed['port'];
        $config['database'] = $parsed['database'];
        $config['username'] = (string) ($conn['refLogin'] ?? '');
        $config['password'] = (string) ($conn['refSenha'] ?? '');
        if (isset($parsed['service_name'])) {
            $config['service_name'] = $parsed['service_name'];
        }
        if (isset($parsed['server'])) {
            $config['server'] = $parsed['server'];
        }
        $service->type = $dfType;
        $service->config = $config;
        $service->description = mb_substr(
            'SGA/SGC sync id ' . $id . ' sistema ' . $nomSistema . ' — editavel; o sync reaplica a conexao.' .
            ($parsed['needs_attention'] ? ' Completar manualmente: ' . $parsed['needs_attention'] : ''),
            0, 255
        );
        $indAtivo = (int) ($conn['indAtivo'] ?? 1);
        $service->is_active = $indAtivo === 0 ? 0 : 1;
        $service->save();

        $entry = ['idConexao' => $id, 'nomConexao' => $nomConexao, 'service' => $service->name, 'type' => $dfType];
        if ($isNew) {
            $report['created'][] = $entry;
        } else {
            $report['updated'][] = $entry;
        }
        if ($parsed['needs_attention'] !== '') {
            $report['needs_attention'][] = $entry + ['reason' => $parsed['needs_attention']];
        }
        if ($indAtivo === 0) {
            $report['needs_attention'][] = $entry + ['reason' => 'indAtivo=0 no SGC: service desativado'];
        }
        $this->log('sga_db_sync.one', ['idConexao' => $id, 'nomConexao' => $nomConexao, 'service' => $service->name, 'new' => $isNew]);
    }

    /**
     * Encontra o service ja espelhado pelo prefixo do nome (sgc-{id}-).
     * Os marcadores sgc_* nao sobrevivem ao fillable do handler, por isso
     * o contrato de idempotencia e o prefixo do nome, gerado so por este sync.
     */
    private function findSynced(int $id, string $nomSistema): ?Service
    {
        $candidates = Service::where('name', 'like', 'sgc-' . $id . '-%')->get(['id', 'name', 'config']);
        foreach ($candidates as $candidate) {
            $config = is_array($candidate->config) ? $candidate->config : [];
            $marked = strcasecmp((string) ($config['sgc_nom_sistema'] ?? ''), $nomSistema) === 0;
            if ($marked || preg_match('/^sgc-' . $id . '-/i', (string) $candidate->name)) {
                $fresh = Service::find($candidate->id);
                if ($fresh) {
                    return $fresh;
                }
            }
        }
        return null;
    }

    private function buildName(int $id, string $nomConexao): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $nomConexao), '-'));
        $slug = mb_substr($slug !== '' ? $slug : 'db', 0, 30);
        $base = 'sgc-' . $id . '-' . $slug;
        $name = $base;
        $i = 2;
        while (Service::where('name', $name)->exists()) {
            $name = $base . '-' . ($i++);
        }
        return $name;
    }

    /**
     * @return array{host:string,port:int,database:string,service_name?:string,needs_attention:string}
     */
    private function parseConnection(string $tpo, string $refUrl, string $refDatabase, int $defaultPort): array
    {
        $refUrl = trim($refUrl);
        $refDatabase = trim($refDatabase);
        $out = ['host' => '', 'port' => $defaultPort, 'database' => $refDatabase, 'needs_attention' => ''];
        if ($tpo === 'ORACLE') {
            return $this->parseOracle($refUrl, $refDatabase, $out);
        }
        if ($tpo === 'SQL SERVER') {
            if (preg_match('#^jdbc:sqlserver://([^;:/]+)(?::(\d+))?\s*(?:;(.*))?$#i', $refUrl, $m)) {
                $out['host'] = $m[1];
                if (!empty($m[2])) {
                    $out['port'] = (int) $m[2];
                }
                foreach (explode(';', (string) ($m[3] ?? '')) as $pair) {
                    if (preg_match('/^\s*databaseName\s*=\s*(.+?)\s*$/i', $pair, $mm)) {
                        $out['database'] = $mm[1];
                    }
                }
            }
        } elseif ($tpo === 'FIREBIRD') {
            if (preg_match('#^jdbc:firebirdsql:([^:/]+)(?::(\d+))?:(.+)$#i', $refUrl, $m)) {
                $out['host'] = $m[1];
                if (!empty($m[2])) {
                    $out['port'] = (int) $m[2];
                }
                $out['database'] = $m[3];
            }
        } elseif ($tpo === 'INFORMIX') {
            if (preg_match('#^jdbc:informix-sqli://([^:/]+)(?::(\d+))?/([^:;]+)#i', $refUrl, $m)) {
                $out['host'] = $m[1];
                if (!empty($m[2])) {
                    $out['port'] = (int) $m[2];
                }
                $out['database'] = $m[3];
            }
            if (preg_match('/INFORMIXSERVER\s*=\s*([^:;\/\s]+)/i', $refUrl, $mm)) {
                $out['server'] = trim($mm[1]);
            } elseif (preg_match('/INFORMIXSERVER\s*=\s*([^:;\/\s]+)/i', $refDatabase, $mm)) {
                $out['server'] = trim($mm[1]);
            }
        } else {
            if (preg_match('#^jdbc:[a-z]+://([^:/]+)(?::(\d+))?/([^?;]*?)(\?.*)?$#i', $refUrl, $m)) {
                $out['host'] = $m[1];
                if (!empty($m[2])) {
                    $out['port'] = (int) $m[2];
                }
                if ($m[3] !== '') {
                    $out['database'] = $m[3];
                }
            }
        }
        if ($out['host'] === '') {
            $out['needs_attention'] = 'host nao extraido da URL; preencher host/porta no DF';
        }
        return $out;
    }

    private function parseOracle(string $refUrl, string $refDatabase, array $out): array
    {
        if (preg_match('/thin:@([^:]+):(\d+)[:\/]([^\/\s]+)/i', $refUrl, $m)) {
            $out['host'] = $m[1];
            $out['port'] = (int) $m[2];
            $out['database'] = $m[3];
            $out['service_name'] = $m[3];
            return $out;
        }
        $host = '';
        $port = $out['port'];
        $service = '';
        if (preg_match('/HOST\s*=\s*([^\)\s]+)/i', $refUrl, $m)) {
            $host = trim($m[1]);
        }
        if (preg_match('/PORT\s*=\s*(\d+)/i', $refUrl, $m)) {
            $port = (int) $m[1];
        }
        if (preg_match('/SERVICE_NAME\s*=\s*([^\)\s]+)/i', $refUrl, $m)) {
            $service = trim($m[1]);
        } elseif (preg_match('/\bSID\s*=\s*([^\)\s]+)/i', $refUrl, $m)) {
            $service = trim($m[1]);
        }
        if ($host !== '') {
            $out['host'] = $host;
            $out['port'] = $port;
            if ($service !== '') {
                $out['database'] = $service;
                $out['service_name'] = $service;
            } elseif ($refDatabase !== '') {
                $out['database'] = $refDatabase;
            }
            return $out;
        }
        if ($refDatabase !== '') {
            $out['needs_attention'] = 'TNS complexo: preencher host/porta/service_name no DF';
        } else {
            $out['needs_attention'] = 'sem URL parseavel: preencher host/porta/database no DF';
        }
        return $out;
    }

    private function log(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, ['password', 'refsenha', 'secret', 'credentials', 'dscsenha'], true)) {
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
