<?php

namespace Yamaha\DreamFactory\NamedQuery\Console;

use Illuminate\Console\Command;
use Yamaha\DreamFactory\NamedQuery\Services\ConfigReconciliationService;

/**
 * RQ-081 — CLI de reconciliação de configuração migrada
 */
class ReconcileConfig extends Command
{
    protected $signature = 'named-query:reconcile {--dry-run : Validate only} {--allow-overwrite : Allow overwriting collisions} {--file= : JSON file with migrated definitions}';
    protected $description = 'Reconciles migrated Named Query config and blocks promotion on unsupported/collisions.';

    public function handle(ConfigReconciliationService $svc): int
    {
        $file = $this->option('file');
        $dryRun = (bool) $this->option('dry-run');
        $allowOverwrite = (bool) $this->option('allow-overwrite');

        $defs = [];
        if ($file && is_file($file)) {
            $doc = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $defs = $doc['queries'] ?? $doc['definitions'] ?? $doc;
            if (!is_array($defs)) $defs = [];
            // Ensure service_id present
            foreach ($defs as &$d) {
                if (!isset($d['service_id']) && isset($doc['service_name'])) {
                    try {
                        $s = \DreamFactory\Core\Models\Service::where('name', $doc['service_name'])->first();
                        if ($s) $d['service_id'] = $s->id;
                    } catch (\Throwable $ignored) {}
                }
            }
        } else {
            // Demo mode: try to load from definitions folder
            $defs = $this->loadDemoDefinitions();
        }

        $validation = $svc->validate($defs);
        $existing = []; // In real, load from DB: NamedQuery::all()
        try {
            $existing = \Yamaha\DreamFactory\NamedQuery\Models\NamedQuery::with('publishedRevision')->get()->map(fn($q) => [
                'name' => $q->name,
                'service_id' => $q->service_id,
                'checksum' => $q->publishedRevision->checksum ?? $svc->checksum(['sql' => $q->publishedRevision->sql ?? '']),
            ])->toArray();
        } catch (\Throwable $ignored) {}

        $report = $svc->reconcile($defs, $existing);
        $report['dry_run'] = $dryRun;

        // Sanitized report already
        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        if (!empty($report['unsupported'])) {
            $this->error('Blocked: unsupported items found: ' . count($report['unsupported']));
            foreach ($report['unsupported'] as $u) {
                $this->line(' - ' . json_encode($u));
            }
            return self::FAILURE;
        }

        if (!empty($report['collisions']) && !$allowOverwrite) {
            $this->error('Blocked: collisions found: ' . count($report['collisions']) . ' (use --allow-overwrite to force)');
            foreach ($report['collisions'] as $c) {
                $this->line(' - ' . json_encode($c));
            }
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry-run validated: ' . $report['total'] . ' items, checksums_match=' . ($report['checksums_match'] ? 'true' : 'false'));
            return self::SUCCESS;
        }

        $this->info('Reconciliation done: ' . $report['total'] . ' items, valid=' . $report['valid']);
        return self::SUCCESS;
    }

    private function loadDemoDefinitions(): array
    {
        $dir = __DIR__ . '/../../database/definitions';
        $out = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') as $f) {
                $j = json_decode(file_get_contents($f), true);
                if (is_array($j)) $out[] = $j;
            }
        }
        return $out;
    }
}
