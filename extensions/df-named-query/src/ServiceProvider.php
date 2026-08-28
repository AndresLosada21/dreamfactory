<?php

namespace Yamaha\DreamFactory\NamedQuery;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;
use DreamFactory\Core\SqlDb\Models\PgSqlDbConfig;
use Yamaha\DreamFactory\NamedQuery\Resources\NamedQueryAdminResource;
use Yamaha\DreamFactory\NamedQuery\Console\ImportNamedQueries;
use Yamaha\DreamFactory\NamedQuery\Console\EnablePostgreSqlNamedQueries;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;
use Yamaha\DreamFactory\NamedQuery\Services\QueryPostgreSql;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([EnablePostgreSqlNamedQueries::class, ImportNamedQueries::class]);
        }
    }

    public function register()
    {
        // RQ-021 — contrato de capacidades independente do driver, consultável pela UI/engine.
        $this->app->singleton(DialectCapabilities::class, fn () => new DialectCapabilities());
        $this->app->alias(DialectCapabilities::class, 'df.named-query.capabilities');

        $this->app->resolving('df.system.resource', function (SystemResourceManager $resources) {
            $resources->addType(new SystemResourceType([
                'name' => 'named_query',
                'label' => 'Named Queries',
                'description' => 'Administrates versioned Named Query definitions.',
                'class_name' => NamedQueryAdminResource::class,
            ]));
        });

        $this->app->resolving('df.service', function (ServiceManager $services) {
            $services->addType(new ServiceType([
                'name' => 'pgsql_query',
                'label' => 'PostgreSQL with Named Queries',
                'description' => 'Native PostgreSQL service with the read-only _query resource.',
                'group' => ServiceTypeGroups::DATABASE,
                'config_handler' => PgSqlDbConfig::class,
                'factory' => function (array $config) {
                    return new QueryPostgreSql($config);
                },
            ]));
        });
    }
}
