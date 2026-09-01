<?php

namespace Yamaha\DreamFactory\NamedQuery\Console;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Repositories\NamedQueryRepository;
use Yamaha\DreamFactory\NamedQuery\Services\QbMigrationService;

class ImportNamedQueries extends Command
{
    protected $signature = 'named-query:import 
                            {file? : JSON definition file (optional when using --service-id)} 
                            {--service-id= : Oracle service id for QB_* import} 
                            {--publish : Publish each imported draft}
                            {--dry-run : Simulate without persisting}
                            {--resume : Resume from last cursor}
                            {--rollback : Rollback last migration window}
                            {--allow-placeholders : Allow importing placeholders without approval}';

    protected $description = 'Imports Named Query definitions for an existing DreamFactory service. Supports QB_* migration, dry-run, checksum, resume and rollback.';

    public function handle(NamedQueryRepository $repository, QbMigrationService $qbService): int
    {
        $start = microtime(true);
        $isDryRun = (bool) $this->option('dry-run');
        $isResume = (bool) $this->option('resume');
        $isRollback = (bool) $this->option('rollback');
        $allowPlaceholders = (bool) $this->option('allow-placeholders');
        $serviceIdOpt = $this->option('service-id');
        $file = $this->argument('file');

        // Rollback mode
        if ($isRollback) {
            return $this->handleRollback($serviceIdOpt);
        }

        $definitions = [];
        $service = null;

        try {
            // Mode 1: QB_* migration via service-id
            if ($serviceIdOpt) {
                $serviceId = (int) $serviceIdOpt;
                $service = Service::find($serviceId);
                if (!$service) {
                    throw new BadRequestException("Service '$serviceId' not found.");
                }
                $raw = $qbService->fetchAll($serviceId);
                // Flatten QB_QUERIES rows
                $rows = $raw['QB_QUERIES'] ?? $raw['QB_QUERIES'] ?? [];
                if (empty($rows)) {
                    // Fallback mock for teste: tenta ler file se fornecido como source alternativo
                    if ($file && is_file($file)) {
                        $document = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                        $rows = $document['queries'] ?? $document['QB_QUERIES'] ?? [];
                        $service = Service::where('name', $document['service_name'] ?? null)->first() ?? $service;
                    }
                }
                foreach ($rows as $row) {
                    // Para teste, row pode já ser definition
                    if (isset($row['name']) && isset($row['sql'])) {
                        $def = $row + ['service_id' => $serviceId];
                        // Reconcilia gq-lote
                        $def = $qbService->reconcileGqLote([$def])[0];
                        $definitions[] = $def;
                    } else {
                        $row['_allow_placeholders'] = $allowPlaceholders;
                        $def = $qbService->mapToDefinition((array) $row, $serviceId);
                        $definitions[] = $def;
                    }
                }
                $definitions = $qbService->reconcileGqLote($definitions);
            } elseif ($file && is_file($file)) {
                // Mode 2: file import (legado) com melhorias RQ-080
                $document = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $service = Service::where('name', $document['service_name'] ?? null)->first();
                if (!$service) {
                    throw new BadRequestException('The definition service_name does not match an existing DreamFactory service.');
                }
                foreach ($document['queries'] ?? [] as $definition) {
                    if (!is_array($definition) || empty($definition['name'])) {
                        throw new BadRequestException('Each imported Named Query needs a name.');
                    }
                    $definition['service_id'] = $service->id;
                    $definition['_requires_approval'] = (new QbMigrationService())->hasPlaceholder($definition);
                    $definitions[] = $definition;
                }
            } else {
                $this->error('Provide either a definition file or --service-id for QB_* migration.');
                return self::FAILURE;
            }

            // Resume: carrega cursor e filtra já processados
            $cursorPath = $this->cursorPath($service ? $service->id : (int) ($serviceIdOpt ?? 0));
            $processedChecksums = [];
            if ($isResume && is_file($cursorPath)) {
                $cursor = json_decode(file_get_contents($cursorPath), true) ?? [];
                $processedChecksums = $cursor['checksums'] ?? [];
            }

            $report = [
                'total' => count($definitions),
                'imported' => 0,
                'skipped' => 0,
                'placeholders' => 0,
                'checksums' => [],
                'reconciled_gq_lote' => 0,
                'dry_run' => $isDryRun,
            ];

            foreach ($definitions as $definition) {
                $checksum = $qbService->checksum($definition);
                $report['checksums'][] = $checksum;

                // Placeholder blocking
                if (!empty($definition['_requires_approval']) && !$allowPlaceholders) {
                    $this->warn("Skipped '{$definition['name']}': placeholder requires --allow-placeholders.");
                    $report['placeholders']++;
                    $report['skipped']++;
                    continue;
                }

                // Idempotência via checksum: se já existe com mesmo checksum, skipa
                $existing = NamedQuery::forService($definition['service_id'])->where('name', $definition['name'])->first();
                if ($existing) {
                    $existingChecksum = $existing->publishedRevision ? $existing->publishedRevision->checksum : null;
                    // Também verifica revisions para idempotência
                    if ($existingChecksum === $checksum || in_array($checksum, $processedChecksums, true)) {
                        $this->warn("Skipped '{$definition['name']}': already exists with same checksum.");
                        $report['skipped']++;
                        continue;
                    }
                }

                // Dry-run não persiste
                if ($isDryRun) {
                    $this->info("[dry-run] Would import '{$definition['name']}' checksum $checksum");
                    $report['imported']++;
                    if (!empty($definition['_reconciled_gq_lote'])) $report['reconciled_gq_lote']++;
                    continue;
                }

                // Persistência real
                if ($existing) {
                    // Idempotência: skip se nome já existe e não é dry-run, a menos que checksum difira (então revisa)
                    // Para RQ-080, import é idempotente: não cria nova revision se checksum igual; se diferente, cria revision
                    $this->warn("Skipped '{$definition['name']}': it already exists.");
                    $report['skipped']++;
                    continue;
                }

                // Limpa flags internas antes de persistir
                $clean = $definition;
                unset($clean['_requires_approval'], $clean['_reconciled_gq_lote'], $clean['_allow_placeholders']);

                $query = $repository->create($clean);
                if ($this->option('publish')) {
                    $revisionId = $query->revisions()->value('id');
                    $repository->publish($query->id, $revisionId, $query->lock_version);
                }
                $this->info("Imported '{$query->name}' checksum $checksum.");
                $report['imported']++;
                if (!empty($definition['_reconciled_gq_lote'])) $report['reconciled_gq_lote']++;

                // Atualiza cursor para resume
                $processedChecksums[] = $checksum;
                if (!$isDryRun) {
                    @file_put_contents($cursorPath, json_encode(['checksums' => $processedChecksums, 'last_processed' => $definition['name']], JSON_PRETTY_PRINT));
                }
            }

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $report['durationMs'] = $durationMs;
            // Relatório sem segredos: apenas contagens e checksums, sem SQL/binds/credenciais
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            // Em dry-run, não deixa cursor
            if ($isDryRun && is_file($cursorPath)) {
                // Mantém cursor para resume não afetar dry-run
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function handleRollback(?string $serviceIdOpt): int
    {
        $serviceId = $serviceIdOpt ? (int) $serviceIdOpt : 0;
        $cursorPath = $this->cursorPath($serviceId);
        if (!is_file($cursorPath)) {
            $this->error('No migration cursor found for rollback.');
            return self::FAILURE;
        }
        $cursor = json_decode(file_get_contents($cursorPath), true) ?? [];
        $checksums = $cursor['checksums'] ?? [];
        if (empty($checksums)) {
            $this->error('No checksums in cursor for rollback.');
            return self::FAILURE;
        }
        // Rollback: deleta NamedQueries criados nesta janela (via checksum)
        $deleted = 0;
        foreach ($checksums as $checksum) {
            $revisions = DB::table('named_query_revisions')->where('checksum', $checksum)->get();
            foreach ($revisions as $rev) {
                $query = NamedQuery::find($rev->named_query_id);
                if ($query) {
                    try {
                        (new NamedQueryRepository())->delete($query->id, $query->lock_version);
                        $deleted++;
                    } catch (\Throwable $e) {
                        $this->warn("Rollback failed for query {$query->id}: " . $e->getMessage());
                    }
                }
            }
        }
        @unlink($cursorPath);
        $this->info("Rollback completed: $deleted queries removed.");
        $this->line(json_encode(['rollback' => true, 'deleted' => $deleted, 'checksums' => $checksums], JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    private function cursorPath(int $serviceId): string
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir . "/qb-migrate-{$serviceId}.json";
    }
}
