<?php

namespace Yamaha\DreamFactory\NamedQuery\Console;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Service;
use Illuminate\Console\Command;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Repositories\NamedQueryRepository;

class ImportNamedQueries extends Command
{
    protected $signature = 'named-query:import {file : JSON definition file} {--publish : Publish each imported draft}';

    protected $description = 'Imports Named Query definitions for an existing DreamFactory service.';

    public function handle(NamedQueryRepository $repository): int
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("Definition file '$path' was not found.");

            return self::FAILURE;
        }

        try {
            $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $service = Service::where('name', $document['service_name'] ?? null)->first();
            if (!$service) {
                throw new BadRequestException('The definition service_name does not match an existing DreamFactory service.');
            }
            foreach ($document['queries'] ?? [] as $definition) {
                if (!is_array($definition) || empty($definition['name'])) {
                    throw new BadRequestException('Each imported Named Query needs a name.');
                }
                if (NamedQuery::forService($service->id)->where('name', $definition['name'])->exists()) {
                    $this->warn("Skipped '{$definition['name']}': it already exists.");
                    continue;
                }

                $query = $repository->create($definition + ['service_id' => $service->id]);
                if ($this->option('publish')) {
                    $revisionId = $query->revisions()->value('id');
                    $repository->publish($query->id, $revisionId, $query->lock_version);
                }
                $this->info("Imported '{$query->name}'.");
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
