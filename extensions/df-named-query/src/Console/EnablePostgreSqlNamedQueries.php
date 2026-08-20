<?php

namespace Yamaha\DreamFactory\NamedQuery\Console;

use DreamFactory\Core\Models\Service;
use Illuminate\Console\Command;

class EnablePostgreSqlNamedQueries extends Command
{
    protected $signature = 'named-query:enable-postgresql {service : Existing PostgreSQL service name}';

    protected $description = 'Changes an existing PostgreSQL service to pgsql_query without changing its configuration.';

    public function handle(): int
    {
        $service = Service::where('name', $this->argument('service'))->first();
        if (!$service) {
            $this->error('DreamFactory service was not found.');

            return self::FAILURE;
        }
        if ($service->type === 'pgsql_query') {
            $this->info("Service '{$service->name}' already supports Named Queries.");

            return self::SUCCESS;
        }
        if ($service->type !== 'pgsql') {
            $this->error("Service '{$service->name}' is type '{$service->type}', not pgsql.");

            return self::FAILURE;
        }

        $service->type = 'pgsql_query';
        // Service model validation requires a string even when legacy records store NULL.
        $service->description ??= '';
        $service->save();
        $this->info("Service '{$service->name}' now supports Named Queries.");

        return self::SUCCESS;
    }
}
