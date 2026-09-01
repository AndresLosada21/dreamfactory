<?php

namespace Yamaha\DreamFactory\NamedQuery;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;
use DreamFactory\Core\SqlDb\Models\PgSqlDbConfig;
use Yamaha\DreamFactory\NamedQuery\Resources\NamedQueryAdminResource;
use Yamaha\DreamFactory\NamedQuery\Resources\HealthResource;
use Yamaha\DreamFactory\NamedQuery\Console\ImportNamedQueries;
use Yamaha\DreamFactory\NamedQuery\Console\EnablePostgreSqlNamedQueries;
use Yamaha\DreamFactory\NamedQuery\Console\ReconcileConfig;
use Yamaha\DreamFactory\NamedQuery\Services\ConfigReconciliationService;
use Yamaha\DreamFactory\NamedQuery\Services\SecretRotationService;
use Yamaha\DreamFactory\NamedQuery\Services\ClusterInvalidationService;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;
use Yamaha\DreamFactory\NamedQuery\Services\MetricsService;
use Yamaha\DreamFactory\NamedQuery\Services\QueryPostgreSql;
use Yamaha\DreamFactory\NamedQuery\Services\StructuredLogService;
use Yamaha\DreamFactory\NamedQuery\Services\SgcConnectionClient;
use Yamaha\DreamFactory\NamedQuery\Services\SgaClient;
use Yamaha\DreamFactory\NamedQuery\Services\SgaSgcOrchestrator;
use Yamaha\DreamFactory\NamedQuery\Services\SgcCircuitBreaker;
use Yamaha\DreamFactory\NamedQuery\Http\Middleware\RequestTracingMiddleware;
use Yamaha\DreamFactory\NamedQuery\Http\HealthCheckService;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // RQ-070 — enforcement de driver cluster-safe em boot (warn se array/file em prod)
        try {
            $this->app->make(ClusterInvalidationService::class)->ensureClusterSafe();
        } catch (\Throwable $ignored) {}

        // RQ-072 — tracing middleware (propaga X-Request-ID)
        try {
            $router = $this->app['router'] ?? null;
            if ($router && method_exists($router, 'pushMiddlewareToGroup')) {
                $router->pushMiddlewareToGroup('api', RequestTracingMiddleware::class);
            }
        } catch (\Throwable $ignored) {}

        // RQ-071 — health liveness não depende dos bancos
        try {
            $this->app->make(HealthCheckService::class);
        } catch (\Throwable $ignored) {}

        if ($this->app->runningInConsole()) {
            $this->commands([EnablePostgreSqlNamedQueries::class, ImportNamedQueries::class, ReconcileConfig::class]);
        }
    }

    public function register()
    {
        // RQ-081 — reconciliation e secret rotation
        $this->app->singleton(ConfigReconciliationService::class, fn () => new ConfigReconciliationService());
        $this->app->alias(ConfigReconciliationService::class, 'df.named-query.reconciliation');
        $this->app->singleton(SecretRotationService::class, fn () => new SecretRotationService());
        $this->app->alias(SecretRotationService::class, 'df.named-query.secret-rotation');

        // RQ-071 — health check service (liveness sem DB)
        $this->app->singleton(HealthCheckService::class, fn () => new HealthCheckService());
        $this->app->alias(HealthCheckService::class, 'df.named-query.health');

        // RQ-072 — metrics e structured log singletons
        $this->app->singleton(MetricsService::class, fn () => new MetricsService());
        $this->app->alias(MetricsService::class, 'df.named-query.metrics');
        $this->app->singleton(StructuredLogService::class, fn () => new StructuredLogService());
        $this->app->alias(StructuredLogService::class, 'df.named-query.structured-log');

        // RQ-070 — cluster-safe invalidation singleton
        $this->app->singleton(ClusterInvalidationService::class, fn () => new ClusterInvalidationService());
        $this->app->alias(ClusterInvalidationService::class, 'df.named-query.invalidation');

        // RQ-021 — contrato de capacidades independente do driver, consultável pela UI/engine.
        $this->app->singleton(DialectCapabilities::class, fn () => new DialectCapabilities());
        $this->app->alias(DialectCapabilities::class, 'df.named-query.capabilities');

        // RQ-061 + RQ-086 + RQ-087 — SGA+SGC nativo v2 (substitui api-query) — singletons df.sga/df.sgc/df.sga-sgc-orchestrator
        $this->app->singleton(SgcConnectionClient::class, fn () => new SgcConnectionClient());
        $this->app->alias(SgcConnectionClient::class, 'df.sgc');
        $this->app->singleton(SgaClient::class, fn () => new SgaClient());
        $this->app->alias(SgaClient::class, 'df.sga');
        $this->app->singleton(SgcCircuitBreaker::class, fn () => new SgcCircuitBreaker());
        $this->app->alias(SgcCircuitBreaker::class, 'df.sgc.circuit-breaker');
        $this->app->singleton(SgaSgcOrchestrator::class, fn () => new SgaSgcOrchestrator(
            $this->app->make(SgaClient::class),
            $this->app->make(SgcConnectionClient::class),
            $this->app->make(SgcCircuitBreaker::class),
            $this->app->make(ClusterInvalidationService::class),
            $this->app->make(SecretRotationService::class)
        ));
        $this->app->alias(SgaSgcOrchestrator::class, 'df.sga-sgc-orchestrator');

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
            } else {
                $resources->addType(new SystemResourceType([
                    'name' => 'named_query',
                    'label' => 'Named Queries',
                    'description' => 'Administrates versioned Named Query definitions.',
                    'class_name' => NamedQueryAdminResource::class,
                ]));
            }
            // RQ-071 — health resource (liveness/readiness) — system-level, sem auth para liveness/ready, admin para detailed
            $healthExisting = $resources->getResourceType('health');
            if ($healthExisting === null) {
                $resources->addType(new SystemResourceType([
                    'name' => 'health',
                    'label' => 'Health Checks',
                    'description' => 'Liveness, readiness and detailed health (RQ-071).',
                    'class_name' => HealthResource::class,
                ]));
            }
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
