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
use Yamaha\DreamFactory\NamedQuery\Services\ClusterInvalidationService;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;
use Yamaha\DreamFactory\NamedQuery\Services\QueryPostgreSql;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // RQ-070 — enforcement de driver cluster-safe em boot (warn se array/file em prod)
        try {
            $this->app->make(ClusterInvalidationService::class)->ensureClusterSafe();
        } catch (\Throwable $ignored) {}

        if ($this->app->runningInConsole()) {
            $this->commands([EnablePostgreSqlNamedQueries::class, ImportNamedQueries::class]);
        }
    }

    public function register()
    {
        // RQ-070 — cluster-safe invalidation singleton
        $this->app->singleton(ClusterInvalidationService::class, fn () => new ClusterInvalidationService());
        $this->app->alias(ClusterInvalidationService::class, 'df.named-query.invalidation');

        // RQ-021 — contrato de capacidades independente do driver, consultável pela UI/engine.
        $this->app->singleton(DialectCapabilities::class, fn () => new DialectCapabilities());
        $this->app->alias(DialectCapabilities::class, 'df.named-query.capabilities');

        $this->app->resolving('df.system.resource', function (SystemResourceManager $resources) {
            $existing = $resources->getResourceType('named_query');
            if ($existing !== null) {
                $existingClass = $existing->getClassName();
                if ($existingClass !== NamedQueryAdminResource::class) {
                    throw new \RuntimeException(
                        "Service resource type 'named_query' collision: already registered as '$existingClass', cannot register as '" . NamedQueryAdminResource::class . "'. " .
                        "Remove the incompatible package or rename the conflicting type."
                    );
                }

                return;
            }
            $resources->addType(new SystemResourceType([
                'name' => 'named_query',
                'label' => 'Named Queries',
                'description' => 'Administrates versioned Named Query definitions.',
                'class_name' => NamedQueryAdminResource::class,
            ]));
        });

        $this->app->resolving('df.service', function (ServiceManager $services) {
            $existing = $services->getServiceType('pgsql_query');
            if ($existing !== null) {
                $existingHandler = $existing->getConfigHandler();
                if ($existingHandler !== null && $existingHandler !== PgSqlDbConfig::class) {
                    throw new \RuntimeException(
                        "Service type 'pgsql_query' collision: already registered with config_handler '$existingHandler', cannot register as '" . PgSqlDbConfig::class . "'. " .
                        "Remove the incompatible package providing the conflicting type."
                    );
                }

                return;
            }
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
